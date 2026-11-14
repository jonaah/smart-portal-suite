<?php
/**
 * Austauschordner zwischen Hauseigentümer (HE) und Energieberater (EE).
 *
 * Nach einer Formularübermittlung (Schema-Option `submit.exchange_folder`) entsteht
 * je Objekt ein Projektordner nach der Namenskonvention der Projekte
 * (gleiches Schema wie die Deck-Boards, vgl. parseProjectName in der Sanierungs-App):
 *
 *   <exchange_base_dir>/<E-Mail>_<PLZ>_<Ort>_<Straße>_<Hausnr>
 *   z. B. Projekte/max.muster@example.org_12345_Musterstadt_Musterweg_1
 *
 * Empfohlene Einrichtung (einmalig je Energieberater): Der Berater teilt seinen
 * Ordner „Projekte“ mit dem Service-Account (Bearbeiten + Teilen). Dann legt das
 * Plugin die Objektordner direkt im „Projekte“-Ordner des Beraters an – Eigentümer
 * ist der Berater, vorhandene Projektordner (auch mit Zusatz _Maßnahme_Programm)
 * werden wiederverwendet. Freigegeben wird der Objektordner für den Kunden erst,
 * wenn der Steckbrief darin liegt – durch energy_check, nur lesend (Filter
 * sps_exchange_share_customer: Plugin gibt selbst frei). Mehr Rechte vergibt der Berater.
 *
 * Ohne diese Freigabe legt das Plugin „Projekte“ im Home des Service-Accounts an und
 * teilt den Ordner einmal als Ganzes mit dem Berater (alle Rechte) – auch dann sieht
 * der Berater alle Projektordner unter „Projekte“, ohne Einrichtung. Ist der Berater
 * selbst der Service-Account, entfällt die Freigabe. `wp sps doctor` meldet den Fall.
 *
 * Berater = Nextcloud-Konto zur „Admin Benachrichtigungs-E-Mail“ oder fest über
 * „Berater-Konto“ (`exchange_advisor_user`).
 *
 * Ablauf asynchron (WP-Cron). Stündlich werden offene Ordner nachgeholt und
 * bestehende geprüft (fehlende Ordner/Freigaben werden wieder angelegt, nichts gelöscht).
 *
 * User-Meta: sps_exchange_objects (Liste der Ordnernamen), sps_exchange_folders
 *            (Name => Pfad), sps_exchange_status (ok|pending|error),
 *            sps_exchange_message, sps_exchange_checked_at.
 *
 * @package SmartPortalSuite\Nextcloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_NC_Exchange {

	const CRON_SINGLE = 'sps_exchange_ensure';
	const CRON_HOURLY = 'sps_exchange_maintenance';

	/**
	 * Freigaberechte: 1 lesen + 2 ändern + 4 anlegen + 8 löschen (+ 16 teilen).
	 *
	 * Kunde: nur lesen. Den Projektordner gibt energy_check frei, sobald der Steckbrief
	 * darin liegt (sonst kommt die Freigabe-Mail zu einem leeren Ordner). Weitere Rechte
	 * vergibt der Energieberater gezielt von Hand.
	 */
	const PERM_CUSTOMER = 1;
	const PERM_ADVISOR  = 31;

	const VERIFY_BATCH = 20;

	const DEFAULT_BASE = 'Projekte';

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'sps_submission_completed', array( __CLASS__, 'on_submission' ), 10, 5 );
		add_action( self::CRON_SINGLE, array( __CLASS__, 'cron_single' ) );
		add_action( self::CRON_HOURLY, array( __CLASS__, 'cron_hourly' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOURLY ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON_HOURLY );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOURLY );
		wp_clear_scheduled_hook( self::CRON_SINGLE );
	}

	/**
	 * @return bool Funktion global eingeschaltet?
	 */
	public static function is_enabled() {
		return (bool) (int) SPS_Settings::get_setting( 'exchange_enabled', 1 );
	}

	/**
	 * @return string Basisordner (Standard „Projekte“).
	 */
	public static function base_dir() {
		$base = trim( (string) SPS_Settings::get_setting( 'exchange_base_dir', self::DEFAULT_BASE ), '/' );
		return '' !== $base ? $base : self::DEFAULT_BASE;
	}

	/**
	 * Legt dieses Formular einen Austauschordner an?
	 *
	 * @param array $schema Formular-Schema.
	 * @return bool
	 */
	public static function form_wants_folder( array $schema ) {
		if ( isset( $schema['submit']['exchange_folder'] ) ) {
			$wants = ! empty( $schema['submit']['exchange_folder'] );
		} else {
			// Importierte Schemas ohne die Option: Gebäude-Check standardmäßig an.
			$form_id = isset( $schema['form_id'] ) ? str_replace( '-', '_', sanitize_key( $schema['form_id'] ) ) : '';
			$wants   = in_array( $form_id, (array) apply_filters( 'sps_exchange_default_forms', array( 'gebaeude_check' ) ), true );
		}
		return (bool) apply_filters( 'sps_exchange_enabled', $wants && self::is_enabled(), $schema );
	}

	/* ------------------------------------------------------------------ Namen */

	/**
	 * Ein Namensteil: "_" trennt die Teile, daher ersetzt; Leerzeichen/Umlaute bleiben.
	 *
	 * @param mixed $value Wert.
	 * @return string
	 */
	public static function name_part( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = preg_replace( '/[_\/\\\\:*?"<>|\x00-\x1F]+/u', ' ', $value );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Ordnername nach der Projekt-Konvention: E-Mail_PLZ_Ort_Straße_Hausnr.
	 * Ohne vollständige Adresse nur die E-Mail.
	 *
	 * @param string $email   E-Mail des Kunden.
	 * @param array  $answers Aufbereitete Antworten (plz, ort, strasse, hausnummer).
	 * @return string
	 */
	public static function object_name( $email, array $answers = array() ) {
		$parts = array( strtolower( self::name_part( $email ) ) );
		$addr  = array();
		foreach ( array( 'plz', 'ort', 'strasse', 'hausnummer' ) as $key ) {
			$addr[] = isset( $answers[ $key ] ) ? self::name_part( $answers[ $key ] ) : '';
		}
		if ( '' !== $addr[0] && '' !== $addr[1] && '' !== $addr[2] ) {
			$parts = array_merge( $parts, array_filter( $addr, 'strlen' ) );
		}
		return (string) apply_filters( 'sps_exchange_folder_name', implode( '_', $parts ), $email, $answers );
	}

	/* ------------------------------------------------------------------ Ablauf */

	/**
	 * Nach jeder erfolgreichen Übermittlung (Forms oder WebDAV-Fallback).
	 *
	 * @param string $form_id Formular.
	 * @param int    $user_id WP-Benutzer.
	 * @param string $status  forms|fallback.
	 * @param array  $schema  Schema.
	 * @param array  $answers Aufbereitete Antworten.
	 */
	public static function on_submission( $form_id, $user_id, $status, $schema, $answers = array() ) {
		$user_id = (int) $user_id;
		if ( ! is_array( $schema ) || ! self::form_wants_folder( $schema ) ) {
			return;
		}
		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			SPS_Diagnostics::log( sprintf( 'Austauschordner: "%s" ohne Login abgesendet – kein Ordner angelegt.', $form_id ), 'info' );
			return;
		}
		$name    = self::object_name( $user->user_email ? $user->user_email : $user->user_login, is_array( $answers ) ? $answers : array() );
		$objects = self::objects( $user_id );
		if ( ! in_array( $name, $objects, true ) ) {
			$objects[] = $name;
			update_user_meta( $user_id, 'sps_exchange_objects', $objects );
		}
		update_user_meta( $user_id, 'sps_exchange_status', 'pending' );
		if ( ! wp_next_scheduled( self::CRON_SINGLE, array( $user_id ) ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_SINGLE, array( $user_id ) );
		}
	}

	/**
	 * @param int $user_id WP-Benutzer.
	 * @return string[] Ordnernamen (ohne Basisordner).
	 */
	public static function objects( $user_id ) {
		$list = get_user_meta( $user_id, 'sps_exchange_objects', true );
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ), 'strlen' ) ) : array();
	}

	public static function cron_single( $user_id ) {
		self::ensure( (int) $user_id );
	}

	public static function cron_hourly() {
		if ( ! self::is_enabled() || ! SPS_NC_Client::service()->is_configured() ) {
			return;
		}
		foreach ( self::users_by_status( array( 'pending', 'error' ), 50 ) as $uid ) {
			self::ensure( $uid );
		}
		foreach ( self::users_to_verify( self::VERIFY_BATCH ) as $uid ) {
			self::ensure( $uid );
		}
	}

	/**
	 * @param string[] $statuses Status.
	 * @param int      $limit    Anzahl.
	 * @return int[]
	 */
	public static function users_by_status( array $statuses, $limit = 50 ) {
		return array_map(
			'intval',
			get_users(
				array(
					'fields'     => 'ID',
					'number'     => (int) $limit,
					'meta_query' => array(
						array(
							'key'     => 'sps_exchange_status',
							'value'   => $statuses,
							'compare' => 'IN',
						),
					),
				)
			)
		);
	}

	protected static function users_to_verify( $limit ) {
		return array_map(
			'intval',
			get_users(
				array(
					'fields'     => 'ID',
					'number'     => (int) $limit,
					'meta_key'   => 'sps_exchange_checked_at', // phpcs:ignore WordPress.DB.SlowDBQuery
					'orderby'    => 'meta_value_num',
					'order'      => 'ASC',
					'meta_query' => array(
						array(
							'key'   => 'sps_exchange_status',
							'value' => 'ok',
						),
					),
				)
			)
		);
	}

	/**
	 * Eigentümer des Basisordners und ob er dem Berater gehört.
	 *
	 * @param SPS_NC_Client $service Service-Account.
	 * @param string        $advisor Berater-Konto.
	 * @return array|WP_Error array( 'exists' => bool, 'owner' => string, 'advisor_owns' => bool )
	 */
	public static function base_info( SPS_NC_Client $service, $advisor ) {
		$base = self::base_dir();
		$res  = $service->request(
			self::dav( $service, $base ),
			'PROPFIND',
			'<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:owner-id/></d:prop></d:propfind>',
			array( 'Depth' => '0', 'Content-Type' => 'application/xml' )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code ) {
			return array( 'exists' => false, 'owner' => '', 'advisor_owns' => false );
		}
		if ( 207 !== $code ) {
			return new WP_Error( 'sps_exchange_base', sprintf( 'PROPFIND %s: HTTP %d', $base, $code ) );
		}
		$owner = preg_match( '#<oc:owner-id>(.*?)</oc:owner-id>#s', wp_remote_retrieve_body( $res ), $m ) ? html_entity_decode( trim( $m[1] ) ) : '';
		return array(
			'exists'       => true,
			'owner'        => $owner,
			'advisor_owns' => '' !== $owner && strtolower( $owner ) === strtolower( $advisor ),
		);
	}

	/**
	 * Wie der Berater den Basisordner sieht, wenn der Service-Account ihn mit ihm teilt.
	 *
	 * @param SPS_NC_Client $service Service-Account.
	 * @param string        $advisor Berater.
	 * @return string Zielpfad beim Berater ('' = nicht geteilt), z. B. "/Projekte" oder "/Projekte (2)".
	 */
	public static function base_share_target( SPS_NC_Client $service, $advisor ) {
		$res = SPS_NC_Client::parse_ocs( $service->request( 'ocs/v2.php/apps/files_sharing/api/v1/shares?format=json&path=' . rawurlencode( '/' . self::base_dir() ) ) );
		if ( is_wp_error( $res ) ) {
			return '';
		}
		foreach ( (array) $res as $share ) {
			if ( 0 === (int) ( isset( $share['share_type'] ) ? $share['share_type'] : -1 ) && strtolower( (string) $share['share_with'] ) === strtolower( $advisor ) ) {
				return (string) ( isset( $share['file_target'] ) ? $share['file_target'] : '' );
			}
		}
		return '';
	}

	/**
	 * Vorhandenen Projektordner suchen: gleicher Name oder Name + "_Zusatz"
	 * (z. B. _Maßnahme_Programm aus der Projektanlage). Groß/klein egal.
	 *
	 * @param string[] $existing Ordnernamen im Basisordner.
	 * @param string   $name     Gesuchter Name.
	 * @return string '' = nicht gefunden.
	 */
	public static function match_existing( array $existing, $name ) {
		$needle = mb_strtolower( $name );
		$hits   = array();
		foreach ( $existing as $dir ) {
			$low = mb_strtolower( $dir );
			if ( $low === $needle ) {
				return $dir;
			}
			if ( 0 === strpos( $low, $needle . '_' ) ) {
				$hits[] = $dir;
			}
		}
		sort( $hits );
		return $hits ? $hits[0] : '';
	}

	/**
	 * Ordner anlegen bzw. prüfen und freigeben (idempotent).
	 *
	 * @param int  $user_id WP-Benutzer.
	 * @param bool $dry_run Nur ermitteln, nichts ändern.
	 * @return array|WP_Error array( 'folders' => string[], 'customer', 'advisor', 'mode', 'actions' => string[] )
	 */
	public static function ensure( $user_id, $dry_run = false ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new WP_Error( 'sps_exchange_user', 'WordPress-Benutzer nicht gefunden.' );
		}

		$service = SPS_NC_Client::service();
		if ( ! $service->is_configured() ) {
			return self::fail( $user_id, 'Nextcloud-Service-Account ist nicht konfiguriert.', $dry_run, 'pending' );
		}

		$customer = (string) get_user_meta( $user_id, 'nextcloud_username', true );
		if ( '' === $customer ) {
			return self::fail( $user_id, 'Kunde hat noch kein Nextcloud-Konto (Account-Sync).', $dry_run, 'pending' );
		}

		$objects = self::objects( $user_id );
		if ( empty( $objects ) ) {
			$objects = array( self::object_name( $user->user_email ? $user->user_email : $user->user_login ) );
		}

		$advisor = self::resolve_advisor( $service );
		if ( is_wp_error( $advisor ) ) {
			return self::fail( $user_id, $advisor->get_error_message(), $dry_run );
		}

		$info = self::base_info( $service, $advisor );
		if ( is_wp_error( $info ) ) {
			return self::fail( $user_id, $info->get_error_message(), $dry_run );
		}

		$base    = self::base_dir();
		$service_is_advisor = in_array( strtolower( $advisor ), array( strtolower( $service->get_uid() ), strtolower( $service->get_username() ) ), true );
		$result  = array(
			'folders'  => array(),
			'customer' => $customer,
			'advisor'  => $advisor,
			'mode'     => $info['advisor_owns'] || $service_is_advisor ? 'berater' : 'service',
			'actions'  => array(),
		);

		$webdav = new SPS_NC_WebDAV( $service );
		if ( ! $info['exists'] ) {
			$result['actions'][] = 'Basisordner ' . $base . ' anlegen (Service-Account)';
			if ( ! $dry_run ) {
				$ok = $webdav->ensure_dir( $base );
				if ( is_wp_error( $ok ) ) {
					return self::fail( $user_id, $ok->get_error_message(), $dry_run );
				}
			}
		}
		// Basisordner gehört dem Service-Account: einmal als Ganzes mit dem Berater teilen,
		// dann sieht er alle Projektordner unter „Projekte“ (keine Einzelfreigaben je Objekt).
		if ( 'service' === $result['mode'] ) {
			$base_shares = $info['exists'] ? self::list_shares( $service, $base ) : array();
			if ( is_wp_error( $base_shares ) ) {
				return self::fail( $user_id, $base_shares->get_error_message(), $dry_run );
			}
			if ( ! in_array( strtolower( $advisor ), $base_shares, true ) ) {
				$result['actions'][] = 'Basisordner ' . $base . ' für Berater ' . $advisor . ' freigeben';
				if ( ! $dry_run ) {
					$ok = self::create_share( $service, $base, $advisor, self::PERM_ADVISOR );
					if ( is_wp_error( $ok ) ) {
						return self::fail( $user_id, sprintf( 'Freigabe %s für %s: %s', $base, $advisor, $ok->get_error_message() ), $dry_run );
					}
				}
			}
		}

		$existing = $info['exists'] ? $webdav->list_dir( $base ) : array();
		if ( is_wp_error( $existing ) ) {
			return self::fail( $user_id, $existing->get_error_message(), $dry_run );
		}
		$existing = wp_list_pluck( array_filter( $existing, function ( $e ) { return ! empty( $e['is_dir'] ); } ), 'name' );

		$paths = get_user_meta( $user_id, 'sps_exchange_folders', true );
		$paths = is_array( $paths ) ? $paths : array();

		foreach ( $objects as $name ) {
			$found = self::match_existing( $existing, $name );
			$path  = $base . '/' . ( '' !== $found ? $found : $name );
			if ( '' === $found ) {
				$result['actions'][] = 'Ordner ' . $path . ' anlegen';
				if ( ! $dry_run ) {
					$ok = $webdav->ensure_dir( $path );
					if ( is_wp_error( $ok ) ) {
						return self::fail( $user_id, $ok->get_error_message(), $dry_run );
					}
					foreach ( (array) apply_filters( 'sps_exchange_subfolders', array(), $user ) as $sub ) {
						$sub = trim( (string) $sub, '/' );
						if ( '' !== $sub ) {
							$webdav->ensure_dir( $path . '/' . $sub );
						}
					}
				}
			}

			$shares = '' !== $found ? self::list_shares( $service, $path ) : array();
			if ( is_wp_error( $shares ) ) {
				return self::fail( $user_id, $shares->get_error_message(), $dry_run );
			}

			// Kundenfreigabe übernimmt energy_check nach dem Ablegen des Steckbriefs.
			// Mit dem Filter sps_exchange_share_customer (true) gibt das Plugin selbst sofort frei.
			$targets = self::share_customer( $user ) ? array( $customer => self::PERM_CUSTOMER ) : array();
			foreach ( $targets as $uid => $perm ) {
				$low = strtolower( $uid );
				if ( in_array( $low, $shares, true ) || $low === strtolower( $service->get_uid() ) || $low === strtolower( $service->get_username() ) || ( $info['owner'] && $low === strtolower( $info['owner'] ) ) ) {
					continue;
				}
				$result['actions'][] = 'freigeben für ' . $uid;
				if ( $dry_run ) {
					continue;
				}
				$ok = self::create_share( $service, $path, $uid, $perm );
				if ( is_wp_error( $ok ) ) {
					return self::fail( $user_id, sprintf( 'Freigabe %s für %s: %s', $path, $uid, $ok->get_error_message() ), $dry_run );
				}
			}
			$paths[ $name ]      = $path;
			$result['folders'][] = $path;
		}

		if ( ! $dry_run ) {
			update_user_meta( $user_id, 'sps_exchange_folders', $paths );
			update_user_meta( $user_id, 'sps_exchange_status', 'ok' );
			update_user_meta( $user_id, 'sps_exchange_message', empty( $result['actions'] ) ? 'geprüft' : implode( ', ', $result['actions'] ) );
			update_user_meta( $user_id, 'sps_exchange_checked_at', time() );
			if ( ! empty( $result['actions'] ) ) {
				SPS_Diagnostics::log( sprintf( 'Austauschordner (%s): %s.', implode( ', ', $result['folders'] ), implode( ', ', $result['actions'] ) ), 'info' );
			}
		}

		return $result;
	}

	protected static function fail( $user_id, $message, $dry_run, $status = 'error' ) {
		if ( ! $dry_run ) {
			update_user_meta( $user_id, 'sps_exchange_status', $status );
			update_user_meta( $user_id, 'sps_exchange_message', $message );
			update_user_meta( $user_id, 'sps_exchange_checked_at', time() );
			SPS_Diagnostics::log( sprintf( 'Austauschordner für Benutzer %d: %s', $user_id, $message ), 'pending' === $status ? 'info' : 'warning' );
		}
		return new WP_Error( 'sps_exchange_' . $status, $message );
	}

	/* ------------------------------------------------------------------ Berater */

	/**
	 * Nextcloud-Konto des Energieberaters.
	 *
	 * @param SPS_NC_Client $service Service-Account.
	 * @return string|WP_Error
	 */
	public static function resolve_advisor( SPS_NC_Client $service ) {
		$fixed = trim( (string) SPS_Settings::get_setting( 'exchange_advisor_user', '' ) );
		if ( '' !== $fixed ) {
			return $fixed;
		}

		$email = strtolower( trim( (string) SPS_Settings::get_setting( 'admin_email', get_option( 'admin_email' ) ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'sps_exchange_advisor', 'Keine gültige Admin-Benachrichtigungs-E-Mail hinterlegt.' );
		}

		$cache_key = 'sps_exchange_advisor_' . md5( $email . '|' . $service->get_base_url() );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$uid = self::find_user_by_email( $service, $email );
		if ( '' === $uid ) {
			return new WP_Error(
				'sps_exchange_advisor',
				sprintf( 'Kein eindeutiges Nextcloud-Konto mit der E-Mail %s gefunden. Konto-E-Mail in Nextcloud prüfen oder „Berater-Konto“ in den Einstellungen setzen.', $email )
			);
		}
		set_transient( $cache_key, $uid, 12 * HOUR_IN_SECONDS );
		return $uid;
	}

	protected static function find_user_by_email( SPS_NC_Client $service, $email ) {
		$list = SPS_NC_Client::parse_ocs( $service->request( 'ocs/v2.php/cloud/users/details?format=json&limit=10&search=' . rawurlencode( $email ) ) );
		if ( ! is_wp_error( $list ) && ! empty( $list['users'] ) && is_array( $list['users'] ) ) {
			$hits = array();
			foreach ( $list['users'] as $key => $info ) {
				$mail = isset( $info['email'] ) ? strtolower( (string) $info['email'] ) : '';
				if ( $mail === $email ) {
					$hits[] = isset( $info['id'] ) ? (string) $info['id'] : (string) $key;
				}
			}
			if ( 1 === count( $hits ) ) {
				return $hits[0];
			}
		}

		$res = SPS_NC_Client::parse_ocs( $service->request( 'ocs/v2.php/apps/files_sharing/api/v1/sharees?format=json&itemType=folder&shareType[]=0&perPage=10&search=' . rawurlencode( $email ) ) );
		if ( ! is_wp_error( $res ) && ! empty( $res['exact']['users'] ) && 1 === count( $res['exact']['users'] ) ) {
			$hit = $res['exact']['users'][0];
			if ( ! empty( $hit['value']['shareWith'] ) ) {
				return (string) $hit['value']['shareWith'];
			}
		}

		return '';
	}

	/* ------------------------------------------------------------------ Nextcloud */

	protected static function dav( SPS_NC_Client $service, $relative ) {
		$segments = array_filter( explode( '/', trim( $relative, '/' ) ), 'strlen' );
		return 'remote.php/dav/files/' . rawurlencode( $service->get_uid() ) . '/' . implode( '/', array_map( 'rawurlencode', $segments ) );
	}

	/**
	 * @return string[]|WP_Error Empfänger (klein), mit denen der Ordner geteilt ist.
	 */
	protected static function list_shares( SPS_NC_Client $service, $folder ) {
		$res = SPS_NC_Client::parse_ocs( $service->request( 'ocs/v2.php/apps/files_sharing/api/v1/shares?format=json&reshares=true&path=' . rawurlencode( '/' . trim( $folder, '/' ) ) ) );
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			if ( isset( $data['http'] ) && 404 === (int) $data['http'] ) {
				return array();
			}
			return $res;
		}
		$out = array();
		foreach ( (array) $res as $share ) {
			if ( isset( $share['share_type'] ) && 0 === (int) $share['share_type'] && ! empty( $share['share_with'] ) ) {
				$out[] = strtolower( (string) $share['share_with'] );
			}
		}
		return $out;
	}

	/**
	 * Soll das Plugin den Projektordner selbst für den Kunden freigeben?
	 *
	 * Standard: nein – energy_check gibt ihn nach dem Ablegen des Steckbriefs nur lesend frei.
	 *
	 * @param WP_User $user Kunde.
	 * @return bool
	 */
	public static function share_customer( $user ) {
		return (bool) apply_filters( 'sps_exchange_share_customer', false, $user );
	}

	protected static function create_share( SPS_NC_Client $service, $folder, $uid, $perm ) {
		$res = SPS_NC_Client::parse_ocs(
			$service->request(
				'ocs/v2.php/apps/files_sharing/api/v1/shares?format=json',
				'POST',
				array(
					'path'        => '/' . trim( $folder, '/' ),
					'shareType'   => 0,
					'shareWith'   => $uid,
					'permissions' => (int) $perm,
				)
			)
		);
		return is_wp_error( $res ) ? $res : true;
	}
}
