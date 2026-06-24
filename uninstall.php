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
delete_option( 'bcs_import_state' );
delete_option( 'bcs_loopback_token' );
delete_transient( 'bcs_notice' );
delete_transient( 'bcs_batch_lock' );
delete_transient( 'bcs_import_lock' );
delete_transient( 'bcs_meta_keys' );
delete_transient( 'bcs_meta_keys_all' );

wp_clear_scheduled_hook( 'bcs_process_batch' );
wp_clear_scheduled_hook( 'bcs_process_import' );

// Remove any uploaded import files.
$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'bcs-imports';
if ( is_dir( $dir ) ) {
	foreach ( (array) glob( $dir . '/*' ) as $f ) {
		@unlink( $f ); // phpcs:ignore
	}
	@rmdir( $dir ); // phpcs:ignore
}
