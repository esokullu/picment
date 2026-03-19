<?php
/**
 * Plugin Name:       Picment AI Featured Image Generator
 * Plugin URI:        https://picment.xyz
 * Description:       Auto-generate stunning DALL-E 3 AI featured images for every WordPress post. Bulk generation, per-post control, BYOK mode, and subscription plans.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Barack Sokullu
 * Author URI:        https://emresokullu.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       picment-ai-featured-image-generator
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PICMENT_AI_IMAGE_VERSION', '1.1.1' );
define( 'PICMENT_AI_IMAGE_PLUGIN_FILE', __FILE__ );
define( 'PICMENT_AI_IMAGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PICMENT_AI_IMAGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PICMENT_AI_IMAGE_PLUGIN_DIR . 'includes/class-billing.php';

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, array( 'Picment_AI_Image', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Picment_AI_Image', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Picment_AI_Image', 'get_instance' ) );

// ---------------------------------------------------------------------------
// Main plugin class
// ---------------------------------------------------------------------------

class Picment_AI_Image {

	// Option / meta key constants -------------------------------------------

	const OPTION_API_KEY         = 'picment_ai_image_api_key';
	const OPTION_SERVER_BASE_URL = 'picment_ai_image_server_base_url';
	const OPTION_SITE_TOKEN      = 'picment_ai_image_site_token';
	const OPTION_IMAGE_SIZE      = 'picment_ai_image_image_size';
	const OPTION_IMAGE_QUALITY   = 'picment_ai_image_image_quality';
	const OPTION_IMAGE_STYLE     = 'picment_ai_image_image_style';
	const OPTION_IMAGE_LOOK      = 'picment_ai_image_image_look';
	const OPTION_IMAGE_LOOK_CUSTOM_PROMPT = 'picment_ai_image_image_look_custom_prompt';
	const OPTION_ALLOW_TEXT_LOGOS = 'picment_ai_image_allow_text_logos';
	const OPTION_AUTO_GENERATE   = 'picment_ai_image_auto_generate';
	const OPTION_OVERWRITE       = 'picment_ai_image_overwrite_existing';
	const OPTION_PROMPT_TEMPLATE = 'picment_ai_image_prompt_template';

	const META_STATUS       = '_picment_ai_image_status';
	const META_GENERATED_AT = '_picment_ai_image_generated_at';
	const META_ERROR        = '_picment_ai_image_error';
	const META_ENABLED      = '_picment_ai_image_enabled';

	/** @var Picment_AI_Image|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Initialise billing singleton (registers its own hooks)
		Picment_AI_Image_Billing::get_instance();

		// Admin UI
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		// Post metabox
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'save_post', array( $this, 'save_metabox' ) );

		// Auto-generate on publish
		add_action( 'transition_post_status', array( $this, 'on_publish' ), 10, 3 );

		// WP-Cron background generation
		add_action( 'picment_ai_image_generate_event', array( $this, 'cron_generate' ) );

		// AJAX handlers (admin only – logged-in users)
		add_action( 'wp_ajax_picment_ai_image_generate', array( $this, 'ajax_generate' ) );
	}

	// =========================================================================
	// Activation / Deactivation
	// =========================================================================

	public static function activate() {
		// Billing defaults (idempotent — only runs on first-ever activation)
		Picment_AI_Image_Billing::init_on_activation();

		if ( false === get_option( self::OPTION_SERVER_BASE_URL ) ) {
			add_option( self::OPTION_SERVER_BASE_URL, 'https://picment.xyz/api' );
		}

		// Set sensible defaults on first activation
		if ( false === get_option( self::OPTION_IMAGE_SIZE ) ) {
			add_option( self::OPTION_IMAGE_SIZE, '1792x1024' );
		}
		if ( false === get_option( self::OPTION_IMAGE_QUALITY ) ) {
			add_option( self::OPTION_IMAGE_QUALITY, 'hd' );
		}
		if ( false === get_option( self::OPTION_IMAGE_STYLE ) ) {
			add_option( self::OPTION_IMAGE_STYLE, 'natural' );
		}
		if ( false === get_option( self::OPTION_IMAGE_LOOK ) ) {
			add_option( self::OPTION_IMAGE_LOOK, 'illustration' );
		}
		if ( false === get_option( self::OPTION_IMAGE_LOOK_CUSTOM_PROMPT ) ) {
			add_option( self::OPTION_IMAGE_LOOK_CUSTOM_PROMPT, '' );
		}
		if ( false === get_option( self::OPTION_ALLOW_TEXT_LOGOS ) ) {
			add_option( self::OPTION_ALLOW_TEXT_LOGOS, 0 );
		}
		if ( false === get_option( self::OPTION_AUTO_GENERATE ) ) {
			add_option( self::OPTION_AUTO_GENERATE, 1 );
		}
		if ( false === get_option( self::OPTION_OVERWRITE ) ) {
			add_option( self::OPTION_OVERWRITE, 0 );
		}
	}

	public static function deactivate() {
		// Clear any pending cron events
		$timestamp = wp_next_scheduled( 'picment_ai_image_generate_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'picment_ai_image_generate_event' );
		}
	}

	// =========================================================================
	// Admin Menu
	// =========================================================================

	public function add_admin_menu() {
		add_menu_page(
			__( 'Picment AI Featured Image Generator', 'picment-ai-featured-image-generator' ),
			__( 'Picment', 'picment-ai-featured-image-generator' ),
			'manage_options',
			'picment-ai-image',
			array( $this, 'render_bulk_page' ),
			'dashicons-format-image',
			100
		);
		add_submenu_page(
			'picment-ai-image',
			__( 'Generate Images', 'picment-ai-featured-image-generator' ),
			__( 'Generate Images', 'picment-ai-featured-image-generator' ),
			'manage_options',
			'picment-ai-image',
			array( $this, 'render_bulk_page' )
		);
		add_submenu_page(
			'picment-ai-image',
			__( 'Settings', 'picment-ai-featured-image-generator' ),
			__( 'Settings', 'picment-ai-featured-image-generator' ),
			'manage_options',
			'picment-ai-image-settings',
			array( $this, 'render_settings_page' )
		);
		add_submenu_page(
			'picment-ai-image',
			__( 'Billing', 'picment-ai-featured-image-generator' ),
			__( 'Billing', 'picment-ai-featured-image-generator' ),
			'manage_options',
			'picment-ai-image-billing',
			array( Picment_AI_Image_Billing::get_instance(), 'render_billing_page' )
		);
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public function register_settings() {
		$settings = array(
			self::OPTION_API_KEY         => 'sanitize_text_field',
			self::OPTION_IMAGE_SIZE      => array( $this, 'sanitize_image_size' ),
			self::OPTION_IMAGE_QUALITY   => array( $this, 'sanitize_image_quality' ),
			self::OPTION_IMAGE_STYLE     => array( $this, 'sanitize_image_style' ),
			self::OPTION_IMAGE_LOOK      => array( $this, 'sanitize_image_look' ),
			self::OPTION_IMAGE_LOOK_CUSTOM_PROMPT => 'sanitize_textarea_field',
			self::OPTION_ALLOW_TEXT_LOGOS => 'absint',
			self::OPTION_AUTO_GENERATE   => 'absint',
			self::OPTION_OVERWRITE       => 'absint',
			self::OPTION_PROMPT_TEMPLATE => 'sanitize_textarea_field',
		);
		foreach ( $settings as $key => $callback ) {
			register_setting( 'picment_ai_image_settings', $key, array( 'sanitize_callback' => $callback ) );
		}

		// --- API section ---
		// add_settings_section( 'picment_ai_image_api', __( 'API Configuration', 'picment-ai-featured-image-generator' ), '__return_false', 'picment-ai-image-settings' );
		//add_settings_field( self::OPTION_API_KEY, __( 'OpenAI API Key', 'picment-ai-featured-image-generator' ), array( $this, 'field_api_key' ), 'picment-ai-image-settings', 'picment_ai_image_api' );

		// --- Image section ---
		add_settings_section( 'picment_ai_image_image', __( 'Image Settings', 'picment-ai-featured-image-generator' ), '__return_false', 'picment-ai-image-settings' );
		add_settings_field( self::OPTION_IMAGE_SIZE,      __( 'Image Size', 'picment-ai-featured-image-generator' ),              array( $this, 'field_image_size' ),      'picment-ai-image-settings', 'picment_ai_image_image' );
		add_settings_field( self::OPTION_IMAGE_QUALITY,   __( 'Image Quality', 'picment-ai-featured-image-generator' ),           array( $this, 'field_image_quality' ),   'picment-ai-image-settings', 'picment_ai_image_image' );
		add_settings_field( self::OPTION_IMAGE_STYLE,     __( 'Image Style', 'picment-ai-featured-image-generator' ),             array( $this, 'field_image_style' ),     'picment-ai-image-settings', 'picment_ai_image_image' );
		add_settings_field( self::OPTION_IMAGE_LOOK,      __( 'Image Look', 'picment-ai-featured-image-generator' ),              array( $this, 'field_image_look' ),      'picment-ai-image-settings', 'picment_ai_image_image' );
		add_settings_field( self::OPTION_IMAGE_LOOK_CUSTOM_PROMPT, __( 'Custom Look Instructions', 'picment-ai-featured-image-generator' ), array( $this, 'field_image_look_custom_prompt' ), 'picment-ai-image-settings', 'picment_ai_image_image' );
		add_settings_field( self::OPTION_ALLOW_TEXT_LOGOS, __( 'Allow Text/Logos', 'picment-ai-featured-image-generator' ),       array( $this, 'field_allow_text_logos' ), 'picment-ai-image-settings', 'picment_ai_image_image' );
		add_settings_field( self::OPTION_PROMPT_TEMPLATE, __( 'Custom Prompt Template', 'picment-ai-featured-image-generator' ),  array( $this, 'field_prompt_template' ), 'picment-ai-image-settings', 'picment_ai_image_image' );

		// --- Automation section ---
		add_settings_section( 'picment_ai_image_auto', __( 'Automation', 'picment-ai-featured-image-generator' ), '__return_false', 'picment-ai-image-settings' );
		add_settings_field( self::OPTION_AUTO_GENERATE, __( 'Auto-generate on Publish', 'picment-ai-featured-image-generator' ),   array( $this, 'field_auto_generate' ), 'picment-ai-image-settings', 'picment_ai_image_auto' );
		add_settings_field( self::OPTION_OVERWRITE,     __( 'Overwrite Existing Images', 'picment-ai-featured-image-generator' ),  array( $this, 'field_overwrite' ),      'picment-ai-image-settings', 'picment_ai_image_auto' );
	}

	// Sanitizers ---------------------------------------------------------------

	public function sanitize_image_size( $v ) {
		return in_array( $v, array( '1024x1024', '1792x1024', '1024x1792' ), true ) ? $v : '1792x1024';
	}
	public function sanitize_image_quality( $v ) {
		return in_array( $v, array( 'standard', 'hd' ), true ) ? $v : 'hd';
	}
	public function sanitize_image_style( $v ) {
		return in_array( $v, array( 'vivid', 'natural' ), true ) ? $v : 'vivid';
	}
	public function sanitize_image_look( $v ) {
		$allowed = array_keys( $this->get_image_look_options() );
		return in_array( $v, $allowed, true ) ? $v : 'illustration';
	}

	// Field renderers ----------------------------------------------------------

	public function field_api_key() {
		$val = get_option( self::OPTION_API_KEY, '' );
		?>
		<input type="password"
		       name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>"
		       value="<?php echo esc_attr( $val ); ?>"
		       class="regular-text"
		       autocomplete="new-password" />
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to OpenAI API keys page */
				wp_kses_post( __( 'Get your API key at <a href="%s" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>.', 'picment-ai-featured-image-generator' ) ),
				esc_url( 'https://platform.openai.com/api-keys' )
			);
			?>
		</p>
		<?php
	}

	public function field_image_size() {
		$val = get_option( self::OPTION_IMAGE_SIZE, '1792x1024' );
		$opts = array(
			'1792x1024' => __( '1792 × 1024 — Landscape (recommended for blog posts)', 'picment-ai-featured-image-generator' ),
			'1024x1024' => __( '1024 × 1024 — Square', 'picment-ai-featured-image-generator' ),
			'1024x1792' => __( '1024 × 1792 — Portrait', 'picment-ai-featured-image-generator' ),
		);
		echo '<select name="' . esc_attr( self::OPTION_IMAGE_SIZE ) . '">';
		foreach ( $opts as $k => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function field_image_quality() {
		$val = get_option( self::OPTION_IMAGE_QUALITY, 'hd' );
		?>
		<label><input type="radio" name="<?php echo esc_attr( self::OPTION_IMAGE_QUALITY ); ?>" value="hd" <?php checked( $val, 'hd' ); ?> />
			<?php esc_html_e( 'HD — higher detail, higher cost', 'picment-ai-featured-image-generator' ); ?></label><br>
		<label><input type="radio" name="<?php echo esc_attr( self::OPTION_IMAGE_QUALITY ); ?>" value="standard" <?php checked( $val, 'standard' ); ?> />
			<?php esc_html_e( 'Standard', 'picment-ai-featured-image-generator' ); ?></label>
		<?php
	}

	public function field_image_style() {
		$val = get_option( self::OPTION_IMAGE_STYLE, 'natural' );
		?>
		<label><input type="radio" name="<?php echo esc_attr( self::OPTION_IMAGE_STYLE ); ?>" value="vivid" <?php checked( $val, 'vivid' ); ?> />
			<?php esc_html_e( 'Vivid — hyper-real and dramatic', 'picment-ai-featured-image-generator' ); ?></label><br>
		<label><input type="radio" name="<?php echo esc_attr( self::OPTION_IMAGE_STYLE ); ?>" value="natural" <?php checked( $val, 'natural' ); ?> />
			<?php esc_html_e( 'Natural — more realistic tones', 'picment-ai-featured-image-generator' ); ?></label>
		<?php
	}

	public function field_image_look() {
		$val = get_option( self::OPTION_IMAGE_LOOK, 'illustration' );
		$options = $this->get_image_look_options();
		?>
		<?php foreach ( $options as $key => $label ) : ?>
		<label>
			<input type="radio" name="<?php echo esc_attr( self::OPTION_IMAGE_LOOK ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $val, $key ); ?> />
			<?php echo esc_html( $label ); ?>
		</label><br>
		<?php endforeach; ?>
		<?php
	}

	private function get_image_look_options() {
		return array(
			'illustration'  => __( 'Illustration (default)', 'picment-ai-featured-image-generator' ),
			'photorealistic' => __( 'Photorealistic', 'picment-ai-featured-image-generator' ),
			'anime'         => __( 'Anime / Manga', 'picment-ai-featured-image-generator' ),
			'cinematic'     => __( 'Cinematic', 'picment-ai-featured-image-generator' ),
			'watercolor'    => __( 'Watercolor painting', 'picment-ai-featured-image-generator' ),
			'three_d'       => __( '3D render', 'picment-ai-featured-image-generator' ),
			'custom'        => __( 'Custom (use instructions below)', 'picment-ai-featured-image-generator' ),
		);
	}

	public function field_image_look_custom_prompt() {
		$val = get_option( self::OPTION_IMAGE_LOOK_CUSTOM_PROMPT, '' );
		?>
		<textarea name="<?php echo esc_attr( self::OPTION_IMAGE_LOOK_CUSTOM_PROMPT ); ?>"
			  rows="3"
			  class="large-text"
			  placeholder="<?php echo esc_attr__( 'Example: semi-realistic editorial illustration, pastel palette, subtle grain', 'picment-ai-featured-image-generator' ); ?>"><?php echo esc_textarea( $val ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Used only when Image Look is set to "Custom". This lets you define your own style direction.', 'picment-ai-featured-image-generator' ); ?>
		</p>
		<?php
	}

	public function field_allow_text_logos() {
		$val = get_option( self::OPTION_ALLOW_TEXT_LOGOS, 0 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_ALLOW_TEXT_LOGOS ); ?>" value="1" <?php checked( $val, 1 ); ?> />
			<?php esc_html_e( 'Allow images to contain text and logo-like marks', 'picment-ai-featured-image-generator' ); ?>
		</label>
		<?php
	}

	public function field_prompt_template() {
		$val = get_option( self::OPTION_PROMPT_TEMPLATE, '' );
		?>
		<textarea name="<?php echo esc_attr( self::OPTION_PROMPT_TEMPLATE ); ?>"
		          rows="4" class="large-text"><?php echo esc_textarea( $val ); ?></textarea>
		<p class="description">
			<?php echo wp_kses( __( 'Use <code>{title}</code> and <code>{content}</code> as placeholders. Leave blank to use the built-in prompt.', 'picment-ai-featured-image-generator' ), array( 'code' => array() ) ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: the default prompt text */
				wp_kses_post( __( '<strong>Default:</strong> <em>%s</em>', 'picment-ai-featured-image-generator' ) ),
				esc_html( $this->default_prompt( __( '(post title)', 'picment-ai-featured-image-generator' ), __( '(post content)', 'picment-ai-featured-image-generator' ) ) )
			);
			?>
		</p>
		<?php
	}

	public function field_auto_generate() {
		$val = get_option( self::OPTION_AUTO_GENERATE, 1 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_AUTO_GENERATE ); ?>" value="1" <?php checked( $val, 1 ); ?> />
			<?php esc_html_e( 'Automatically generate a featured image when a post is published (if none exists)', 'picment-ai-featured-image-generator' ); ?>
		</label>
		<?php
	}

	public function field_overwrite() {
		$val = get_option( self::OPTION_OVERWRITE, 0 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_OVERWRITE ); ?>" value="1" <?php checked( $val, 1 ); ?> />
			<?php esc_html_e( 'Overwrite existing featured images when regenerating via the admin page', 'picment-ai-featured-image-generator' ); ?>
		</label>
		<?php
	}

	// =========================================================================
	// Settings Page
	// =========================================================================

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Picment AI Featured Image Generator — Settings', 'picment-ai-featured-image-generator' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'picment_ai_image_settings' );
				do_settings_sections( 'picment-ai-image-settings' );
				submit_button( __( 'Save Settings', 'picment-ai-featured-image-generator' ) );
				?>
			</form>
		</div>
		<?php
	}

	// =========================================================================
	// Bulk Generate Admin Page
	// =========================================================================

	public function render_bulk_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$billing = Picment_AI_Image_Billing::get_instance();
		if ( ! $billing->is_configured() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Picment AI Featured Image Generator', 'picment-ai-featured-image-generator' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses(
				sprintf(
					/* translators: %s: billing page URL */
					__( 'Image generation is not available. <a href="%s">Configure billing →</a>', 'picment-ai-featured-image-generator' ),
					esc_url( admin_url( 'admin.php?page=picment-ai-image-billing' ) )
				),
				array( 'a' => array( 'href' => array() ) )
			);
			echo '</p></div></div>';
			return;
		}

		$posts = get_posts( array(
			'numberposts' => -1,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'orderby'     => 'date',
			'order'       => 'DESC',
		) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Picment AI Featured Image Generator — Generate', 'picment-ai-featured-image-generator' ); ?></h1>

			<div class="picment-ai-image-toolbar" style="margin:1em 0;display:flex;align-items:center;flex-wrap:wrap;gap:8px;">
				<button type="button" id="picment-ai-image-select-all" class="button">
					<?php esc_html_e( 'Select All', 'picment-ai-featured-image-generator' ); ?>
				</button>
				<button type="button" id="picment-ai-image-select-none" class="button">
					<?php esc_html_e( 'Deselect All', 'picment-ai-featured-image-generator' ); ?>
				</button>
				<button type="button" id="picment-ai-image-select-missing" class="button">
					<?php esc_html_e( 'Select Posts Without Image', 'picment-ai-featured-image-generator' ); ?>
				</button>
				<button type="button" id="picment-ai-image-generate-selected" class="button button-primary" style="margin-left:8px;">
					<?php esc_html_e( 'Generate for Selected', 'picment-ai-featured-image-generator' ); ?>
				</button>
				<span id="picment-ai-image-progress-text" style="color:#555;"></span>
			</div>

			<div id="picment-ai-image-progress-bar-wrap" style="display:none;background:#f0f0f0;border-radius:4px;margin-bottom:1em;height:8px;width:100%;max-width:600px;">
				<div id="picment-ai-image-progress-fill" style="background:#0073aa;height:100%;border-radius:4px;width:0%;transition:width 0.4s;"></div>
			</div>

			<table class="wp-list-table widefat fixed striped" id="picment-ai-image-posts-table">
				<thead>
					<tr>
						<td class="manage-column check-column" style="width:40px;">
							<input type="checkbox" id="picment-ai-image-check-all" />
						</td>
						<th class="manage-column"><?php esc_html_e( 'Post Title', 'picment-ai-featured-image-generator' ); ?></th>
						<th class="manage-column" style="width:120px;"><?php esc_html_e( 'Published', 'picment-ai-featured-image-generator' ); ?></th>
						<th class="manage-column" style="width:100px;"><?php esc_html_e( 'Thumbnail', 'picment-ai-featured-image-generator' ); ?></th>
						<th class="manage-column" style="width:180px;"><?php esc_html_e( 'AI Status', 'picment-ai-featured-image-generator' ); ?></th>
						<th class="manage-column" style="width:130px;"><?php esc_html_e( 'Action', 'picment-ai-featured-image-generator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $posts ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No published posts found.', 'picment-ai-featured-image-generator' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $posts as $p ) :
							$has_thumb    = has_post_thumbnail( $p->ID );
							$status       = get_post_meta( $p->ID, self::META_STATUS, true );
							$error        = get_post_meta( $p->ID, self::META_ERROR, true );
							$generated_at = get_post_meta( $p->ID, self::META_GENERATED_AT, true );
						?>
						<tr id="picment-ai-image-row-<?php echo esc_attr( $p->ID ); ?>"
						    data-post-id="<?php echo esc_attr( $p->ID ); ?>"
						    data-has-thumbnail="<?php echo $has_thumb ? '1' : '0'; ?>">
							<th scope="row" class="check-column">
								<input type="checkbox" class="picment-ai-image-post-checkbox" value="<?php echo esc_attr( $p->ID ); ?>" />
							</th>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>" target="_blank">
									<?php echo esc_html( $p->post_title ); ?>
								</a>
							</td>
							<td><?php echo esc_html( get_the_date( 'Y-m-d', $p->ID ) ); ?></td>
							<td class="picment-ai-image-thumbnail-cell">
								<?php if ( $has_thumb ) : ?>
									<?php echo get_the_post_thumbnail( $p->ID, array( 80, 45 ) ); ?>
								<?php else : ?>
									<span style="color:#aaa;">—</span>
								<?php endif; ?>
							</td>
							<td id="picment-ai-image-status-<?php echo esc_attr( $p->ID ); ?>">
								<?php echo $this->status_badge( $status, $error, $generated_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</td>
							<td>
								<button type="button"
								        class="button picment-ai-image-generate-single"
								        data-post-id="<?php echo esc_attr( $p->ID ); ?>">
									<?php echo $has_thumb ? esc_html__( 'Regenerate', 'picment-ai-featured-image-generator' ) : esc_html__( 'Generate', 'picment-ai-featured-image-generator' ); ?>
								</button>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Returns an escaped HTML status badge. */
	private function status_badge( $status, $error = '', $generated_at = '' ) {
		switch ( $status ) {
			case 'done':
				$date = $generated_at ? ' <small>(' . esc_html( date_i18n( 'Y-m-d', $generated_at ) ) . ')</small>' : '';
				return '<span style="color:#46b450;">&#10003; ' . esc_html__( 'Generated', 'picment-ai-featured-image-generator' ) . $date . '</span>';
			case 'pending':
				return '<span style="color:#f90;">&#9679; ' . esc_html__( 'Pending&hellip;', 'picment-ai-featured-image-generator' ) . '</span>';
			case 'generating':
				return '<span style="color:#0073aa;">&#9679; ' . esc_html__( 'Generating&hellip;', 'picment-ai-featured-image-generator' ) . '</span>';
			case 'failed':
				$tip = $error ? ' title="' . esc_attr( $error ) . '"' : '';
				return '<span style="color:#dc3232;"' . $tip . '>&#10007; ' . esc_html__( 'Failed', 'picment-ai-featured-image-generator' ) . ( $error ? ' <small>(hover)</small>' : '' ) . '</span>';
			default:
				return '<span style="color:#aaa;">—</span>';
		}
	}

	// =========================================================================
	// Admin Scripts & Notices
	// =========================================================================

	public function enqueue_admin_scripts( $hook ) {
		$screen = get_current_screen();
		$on_plugin_page = ( strpos( $hook, 'picment-ai-image' ) !== false );
		$on_post_editor = ( $screen && $screen->post_type === 'post' && in_array( $screen->base, array( 'post', 'edit' ), true ) );

		if ( ! $on_plugin_page && ! $on_post_editor ) {
			return;
		}

		wp_enqueue_script(
			'picment-ai-image-admin',
			PICMENT_AI_IMAGE_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			PICMENT_AI_IMAGE_VERSION,
			true
		);

		// Billing page JS
		if ( strpos( $hook, 'picment-ai-image-billing' ) !== false ) {
			wp_enqueue_script(
				'picment-ai-image-billing',
				PICMENT_AI_IMAGE_PLUGIN_URL . 'assets/js/billing.js',
				array( 'jquery' ),
				PICMENT_AI_IMAGE_VERSION,
				true
			);
			wp_localize_script(
				'picment-ai-image-billing',
				'picmentAiImageBilling',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'picment_ai_image_billing' ),
				)
			);
		}

		wp_localize_script(
			'picment-ai-image-admin',
			'picmentAiImage',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'picment_ai_image_generate' ),
				'i18n'     => array(
					'generating'       => __( 'Generating&hellip;', 'picment-ai-featured-image-generator' ),
					'done'             => __( 'Done', 'picment-ai-featured-image-generator' ),
					'failed'           => __( 'Failed', 'picment-ai-featured-image-generator' ),
					'generate'         => __( 'Generate', 'picment-ai-featured-image-generator' ),
					'regenerate'       => __( 'Regenerate', 'picment-ai-featured-image-generator' ),
					'confirm_overwrite'=> __( 'This post already has a featured image. Overwrite it?', 'picment-ai-featured-image-generator' ),
					'select_one'       => __( 'Please select at least one post.', 'picment-ai-featured-image-generator' ),
					/* translators: 1: current count, 2: total count */
					'progress'         => __( 'Processing %1$d of %2$d&hellip;', 'picment-ai-featured-image-generator' ),
					/* translators: %d: number of images generated */
					'complete'         => __( 'Done! %d image(s) generated.', 'picment-ai-featured-image-generator' ),
					'refresh_notice'   => __( 'Image generated — refresh to see the thumbnail.', 'picment-ai-featured-image-generator' ),
				),
			)
		);
	}

	public function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'picment-ai-image' ) === false ) {
			return;
		}

		$billing     = Picment_AI_Image_Billing::get_instance();
		$mode        = $billing->get_mode();
		$billing_url = esc_url( admin_url( 'admin.php?page=picment-ai-image-billing' ) );
		$msg         = '';

		if ( 'byok' === $mode && empty( get_option( self::OPTION_API_KEY, '' ) ) ) {
			$msg = sprintf(
				/* translators: %s: billing page URL */
				__( 'BYOK mode is active but no API key is configured. <a href="%s">Add your key →</a>', 'picment-ai-featured-image-generator' ),
				$billing_url
			);
		} elseif ( 'trial' === $mode && (int) get_option( Picment_AI_Image_Billing::OPT_TRIAL_CREDITS, 0 ) <= 0 ) {
			$msg = sprintf(
				/* translators: %s: billing page URL */
				__( 'Your free trial image has been used. <a href="%s">Subscribe or enter your own API key →</a>', 'picment-ai-featured-image-generator' ),
				$billing_url
			);
		} elseif ( 'paid' === $mode && (int) get_option( Picment_AI_Image_Billing::OPT_CREDITS, 0 ) <= 0 ) {
			$msg = sprintf(
				/* translators: %s: billing page URL */
				__( 'No image credits remaining this month. <a href="%s">Upgrade your plan →</a>', 'picment-ai-featured-image-generator' ),
				$billing_url
			);
		}

		if ( $msg ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Picment AI Featured Image Generator:', 'picment-ai-featured-image-generator' ),
				wp_kses( $msg, array( 'a' => array( 'href' => array() ) ) )
			);
		}
	}

	// =========================================================================
	// Post Metabox
	// =========================================================================

	public function add_metabox() {
		add_meta_box(
			'picment_ai_image_metabox',
			__( 'AI Featured Image', 'picment-ai-featured-image-generator' ),
			array( $this, 'render_metabox' ),
			'post',
			'side',
			'high'
		);
	}

	public function render_metabox( $post ) {
		wp_nonce_field( 'picment_ai_image_metabox', 'picment_ai_image_metabox_nonce' );

		$enabled      = get_post_meta( $post->ID, self::META_ENABLED, true );
		$enabled      = ( '' === $enabled ) ? '1' : $enabled; // default on
		$status       = get_post_meta( $post->ID, self::META_STATUS, true );
		$error        = get_post_meta( $post->ID, self::META_ERROR, true );
		$generated_at = get_post_meta( $post->ID, self::META_GENERATED_AT, true );
		$has_thumb    = has_post_thumbnail( $post->ID );
		?>
		<p>
			<label>
				<input type="checkbox" name="picment_ai_image_enabled" value="1" <?php checked( $enabled, '1' ); ?> />
				<?php esc_html_e( 'Auto-generate on publish', 'picment-ai-featured-image-generator' ); ?>
			</label>
		</p>
		<?php if ( $status ) : ?>
		<p>
			<strong><?php esc_html_e( 'Status:', 'picment-ai-featured-image-generator' ); ?></strong>
			<?php echo $this->status_badge( $status, $error, $generated_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</p>
		<?php endif; ?>
		<?php if ( $post->ID && 'publish' === $post->post_status ) : ?>
		<p>
			<button type="button"
			        class="button button-secondary"
			        id="picment-ai-image-metabox-generate"
			        data-post-id="<?php echo esc_attr( $post->ID ); ?>"
			        data-has-thumbnail="<?php echo $has_thumb ? '1' : '0'; ?>"
			        style="width:100%;">
				<?php echo $has_thumb ? esc_html__( 'Regenerate Image', 'picment-ai-featured-image-generator' ) : esc_html__( 'Generate Image Now', 'picment-ai-featured-image-generator' ); ?>
			</button>
			<span id="picment-ai-image-metabox-status" style="display:block;margin-top:6px;font-size:12px;"></span>
		</p>
		<?php endif; ?>
		<p class="description" style="margin-top:4px;">
			<?php
			printf(
				/* translators: %s: settings page URL */
				wp_kses_post( __( 'Powered by DALL-E 3. <a href="%s" target="_blank">Settings</a>', 'picment-ai-featured-image-generator' ) ),
				esc_url( admin_url( 'admin.php?page=picment-ai-image-settings' ) )
			);
			?>
		</p>
		<?php
	}

	public function save_metabox( $post_id ) {
		if ( ! isset( $_POST['picment_ai_image_metabox_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['picment_ai_image_metabox_nonce'] ), 'picment_ai_image_metabox' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_ENABLED, isset( $_POST['picment_ai_image_enabled'] ) ? '1' : '0' );
	}

	// =========================================================================
	// Auto-generate on Publish
	// =========================================================================

	public function on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}
		if ( ! get_option( self::OPTION_AUTO_GENERATE, 1 ) ) {
			return;
		}

		// Per-post opt-out
		$enabled = get_post_meta( $post->ID, self::META_ENABLED, true );
		if ( '0' === $enabled ) {
			return;
		}

		// Skip if already has a featured image and overwrite is off
		if ( has_post_thumbnail( $post->ID ) && ! get_option( self::OPTION_OVERWRITE, 0 ) ) {
			return;
		}

		$this->schedule_generation( $post->ID );
	}

	private function schedule_generation( $post_id ) {
		if ( ! wp_next_scheduled( 'picment_ai_image_generate_event', array( $post_id ) ) ) {
			update_post_meta( $post_id, self::META_STATUS, 'pending' );
			delete_post_meta( $post_id, self::META_ERROR );
			wp_schedule_single_event( time(), 'picment_ai_image_generate_event', array( $post_id ) );
			spawn_cron(); // trigger immediately instead of waiting for next page load
		}
	}

	// =========================================================================
	// Image Generation (shared by cron + AJAX)
	// =========================================================================

	/** Called by WP-Cron. */
	public function cron_generate( $post_id ) {
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}
		$this->run_generation( $post_id );
	}

	/** Core generation routine. Returns true on success, WP_Error on failure. */
	public function run_generation( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'no_post', __( 'Post not found.', 'picment-ai-featured-image-generator' ) );
		}

		// ── Billing / entitlement check ──────────────────────────────────────
		$billing = Picment_AI_Image_Billing::get_instance();
		$ent     = $billing->check_entitlement();
		if ( ! $ent['ok'] ) {
			$msg = wp_strip_all_tags( $billing->entitlement_message( $ent['reason'] ) );
			update_post_meta( $post_id, self::META_STATUS, 'failed' );
			update_post_meta( $post_id, self::META_ERROR, $msg );
			return new WP_Error( 'billing_' . $ent['reason'], $msg );
		}

		update_post_meta( $post_id, self::META_STATUS, 'generating' );
		delete_post_meta( $post_id, self::META_ERROR );

		if ( 'byok' === $ent['mode'] ) {
			$img_url = $this->call_openai( $post->post_title, $post->post_content );
		} else {
			$img_url = $this->call_server_generate( $post->post_title, $post->post_content );
		}
		if ( is_wp_error( $img_url ) ) {
			update_post_meta( $post_id, self::META_STATUS, 'failed' );
			update_post_meta( $post_id, self::META_ERROR, $img_url->get_error_message() );
			return $img_url;
		}

		$media_id = $this->sideload_image( $img_url, $post_id, $post->post_title );
		if ( is_wp_error( $media_id ) ) {
			update_post_meta( $post_id, self::META_STATUS, 'failed' );
			update_post_meta( $post_id, self::META_ERROR, $media_id->get_error_message() );
			return $media_id;
		}

		set_post_thumbnail( $post_id, $media_id );
		update_post_meta( $post_id, self::META_STATUS, 'done' );
		update_post_meta( $post_id, self::META_GENERATED_AT, time() );
		delete_post_meta( $post_id, self::META_ERROR );

		// ── Consume one credit (trial / paid only; BYOK is unlimited) ────────
		if ( in_array( $ent['mode'], array( 'trial', 'paid' ), true ) ) {
			$billing->consume_credit( $ent['mode'] );
		}

		return true;
	}

	// =========================================================================
	// OpenAI API
	// =========================================================================

	/**
	 * @param string      $post_title
	 * @param string      $post_content
	 * @param string|null $api_key  Injected by billing; falls back to BYOK option when null.
	 */
	private function call_openai( $post_title, $post_content, $api_key = null ) {
		if ( null === $api_key ) {
			$api_key = get_option( self::OPTION_API_KEY, '' );
		}
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API key is not configured. Go to AI Image Gen → Billing.', 'picment-ai-featured-image-generator' ) );
		}

		$size    = get_option( self::OPTION_IMAGE_SIZE, '1792x1024' );
		$quality = get_option( self::OPTION_IMAGE_QUALITY, 'hd' );
		$style   = get_option( self::OPTION_IMAGE_STYLE, 'natural' );
		$prompt  = $this->build_prompt( $post_title, $post_content );

		$payload = wp_json_encode( array(
			'model'   => 'dall-e-3',
			'prompt'  => $prompt,
			'n'       => 1,
			'size'    => $size,
			'quality' => $quality,
			'style'   => $style,
		) );

		$response = wp_remote_post(
			'https://api.openai.com/v1/images/generations',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => $payload,
				'timeout' => 90,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body, true );

		if ( 200 !== $http_code ) {
			$api_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
			$message = $api_msg
				/* translators: 1: HTTP code, 2: API error message */
				? sprintf( __( 'OpenAI API error (HTTP %1$d): %2$s', 'picment-ai-featured-image-generator' ), $http_code, $api_msg )
				/* translators: %d: HTTP status code */
				: sprintf( __( 'OpenAI API returned HTTP %d.', 'picment-ai-featured-image-generator' ), $http_code );
			return new WP_Error( 'openai_http_error', $message );
		}

		if ( empty( $data['data'][0]['url'] ) ) {
			return new WP_Error( 'no_image_url', __( 'OpenAI returned no image URL.', 'picment-ai-featured-image-generator' ) );
		}

		return $data['data'][0]['url'];
	}

	private function call_server_generate( $post_title, $post_content ) {
		$base_url = untrailingslashit( (string) get_option( self::OPTION_SERVER_BASE_URL, 'https://picment.xyz/api' ) );
		$token    = $this->ensure_site_token( $base_url );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$size    = get_option( self::OPTION_IMAGE_SIZE, '1792x1024' );
		$quality = get_option( self::OPTION_IMAGE_QUALITY, 'hd' );
		$style   = get_option( self::OPTION_IMAGE_STYLE, 'natural' );
		$look    = get_option( self::OPTION_IMAGE_LOOK, 'illustration' );
		$custom_look_prompt = get_option( self::OPTION_IMAGE_LOOK_CUSTOM_PROMPT, '' );
		$allow   = (int) get_option( self::OPTION_ALLOW_TEXT_LOGOS, 0 );
		$prompt  = $this->build_prompt( $post_title, $post_content );

		$payload = array(
			'install_id' => (string) get_option( Picment_AI_Image_Billing::OPT_INSTALL_ID, '' ),
			'prompt'     => $prompt,
			'size'       => $size,
			'quality'    => $quality,
			'style'      => $style,
			'look'       => $look,
			'custom_look_prompt' => $custom_look_prompt,
			'allow_text_logos' => $allow,
		);

		$response = wp_remote_post(
			$base_url . '/v1/images/generate',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 120,
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
			$message = $api_msg ? $api_msg : sprintf( __( 'Server returned HTTP %d.', 'picment-ai-featured-image-generator' ), $http_code );
			return new WP_Error( 'server_http_error', $message );
		}

		if ( empty( $data['success'] ) ) {
			$api_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
			$message = $api_msg ? $api_msg : __( 'Server request failed.', 'picment-ai-featured-image-generator' );
			return new WP_Error( 'server_failed', $message );
		}

		$image_url = '';
		if ( isset( $data['data']['image_url'] ) ) {
			$image_url = (string) $data['data']['image_url'];
		} elseif ( isset( $data['data']['url'] ) ) {
			$image_url = (string) $data['data']['url'];
		}

		if ( empty( $image_url ) ) {
			return new WP_Error( 'server_no_image_url', __( 'Server returned no image URL.', 'picment-ai-featured-image-generator' ) );
		}

		return $image_url;
	}

	private function ensure_site_token( $base_url ) {
		$token = (string) get_option( self::OPTION_SITE_TOKEN, '' );
		if ( ! empty( $token ) ) {
			return $token;
		}

		$install_id = (string) get_option( Picment_AI_Image_Billing::OPT_INSTALL_ID, '' );
		if ( empty( $install_id ) ) {
			return new WP_Error( 'missing_install_id', __( 'Install ID is missing.', 'picment-ai-featured-image-generator' ) );
		}

		$response = wp_remote_post(
			$base_url . '/v1/sites/register',
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
			$message = $api_msg ? $api_msg : sprintf( __( 'Server returned HTTP %d.', 'picment-ai-featured-image-generator' ), $http_code );
			return new WP_Error( 'server_register_http_error', $message );
		}

		if ( empty( $data['success'] ) || empty( $data['data']['site_token'] ) ) {
			$api_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
			$message = $api_msg ? $api_msg : __( 'Could not register site with server.', 'picment-ai-featured-image-generator' );
			return new WP_Error( 'server_register_failed', $message );
		}

		if ( ! empty( $data['data']['install_id'] ) ) {
			$canonical = sanitize_text_field( (string) $data['data']['install_id'] );
			if ( $canonical !== '' && $canonical !== $install_id ) {
				update_option( Picment_AI_Image_Billing::OPT_INSTALL_ID, $canonical );
				$install_id = $canonical;
			}
		}

		$token = sanitize_text_field( (string) $data['data']['site_token'] );
		update_option( self::OPTION_SITE_TOKEN, $token );
		return $token;
	}

	private function build_prompt( $post_title, $post_content ) {
		$template = get_option( self::OPTION_PROMPT_TEMPLATE, '' );
		$content  = wp_strip_all_tags( $post_content );
		$content  = wp_trim_words( $content, 500, '' ); // ~3000 chars, stays within DALL-E limit

		if ( ! empty( $template ) ) {
			$prompt = str_replace(
				array( '{title}', '{content}' ),
				array( $post_title, $content ),
				$template
			);
		} else {
			$prompt = $this->default_prompt( $post_title, $content );
		}

		$prompt = $this->apply_prompt_options( $prompt );

		if ( function_exists( 'mb_substr' ) ) {
			$prompt = (string) mb_substr( $prompt, 0, 4000 );
		} else {
			$prompt = (string) substr( (string) $prompt, 0, 4000 );
		}

		return $prompt;
	}

	private function apply_prompt_options( $prompt ) {
		$look  = get_option( self::OPTION_IMAGE_LOOK, 'illustration' );
		$custom_look_prompt = trim( (string) get_option( self::OPTION_IMAGE_LOOK_CUSTOM_PROMPT, '' ) );
		$look_options = array_keys( $this->get_image_look_options() );
		$look  = in_array( $look, $look_options, true ) ? $look : 'illustration';
		$allow = (int) get_option( self::OPTION_ALLOW_TEXT_LOGOS, 0 );
		$addon = '';

		switch ( $look ) {
			case 'photorealistic':
				$addon .= ' Use a photorealistic editorial photography style with realistic lighting and a clean minimal background.';
				break;
			case 'anime':
				$addon .= ' Use a polished anime and manga-inspired style, expressive linework, vibrant color grading, and dynamic composition.';
				break;
			case 'cinematic':
				$addon .= ' Use a cinematic visual style with dramatic lighting, rich depth, and a film-like color palette.';
				break;
			case 'watercolor':
				$addon .= ' Use a hand-painted watercolor style with organic textures, soft brush blending, and subtle paper grain.';
				break;
			case 'three_d':
				$addon .= ' Use a high-quality 3D render style with realistic materials, global illumination, and depth.';
				break;
			case 'custom':
				if ( '' !== $custom_look_prompt ) {
					$addon .= ' Use this custom visual style guidance: ' . $custom_look_prompt . '.';
				}
				break;
			case 'illustration':
			default:
				$addon .= ' Use a clean, modern digital illustration style with soft lighting and a minimal background.';
				break;
		}

		if ( $allow ) {
			$addon .= ' Text and logos are allowed.';
		} else {
			$addon .= ' No text, watermarks, or logos.';
		}

		return rtrim( (string) $prompt ) . $addon;
	}

	private function default_prompt( $title, $content ) {
		$combined = trim( $title . "\n\n" . $content );
		return sprintf(
			'Create a visually compelling featured image for a blog post. '
			. 'Focus on a clear central subject that represents the post content. '
			. 'Post content: %s',
			$combined
		);
	}

	// =========================================================================
	// Media Sideload
	// =========================================================================

	private function sideload_image( $img_url, $post_id, $title = '' ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$desc = $title
			? sprintf(
				/* translators: %s: post title */
				__( 'AI generated image for: %s', 'picment-ai-featured-image-generator' ),
				$title
			)
			: __( 'AI generated image', 'picment-ai-featured-image-generator' );

		return media_sideload_image( $img_url, $post_id, $desc, 'id' );
	}

	// =========================================================================
	// AJAX Handler
	// =========================================================================

	public function ajax_generate() {
		check_ajax_referer( 'picment_ai_image_generate', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'picment-ai-featured-image-generator' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'picment-ai-featured-image-generator' ) ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post.', 'picment-ai-featured-image-generator' ) ) );
		}

		$result = $this->run_generation( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'status'  => get_post_meta( $post_id, self::META_STATUS, true ),
			) );
		}

		wp_send_json_success( array(
			'message'        => __( 'Image generated successfully.', 'picment-ai-featured-image-generator' ),
			'status'         => 'done',
			'thumbnail_html' => get_the_post_thumbnail( $post_id, array( 80, 45 ) ),
		) );
	}
}
