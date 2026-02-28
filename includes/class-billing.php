<?php
/**
 * Billing, entitlements, rate-limiting, and plan management.
 *
 * Modes
 * -----
 *  trial  – one free image credit on new activation; uses our API key.
 *  byok   – user supplies their own OpenAI key; unlimited (rate-limited only).
 *  paid   – active Stripe subscription; uses our API key; monthly credit quota.
 *
 * wp-config.php constants required for paid/trial image generation:
 *   WPAIIMAGE_OUR_API_KEY  – your OpenAI key
 *   STRIPE_SECRET_KEY
 *   STRIPE_PUBLISHABLE_KEY
 *   STRIPE_WEBHOOK_SECRET
 *   PRICE_STARTER / PRICE_PRO / PRICE_AGENCY  – Stripe price IDs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Image_Billing {

	// -------------------------------------------------------------------------
	// Option key constants
	// -------------------------------------------------------------------------

	const OPT_MODE         = 'wpaiimage_billing_mode';              // 'trial'|'byok'|'paid'
	const OPT_TRIAL_CREDITS = 'wpaiimage_trial_credits';            // int: 0 or 1
	const OPT_INSTALL_ID   = 'wpaiimage_install_id';                // UUID for server-side trial enforcement
	const OPT_STRIPE_CUS   = 'wpaiimage_stripe_customer_id';
	const OPT_STRIPE_SUB   = 'wpaiimage_stripe_subscription_id';
	const OPT_STRIPE_PLAN  = 'wpaiimage_stripe_plan';               // 'starter'|'pro'|'agency'
	const OPT_STRIPE_STATUS = 'wpaiimage_stripe_status';            // Stripe subscription status string
	const OPT_STRIPE_ENDS  = 'wpaiimage_stripe_current_period_end'; // Unix timestamp
	const OPT_CREDITS      = 'wpaiimage_credits_remaining';         // int
	const OPT_CREDITS_RESET = 'wpaiimage_credits_reset_at';         // Unix timestamp of last credit reset

	// -------------------------------------------------------------------------
	// Plan definitions
	// -------------------------------------------------------------------------

	const PLANS = array(
		'starter' => array( 'name' => 'Starter', 'credits' => 20,  'price_usd' => 7  ),
		'pro'     => array( 'name' => 'Pro',     'credits' => 100, 'price_usd' => 19 ),
		'agency'  => array( 'name' => 'Agency',  'credits' => 400, 'price_usd' => 49 ),
	);

	// Rate limits (requests per 60-second window, per site)
	const RL_BYOK_LIMIT = 10;
	const RL_PAID_LIMIT = 20;

	/** @var self|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_wpaiimage_checkout',     array( $this, 'ajax_checkout' ) );
		add_action( 'wp_ajax_wpaiimage_portal',       array( $this, 'ajax_portal' ) );
		add_action( 'wp_ajax_wpaiimage_save_byok',    array( $this, 'ajax_save_byok' ) );
		add_action( 'wp_ajax_wpaiimage_switch_trial', array( $this, 'ajax_switch_trial' ) );
		add_action( 'wp_ajax_wpaiimage_billing_sync', array( $this, 'ajax_sync' ) );
	}

	private function get_server_base_url() {
		$base = (string) get_option( WP_AI_Image::OPTION_SERVER_BASE_URL, '' );
		return rtrim( $base, '/' );
	}

	private function get_install_id() {
		return (string) get_option( self::OPT_INSTALL_ID, '' );
	}

	private function ensure_site_token() {
		$token = (string) get_option( WP_AI_Image::OPTION_SITE_TOKEN, '' );
		if ( $token !== '' ) {
			return $token;
		}

		$base       = $this->get_server_base_url();
		$install_id = $this->get_install_id();
		if ( $base === '' ) {
			return new WP_Error( 'no_server_url', __( 'Server base URL is not configured.', 'wp-ai-image-plugin' ) );
		}
		if ( $install_id === '' ) {
			return new WP_Error( 'no_install_id', __( 'Install ID is missing.', 'wp-ai-image-plugin' ) );
		}

		$response = wp_remote_post(
			$base . '/v1/sites/register',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'install_id' => $install_id, 'site_url' => site_url() ) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		if ( $http_code < 200 || $http_code >= 300 ) {
			$api_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
			$message = $api_msg ? $api_msg : sprintf( __( 'Server returned HTTP %d.', 'wp-ai-image-plugin' ), $http_code );
			return new WP_Error( 'server_register_http_error', $message );
		}

		if ( empty( $data['success'] ) || empty( $data['data']['site_token'] ) ) {
			$api_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
			$message = $api_msg ? $api_msg : __( 'Could not register site with server.', 'wp-ai-image-plugin' );
			return new WP_Error( 'server_register_failed', $message );
		}

		if ( ! empty( $data['data']['install_id'] ) ) {
			$canonical = sanitize_text_field( (string) $data['data']['install_id'] );
			if ( $canonical !== '' && $canonical !== $install_id ) {
				update_option( self::OPT_INSTALL_ID, $canonical );
				$install_id = $canonical;
			}
		}

		$token = sanitize_text_field( (string) $data['data']['site_token'] );
		update_option( WP_AI_Image::OPTION_SITE_TOKEN, $token );
		return $token;
	}

	private function server_request( $method, $path, $body = array(), $query = array() ) {
		$base       = $this->get_server_base_url();
		$install_id = $this->get_install_id();
		$token      = $this->ensure_site_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( $base === '' ) {
			return new WP_Error( 'no_server_url', __( 'Server base URL is not configured.', 'wp-ai-image-plugin' ) );
		}
		if ( $install_id === '' ) {
			return new WP_Error( 'no_install_id', __( 'Install ID is missing.', 'wp-ai-image-plugin' ) );
		}

		$url = $base . $path;
		if ( ! empty( $query ) ) {
			$url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $query );
		}

		$payload = array_merge( array( 'install_id' => $install_id ), is_array( $body ) ? $body : array() );
		$args    = array(
			'method'  => strtoupper( (string) $method ),
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
		);

		if ( 'GET' !== $args['method'] ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$data      = json_decode( $raw_body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'server_invalid_json', __( 'Server returned invalid JSON.', 'wp-ai-image-plugin' ) );
		}

		if ( $http_code < 200 || $http_code >= 300 || empty( $data['success'] ) ) {
			$api_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
			$message = $api_msg ? $api_msg : sprintf( __( 'Server returned HTTP %d.', 'wp-ai-image-plugin' ), $http_code );
			return new WP_Error( 'server_error', $message );
		}

		return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
	}

	// =========================================================================
	// Activation
	// =========================================================================

	/**
	 * Called from WP_AI_Image::activate().
	 * Sets up billing defaults for new installs only (idempotent).
	 */
	public static function init_on_activation() {
		if ( false === get_option( self::OPT_MODE ) ) {
			add_option( self::OPT_MODE, 'trial' );
			add_option( self::OPT_TRIAL_CREDITS, 1 );
			add_option( self::OPT_INSTALL_ID, self::generate_uuid() );
		}
	}

	private static function generate_uuid() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff )
		);
	}

	// =========================================================================
	// Entitlement check
	// =========================================================================

	/**
	 * Check whether image generation is allowed RIGHT NOW.
	 *
	 * Returns an array:
	 *   ok      bool    — whether generation may proceed
	 *   mode    string  — 'byok'|'trial'|'paid'   (only when ok=true)
	 *   api_key string  — the key to pass to OpenAI (only when ok=true)
	 *   credits int     — remaining credits; -1 for BYOK (only when ok=true)
	 *   reason  string  — machine-readable failure code (only when ok=false)
	 */
	public function check_entitlement() {
		switch ( $this->get_mode() ) {
			case 'byok':
				return $this->check_byok();
			case 'trial':
				return $this->check_trial();
			case 'paid':
				return $this->check_paid();
			default:
				return $this->deny( 'not_configured' );
		}
	}

	private function check_byok() {
		$api_key = get_option( WP_AI_Image::OPTION_API_KEY, '' );
		if ( empty( $api_key ) ) {
			return $this->deny( 'no_byok_key' );
		}
		if ( ! $this->rate_limit_ok( 'byok' ) ) {
			return $this->deny( 'rate_limited' );
		}
		return array( 'ok' => true, 'mode' => 'byok', 'api_key' => $api_key, 'credits' => -1 );
	}

	private function check_trial() {
		$credits = (int) get_option( self::OPT_TRIAL_CREDITS, 0 );
		if ( $credits <= 0 ) {
			return $this->deny( 'trial_exhausted' );
		}
		if ( ! $this->rate_limit_ok( 'paid' ) ) {
			return $this->deny( 'rate_limited' );
		}
		return array( 'ok' => true, 'mode' => 'trial', 'credits' => $credits );
	}

	private function check_paid() {
		$status     = get_option( self::OPT_STRIPE_STATUS, '' );
		$period_end = (int) get_option( self::OPT_STRIPE_ENDS, 0 );

		$active = in_array( $status, array( 'active', 'trialing' ), true );
		// Allow until period ends on cancellation (grace period)
		$grace  = ( 'canceled' === $status && $period_end > time() );

		if ( ! $active && ! $grace ) {
			return $this->deny( 'subscription_inactive' );
		}

		$this->maybe_reset_credits();

		$credits = (int) get_option( self::OPT_CREDITS, 0 );
		if ( $credits <= 0 ) {
			return $this->deny( 'no_credits' );
		}
		if ( ! $this->rate_limit_ok( 'paid' ) ) {
			return $this->deny( 'rate_limited' );
		}
		return array(
			'ok'      => true,
			'mode'    => 'paid',
			'credits' => $credits,
			'plan'    => get_option( self::OPT_STRIPE_PLAN, '' ),
		);
	}

	private function deny( $reason ) {
		return array( 'ok' => false, 'reason' => $reason );
	}

	private function our_api_key() {
		return defined( 'WPAIIMAGE_OUR_API_KEY' ) ? WPAIIMAGE_OUR_API_KEY : '';
	}

	/**
	 * Quick non-rate-limited check for UI rendering.
	 * Returns true if the current mode is potentially capable of generating.
	 */
	public function is_configured() {
		$mode = $this->get_mode();
		switch ( $mode ) {
			case 'byok':
				return ! empty( get_option( WP_AI_Image::OPTION_API_KEY, '' ) );
			case 'trial':
				return (int) get_option( self::OPT_TRIAL_CREDITS, 0 ) > 0;
			case 'paid':
				$status  = get_option( self::OPT_STRIPE_STATUS, '' );
				$credits = (int) get_option( self::OPT_CREDITS, 0 );
				$ends    = (int) get_option( self::OPT_STRIPE_ENDS, 0 );
				$ok_status = in_array( $status, array( 'active', 'trialing' ), true )
				             || ( 'canceled' === $status && $ends > time() );
				return $ok_status && $credits > 0;
			default:
				return false;
		}
	}

	/**
	 * Human-readable (HTML-allowed) message for a given failure reason.
	 */
	public function entitlement_message( $reason ) {
		$billing_url = esc_url( admin_url( 'admin.php?page=wpaiimage-billing' ) );
		$messages    = array(
			'no_byok_key'          => sprintf(
				/* translators: %s: billing page URL */
				__( 'No API key configured. <a href="%s">Add your key or subscribe →</a>', 'wp-ai-image-plugin' ),
				$billing_url
			),
			'trial_exhausted'      => sprintf(
				/* translators: %s: billing page URL */
				__( 'Your free trial image has been used. <a href="%s">Subscribe or enter your own API key →</a>', 'wp-ai-image-plugin' ),
				$billing_url
			),
			'no_credits'           => sprintf(
				/* translators: %s: billing page URL */
				__( 'No image credits remaining this month. <a href="%s">Upgrade your plan →</a>', 'wp-ai-image-plugin' ),
				$billing_url
			),
			'subscription_inactive' => sprintf(
				/* translators: %s: billing page URL */
				__( 'Subscription inactive. <a href="%s">Manage billing →</a>', 'wp-ai-image-plugin' ),
				$billing_url
			),
			'rate_limited'         => __( 'Too many requests. Please wait a moment and try again.', 'wp-ai-image-plugin' ),
			'service_unavailable'  => __( 'Image service temporarily unavailable. Please try again shortly.', 'wp-ai-image-plugin' ),
			'not_configured'       => sprintf(
				/* translators: %s: billing page URL */
				__( 'Plugin not yet configured. <a href="%s">Go to Billing →</a>', 'wp-ai-image-plugin' ),
				$billing_url
			),
		);
		return isset( $messages[ $reason ] )
			? $messages[ $reason ]
			: __( 'Image generation is not available.', 'wp-ai-image-plugin' );
	}

	// =========================================================================
	// Credit management
	// =========================================================================

	/**
	 * Decrement one credit after a successful generation.
	 * Only call this on success.
	 */
	public function consume_credit( $mode ) {
		if ( 'trial' === $mode ) {
			update_option( self::OPT_TRIAL_CREDITS, max( 0, (int) get_option( self::OPT_TRIAL_CREDITS, 0 ) - 1 ) );
		} elseif ( 'paid' === $mode ) {
			update_option( self::OPT_CREDITS, max( 0, (int) get_option( self::OPT_CREDITS, 0 ) - 1 ) );
		}
	}

	/**
	 * Lazy credit reset: if the Stripe period_end has advanced since the last
	 * reset, the billing period has renewed — give the plan's full quota.
	 * This is a fallback; the webhook's invoice.paid handler does it eagerly.
	 */
	private function maybe_reset_credits() {
		$period_end = (int) get_option( self::OPT_STRIPE_ENDS, 0 );
		$reset_at   = (int) get_option( self::OPT_CREDITS_RESET, 0 );

		if ( $period_end > $reset_at && $period_end > 0 ) {
			$plan    = get_option( self::OPT_STRIPE_PLAN, '' );
			$credits = isset( self::PLANS[ $plan ] ) ? self::PLANS[ $plan ]['credits'] : 0;
			update_option( self::OPT_CREDITS, $credits );
			update_option( self::OPT_CREDITS_RESET, $period_end );
		}
	}

	/**
	 * Hard-reset credits. Called by the Stripe class on invoice.paid.
	 */
	public function reset_credits_for_plan( $plan, $period_end ) {
		$credits = isset( self::PLANS[ $plan ] ) ? self::PLANS[ $plan ]['credits'] : 0;
		update_option( self::OPT_CREDITS, $credits );
		update_option( self::OPT_CREDITS_RESET, (int) $period_end );
	}

	// =========================================================================
	// Rate limiting
	// =========================================================================

	private function rate_limit_ok( $tier ) {
		$limit  = ( 'byok' === $tier ) ? self::RL_BYOK_LIMIT : self::RL_PAID_LIMIT;
		$window = (int) floor( time() / 60 ); // 1-minute bucket
		$key    = 'wpaiimage_rl_' . $tier . '_' . $window;
		$count  = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, 120 ); // expires after 2 minutes
		return true;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	public function get_mode() {
		return get_option( self::OPT_MODE, 'trial' );
	}

	private function is_subscription_active_status( $status, $period_end ) {
		$status = (string) $status;
		$period_end = (int) $period_end;
		return in_array( $status, array( 'active', 'trialing' ), true )
			|| ( 'canceled' === $status && $period_end > time() );
	}

	private function sync_from_server() {
		$install_id = $this->get_install_id();
		return $this->server_request( 'GET', '/v1/sites/me', array(), array( 'install_id' => $install_id ) );
	}

	private function maybe_sync_from_server() {
		$base = $this->get_server_base_url();
		if ( $base === '' ) {
			return;
		}

		$last = (int) get_transient( 'wpaiimage_billing_last_sync' );
		if ( $last > 0 && ( time() - $last ) < 300 ) {
			return;
		}

		$data = $this->sync_from_server();
		if ( is_wp_error( $data ) ) {
			return;
		}

		$plan   = isset( $data['plan'] ) ? sanitize_key( (string) $data['plan'] ) : '';
		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
		$ends   = isset( $data['current_period_end'] ) ? (int) $data['current_period_end'] : 0;
		$creds  = isset( $data['credits_remaining'] ) ? (int) $data['credits_remaining'] : 0;
		$reset  = isset( $data['credits_reset_at'] ) ? (int) $data['credits_reset_at'] : 0;

		update_option( self::OPT_STRIPE_PLAN, $plan );
		update_option( self::OPT_STRIPE_STATUS, $status );
		update_option( self::OPT_STRIPE_ENDS, $ends );
		update_option( self::OPT_CREDITS, $creds );
		update_option( self::OPT_CREDITS_RESET, $reset );

		if ( $this->is_subscription_active_status( $status, $ends ) ) {
			update_option( self::OPT_MODE, 'paid' );
		}

		set_transient( 'wpaiimage_billing_last_sync', time(), 600 );
	}

	// =========================================================================
	// AJAX — Stripe Checkout
	// =========================================================================

	public function ajax_checkout() {
		check_ajax_referer( 'wpaiimage_billing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-image-plugin' ) ) );
		}

		$plan = isset( $_POST['plan'] ) ? sanitize_key( $_POST['plan'] ) : '';
		if ( ! array_key_exists( $plan, self::PLANS ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid plan selected.', 'wp-ai-image-plugin' ) ) );
		}

		$data = $this->server_request( 'POST', '/v1/billing/checkout-session', array( 'plan' => $plan ) );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		$url = isset( $data['url'] ) ? (string) $data['url'] : '';
		if ( $url === '' ) {
			wp_send_json_error( array( 'message' => __( 'Server returned no checkout URL.', 'wp-ai-image-plugin' ) ) );
		}
		wp_send_json_success( array( 'url' => $url ) );
	}

	// =========================================================================
	// AJAX — Stripe Customer Portal
	// =========================================================================

	public function ajax_portal() {
		check_ajax_referer( 'wpaiimage_billing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-image-plugin' ) ) );
		}

		$data = $this->server_request( 'POST', '/v1/billing/portal-session', array() );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		$url = isset( $data['url'] ) ? (string) $data['url'] : '';
		if ( $url === '' ) {
			wp_send_json_error( array( 'message' => __( 'Server returned no portal URL.', 'wp-ai-image-plugin' ) ) );
		}
		wp_send_json_success( array( 'url' => $url ) );
	}

	// =========================================================================
	// AJAX — Save BYOK key
	// =========================================================================

	public function ajax_save_byok() {
		check_ajax_referer( 'wpaiimage_billing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-image-plugin' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key cannot be empty.', 'wp-ai-image-plugin' ) ) );
		}

		$api_key = trim( $api_key );
		if ( strlen( $api_key ) > 200 ) {
			wp_send_json_error( array( 'message' => __( 'That does not look like a valid OpenAI API key.', 'wp-ai-image-plugin' ) ) );
		}
		if ( strpos( $api_key, 'sk-' ) !== 0 ) {
			wp_send_json_error( array( 'message' => __( 'That does not look like a valid OpenAI API key. It should start with “sk-”.', 'wp-ai-image-plugin' ) ) );
		}
		if ( preg_match( '/\s/', $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'That does not look like a valid OpenAI API key (contains spaces/newlines).', 'wp-ai-image-plugin' ) ) );
		}

		update_option( WP_AI_Image::OPTION_API_KEY, $api_key );
		update_option( self::OPT_MODE, 'byok' );

		wp_send_json_success( array( 'message' => __( 'Saved. Switched to BYOK mode.', 'wp-ai-image-plugin' ) ) );
	}

	public function ajax_switch_trial() {
		check_ajax_referer( 'wpaiimage_billing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-ai-image-plugin' ) ) );
		}

		$data = $this->sync_from_server();
		if ( ! is_wp_error( $data ) ) {
			$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
			$ends   = isset( $data['current_period_end'] ) ? (int) $data['current_period_end'] : 0;
			if ( $this->is_subscription_active_status( $status, $ends ) ) {
				update_option( self::OPT_MODE, 'paid' );
				wp_send_json_error( array( 'message' => __( 'Subscription is active. You cannot switch to Free Trial.', 'wp-ai-image-plugin' ) ) );
			}
		}

		update_option( self::OPT_MODE, 'trial' );
		wp_send_json_success( array( 'message' => __( 'Switched to Free Trial mode.', 'wp-ai-image-plugin' ) ) );
	}

	// =========================================================================
	// AJAX — Sync subscription from Stripe
	// =========================================================================

	public function ajax_sync() {
		check_ajax_referer( 'wpaiimage_billing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$data = $this->sync_from_server();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		$plan   = isset( $data['plan'] ) ? sanitize_key( (string) $data['plan'] ) : '';
		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
		$ends   = isset( $data['current_period_end'] ) ? (int) $data['current_period_end'] : 0;
		$creds  = isset( $data['credits_remaining'] ) ? (int) $data['credits_remaining'] : 0;
		$reset  = isset( $data['credits_reset_at'] ) ? (int) $data['credits_reset_at'] : 0;

		update_option( self::OPT_STRIPE_PLAN, $plan );
		update_option( self::OPT_STRIPE_STATUS, $status );
		update_option( self::OPT_STRIPE_ENDS, $ends );
		update_option( self::OPT_CREDITS, $creds );
		update_option( self::OPT_CREDITS_RESET, $reset );


		if ( $this->is_subscription_active_status( $status, $ends ) ) {
			update_option( self::OPT_MODE, 'paid' );
		} elseif ( $status === '' || $status === 'canceled' ) {
			update_option( self::OPT_MODE, 'trial' );
		}

		set_transient( 'wpaiimage_billing_last_sync', time(), 600 );

		wp_send_json_success( array( 'message' => __( 'Status synced.', 'wp-ai-image-plugin' ) ) );
	}

	// =========================================================================
	// Billing admin page
	// =========================================================================

	public function render_billing_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = '';

		$this->maybe_sync_from_server();

		$mode       = $this->get_mode();
		$plan_key   = get_option( self::OPT_STRIPE_PLAN, '' );
		$status     = get_option( self::OPT_STRIPE_STATUS, '' );
		$period_end = (int) get_option( self::OPT_STRIPE_ENDS, 0 );
		$has_sub    = $this->is_subscription_active_status( $status, $period_end );

		if ( 'paid' === $mode ) {
			$credits = (int) get_option( self::OPT_CREDITS, 0 );
		} elseif ( 'trial' === $mode ) {
			$credits = (int) get_option( self::OPT_TRIAL_CREDITS, 0 );
		} else {
			$credits = -1;
		}

		$plan_info = isset( self::PLANS[ $plan_key ] ) ? self::PLANS[ $plan_key ] : null;

		$mode_label = array(
			'trial' => __( 'Free Trial', 'wp-ai-image-plugin' ),
			'byok'  => __( 'BYOK — Your API Key', 'wp-ai-image-plugin' ),
			'paid'  => $plan_info
				? sprintf( /* translators: %s: plan name */ __( '%s Plan', 'wp-ai-image-plugin' ), $plan_info['name'] )
				: __( 'Paid Plan', 'wp-ai-image-plugin' ),
		);

		$byok_key = get_option( WP_AI_Image::OPTION_API_KEY, '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zero-Key AI Images — Billing', 'wp-ai-image-plugin' ); ?></h1>

			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:960px;margin-top:20px;">

				<!-- ── Current plan card ─────────────────────────────────────── -->
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;">
					<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">
						<?php esc_html_e( 'Current Plan', 'wp-ai-image-plugin' ); ?>
					</h2>

					<table class="form-table" style="margin:0;">
						<tr>
							<th style="width:130px;"><?php esc_html_e( 'Mode', 'wp-ai-image-plugin' ); ?></th>
							<td>
								<strong><?php echo esc_html( isset( $mode_label[ $mode ] ) ? $mode_label[ $mode ] : __( 'Not configured', 'wp-ai-image-plugin' ) ); ?></strong>
							</td>
						</tr>
						<?php if ( 'trial' === $mode ) : ?>
						<tr>
							<th><?php esc_html_e( 'Trial Credits', 'wp-ai-image-plugin' ); ?></th>
							<td>
								<strong><?php echo esc_html( $credits ); ?></strong> / 1
								<?php if ( $credits <= 0 ) : ?>
									&nbsp;<span style="color:#dc3232;"><?php esc_html_e( '(used)', 'wp-ai-image-plugin' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endif; ?>
						<?php if ( 'paid' === $mode ) : ?>
						<tr>
							<th><?php esc_html_e( 'Credits', 'wp-ai-image-plugin' ); ?></th>
							<td>
								<strong><?php echo esc_html( $credits ); ?></strong>
								<?php if ( $plan_info ) : ?>
									/ <?php echo esc_html( $plan_info['credits'] ); ?>
									<?php esc_html_e( 'this month', 'wp-ai-image-plugin' ); ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', 'wp-ai-image-plugin' ); ?></th>
							<td><?php echo esc_html( ucfirst( $status ) ); ?></td>
						</tr>
						<?php if ( $period_end ) : ?>
						<tr>
							<th><?php esc_html_e( 'Renews', 'wp-ai-image-plugin' ); ?></th>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), $period_end ) ); ?></td>
						</tr>
						<?php endif; ?>
						<?php endif; ?>
					</table>

					<?php if ( $has_sub ) : ?>
					<p style="margin-top:16px;margin-bottom:0;">
						<button type="button" class="button" id="wpaiimage-portal-btn">
							<?php esc_html_e( 'Manage Subscription →', 'wp-ai-image-plugin' ); ?>
						</button>
						<button type="button" class="button" id="wpaiimage-sync-btn" style="margin-left:6px;">
							<?php esc_html_e( 'Sync Status', 'wp-ai-image-plugin' ); ?>
						</button>
					</p>
					<?php endif; ?>
				</div>

				<!-- ── BYOK card ─────────────────────────────────────────────── -->
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;">
					<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">
						<?php esc_html_e( 'Use Your Own API Key (Free)', 'wp-ai-image-plugin' ); ?>
					</h2>
					<p class="description">
						<?php esc_html_e( 'Enter your OpenAI API key. We never see it — it stays in your WordPress database. No monthly charge from us.', 'wp-ai-image-plugin' ); ?>
					</p>
					<input type="password"
					       id="wpaiimage-byok-key"
					       value="<?php echo esc_attr( $byok_key ); ?>"
					       class="regular-text"
					       autocomplete="new-password"
					       placeholder="sk-proj-..." />
					<p style="margin-top:8px;">
						<button type="button" class="button button-secondary" id="wpaiimage-byok-save">
							<?php esc_html_e( 'Save &amp; Switch to BYOK', 'wp-ai-image-plugin' ); ?>
						</button>
						<?php if ( 'byok' === $mode && ! $has_sub ) : ?>
						<button type="button" class="button" id="wpaiimage-switch-trial" style="margin-left:6px;">
							<?php esc_html_e( 'Switch back to Free Trial', 'wp-ai-image-plugin' ); ?>
						</button>
						<?php endif; ?>
						<span id="wpaiimage-byok-msg" style="margin-left:8px;font-size:13px;"></span>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: OpenAI keys page URL */
								__( 'Get a key at <a href="%s" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>.', 'wp-ai-image-plugin' ),
								'https://platform.openai.com/api-keys'
							),
							array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
						);
						?>
					</p>
					<?php if ( 'byok' === $mode ) : ?>
					<p style="margin-top:8px;">
						<span style="color:#46b450;">&#10003; <?php esc_html_e( 'Active mode', 'wp-ai-image-plugin' ); ?></span>
						&nbsp;—&nbsp;
						<small><?php esc_html_e( 'Rate-limited to 10 images/min.', 'wp-ai-image-plugin' ); ?></small>
					</p>
					<?php endif; ?>
				</div>

			</div><!-- /grid -->

			<!-- ── Subscribe / upgrade ───────────────────────────────────────── -->
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;max-width:960px;margin-top:20px;">
				<h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">
					<?php esc_html_e( 'Subscribe to a Managed Plan', 'wp-ai-image-plugin' ); ?>
				</h2>
				<p class="description">
					<?php esc_html_e( 'We provide the OpenAI key — no setup needed. Credits reset on your monthly renewal date. No carry-over.', 'wp-ai-image-plugin' ); ?>
				</p>

				<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:16px;">
					<?php foreach ( self::PLANS as $key => $plan ) :
						$is_current = ( 'paid' === $mode && $plan_key === $key );
					?>
					<div style="flex:1;min-width:200px;border:2px solid <?php echo $is_current ? '#0073aa' : '#ddd'; ?>;border-radius:6px;padding:20px;text-align:center;position:relative;">
						<?php if ( $is_current ) : ?>
						<span style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#0073aa;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;">
							<?php esc_html_e( 'Current', 'wp-ai-image-plugin' ); ?>
						</span>
						<?php endif; ?>
						<h3 style="margin:0 0 8px;"><?php echo esc_html( $plan['name'] ); ?></h3>
						<div style="font-size:32px;font-weight:700;margin:8px 0;">
							$<?php echo esc_html( $plan['price_usd'] ); ?><span style="font-size:14px;font-weight:400;">/mo</span>
						</div>
						<div style="color:#555;margin-bottom:16px;font-size:13px;">
							<?php
echo esc_html( sprintf(
							/* translators: %d: number of credits */
							__( '%d image credits / month', 'wp-ai-image-plugin' ),
							$plan['credits']
						) );
							?>
						</div>
						<?php if ( $is_current ) : ?>
						<span class="button button-primary disabled" aria-disabled="true">
							<?php esc_html_e( 'Your plan', 'wp-ai-image-plugin' ); ?>
						</span>
						<?php else : ?>
						<button type="button"
						        class="button button-primary wpaiimage-subscribe-btn"
						        data-plan="<?php echo esc_attr( $key ); ?>">
							<?php echo $has_sub ? esc_html__( 'Switch Plan', 'wp-ai-image-plugin' ) : esc_html__( 'Subscribe', 'wp-ai-image-plugin' ); ?>
						</button>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
			</div><!-- /upgrade -->

			<p style="max-width:960px;margin-top:12px;color:#777;font-size:12px;">
				<?php
				esc_html_e( 'Billing is handled securely by Stripe. Zero-Key AI Images does not store your payment details.', 'wp-ai-image-plugin' );
				?>
			</p>
		</div><!-- /wrap -->
		<?php
	}

}
