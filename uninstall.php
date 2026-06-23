<?php
/**
 * Cleanup on uninstall.
 *
 * @package BrevoContactSync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bcs_api_key' );
delete_option( 'bcs_settings' );
delete_option( 'bcs_field_mapping' );
delete_option( 'bcs_sync_state' );
delete_transient( 'bcs_notice' );
delete_transient( 'bcs_meta_keys' );
delete_transient( 'bcs_meta_keys_all' );

wp_clear_scheduled_hook( 'bcs_process_batch' );
