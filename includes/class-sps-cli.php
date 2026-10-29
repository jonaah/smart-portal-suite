<?php
/**
 * WP-CLI: wp sps …
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Einrichtung, Prüfung und Wartung der Smart Portal Suite.
 */
class SPS_CLI {

	/**
	 * Zuordnung CLI-Option → Einstellung (Klartextwerte).
	 */
	const PLAIN_OPTIONS = array(
		'nextcloud-url'      => 'nextcloud_url',
		'service-account'    => 'service_account',
		'webdav-dir'         => 'webdav_base_dir',
		'nc-db-host'         => 'nc_db_host',
		'nc-db-port'         => 'nc_db_port',
		'nc-db-name'         => 'nc_db_name',
		'nc-db-user'         => 'nc_db_user',
		'nc-db-prefix'       => 'nc_db_prefix',
		'groups'             => 'nc_sync_auto_groups',
		'privacy-url'        => 'privacy_url',
		'admin-email'        => 'admin_email',
		'login-redirect-url' => 'login_redirect_url',
		'login-url'          => 'auth_login_url',
		'register-url'       => 'auth_register_url',
		'primary-color'      => 'primary_color',
		'submit-as-user'     => 'submit_as_user',
		'file-mode'          => 'nc_file_mode',
		'upload-dir'         => 'webdav_upload_dir',
		'debug'              => 'debug_mode',
	);

