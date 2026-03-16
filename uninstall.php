<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin options and post meta from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options
$options = array(
	// Core settings
	'picment_ai_image_api_key',
	'picment_ai_image_image_size',
	'picment_ai_image_image_quality',
	'picment_ai_image_image_style',
	'picment_ai_image_auto_generate',
	'picment_ai_image_overwrite_existing',
	'picment_ai_image_prompt_template',
	'picment_ai_image_server_base_url',
	'picment_ai_image_site_token',
	// Billing
	'picment_ai_image_billing_mode',
	'picment_ai_image_trial_credits',
	'picment_ai_image_install_id',
	'picment_ai_image_stripe_customer_id',
	'picment_ai_image_stripe_subscription_id',
	'picment_ai_image_stripe_plan',
	'picment_ai_image_stripe_status',
	'picment_ai_image_stripe_current_period_end',
	'picment_ai_image_credits_remaining',
	'picment_ai_image_credits_reset_at',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove all post meta added by this plugin
$meta_keys = array(
	'_picment_ai_image_status',
	'_picment_ai_image_generated_at',
	'_picment_ai_image_error',
	'_picment_ai_image_enabled',
	// Legacy keys from earlier versions
	'_picment_ai_image_url',
	'_picment_ai_image_auto_generate',
);

global $wpdb;
foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}

// Clear any pending WP-Cron events
wp_clear_scheduled_hook( 'picment_ai_image_generate_event' );
