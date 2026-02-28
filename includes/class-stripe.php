<?php
/**
 * Stripe API client and webhook handler.
 *
 * Uses wp_remote_post / wp_remote_get directly (no Stripe SDK dependency).
 *
 * Webhook endpoint: POST /wp-json/wpaiimage/v1/stripe-webhook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Image_Stripe {

	const STRIPE_API_BASE = 'https://api.stripe.com/v1/';
	const STRIPE_VERSION  = '2024-06-20'; // pinned stable version

	/** @var self|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );
	}

	// =========================================================================
	// REST webhook endpoint
	// =========================================================================

	public function register_webhook_route() {
		register_rest_route(
			'wpaiimage/v1',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true', // auth done via Stripe signature
			)
		);
	}

	/**
	 * Webhook handler called by WordPress REST API.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$payload    = $request->get_body();
		$sig_header = $request->get_header( 'stripe-signature' );

		if ( ! $this->verify_signature( $payload, $sig_header ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! isset( $event['type'], $event['data']['object'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Malformed event' ), 400 );
		}

		$obj = $event['data']['object'];

		switch ( $event['type'] ) {
			case 'customer.subscription.created':
			case 'customer.subscription.updated':
			case 'customer.subscription.deleted':
				$this->apply_subscription( $obj );
				break;

			case 'invoice.paid':
				$this->handle_invoice_paid( $obj );
				break;
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// =========================================================================
	// Subscription state sync
	// =========================================================================

	/**
	 * Fetch a subscription from Stripe and update local billing state.
	 *
	 * @param  string       $subscription_id
	 * @return true|WP_Error
	 */
	public function sync_subscription( $subscription_id ) {
		$sub = $this->stripe_request( 'GET', 'subscriptions/' . rawurlencode( $subscription_id ) );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		$this->apply_subscription( $sub );
		return true;
	}

	/**
	 * Write a Stripe subscription object into wp_options.
	 *
	 * @param array $sub  Decoded Stripe subscription object.
	 */
	private function apply_subscription( array $sub ) {
		$customer_id = isset( $sub['customer'] ) ? $sub['customer'] : '';
		$sub_id      = isset( $sub['id'] )       ? $sub['id']       : '';
		$status      = isset( $sub['status'] )   ? $sub['status']   : '';
		$period_end  = isset( $sub['current_period_end'] ) ? (int) $sub['current_period_end'] : 0;

		// Resolve plan key: prefer metadata, fall back to price ID match
		$plan = '';
		if ( ! empty( $sub['metadata']['plan'] ) ) {
			$plan = sanitize_key( $sub['metadata']['plan'] );
		} elseif ( ! empty( $sub['items']['data'][0]['price']['id'] ) ) {
			$plan = $this->price_id_to_plan( $sub['items']['data'][0]['price']['id'] );
		}

		if ( $customer_id ) {
			update_option( WP_AI_Image_Billing::OPT_STRIPE_CUS, $customer_id );
		}
		if ( $sub_id ) {
			update_option( WP_AI_Image_Billing::OPT_STRIPE_SUB, $sub_id );
		}

		update_option( WP_AI_Image_Billing::OPT_STRIPE_STATUS, $status );
		update_option( WP_AI_Image_Billing::OPT_STRIPE_ENDS, $period_end );

		if ( $plan ) {
			$old_plan = get_option( WP_AI_Image_Billing::OPT_STRIPE_PLAN, '' );
			update_option( WP_AI_Image_Billing::OPT_STRIPE_PLAN, $plan );

			// If plan changed mid-period, adjust credit allowance immediately
			if ( $old_plan !== $plan ) {
				$plans   = WP_AI_Image_Billing::PLANS;
				$credits = isset( $plans[ $plan ] ) ? $plans[ $plan ]['credits'] : 0;
				update_option( WP_AI_Image_Billing::OPT_CREDITS, $credits );
			}
		}

		// Update billing mode
		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			update_option( WP_AI_Image_Billing::OPT_MODE, 'paid' );
		} elseif ( 'canceled' === $status && $period_end <= time() ) {
			// Grace period has ended — revert to trial (user can re-subscribe)
			update_option( WP_AI_Image_Billing::OPT_MODE, 'trial' );
		}
		// 'canceled' with period_end still in the future: keep mode='paid' until grace ends
	}

	/**
	 * Handle invoice.paid: reset credits for the new billing period.
	 *
	 * @param array $invoice  Decoded Stripe invoice object.
	 */
	private function handle_invoice_paid( array $invoice ) {
		$subscription_id = ! empty( $invoice['subscription'] ) ? $invoice['subscription'] : '';

		// Determine new period_end from the invoice line item
		$new_period_end = 0;
		if ( ! empty( $invoice['lines']['data'][0]['period']['end'] ) ) {
			$new_period_end = (int) $invoice['lines']['data'][0]['period']['end'];
		}

		// Sync subscription to get latest status/plan
		if ( $subscription_id ) {
			$sub = $this->stripe_request( 'GET', 'subscriptions/' . rawurlencode( $subscription_id ) );
			if ( ! is_wp_error( $sub ) ) {
				$this->apply_subscription( $sub );
				// Override period_end with the invoice line item value if available
				if ( $new_period_end > 0 ) {
					update_option( WP_AI_Image_Billing::OPT_STRIPE_ENDS, $new_period_end );
				}
			}
		}

		// Hard-reset credits now that a new period has been paid for
		$plan = get_option( WP_AI_Image_Billing::OPT_STRIPE_PLAN, '' );
		if ( $plan && $new_period_end > 0 ) {
			WP_AI_Image_Billing::get_instance()->reset_credits_for_plan( $plan, $new_period_end );
		}
	}

	// =========================================================================
	// Stripe API calls
	// =========================================================================

	/**
	 * Create a Stripe Checkout Session for a subscription.
	 *
	 * @param  string      $plan_key    One of 'starter', 'pro', 'agency'.
	 * @param  string|null $customer_id Existing Stripe customer ID, if any.
	 * @return array|WP_Error           Decoded session object or error.
	 */
	public function create_checkout_session( $plan_key, $customer_id = null ) {
		$price_id = $this->plan_to_price_id( $plan_key );
		if ( empty( $price_id ) ) {
			return new WP_Error(
				'no_price_id',
				/* translators: %s: plan name */
				sprintf( __( 'Stripe price ID for plan "%s" not configured in wp-config.php.', 'wp-ai-image' ), $plan_key )
			);
		}

		$billing_url = admin_url( 'admin.php?page=wpaiimage-billing' );
		$install_id  = get_option( WP_AI_Image_Billing::OPT_INSTALL_ID, '' );

		$data = array(
			'mode'                                     => 'subscription',
			'line_items[0][price]'                     => $price_id,
			'line_items[0][quantity]'                  => '1',
			// {CHECKOUT_SESSION_ID} is a Stripe literal — not a PHP variable
			'success_url'                              => $billing_url . '&stripe=success&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'                               => $billing_url . '&stripe=cancel',
			'metadata[install_id]'                     => $install_id,
			'metadata[site_url]'                       => site_url(),
			'subscription_data[metadata][plan]'        => $plan_key,
			'subscription_data[metadata][site_url]'    => site_url(),
			'subscription_data[metadata][install_id]'  => $install_id,
		);

		if ( $customer_id ) {
			$data['customer'] = $customer_id;
		}

		return $this->stripe_request( 'POST', 'checkout/sessions', $data );
	}

	/**
	 * Create a Stripe Billing Portal session.
	 *
	 * @param  string $customer_id
	 * @param  string $return_url
	 * @return array|WP_Error
	 */
	public function create_portal_session( $customer_id, $return_url ) {
		return $this->stripe_request( 'POST', 'billing_portal/sessions', array(
			'customer'   => $customer_id,
			'return_url' => $return_url,
		) );
	}

	/**
	 * Retrieve a Checkout Session by ID.
	 *
	 * @param  string $session_id
	 * @return array|WP_Error
	 */
	public function retrieve_checkout_session( $session_id ) {
		return $this->stripe_request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
	}

	// =========================================================================
	// HTTP client
	// =========================================================================

	/**
	 * Make an authenticated request to the Stripe API.
	 *
	 * @param  string $method   'GET' or 'POST'.
	 * @param  string $endpoint Path relative to STRIPE_API_BASE, e.g. 'checkout/sessions'.
	 * @param  array  $data     Request body / query params.
	 * @return array|WP_Error   Decoded response body or error.
	 */
	private function stripe_request( $method, $endpoint, $data = array() ) {
		$secret_key = defined( 'STRIPE_SECRET_KEY' ) ? STRIPE_SECRET_KEY : '';
		if ( empty( $secret_key ) ) {
			return new WP_Error(
				'no_stripe_key',
				__( 'Stripe secret key not configured. Add STRIPE_SECRET_KEY to wp-config.php.', 'wp-ai-image' )
			);
		}

		$url  = self::STRIPE_API_BASE . ltrim( $endpoint, '/' );
		$args = array(
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret_key,
				'Content-Type'   => 'application/x-www-form-urlencoded',
				'Stripe-Version' => self::STRIPE_VERSION,
			),
			'timeout' => 30,
		);

		if ( 'GET' === strtoupper( $method ) ) {
			if ( ! empty( $data ) ) {
				$url .= '?' . http_build_query( $data );
			}
			$response = wp_remote_get( $url, $args );
		} else {
			$args['body'] = $data;
			$response     = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code   = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( $http_code >= 400 ) {
			$msg = isset( $decoded['error']['message'] )
				? $decoded['error']['message']
				: sprintf( __( 'Stripe API error (HTTP %d).', 'wp-ai-image' ), $http_code );
			return new WP_Error( 'stripe_error', $msg );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	// =========================================================================
	// Webhook signature verification
	// =========================================================================

	/**
	 * Verify the Stripe-Signature header against STRIPE_WEBHOOK_SECRET.
	 *
	 * @param  string $payload    Raw request body.
	 * @param  string $sig_header Value of the Stripe-Signature header.
	 * @return bool
	 */
	private function verify_signature( $payload, $sig_header ) {
		$secret = defined( 'STRIPE_WEBHOOK_SECRET' ) ? STRIPE_WEBHOOK_SECRET : '';
		if ( empty( $secret ) || empty( $sig_header ) ) {
			return false;
		}

		// Parse "t=timestamp,v1=hash,v1=hash2"
		$parts = array();
		foreach ( explode( ',', $sig_header ) as $chunk ) {
			$kv = explode( '=', $chunk, 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}

		if ( ! isset( $parts['t'], $parts['v1'] ) ) {
			return false;
		}

		// Reconstruct signed payload: timestamp + "." + body
		$expected = hash_hmac( 'sha256', $parts['t'] . '.' . $payload, $secret );
		if ( ! hash_equals( $expected, $parts['v1'] ) ) {
			return false;
		}

		// Reject events older than 5 minutes (replay-attack protection)
		if ( abs( time() - (int) $parts['t'] ) > 300 ) {
			return false;
		}

		return true;
	}

	// =========================================================================
	// Plan ↔ Stripe price ID helpers
	// =========================================================================

	private function plan_to_price_id( $plan_key ) {
		$map = array(
			'starter' => 'PRICE_STARTER',
			'pro'     => 'PRICE_PRO',
			'agency'  => 'PRICE_AGENCY',
		);
		if ( ! isset( $map[ $plan_key ] ) ) {
			return '';
		}
		$const = $map[ $plan_key ];
		return defined( $const ) ? constant( $const ) : '';
	}

	private function price_id_to_plan( $price_id ) {
		$consts = array( 'PRICE_STARTER' => 'starter', 'PRICE_PRO' => 'pro', 'PRICE_AGENCY' => 'agency' );
		foreach ( $consts as $const => $plan ) {
			if ( defined( $const ) && constant( $const ) === $price_id ) {
				return $plan;
			}
		}
		return '';
	}
}
