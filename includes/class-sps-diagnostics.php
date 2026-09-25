<?php
/**
 * Admin Diagnostics & Debugging Tool
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Diagnostics {

	/**
	 * Main option key for log buffer.
	 */
	const LOG_OPTION = 'sps_debug_log';

	/**
	 * Maximum number of log entries to store.
	 */
	const MAX_LOG_ENTRIES = 50;

	/**
	 * Singleton instance.
	 *
	 * @var SPS_Diagnostics|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_Diagnostics
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_diagnostics_submenu' ) );
		add_action( 'wp_ajax_sps_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_sps_clear_log', array( $this, 'ajax_clear_log' ) );
	}

	/**
	 * Register diagnostics submenu page.
	 */
	public function add_diagnostics_submenu() {
		add_submenu_page(
			'smart-portal-suite',
			__( 'Diagnose & Debug', 'smart-portal-suite' ),
			__( 'Diagnose & Debug', 'smart-portal-suite' ),
			'manage_options',
			'sps-diagnostics',
			array( $this, 'render_diagnostics_page' )
		);
	}

	/**
	 * Central Logging Method for SPS.
	 * Writes to error_log and stores the latest entries in a ring-buffer option.
	 *
	 * @param string $message The log message.
	 * @param string $level   Log level: 'info', 'warning', 'error', 'debug'.
	 * @param array  $context Optional context data.
	 */
	public static function log( $message, $level = 'info', $context = array() ) {
		$level = strtolower( trim( $level ) );

		// Prüfen, ob geloggt werden soll
		$debug_mode = (bool) SPS_Settings::get_setting( 'debug_mode', 0 );
		$is_wp_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

		// Wenn nicht Error, nur loggen falls debug_mode oder WP_DEBUG aktiv
		if ( 'error' !== $level && ! $debug_mode && ! $is_wp_debug ) {
			return;
		}

		$entry = array(
			'timestamp' => current_time( 'mysql' ),
			'time'      => current_time( 'H:i:s' ),
			'level'     => strtoupper( $level ),
			'message'   => sanitize_text_field( $message ),
			'context'   => ! empty( $context ) ? $context : null,
		);

		// WordPress error_log
		$context_str = ! empty( $context ) ? ' | ' . wp_json_encode( $context ) : '';
		error_log( sprintf( '[SPS %s] %s%s', strtoupper( $level ), $message, $context_str ) );

		// Ringpuffer in wp_options aktualisieren
		$logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		array_unshift( $logs, $entry );

		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, 0, self::MAX_LOG_ENTRIES );
		}

		update_option( self::LOG_OPTION, $logs, false );
	}

	/**
	 * Backward compatibility for legacy log_error calls.
	 *
	 * @param string $message The message to log.
	 * @param array  $context Optional context.
	 */
	public static function log_error( $message, $context = array() ) {
		self::log( $message, 'error', $context );
	}

	/**
	 * Retrieve all stored debug logs.
	 *
	 * @return array
	 */
	public static function get_logs() {
		$logs = get_option( self::LOG_OPTION, array() );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Clear all stored debug logs.
	 *
	 * @return bool
	 */
	public static function clear_logs() {
		return delete_option( self::LOG_OPTION );
	}

	/**
	 * AJAX Handler: Clear Debug Log.
	 */
	public function ajax_clear_log() {
		check_ajax_referer( 'sps_diagnostics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) ), 403 );
		}

		self::clear_logs();
		self::log( 'Debug-Log wurde durch Administrator geleert.', 'info' );

		wp_send_json_success( array( 'message' => __( 'Debug-Log erfolgreich geleert.', 'smart-portal-suite' ) ) );
	}

	/**
	 * AJAX Handler: Live Nextcloud Connection Test.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'sps_diagnostics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) ), 403 );
		}

		$results = array(
			'success' => true,
			'steps'   => array(),
		);

		$nextcloud_url   = SPS_Settings::get_setting( 'nextcloud_url', '' );
		$service_account = SPS_Settings::get_setting( 'service_account', '' );
		$app_password    = SPS_Settings::get_setting( 'app_password', '' );
		$webdav_base_dir = SPS_Settings::get_setting( 'webdav_base_dir', 'SPS_Leads' );

		// Schritt 1: Konfigurationsprüfung
		if ( empty( $nextcloud_url ) || empty( $service_account ) || empty( $app_password ) ) {
			$results['success'] = false;
			$results['steps'][] = array(
				'step'    => 'config',
				'title'   => __( 'Konfiguration hinterlegt', 'smart-portal-suite' ),
				'status'  => 'error',
				'message' => __( 'Nextcloud URL, Service-Account oder App-Passwort fehlt in den Einstellungen.', 'smart-portal-suite' ),
			);
			wp_send_json_success( $results );
		}

		$results['steps'][] = array(
			'step'    => 'config',
			'title'   => __( 'Konfiguration vollständig', 'smart-portal-suite' ),
			'status'  => 'success',
			'message' => sprintf( __( 'URL: %s | Account: %s | Passwort hinterlegt', 'smart-portal-suite' ), $nextcloud_url, $service_account ),
		);

		// Schritt 2: Server-Erreichbarkeit (status.php)
		$start_time = microtime( true );
		$status_url = untrailingslashit( $nextcloud_url ) . '/status.php';
		$response   = wp_remote_get( $status_url, array(
			'timeout'    => 10,
			'user-agent' => 'SmartPortalSuite-Diagnostics/' . SPS_VERSION,
		) );
		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$results['success'] = false;
			$results['steps'][] = array(
				'step'    => 'reachability',
				'title'   => __( 'Nextcloud Server-Erreichbarkeit', 'smart-portal-suite' ),
				'status'  => 'error',
				'message' => sprintf( __( 'Server nicht erreichbar: %s (%d ms)', 'smart-portal-suite' ), $response->get_error_message(), $duration_ms ),
			);
			self::log( 'Verbindungstest fehlgeschlagen bei Server-Erreichbarkeit: ' . $response->get_error_message(), 'error' );
			wp_send_json_success( $results );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body_raw  = wp_remote_retrieve_body( $response );
		$status_data = json_decode( $body_raw, true );

		if ( 200 !== $http_code || ! is_array( $status_data ) ) {
			$results['success'] = false;
			$results['steps'][] = array(
				'step'    => 'reachability',
				'title'   => __( 'Nextcloud Server-Erreichbarkeit', 'smart-portal-suite' ),
				'status'  => 'error',
				'message' => sprintf( __( 'Server antwortete mit HTTP %d (status.php ungültig). Latenz: %d ms', 'smart-portal-suite' ), $http_code, $duration_ms ),
			);
			self::log( sprintf( 'Nextcloud status.php antwortete mit HTTP %d', $http_code ), 'error' );
			wp_send_json_success( $results );
		}

		$nc_version = isset( $status_data['versionstring'] ) ? $status_data['versionstring'] : ( isset( $status_data['version'] ) ? $status_data['version'] : 'Unbekannt' );
		$results['steps'][] = array(
			'step'    => 'reachability',
			'title'   => __( 'Nextcloud Server erreichbar', 'smart-portal-suite' ),
			'status'  => 'success',
			'message' => sprintf( __( 'Erfolgreich geantwortet in %d ms (Nextcloud Version %s)', 'smart-portal-suite' ), $duration_ms, $nc_version ),
		);

		// Schritt 3: Authentifizierung via OCS Capabilities API
		$client = new SPS_NC_Client( $nextcloud_url, null, $service_account, $app_password );
		$start_time = microtime( true );
		$auth_res   = $client->request( 'ocs/v2.php/cloud/capabilities', 'GET' );
		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $auth_res ) ) {
			$results['success'] = false;
			$results['steps'][] = array(
				'step'    => 'auth',
				'title'   => __( 'Authentifizierung (Service-Account)', 'smart-portal-suite' ),
				'status'  => 'error',
				'message' => sprintf( __( 'Verbindungsfehler bei Authentifizierung: %s', 'smart-portal-suite' ), $auth_res->get_error_message() ),
			);
			self::log( 'Authentifizierungstest fehlgeschlagen: ' . $auth_res->get_error_message(), 'error' );
			wp_send_json_success( $results );
		}

		$auth_code = wp_remote_retrieve_response_code( $auth_res );
		if ( 200 !== $auth_code ) {
			$results['success'] = false;
			$msg = ( 401 === $auth_code || 403 === $auth_code )
				? __( 'Ungültige Zugangsdaten (HTTP 401/403). Bitte Benutzername und App-Passwort prüfen.', 'smart-portal-suite' )
				: sprintf( __( 'Unerwarteter Statuscode: HTTP %d', 'smart-portal-suite' ), $auth_code );

			$results['steps'][] = array(
				'step'    => 'auth',
				'title'   => __( 'Authentifizierung fehlgeschlagen', 'smart-portal-suite' ),
				'status'  => 'error',
				'message' => $msg . sprintf( ' (%d ms)', $duration_ms ),
			);
			self::log( 'Authentifizierungstest fehlgeschlagen: ' . $msg, 'error' );
			wp_send_json_success( $results );
		}

		$results['steps'][] = array(
			'step'    => 'auth',
			'title'   => __( 'Authentifizierung erfolgreich', 'smart-portal-suite' ),
			'status'  => 'success',
			'message' => sprintf( __( 'Service-Account "%s" erfolgreich autorisiert (%d ms)', 'smart-portal-suite' ), $service_account, $duration_ms ),
		);

		// Schritt 4: Nextcloud Forms API v3 Verfügbarkeit
		$start_time = microtime( true );
		$forms_res  = $client->request( 'ocs/v2.php/apps/forms/api/v3/forms', 'GET' );
		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $forms_res ) ) {
			$results['steps'][] = array(
				'step'    => 'forms_api',
				'title'   => __( 'Nextcloud Forms API v3', 'smart-portal-suite' ),
				'status'  => 'warning',
				'message' => sprintf( __( 'Forms API nicht erreichbar: %s', 'smart-portal-suite' ), $forms_res->get_error_message() ),
			);
		} else {
			$forms_code = wp_remote_retrieve_response_code( $forms_res );
			$forms_body = json_decode( wp_remote_retrieve_body( $forms_res ), true );

			if ( 200 === $forms_code ) {
				$raw_forms = array();
				if ( isset( $forms_body['ocs']['data']['forms'] ) && is_array( $forms_body['ocs']['data']['forms'] ) ) {
					$raw_forms = $forms_body['ocs']['data']['forms'];
				} elseif ( isset( $forms_body['ocs']['data'] ) && is_array( $forms_body['ocs']['data'] ) ) {
					$raw_forms = $forms_body['ocs']['data'];
				}
				$form_count = count( $raw_forms );
				$results['steps'][] = array(
					'step'    => 'forms_api',
					'title'   => __( 'Nextcloud Forms API v3 aktiv', 'smart-portal-suite' ),
					'status'  => 'success',
					'message' => sprintf( __( 'Forms API v3 antwortet einwandfrei. %d Formulare für diesen Account gefunden (%d ms).', 'smart-portal-suite' ), $form_count, $duration_ms ),
				);
			} elseif ( 404 === $forms_code ) {
				$results['steps'][] = array(
					'step'    => 'forms_api',
					'title'   => __( 'Nextcloud Forms App nicht installiert', 'smart-portal-suite' ),
					'status'  => 'warning',
					'message' => __( 'Die Forms-App scheint auf der Nextcloud-Instanz nicht installiert oder aktiviert zu sein (HTTP 404).', 'smart-portal-suite' ),
				);
			} else {
				$results['steps'][] = array(
					'step'    => 'forms_api',
					'title'   => __( 'Nextcloud Forms API Rückmeldung', 'smart-portal-suite' ),
					'status'  => 'warning',
					'message' => sprintf( __( 'Forms API gab HTTP %d zurück (%d ms).', 'smart-portal-suite' ), $forms_code, $duration_ms ),
				);
			}
		}

		// Schritt 5: WebDAV Fallback-Pfad Prüfung
		$start_time  = microtime( true );
		$webdav_path = sprintf( 'remote.php/dav/files/%s/%s/', rawurlencode( $service_account ), rawurlencode( $webdav_base_dir ) );
		$dav_res     = $client->request( $webdav_path, 'PROPFIND', null, array( 'Depth' => '0' ) );
		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $dav_res ) ) {
			$results['steps'][] = array(
				'step'    => 'webdav',
				'title'   => __( 'WebDAV Fallback-Ablage', 'smart-portal-suite' ),
				'status'  => 'warning',
				'message' => sprintf( __( 'WebDAV-Endpunkt nicht erreichbar: %s', 'smart-portal-suite' ), $dav_res->get_error_message() ),
			);
		} else {
			$dav_code = wp_remote_retrieve_response_code( $dav_res );
			if ( 207 === $dav_code || 200 === $dav_code ) {
				$results['steps'][] = array(
					'step'    => 'webdav',
					'title'   => __( 'WebDAV Fallback-Ordner bereit', 'smart-portal-suite' ),
					'status'  => 'success',
					'message' => sprintf( __( 'Basisordner "%s" existiert und ist schreibbar (%d ms).', 'smart-portal-suite' ), $webdav_base_dir, $duration_ms ),
				);
			} elseif ( 404 === $dav_code ) {
				$results['steps'][] = array(
					'step'    => 'webdav',
					'title'   => __( 'WebDAV Fallback-Ordner', 'smart-portal-suite' ),
					'status'  => 'warning',
					'message' => sprintf( __( 'Ordner "%s" existiert noch nicht im Nextcloud-Konto. Er wird bei der ersten Fallback-Übertragung angelegt.', 'smart-portal-suite' ), $webdav_base_dir ),
				);
			} else {
				$results['steps'][] = array(
					'step'    => 'webdav',
					'title'   => __( 'WebDAV Fallback Status', 'smart-portal-suite' ),
					'status'  => 'warning',
					'message' => sprintf( __( 'WebDAV gab HTTP %d zurück.', 'smart-portal-suite' ), $dav_code ),
				);
			}
		}

		// Schritt 6: Nextcloud User Provisioning API (Account Sync)
		$start_time  = microtime( true );
		$prov_res    = $client->request( 'ocs/v1.php/cloud/users/' . rawurlencode( $service_account ) . '?format=json', 'GET' );
		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $prov_res ) ) {
			$results['steps'][] = array(
				'step'    => 'user_provisioning',
				'title'   => __( 'Nextcloud User Provisioning API', 'smart-portal-suite' ),
				'status'  => 'warning',
				'message' => sprintf( __( 'User Provisioning API nicht erreichbar: %s', 'smart-portal-suite' ), $prov_res->get_error_message() ),
			);
		} else {
			$prov_code   = wp_remote_retrieve_response_code( $prov_res );
			$prov_body   = json_decode( wp_remote_retrieve_body( $prov_res ), true );
			$prov_status = isset( $prov_body['ocs']['meta']['statuscode'] ) ? (int) $prov_body['ocs']['meta']['statuscode'] : null;

			if ( 200 === $prov_code && 100 === $prov_status ) {
				$results['steps'][] = array(
					'step'    => 'user_provisioning',
					'title'   => __( 'User Provisioning API aktiv', 'smart-portal-suite' ),
					'status'  => 'success',
					'message' => sprintf( __( 'Service-Account verfügt über Administrator-Rechte zur Benutzerverwaltung (%d ms).', 'smart-portal-suite' ), $duration_ms ),
				);
			} else {
				$results['steps'][] = array(
					'step'    => 'user_provisioning',
					'title'   => __( 'User Provisioning API eingeschränkt', 'smart-portal-suite' ),
					'status'  => 'warning',
					'message' => sprintf( __( 'Service-Account konnte Benutzerdaten nicht abfragen (HTTP %d, OCS %s). Prüfe, ob der Account in der Gruppe "admin" ist.', 'smart-portal-suite' ), $prov_code, var_export( $prov_status, true ) ),
				);
			}
		}

		// Schritt 7: Nextcloud Datenbank-Verbindung (Social Login)
		$nc_db_host = SPS_Settings::get_setting( 'nc_db_host', '' );
		if ( ! empty( $nc_db_host ) ) {
			$db_test = SPS_NC_User_Sync::get_instance()->test_nc_db_connection();
			$results['steps'][] = array(
				'step'    => 'nc_db',
				'title'   => __( 'Nextcloud Datenbank (Social Login)', 'smart-portal-suite' ),
				'status'  => $db_test['success'] ? 'success' : 'warning',
				'message' => $db_test['message'],
			);
		}

		self::log( 'Nextcloud Live-Verbindungstest erfolgreich durchgeführt.', 'info' );
		wp_send_json_success( $results );
	}

	/**
	 * Render diagnostics page.
	 */
	public function render_diagnostics_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) );
		}

		$sodium_available  = function_exists( 'sodium_crypto_secretbox' );
		$curl_available    = function_exists( 'curl_version' );
		$openssl_available = extension_loaded( 'openssl' );
		$json_available    = function_exists( 'json_encode' );

		$nextcloud_url   = SPS_Settings::get_setting( 'nextcloud_url', '' );
		$service_account = SPS_Settings::get_setting( 'service_account', '' );
		$has_app_pw      = ! empty( SPS_Settings::get_raw_setting( 'app_password' ) );
		$webdav_base_dir = SPS_Settings::get_setting( 'webdav_base_dir', 'SPS_Leads' );
		$admin_email     = SPS_Settings::get_setting( 'admin_email', get_option( 'admin_email' ) );
		$debug_mode      = (bool) SPS_Settings::get_setting( 'debug_mode', 0 );

		$logs = self::get_logs();

		// System Report Data
		global $wp_version;
		$system_report = sprintf(
			"### Smart Portal Suite - Systembericht\n" .
			"- Plugin Version: %s\n" .
			"- WordPress Version: %s\n" .
			"- PHP Version: %s\n" .
			"- Webserver: %s\n" .
			"- Sodium Erweiterung: %s\n" .
			"- cURL Erweiterung: %s\n" .
			"- OpenSSL Erweiterung: %s\n" .
			"- Memory Limit: %s\n" .
			"- Max Execution Time: %ss\n" .
			"- Nextcloud URL: %s\n" .
			"- Service Account: %s\n" .
			"- App-Passwort vorhanden: %s\n" .
			"- WebDAV Basisordner: %s\n" .
			"- Debug Modus: %s\n" .
			"- Erstellt am: %s\n",
			SPS_VERSION,
			$wp_version,
			PHP_VERSION,
			isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ) : 'Unbekannt',
			$sodium_available ? 'Ja' : 'Nein',
			$curl_available ? 'Ja' : 'Nein',
			$openssl_available ? 'Ja' : 'Nein',
			ini_get( 'memory_limit' ),
			ini_get( 'max_execution_time' ),
			! empty( $nextcloud_url ) ? $nextcloud_url : 'Nicht konfiguriert',
			! empty( $service_account ) ? $service_account : 'Nicht konfiguriert',
			$has_app_pw ? 'Ja' : 'Nein',
			$webdav_base_dir,
			$debug_mode ? 'Aktiviert' : 'Deaktiviert',
			current_time( 'mysql' )
		);
		?>
		<div class="wrap sps-admin-wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-dashboard" style="font-size: 28px; line-height: 1; margin-right: 6px;"></span>
				<?php esc_html_e( 'Smart Portal Suite - Diagnose & Debugging', 'smart-portal-suite' ); ?>
			</h1>
			<p class="sps-lead-text">
				<?php esc_html_e( 'Überprüfe hier die Systemvoraussetzungen, teste die Live-Verbindung zur Nextcloud-Instanz und analysiere das Übertragungsprotokoll.', 'smart-portal-suite' ); ?>
			</p>
			<hr class="wp-header-end" />

			<!-- Sektion 1: Live Verbindungstest -->
			<div class="postbox sps-card">
				<div class="postbox-header">
					<h2>
						<span class="dashicons dashicons-cloud" style="margin-right: 6px;"></span>
						<?php esc_html_e( 'Nextcloud Live-Verbindungstest', 'smart-portal-suite' ); ?>
					</h2>
				</div>
				<div class="inside">
					<p><?php esc_html_e( 'Führt eine schrittweise Live-Prüfung gegen die hinterlegte Nextcloud-Instanz durch (Server-Erreichbarkeit, Service-Account-Authentifizierung, Forms API v3 und WebDAV Fallback).', 'smart-portal-suite' ); ?></p>
					
					<button type="button" id="sps-run-connection-test" class="button button-primary button-hero">
						<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 4px;"></span>
						<?php esc_html_e( 'Nextcloud-Verbindung jetzt prüfen', 'smart-portal-suite' ); ?>
					</button>

					<div id="sps-test-results" class="sps-test-results-box" style="display: none; margin-top: 18px;">
						<!-- Wird dynamisch via admin.js befüllt -->
					</div>
				</div>
			</div>

			<!-- Sektion 2: System- & Konfigurations-Status -->
			<div class="postbox sps-card">
				<div class="postbox-header">
					<h2>
						<span class="dashicons dashicons-admin-tools" style="margin-right: 6px;"></span>
						<?php esc_html_e( 'System- & Konfigurationsstatus', 'smart-portal-suite' ); ?>
					</h2>
				</div>
				<div class="inside" style="padding: 0;">
					<table class="widefat striped sps-status-table">
						<thead>
							<tr>
								<th style="width: 35%;"><?php esc_html_e( 'Komponente / Parameter', 'smart-portal-suite' ); ?></th>
								<th style="width: 25%;"><?php esc_html_e( 'Status', 'smart-portal-suite' ); ?></th>
								<th><?php esc_html_e( 'Details & Hinweise', 'smart-portal-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'PHP Sodium Erweiterung', 'smart-portal-suite' ); ?></strong></td>
								<td>
									<?php if ( $sodium_available ) : ?>
										<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Aktiviert', 'smart-portal-suite' ); ?></span>
									<?php else : ?>
										<span class="sps-badge sps-badge-error">✖ <?php esc_html_e( 'Fehlt', 'smart-portal-suite' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $sodium_available ) {
										esc_html_e( 'sodium_crypto_secretbox() steht für verschlüsselte Credentials bereit.', 'smart-portal-suite' );
									} else {
										esc_html_e( 'Empfohlen ab PHP 7.2. Fallback auf OpenSSL wird verwendet.', 'smart-portal-suite' );
									}
									?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'PHP & WordPress Version', 'smart-portal-suite' ); ?></strong></td>
								<td>
									<span class="sps-badge sps-badge-success">✔ PHP <?php echo esc_html( PHP_VERSION ); ?></span>
								</td>
								<td>WP <?php echo esc_html( $wp_version ); ?> | Memory: <?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Nextcloud URL', 'smart-portal-suite' ); ?></strong></td>
								<td>
									<?php if ( ! empty( $nextcloud_url ) ) : ?>
										<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Konfiguriert', 'smart-portal-suite' ); ?></span>
									<?php else : ?>
										<span class="sps-badge sps-badge-warning">⚠ <?php esc_html_e( 'Fehlt', 'smart-portal-suite' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo ! empty( $nextcloud_url ) ? esc_html( $nextcloud_url ) : esc_html__( 'In den Einstellungen hinterlegen', 'smart-portal-suite' ); ?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Service Account & App-Passwort', 'smart-portal-suite' ); ?></strong></td>
								<td>
									<?php if ( ! empty( $service_account ) && $has_app_pw ) : ?>
										<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Hinterlegt', 'smart-portal-suite' ); ?></span>
									<?php else : ?>
										<span class="sps-badge sps-badge-warning">⚠ <?php esc_html_e( 'Unvollständig', 'smart-portal-suite' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									Account: <?php echo ! empty( $service_account ) ? esc_html( $service_account ) : '—'; ?> |
									Passwort: <?php echo $has_app_pw ? esc_html__( 'Verschlüsselt gespeichert', 'smart-portal-suite' ) : esc_html__( 'Nicht gesetzt', 'smart-portal-suite' ); ?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'WebDAV Fallback-Ablage', 'smart-portal-suite' ); ?></strong></td>
								<td>
									<span class="sps-badge sps-badge-info">ℹ <?php echo esc_html( $webdav_base_dir ); ?></span>
								</td>
								<td><?php esc_html_e( 'Basisverzeichnis für Notfall-JSON-Leads bei API-Ausfällen.', 'smart-portal-suite' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Debug-Modus', 'smart-portal-suite' ); ?></strong></td>
								<td>
									<?php if ( $debug_mode ) : ?>
										<span class="sps-badge sps-badge-warning">● <?php esc_html_e( 'Aktiviert', 'smart-portal-suite' ); ?></span>
									<?php else : ?>
										<span class="sps-badge sps-badge-muted">○ <?php esc_html_e( 'Standard (Aus)', 'smart-portal-suite' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $debug_mode ) {
										esc_html_e( 'Detailliertes Logging aller API-Requests und Formular-Ereignisse ist aktiv.', 'smart-portal-suite' );
									} else {
										esc_html_e( 'Nur Fehler werden protokolliert. Kann in den Einstellungen aktiviert werden.', 'smart-portal-suite' );
									}
									?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Sektion 3: SPS Debug-Log Viewer -->
			<div class="postbox sps-card">
				<div class="postbox-header">
					<h2>
						<span class="dashicons dashicons-list-view" style="margin-right: 6px;"></span>
						<?php esc_html_e( 'SPS Debug-Protokoll (letzte 50 Ereignisse)', 'smart-portal-suite' ); ?>
					</h2>
					<div class="handle-actions">
						<button type="button" id="sps-clear-log-btn" class="button button-secondary button-small">
							<span class="dashicons dashicons-trash" style="vertical-align: text-top; font-size: 14px;"></span>
							<?php esc_html_e( 'Log leeren', 'smart-portal-suite' ); ?>
						</button>
					</div>
				</div>
				<div class="inside" style="padding: 0;">
					<?php if ( empty( $logs ) ) : ?>
						<div class="sps-empty-log">
							<p class="description"><?php esc_html_e( 'Aktuell sind keine Protokolleinträge vorhanden. Starte einen Verbindungstest oder übermittle ein Formular.', 'smart-portal-suite' ); ?></p>
						</div>
					<?php else : ?>
						<table class="widefat striped sps-log-table">
							<thead>
								<tr>
									<th style="width: 140px;"><?php esc_html_e( 'Zeitstempel', 'smart-portal-suite' ); ?></th>
									<th style="width: 90px;"><?php esc_html_e( 'Level', 'smart-portal-suite' ); ?></th>
									<th><?php esc_html_e( 'Meldung', 'smart-portal-suite' ); ?></th>
									<th style="width: 200px;"><?php esc_html_e( 'Kontext / Details', 'smart-portal-suite' ); ?></th>
								</tr>
							</thead>
							<tbody id="sps-log-tbody">
								<?php foreach ( $logs as $entry ) : ?>
									<?php
									$level_class = 'sps-badge-info';
									if ( 'ERROR' === $entry['level'] ) {
										$level_class = 'sps-badge-error';
									} elseif ( 'WARNING' === $entry['level'] ) {
										$level_class = 'sps-badge-warning';
									}
									?>
									<tr>
										<td><code><?php echo esc_html( $entry['timestamp'] ); ?></code></td>
										<td><span class="sps-badge <?php echo esc_attr( $level_class ); ?>"><?php echo esc_html( $entry['level'] ); ?></span></td>
										<td><strong><?php echo esc_html( $entry['message'] ); ?></strong></td>
										<td>
											<?php if ( ! empty( $entry['context'] ) ) : ?>
												<code class="sps-code-snippet"><?php echo esc_html( wp_json_encode( $entry['context'] ) ); ?></code>
											<?php else : ?>
												<span class="description">—</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<!-- Sektion 4: Systembericht / Support-Export -->
			<div class="postbox sps-card">
				<div class="postbox-header">
					<h2>
						<span class="dashicons dashicons-clipboard" style="margin-right: 6px;"></span>
						<?php esc_html_e( 'Systembericht für Support & Fehleranalyse', 'smart-portal-suite' ); ?>
					</h2>
					<div class="handle-actions">
						<button type="button" id="sps-copy-report-btn" class="button button-secondary button-small" data-report="<?php echo esc_attr( $system_report ); ?>">
							<span class="dashicons dashicons-admin-page" style="vertical-align: text-top; font-size: 14px;"></span>
							<?php esc_html_e( 'Bericht in Zwischenablage kopieren', 'smart-portal-suite' ); ?>
						</button>
					</div>
				</div>
				<div class="inside">
					<p class="description"><?php esc_html_e( 'Kopiere diesen kompakten Bericht bei technischen Fragen oder Problemen auf Kundenservern:', 'smart-portal-suite' ); ?></p>
					<textarea readonly rows="8" class="large-text code" style="font-size: 12px; background: #f6f7f7;"><?php echo esc_textarea( $system_report ); ?></textarea>
				</div>
			</div>
		</div>
		<?php
	}
}
