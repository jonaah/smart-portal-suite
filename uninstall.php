<?php
/**
 * Deinstallation: entfernt Zugangsdaten, Einstellungen und Caches.
 *
 * Nextcloud-Konten, Formularantworten und Fallback-Leads in Nextcloud bleiben unberührt.
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'sps_settings' );
delete_option( 'sps_debug_log' );

// Formular-Themes, importierte Formulare, Caches.
$like_patterns = array( 'sps_form_theme_%', 'sps_custom_form_%', '_transient_sps_%', '_transient_timeout_sps_%' );
foreach ( $like_patterns as $pattern ) {
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
	foreach ( $names as $name ) {
		delete_option( $name );
	}
}

// Verschlüsselte Nextcloud-Passwörter der Kunden.
delete_metadata( 'user', 0, 'nextcloud_password_enc', '', true );

wp_clear_scheduled_hook( 'sps_retry_fallback_leads' );
wp_clear_scheduled_hook( 'sps_cleanup_staging' );

// Upload-Staging leeren.
$sps_staging_dirs = array( trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'sps-staging/' );
if ( defined( 'SPS_STAGING_DIR' ) && SPS_STAGING_DIR ) {
	$sps_staging_dirs[] = rtrim( SPS_STAGING_DIR, '/' ) . '/';
}
foreach ( array( (string) ini_get( 'upload_tmp_dir' ), get_temp_dir() ) as $sps_tmp ) {
	if ( '' !== $sps_tmp ) {
		$sps_staging_dirs[] = trailingslashit( $sps_tmp ) . 'sps-staging-' . substr( md5( ABSPATH ), 0, 12 ) . '/';
	}
}
foreach ( array_unique( $sps_staging_dirs ) as $sps_staging ) {
	foreach ( (array) glob( $sps_staging . '*' ) as $sps_file ) {
		@unlink( $sps_file );
	}
	@unlink( $sps_staging . '.htaccess' );
	@rmdir( $sps_staging );
}
