<?php
/**
 * Nextcloud User & Social-Login Synchronization Engine
 *
 * @package SmartPortalSuite\Auth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_NC_User_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var SPS_NC_User_Sync|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_NC_User_Sync
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
		// Hook bei neuer WordPress-Benutzerregistrierung
		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );

		// Hook bei Benutzer-Login (für Self-Healing / Nachsynchronisation)
		add_action( 'wp_login', array( $this, 'on_wp_login' ), 10, 2 );
	}

	/**
	 * Event Handler: Benutzerregistrierung.
	 * Plant asynchronen Hintergrund-Sync im shutdown Hook.
	 *
	 * @param int $user_id Neu registrierte WP User ID.
	 */
	public function on_user_register( $user_id ) {
		SPS_Diagnostics::log( sprintf( 'Neuer Benutzer registriert (ID %d) - Hintergrund-Sync zu Nextcloud eingeplant.', $user_id ), 'info' );

		add_action( 'shutdown', function () use ( $user_id ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			$this->sync_user( $user_id );
		} );
	}

	/**
	 * Event Handler: Benutzer-Login.
	 * Prüft, ob Account bereits vollständig verknüpft ist. Falls nicht, Hintergrund-Sync.
	 *
	 * @param string  $user_login Benutzername.
	 * @param WP_User $user       WP User Objekt.
	 */
	public function on_wp_login( $user_login, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		$user_id        = $user->ID;
		$already_linked = get_user_meta( $user_id, 'nextcloud_social_linked', true );

		if ( '1' === $already_linked ) {
			return;
		}

		SPS_Diagnostics::log( sprintf( 'Benutzer angemeldet (ID %d, noch nicht social-linked) - Hintergrund-Sync eingeplant.', $user_id ), 'info' );

		add_action( 'shutdown', function () use ( $user_id ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			$this->sync_user( $user_id );
		} );
	}

	/**
	 * Prüfen, ob ein Benutzer der geschützte Service-Account ist.
	 *
	 * @param string $email E-Mail-Adresse des Benutzers.
	 * @return bool
	 */
	public function is_protected_account( $email ) {
		$service_account = SPS_Settings::get_setting( 'service_account', '' );
		if ( empty( $service_account ) ) {
			return false;
		}

		return strtolower( trim( $email ) ) === strtolower( trim( $service_account ) );
	}

	/**
	 * Kernmethode: Führt die Synchronisation eines WordPress-Benutzers zu Nextcloud durch.
	 *
	 * @param int  $user_id WP User ID.
	 * @param bool $force   Erzwingt Neu-Synchronisation auch wenn bereits verknüpft.
	 * @return array Statusbericht mit Schlüsseln 'success', 'message', 'details'.
	 */
	public function sync_user( $user_id, $force = false ) {
		$user_info = get_userdata( $user_id );
		if ( ! $user_info ) {
			$msg = sprintf( 'Sync fehlgeschlagen: WP-User ID %d existiert nicht.', $user_id );
			SPS_Diagnostics::log( $msg, 'error' );
			return array( 'success' => false, 'message' => $msg );
		}

		$email        = trim( $user_info->user_email );
		$nc_username  = $email;
		$display_name = trim( $user_info->display_name ?: $user_info->first_name . ' ' . $user_info->last_name );
		if ( empty( $display_name ) ) {
			$display_name = $user_info->user_login;
		}

		// Schutz des administrativen Nextcloud-Service-Accounts
		if ( $this->is_protected_account( $email ) ) {
			$msg = sprintf( 'Sync übersprungen: Benutzer "%s" entspricht dem Nextcloud Service-Account.', $email );
			SPS_Diagnostics::log( $msg, 'info' );
			return array( 'success' => true, 'message' => $msg, 'skipped' => true );
		}

		// Prüfen, ob Nextcloud API konfiguriert ist
		$nc_url          = SPS_Settings::get_setting( 'nextcloud_url', '' );
		$service_account = SPS_Settings::get_setting( 'service_account', '' );
		$app_password    = SPS_Settings::get_setting( 'app_password', '' );

		if ( empty( $nc_url ) || empty( $service_account ) || empty( $app_password ) ) {
			$msg = 'Sync fehlgeschlagen: Nextcloud-URL, Service-Account oder App-Passwort nicht in den Einstellungen hinterlegt.';
			SPS_Diagnostics::log( $msg, 'error' );
			update_user_meta( $user_id, 'nextcloud_sync_error', $msg );
			return array( 'success' => false, 'message' => $msg );
		}

		$client = new SPS_NC_Client( $nc_url, null, $service_account, $app_password );
		$details = array(
			'user_id'         => $user_id,
			'email'           => $email,
			'nc_username'     => $nc_username,
			'account_created' => false,
			'groups_added'    => array(),
			'social_linked'   => false,
			'password_set'    => false,
		);

		// ---------------------------------------------------
		// 1. Existenzprüfung in Nextcloud via OCS
		// ---------------------------------------------------
		$user_exists = $client->user_exists( $nc_username );

		if ( ! $user_exists ) {
			$initial_password = wp_generate_password( 24, true, true );
			$create_res = $client->create_user( $nc_username, $initial_password, $email, $display_name );

			if ( is_wp_error( $create_res ) ) {
				$msg = sprintf( 'Nextcloud User-Anlage für "%s" fehlgeschlagen: %s', $nc_username, $create_res->get_error_message() );
				SPS_Diagnostics::log( $msg, 'error' );
				update_user_meta( $user_id, 'nextcloud_sync_error', $msg );
				return array( 'success' => false, 'message' => $msg, 'details' => $details );
			}

			$details['account_created'] = true;
			SPS_Diagnostics::log( sprintf( 'Nextcloud-Konto für "%s" erfolgreich erstellt.', $nc_username ), 'info' );
		} else {
			SPS_Diagnostics::log( sprintf( 'Nextcloud-Konto für "%s" existiert bereits.', $nc_username ), 'debug' );
		}

		// ---------------------------------------------------
		// 2. Automatische Gruppenzuweisung
		// ---------------------------------------------------
		$groups_raw = SPS_Settings::get_setting( 'nc_sync_auto_groups', 'Hauseigner' );
		$groups = array_filter( array_map( 'trim', explode( ',', $groups_raw ) ) );

		foreach ( $groups as $group_id ) {
			$group_res = $client->add_user_to_group( $nc_username, $group_id );
			if ( ! is_wp_error( $group_res ) ) {
				$details['groups_added'][] = $group_id;
				SPS_Diagnostics::log( sprintf( 'Benutzer "%s" zu Nextcloud-Gruppe "%s" zugewiesen.', $nc_username, $group_id ), 'debug' );
			} else {
				SPS_Diagnostics::log( sprintf( 'Warnung: Zuweisung zu Gruppe "%s" fehlgeschlagen: %s', $group_id, $group_res->get_error_message() ), 'warning' );
			}
		}

		// ---------------------------------------------------
		// 3. Social-Login-Verknüpfung in Nextcloud-Datenbank
		// ---------------------------------------------------
		$social_linked = $this->link_social_login( $nc_username, $user_id );
		$details['social_linked'] = $social_linked;

		if ( $social_linked ) {
			update_user_meta( $user_id, 'nextcloud_social_linked', '1' );
			SPS_Diagnostics::log( sprintf( 'Social-Login-Verknüpfung gesetzt: uid="%s", identifier="wordpress-%d".', $nc_username, $user_id ), 'info' );
		}

		// ---------------------------------------------------
		// 4. Passwort aktiv setzen & verschlüsselt speichern
		// ---------------------------------------------------
		$sync_password = wp_generate_password( 24, true, true );
		$pwd_res = $client->set_user_password( $nc_username, $sync_password );

		if ( is_wp_error( $pwd_res ) ) {
			$msg = sprintf( 'Passwort-Aktualisierung für "%s" fehlgeschlagen: %s', $nc_username, $pwd_res->get_error_message() );
			SPS_Diagnostics::log( $msg, 'error' );
			update_user_meta( $user_id, 'nextcloud_sync_error', $msg );
			return array( 'success' => false, 'message' => $msg, 'details' => $details );
		}

		$encrypted = SPS_Settings::encrypt( $sync_password );
		if ( empty( $encrypted ) ) {
			$msg = 'Verschlüsselung des Nextcloud-Passworts fehlgeschlagen.';
			SPS_Diagnostics::log( $msg, 'error' );
			update_user_meta( $user_id, 'nextcloud_sync_error', $msg );
			return array( 'success' => false, 'message' => $msg, 'details' => $details );
		}

		update_user_meta( $user_id, 'nextcloud_username', $nc_username );
		update_user_meta( $user_id, 'nextcloud_password_enc', $encrypted );
		update_user_meta( $user_id, 'nextcloud_linked', '1' );
		update_user_meta( $user_id, 'nextcloud_synced_at', current_time( 'mysql' ) );
		delete_user_meta( $user_id, 'nextcloud_sync_error' );

		$details['password_set'] = true;

		$success_msg = sprintf( 'Benutzer "%s" (ID %d) erfolgreich mit Nextcloud synchronisiert.', $nc_username, $user_id );
		SPS_Diagnostics::log( $success_msg, 'info', $details );

		return array(
			'success' => true,
			'message' => $success_msg,
			'details' => $details,
		);
	}

	/**
	 * Stellt die direkte Social-Login-Verknüpfung in der Nextcloud-MySQL-Tabelle her.
	 * (Verknüpft WP OAuth Server - CE mit Nextcloud Social Login).
	 *
	 * @param string $nextcloud_username Nextcloud UID (E-Mail).
	 * @param int    $user_id            WordPress User ID.
	 * @return bool True bei Erfolg, false bei Fehler oder fehlender Konfiguration.
	 */
	public function link_social_login( $nextcloud_username, $user_id ) {
		$db_host = SPS_Settings::get_setting( 'nc_db_host', '' );
		$db_port = SPS_Settings::get_setting( 'nc_db_port', 3306 );
		$db_name = SPS_Settings::get_setting( 'nc_db_name', '' );
		$db_user = SPS_Settings::get_setting( 'nc_db_user', '' );
		$db_pass = SPS_Settings::get_setting( 'nc_db_password', '' );
		$prefix  = SPS_Settings::get_setting( 'nc_db_prefix', 'oc_' );

		if ( empty( $db_host ) || empty( $db_name ) || empty( $db_user ) ) {
			SPS_Diagnostics::log( 'Social-Login-Verknüpfung übersprungen: Nextcloud-DB-Zugangsdaten nicht vollständig konfiguriert.', 'warning' );
			return false;
		}

		$identifier = 'wordpress-' . $user_id;

		try {
			$dsn = sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db_host, (int) $db_port, $db_name );
			$pdo = new PDO( $dsn, $db_user, $db_pass, array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_TIMEOUT            => 5,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			) );

			$table = $prefix . 'sociallogin_connect';

			$stmt = $pdo->prepare( "INSERT INTO `{$table}` (uid, identifier) VALUES (:uid, :identifier)
				ON DUPLICATE KEY UPDATE uid = VALUES(uid)" );

			$stmt->execute( array(
				':uid'        => $nextcloud_username,
				':identifier' => $identifier,
			) );

			return true;
		} catch ( PDOException $e ) {
			SPS_Diagnostics::log( 'Fehler bei Social-Login-Verknüpfung (Nextcloud DB PDO): ' . $e->getMessage(), 'error' );
			return false;
		}
	}

	/**
	 * Testet die direkte PDO-Verbindung zur Nextcloud-Datenbank.
	 *
	 * @return array Test-Ergebnis mit Schlüsseln 'success', 'message', 'latency_ms'.
	 */
	public function test_nc_db_connection() {
		$db_host = SPS_Settings::get_setting( 'nc_db_host', '' );
		$db_port = SPS_Settings::get_setting( 'nc_db_port', 3306 );
		$db_name = SPS_Settings::get_setting( 'nc_db_name', '' );
		$db_user = SPS_Settings::get_setting( 'nc_db_user', '' );
		$db_pass = SPS_Settings::get_setting( 'nc_db_password', '' );
		$prefix  = SPS_Settings::get_setting( 'nc_db_prefix', 'oc_' );

		if ( empty( $db_host ) || empty( $db_name ) || empty( $db_user ) ) {
			return array(
				'success' => false,
				'message' => __( 'Datenbank-Host, Name oder Benutzer fehlt in den Einstellungen.', 'smart-portal-suite' ),
			);
		}

		$start_time = microtime( true );

		try {
			$dsn = sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db_host, (int) $db_port, $db_name );
			$pdo = new PDO( $dsn, $db_user, $db_pass, array(
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_TIMEOUT => 5,
			) );

			$latency_ms = round( ( microtime( true ) - $start_time ) * 1000 );

			// Prüfe Existenz der Tabelle {prefix}sociallogin_connect
			$table = $prefix . 'sociallogin_connect';
			$check_stmt = $pdo->prepare( "SHOW TABLES LIKE :table" );
			$check_stmt->execute( array( ':table' => $table ) );
			$table_found = $check_stmt->fetchColumn();

			if ( ! $table_found ) {
				return array(
					'success'    => false,
					'message'    => sprintf( __( 'Verbindung erfolgreich (%d ms), aber Tabelle "%s" wurde nicht gefunden. Bitte Tabellenpräfix prüfen oder Nextcloud Social Login App installieren.', 'smart-portal-suite' ), $latency_ms, $table ),
					'latency_ms' => $latency_ms,
				);
			}

			// Zähle bestehende Verknüpfungen
			$count_stmt = $pdo->query( "SELECT COUNT(*) FROM `{$table}` WHERE identifier LIKE 'wordpress-%'" );
			$linked_count = $count_stmt ? (int) $count_stmt->fetchColumn() : 0;

			return array(
				'success'      => true,
				'message'      => sprintf( __( 'Nextcloud-Datenbankverbindung erfolgreich hergestellt (%d ms). Tabelle "%s" existiert mit %d verknüpften WordPress-Accounts.', 'smart-portal-suite' ), $latency_ms, $table, $linked_count ),
				'latency_ms'   => $latency_ms,
				'linked_count' => $linked_count,
			);
		} catch ( PDOException $e ) {
			$latency_ms = round( ( microtime( true ) - $start_time ) * 1000 );
			return array(
				'success'    => false,
				'message'    => sprintf( __( 'Datenbank-Verbindungsfehler: %s (%d ms)', 'smart-portal-suite' ), $e->getMessage(), $latency_ms ),
				'latency_ms' => $latency_ms,
			);
		}
	}

	/**
	 * Berechnet Synchronisations-Statistiken über alle WordPress-Benutzer.
	 *
	 * @return array
	 */
	public function get_sync_statistics() {
		$user_counts = count_users();
		$total_users = isset( $user_counts['total_users'] ) ? (int) $user_counts['total_users'] : 0;

		global $wpdb;

		// Anzahl synchronisierter Benutzer
		$synced_users = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
				'nextcloud_linked',
				'1'
			)
		);

		// Anzahl Social-Login-verknüpfter Benutzer
		$social_linked_users = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
				'nextcloud_social_linked',
				'1'
			)
		);

		// Benutzer mit Fehlern
		$error_users = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
				'nextcloud_sync_error'
			)
		);

		$pending_users = max( 0, $total_users - $synced_users );

		return array(
			'total'         => $total_users,
			'synced'        => $synced_users,
			'social_linked' => $social_linked_users,
			'pending'       => $pending_users,
			'errors'        => $error_users,
		);
	}

	/**
	 * Ermittelt den Nextcloud-Sync-Status eines einzelnen Nutzers.
	 *
	 * @param int $user_id WP User ID.
	 * @return array
	 */
	public function get_user_sync_status( $user_id ) {
		$is_linked        = get_user_meta( $user_id, 'nextcloud_linked', true ) === '1';
		$is_social_linked = get_user_meta( $user_id, 'nextcloud_social_linked', true ) === '1';
		$nc_username      = get_user_meta( $user_id, 'nextcloud_username', true );
		$synced_at        = get_user_meta( $user_id, 'nextcloud_synced_at', true );
		$sync_error       = get_user_meta( $user_id, 'nextcloud_sync_error', true );

		return array(
			'is_linked'        => $is_linked,
			'is_social_linked' => $is_social_linked,
			'nc_username'      => $nc_username,
			'synced_at'        => $synced_at,
			'sync_error'       => $sync_error,
		);
	}
}