	/**
	 * Einstellungen setzen. Passwörter werden genau einmal verschlüsselt.
	 *
	 * Nicht angegebene Werte bleiben unverändert.
	 *
	 * ## OPTIONS
	 *
	 * [--nextcloud-url=<url>]
	 * : URL der Kunden-Nextcloud, z. B. https://nc.kunde.de
	 *
	 * [--service-account=<user>]
	 * : Nextcloud-Service-Account (z. B. sps_service).
	 *
	 * [--app-password=<pw>]
	 * : App-Passwort (landet in der Shell-History – besser --app-password-stdin).
	 *
	 * [--app-password-stdin]
	 * : App-Passwort aus STDIN lesen.
	 *
	 * [--nc-db-host=<host>]
	 * : Host der Nextcloud-Datenbank (Social-Login-Verknüpfung).
	 *
	 * [--nc-db-port=<port>]
	 * : Port der Nextcloud-Datenbank.
	 *
	 * [--nc-db-name=<name>]
	 * : Name der Nextcloud-Datenbank.
	 *
	 * [--nc-db-user=<user>]
	 * : Benutzer der Nextcloud-Datenbank.
	 *
	 * [--nc-db-prefix=<prefix>]
	 * : Tabellenpräfix (Standard oc_).
	 *
	 * [--nc-db-password-stdin]
	 * : DB-Passwort aus STDIN lesen (bei beiden STDIN-Optionen: erste Zeile App-Passwort, zweite Zeile DB-Passwort).
	 *
	 * [--forms=<map>]
	 * : Formular-Zuordnung, z. B. gebaeude_check=12,projekte_mit_mir=13 (ersetzt die bisherige Zuordnung).
	 *
	 * [--form-access=<map>]
	 * : Zugang je Formular: login (nur angemeldete), public (alle) oder schema, z. B. gebaeude_check=login.
	 *
	 * [--groups=<groups>]
	 * : Kundengruppen, kommagetrennt (Standard: Hauseigner).
	 *
	 * [--privacy-url=<url>]
	 * : URL der Datenschutzerklärung (leer = WP-Datenschutzseite).
	 *
	 * [--admin-email=<email>]
	 * : Empfänger der Fallback-Warnungen.
	 *
	 * [--webdav-dir=<dir>]
	 * : Fallback-Ordner im Home des Service-Accounts.
	 *
	 * [--login-redirect-url=<url>]
	 * : Ziel nach dem Login.
	 *
	 * [--login-url=<path>]
	 * : Pfad der Login-Seite.
	 *
	 * [--register-url=<path>]
	 * : Pfad der Registrierungsseite.
	 *
	 * [--primary-color=<hex>]
	 * : Primärfarbe der Formulare.
	 *
	 * [--file-mode=<mode>]
	 * : Dateien an Nextcloud: webdav (Standard) oder forms (benötigt Multipart).
	 *
	 * [--upload-dir=<dir>]
	 * : WebDAV-Ordner für Dateien (Standard SPS_Uploads).
	 *
	 * [--submit-as-user=<0|1>]
	 * : Eingeloggte Kunden senden mit eigenem NC-Konto.
	 *
	 * [--debug=<0|1>]
	 * : Debug-Log aktivieren.
	 *
	 * ## EXAMPLES
	 *
	 *     printf '%s\n' "$NC_APP_PW" | wp sps configure --nextcloud-url=https://nc.kunde.de \
	 *       --service-account=sps_service --app-password-stdin --forms=gebaeude_check=12
	 *
	 * @param array $args       Positionsargumente.
	 * @param array $assoc_args Optionen.
	 */
	public function configure( $args, $assoc_args ) {
		$current = get_option( SPS_Settings::OPTION_NAME, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		// Ausgangsbasis: aktuelle Werte, Passwörter leer (= unverändert behalten).
		$input                   = $current;
		$input['app_password']   = '';
		$input['nc_db_password'] = '';
		$input['nc_form_ids']    = SPS_Settings::get_form_id_map();
		$input['submit_as_user'] = (int) SPS_Settings::get_setting( 'submit_as_user', 1 );

		$changed = array();
		foreach ( self::PLAIN_OPTIONS as $opt => $key ) {
			if ( isset( $assoc_args[ $opt ] ) ) {
				$input[ $key ] = $assoc_args[ $opt ];
				$changed[]     = $key;
			}
		}

		$stdin_lines = array();
		if ( ! empty( $assoc_args['app-password-stdin'] ) || ! empty( $assoc_args['nc-db-password-stdin'] ) ) {
			$stdin_lines = array_map( 'trim', explode( "\n", (string) stream_get_contents( STDIN ) ) );
		}

		if ( ! empty( $assoc_args['app-password-stdin'] ) ) {
			$pw = array_shift( $stdin_lines );
			if ( '' === (string) $pw ) {
				WP_CLI::error( 'Kein App-Passwort auf STDIN.' );
			}
			$input['app_password'] = $pw;
			$changed[]             = 'app_password';
		} elseif ( isset( $assoc_args['app-password'] ) ) {
			$input['app_password'] = $assoc_args['app-password'];
			$changed[]             = 'app_password';
		}

		if ( ! empty( $assoc_args['nc-db-password-stdin'] ) ) {
			$pw = array_shift( $stdin_lines );
			if ( '' === (string) $pw ) {
				WP_CLI::error( 'Kein DB-Passwort auf STDIN.' );
			}
			$input['nc_db_password'] = $pw;
			$changed[]               = 'nc_db_password';
		}

		if ( isset( $assoc_args['forms'] ) ) {
			$map = SPS_Settings::parse_form_id_map( $assoc_args['forms'] );
			if ( empty( $map ) && '' !== trim( (string) $assoc_args['forms'] ) ) {
				WP_CLI::error( 'Ungültiges Format für --forms. Beispiel: gebaeude_check=12' );
			}
			$input['nc_form_ids'] = $map;
			$changed[]            = 'nc_form_ids';
			foreach ( $map as $id ) {
				SPS_NC_Forms::flush_cache( $id );
			}
		}

		if ( isset( $assoc_args['form-access'] ) ) {
			$done = array();
			foreach ( preg_split( '/[\r\n,;]+/', (string) $assoc_args['form-access'] ) as $pair ) {
				$parts = array_map( 'trim', explode( '=', $pair, 2 ) );
				if ( 2 !== count( $parts ) || '' === sanitize_key( $parts[0] ) || ! in_array( $parts[1], array( 'login', 'public', 'schema', '' ), true ) ) {
					WP_CLI::error( 'Ungültiges Format für --form-access. Beispiel: gebaeude_check=login' );
				}
				$fid      = sanitize_key( $parts[0] );
				$existing = SPS_Form_Renderer::get_access_settings( $fid );
				SPS_Form_Renderer::save_access_settings( $fid, 'schema' === $parts[1] ? '' : $parts[1], $existing['gate_text'] );
				$done[] = $fid . '=' . ( $parts[1] ? $parts[1] : 'schema' );
			}
			if ( empty( $changed ) ) {
				WP_CLI::success( 'Zugang gespeichert: ' . implode( ', ', $done ) . ' (Seiten-Cache leeren!)' );
				return;
			}
			WP_CLI::log( 'Zugang gespeichert: ' . implode( ', ', $done ) );
		}

		if ( empty( $changed ) ) {
			WP_CLI::warning( 'Keine Option angegeben – nichts geändert.' );
			return;
		}

		// Validierung + Verschlüsselung genau einmal. In WP-CLI ist der Sanitize-Callback
		// nicht registriert (admin_init); in anderen Kontexten wird er hier ausgehängt,
		// damit Passwörter nicht doppelt verschlüsselt werden.
		$sanitized = SPS_Settings::get_instance()->sanitize_settings( $input );
		remove_all_filters( 'sanitize_option_' . SPS_Settings::OPTION_NAME );
		update_option( SPS_Settings::OPTION_NAME, $sanitized );

		// Kontrolle: Passwörter entschlüsselbar?
		if ( in_array( 'app_password', $changed, true ) && '' === (string) SPS_Settings::get_setting( 'app_password', '' ) ) {
			WP_CLI::error( 'App-Passwort wurde gespeichert, ist aber nicht entschlüsselbar.' );
		}

		WP_CLI::success( 'Gespeichert: ' . implode( ', ', array_unique( $changed ) ) );
	}

	/**
	 * Gesamte Anbindung prüfen. Exit-Code 1 bei Fehlern.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json oder yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--offline]
	 * : Nur Konfiguration und WordPress prüfen, keine Nextcloud-Anfragen.
	 *
	 * [--fix]
	 * : Fehlenden WebDAV-Fallback-Ordner anlegen.
	 *
	 * [--strict]
	 * : Warnungen ebenfalls als Fehler werten.
	 *
	 * @param array $args       Positionsargumente.
	 * @param array $assoc_args Optionen.
	 */
	public function doctor( $args, $assoc_args ) {
		$doctor  = new SPS_Doctor( ! empty( $assoc_args['fix'] ) );
		$results = $doctor->run( empty( $assoc_args['offline'] ) );
		$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$rows = array();
		foreach ( $results as $r ) {
			$rows[] = array(
				'Status'  => 'table' === $format ? self::badge( $r['status'] ) : $r['status'],
				'Prüfung' => $r['label'],
				'Ergebnis' => $r['message'],
			);
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'Status', 'Prüfung', 'Ergebnis' ) );

		$errors = 0;
		$warns  = 0;
		foreach ( $results as $r ) {
			if ( 'error' === $r['status'] ) {
				$errors++;
			} elseif ( 'warn' === $r['status'] ) {
				$warns++;
			}
		}

		if ( $errors || ( $warns && ! empty( $assoc_args['strict'] ) ) ) {
			WP_CLI::error( sprintf( '%d Fehler, %d Warnungen.', $errors, $warns ) );
		}
		WP_CLI::success( sprintf( 'Keine Fehler, %d Warnungen.', $warns ) );
	}

