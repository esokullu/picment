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
	'wpaiimage_api_key',
	'wpaiimage_image_size',
	'wpaiimage_image_quality',
	'wpaiimage_image_style',
	'wpaiimage_auto_generate',
	'wpaiimage_overwrite_existing',
	'wpaiimage_prompt_template',
	'wpaiimage_server_base_url',
	'wpaiimage_site_token',
	// Billing
	'wpaiimage_billing_mode',
	'wpaiimage_trial_credits',
	'wpaiimage_install_id',
	'wpaiimage_stripe_customer_id',
	'wpaiimage_stripe_subscription_id',
	'wpaiimage_stripe_plan',
	'wpaiimage_stripe_status',
	'wpaiimage_stripe_current_period_end',
	'wpaiimage_credits_remaining',
	'wpaiimage_credits_reset_at',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove all post meta added by this plugin
$meta_keys = array(
	'_wpaiimage_status',
	'_wpaiimage_generated_at',
	'_wpaiimage_error',
	'_wpaiimage_enabled',
	// Legacy keys from earlier versions
	'_wpaiimage_url',
	'_wpaiimage_auto_generate',
);

global $wpdb;
foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}

// Clear any pending WP-Cron events
wp_clear_scheduled_hook( 'wpaiimage_generate_event' );
