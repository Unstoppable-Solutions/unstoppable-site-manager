<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'usmd_sites', 'usmd_activity', 'usmd_files', 'usmd_clients' ) as $usmd_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $usmd_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing this plugin's own tables on uninstall.
}

foreach ( array( 'usmd_keys', 'usmd_settings', 'usmd_db_version', 'usmd_migrated', 'usmd_packages', 'usmd_report_notes', 'usmd_vulns', 'usmd_report_sent', 'usmd_connector_zip', 'usmd_allowed_users', 'usmd_bulk', 'usmd_branding_refused', 'usmd_move', 'usmd_bg_last', 'usmd_digest_last', 'usmd_uptime', 'usmd_expiry', 'usmd_domains', 'usmd_uptime_last', 'usmd_uptime_net', 'usmd_uptime_since', 'usmd_review', 'usmd_installed', 'usmd_groups' ) as $usmd_opt ) {
	delete_option( $usmd_opt );
}
delete_metadata( 'user', 0, 'usmd_group_by', '', true );
wp_clear_scheduled_hook( 'usmd_sync_tick' );
wp_clear_scheduled_hook( 'usmd_report_tick' );
wp_clear_scheduled_hook( 'usmd_report_more' );
wp_unschedule_hook( 'usmd_bg_site' );
wp_clear_scheduled_hook( 'usmd_monitor_tick' );
if ( function_exists( 'wp_unschedule_hook' ) ) {
	wp_unschedule_hook( 'usmd_sync_site' );
	wp_unschedule_hook( 'usmd_thumb' );
	wp_unschedule_hook( 'usmd_vuln_site' );
	wp_unschedule_hook( 'usmd_branding_site' );
}

// Cached screenshots, and folders older versions used for plugin packages.
$usmd_upload = wp_upload_dir( null, false );
foreach ( array( 'usmd-thumbs', 'usmd-packages', 'usmd-connector' ) as $usmd_folder ) {
	$usmd_dir = trailingslashit( $usmd_upload['basedir'] ) . $usmd_folder;
	if ( ! is_dir( $usmd_dir ) ) {
		continue;
	}
	foreach ( (array) glob( $usmd_dir . '/{,*/}{,.}*', GLOB_BRACE ) as $usmd_file ) {
		if ( is_file( $usmd_file ) ) {
			wp_delete_file( $usmd_file );
		}
	}
	foreach ( (array) glob( $usmd_dir . '/*', GLOB_ONLYDIR ) as $usmd_sub ) {
		@rmdir( $usmd_sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- removing this plugin's own, now-empty upload folders on uninstall, where WP_Filesystem may need FTP details.
	}
	@rmdir( $usmd_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- removing this plugin's own, now-empty upload folders on uninstall, where WP_Filesystem may need FTP details.
}

// Temporary data (cached lookups, one-time tokens, dashboard-move results).
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_usmd_' ) . '%', $wpdb->esc_like( '_transient_timeout_usmd_' ) . '%', $wpdb->esc_like( 'usmd_move_site_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup on uninstall.
