<?php
/**
 * Remove plugin options on uninstall.
 *
 * Post meta (_deardoc_id) is kept on purpose: if the plugin is reinstalled,
 * posts already imported are still recognised and never duplicated.
 *
 * @package DearDocSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'dds_settings', 'dds_log', 'dds_last_count', 'dds_last_check', 'dds_ignored_ids', 'dds_failures' ) as $dds_option ) {
	delete_option( $dds_option );
}
delete_transient( 'dds_sync_lock' );
delete_transient( 'dds_alert_sent' );
wp_clear_scheduled_hook( 'dds_sync_event' );