	/**
	 * Zuordnung SPS-Formular → Nextcloud-Fragen anzeigen.
	 *
	 * ## OPTIONS
	 *
	 * <form_id>
	 * : SPS-Formular-ID, z. B. gebaeude_check
	 *
	 * [--format=<format>]
	 * : table, json, csv oder yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positionsargumente.
	 * @param array $assoc_args Optionen.
	 */
	public function mapping( $args, $assoc_args ) {
		$schema = SPS_Form_Renderer::get_instance()->load_schema( sanitize_key( $args[0] ) );
		if ( ! $schema ) {
			WP_CLI::error( 'Schema nicht gefunden: ' . $args[0] );
		}

		$forms = new SPS_NC_Forms();
		$id    = $forms->resolve_form_id( $schema );
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		$questions = $forms->get_questions( $id, false );
		if ( is_wp_error( $questions ) ) {
			WP_CLI::error( $questions->get_error_message() );
		}

		WP_CLI::log( sprintf( 'Nextcloud-Formular %d, %d Fragen', $id, count( $questions ) ) );
		$rows = SPS_Doctor::mapping_report( $schema, $questions );
		WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'id', 'name', 'type', 'required', 'source', 'status' )
		);

		$used   = array();
		foreach ( $rows as $r ) {
			foreach ( explode( '+', $r['source'] ) as $s ) {
				$used[ $s ] = true;
			}
		}
		$unused = array();
		foreach ( SPS_Doctor::available_keys( $schema ) as $k ) {
			if ( ! isset( $used[ $k ] ) ) {
				$unused[] = $k;
			}
		}
		if ( ! empty( $unused ) ) {
			WP_CLI::log( 'Antworten ohne NC-Frage (werden nicht übertragen): ' . implode( ', ', $unused ) );
		}
	}

	/**
	 * Testübertragung mit Beispieldaten (legt eine echte Antwort in Nextcloud an!).
	 *
	 * ## OPTIONS
	 *
	 * <form_id>
	 * : SPS-Formular-ID.
	 *
	 * --answers=<json>
	 * : Antworten als JSON, z. B. '{"typ":"Reihenmittelhaus","E-Mail":"test@example.com"}'
	 *
	 * [--as=<id|email>]
	 * : Als dieser WP-Benutzer (Kunde) senden.
	 *
	 * [--dry-run]
	 * : Nur aufbereitete Antworten und Zuordnung anzeigen.
	 *
	 * @subcommand test-submit
	 *
	 * @param array $args       Positionsargumente.
	 * @param array $assoc_args Optionen.
	 */
	public function test_submit( $args, $assoc_args ) {
		$schema = SPS_Form_Renderer::get_instance()->load_schema( sanitize_key( $args[0] ) );
		if ( ! $schema ) {
			WP_CLI::error( 'Schema nicht gefunden: ' . $args[0] );
		}
		$answers = json_decode( (string) $assoc_args['answers'], true );
		if ( ! is_array( $answers ) ) {
			WP_CLI::error( '--answers ist kein gültiges JSON-Objekt.' );
		}

		$user_id = 0;
		if ( ! empty( $assoc_args['as'] ) ) {
			$user = is_numeric( $assoc_args['as'] ) ? get_userdata( (int) $assoc_args['as'] ) : get_user_by( 'email', $assoc_args['as'] );
			if ( ! $user ) {
				WP_CLI::error( 'Benutzer nicht gefunden.' );
			}
			$user_id = $user->ID;
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$prepared = SPS_Answer_Transformer::apply( $answers, $schema );
			WP_CLI::log( 'Aufbereitete Antworten:' );
			WP_CLI::log( wp_json_encode( $prepared, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			$forms = new SPS_NC_Forms();
			$id    = $forms->resolve_form_id( $schema );
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( $id->get_error_message() );
			}
			$questions = $forms->get_questions( $id, false );
			if ( is_wp_error( $questions ) ) {
				WP_CLI::error( $questions->get_error_message() );
			}
			$built = $forms->build_submission( $questions, $prepared, $schema );
			WP_CLI\Utils\format_items( 'table', $built['report'], array( 'id', 'name', 'type', 'source', 'status', 'value' ) );
			foreach ( $built['warnings'] as $w ) {
				WP_CLI::warning( $w );
			}
			return;
		}

		$result = SPS_Submission::process( $schema, $answers, array(), $user_id, array( 'test' => true ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( 'forms' === $result['status'] ) {
			WP_CLI::success( 'An Nextcloud Forms übertragen (Formular ' . $result['nc_form_id'] . '). Testantwort in Nextcloud löschen.' );
		} else {
			WP_CLI::warning( 'Forms abgelehnt – Lead im Fallback gespeichert: ' . $result['path'] . ' (Grund im Debug-Log).' );
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Einen WP-Benutzer mit Nextcloud synchronisieren.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : WP-Benutzer-ID oder E-Mail.
	 *
	 * [--force]
	 * : NC-Passwort neu setzen.
	 *
	 * @subcommand sync-user
	 *
	 * @param array $args       Positionsargumente.
	 * @param array $assoc_args Optionen.
	 */
	public function sync_user( $args, $assoc_args ) {
		$user = is_numeric( $args[0] ) ? get_userdata( (int) $args[0] ) : get_user_by( 'email', $args[0] );
		if ( ! $user ) {
			WP_CLI::error( 'Benutzer nicht gefunden.' );
		}
		$res = SPS_NC_User_Sync::get_instance()->sync_user( $user->ID, ! empty( $assoc_args['force'] ) );
		if ( empty( $res['success'] ) ) {
			WP_CLI::error( $res['message'] );
		}
		WP_CLI::success( $res['message'] );
	}

	/**
	 * Fallback-Leads erneut an Nextcloud Forms übertragen.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximale Anzahl.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--dry-run]
	 * : Nur offene Leads anzeigen.
	 *
	 * @subcommand retry-leads
	 *
	 * @param array $args       Positionsargumente.
	 * @param array $assoc_args Optionen.
	 */
	public function retry_leads( $args, $assoc_args ) {
		$report = SPS_Submission::retry_pending( (int) $assoc_args['limit'], ! empty( $assoc_args['dry-run'] ) );
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
		}
		if ( empty( $report ) ) {
			WP_CLI::success( 'Keine offenen Leads.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $report, array( 'lead', 'status', 'message' ) );
		foreach ( $report as $r ) {
			if ( 'fehler' === $r['status'] ) {
				WP_CLI::halt( 1 );
			}
		}
	}

	/**
	 * Status als Text.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	protected static function badge( $status ) {
		$map = array(
			'ok'    => 'OK',
			'warn'  => 'WARNUNG',
			'error' => 'FEHLER',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}
}

WP_CLI::add_command( 'sps', 'SPS_CLI' );
