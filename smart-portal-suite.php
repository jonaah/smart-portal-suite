<?php
/**
 * Plugin Name:       Smart Portal Suite
 * Plugin URI:        https://effizientes-heim.de/
 * Description:       Multi-Step Formulare für Gebäude-Check und Projektanfragen mit Nextcloud-Anbindung.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jonah Kleimann
 * Text Domain:       smart-portal-suite
 * Domain Path:       /languages
 *
 * @package           SmartPortalSuite
 */

// Direkten Aufruf der Datei verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin-Konstanten definieren
define( 'SPS_VERSION', '0.1.0' );
define( 'SPS_PLUGIN_FILE', __FILE__ );
define( 'SPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SPS_PREFIX', 'sps_' );

/**
 * Core-Klassen laden (kein Composer in v1.0, einfaches require_once).
 */
require_once SPS_PLUGIN_DIR . 'includes/class-sps-settings.php';
require_once SPS_PLUGIN_DIR . 'includes/class-sps-ajax-handler.php';
require_once SPS_PLUGIN_DIR . 'includes/class-sps-form-renderer.php';
require_once SPS_PLUGIN_DIR . 'includes/class-sps-form-manager.php';
require_once SPS_PLUGIN_DIR . 'includes/class-sps-diagnostics.php';
require_once SPS_PLUGIN_DIR . 'includes/class-sps-account-sync-page.php';

/**
 * Nextcloud Bridge Module laden.
 */
require_once SPS_PLUGIN_DIR . 'modules/nextcloud/class-sps-nc-client.php';
require_once SPS_PLUGIN_DIR . 'modules/nextcloud/class-sps-nc-forms.php';
require_once SPS_PLUGIN_DIR . 'modules/nextcloud/class-sps-nc-webdav.php';

/**
 * Authentication & User Sync Module laden.
 */
require_once SPS_PLUGIN_DIR . 'modules/auth/class-sps-nc-user-sync.php';
require_once SPS_PLUGIN_DIR . 'modules/auth/class-sps-auth-shortcodes.php';

/**
 * Plugin Initialisierung.
 */
function sps_init() {
	// Lade Übersetzungen falls vorhanden
	load_plugin_textdomain( 'smart-portal-suite', false, dirname( SPS_PLUGIN_BASENAME ) . '/languages' );

	// Instanziierung der Singletons
	SPS_Settings::get_instance();
	SPS_Ajax_Handler::get_instance();
	SPS_Form_Renderer::get_instance();
	SPS_NC_User_Sync::get_instance();
	SPS_Auth_Shortcodes::get_instance();

	if ( is_admin() ) {
		SPS_Form_Manager::get_instance();
		SPS_Diagnostics::get_instance();
		SPS_Account_Sync_Page::get_instance();
	}
}
add_action( 'plugins_loaded', 'sps_init' );