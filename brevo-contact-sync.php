<?php
/**
 * Plugin Name:       Brevo Contact Sync (WooCommerce custom fields)
 * Plugin URI:        https://github.com/andyfreed/brevo-plugin
 * Description:       Pushes WooCommerce customers and their custom user-meta fields to Brevo as contact attributes. Auto-detects your customer meta, lets you map it to Brevo custom fields, and syncs in real time + in bulk.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            BHFE
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       brevo-contact-sync
 *
 * WP Pusher: install from https://github.com/andyfreed/brevo-plugin (branch master, no subdirectory)
 *
 * @package BrevoContactSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BCS_VERSION', '2.0.0' );
define( 'BCS_PLUGIN_FILE', __FILE__ );
define( 'BCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Options.
define( 'BCS_OPTION_API_KEY', 'bcs_api_key' );      // string
define( 'BCS_OPTION_SETTINGS', 'bcs_settings' );    // array: default_list_id, realtime, last_full_sync
define( 'BCS_OPTION_MAPPING', 'bcs_field_mapping' ); // array of { meta_key, brevo_attr, transform }
define( 'BCS_OPTION_SYNC', 'bcs_sync_state' );      // array: running, offset, total, processed, errors, list_id

// Cron hook for batched bulk sync.
define( 'BCS_CRON_BATCH', 'bcs_process_batch' );

require_once BCS_PLUGIN_DIR . 'includes/class-bcs-api.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-meta.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-sync.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-checkout.php';
require_once BCS_PLUGIN_DIR . 'includes/class-bcs-admin.php';

/**
 * Activation: seed default settings.
 */
function bcs_activate() {
	if ( false === get_option( BCS_OPTION_SETTINGS, false ) ) {
		add_option(
			BCS_OPTION_SETTINGS,
			array(
				'default_list_id' => 0,
				'realtime'        => 1,
				'include_empty'   => 0,
				'last_full_sync'  => '',
			)
		);
	}
}
register_activation_hook( __FILE__, 'bcs_activate' );

/**
 * Deactivation: clear any scheduled batch jobs.
 */
function bcs_deactivate() {
	wp_clear_scheduled_hook( BCS_CRON_BATCH );
}
register_deactivation_hook( __FILE__, 'bcs_deactivate' );

/**
 * Boot.
 */
function bcs_init() {
	// Background batch processor (registered everywhere so WP-Cron can fire it).
	add_action( BCS_CRON_BATCH, array( 'BCS_Sync', 'run_batch' ) );

	// Real-time event hooks.
	BCS_Sync::register_event_hooks();

	// Checkout opt-in checkbox (front-end + checkout processing).
	BCS_Checkout::hooks();

	if ( is_admin() ) {
		( new BCS_Admin() )->hooks();
	}
}
add_action( 'plugins_loaded', 'bcs_init' );
