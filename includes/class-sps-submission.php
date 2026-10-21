<?php
/**
 * Übertragungsablauf: Antworten aufbereiten → Nextcloud Forms → sonst WebDAV-Fallback.
 * Außerdem: Nachreichen von Fallback-Leads (WP-Cron + `wp sps retry-leads`).
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Submission {

	/**
	 * Cron-Hook für das Nachreichen.
	 */
	const CRON_HOOK = 'sps_retry_fallback_leads';

	/**
	 * HTTP-Codes, bei denen eine Benutzer-Submission mit dem Service-Account wiederholt wird.
	 */
	const USER_RETRY_CODES = array( 401, 403, 404 );

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_retry' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Stündliches Nachreichen einplanen.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Planung entfernen (Deaktivierung).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Cron-Callback.
	 */
	public static function cron_retry() {
		$cfg = SPS_NC_Client::service();
		if ( ! $cfg->is_configured() ) {
			return;
		}
		self::retry_pending( 10 );
	}

	/**
	 * Komplette Verarbeitung einer Formularübermittlung.
	 *
	 * @param array $schema  Formular-Schema.
	 * @param array $answers Bereinigte Antworten.
	 * @param array $files   feld => Liste aus array( 'tmp_name', 'name', 'type', 'size' ).
	 * @param int   $user_id WP-Benutzer (0 = Besucher).
	 * @param array $meta    Zusatzdaten für den Lead (z. B. Dateibeschreibungen).
	 * @return array|WP_Error array( 'status' => 'forms'|'fallback', ... )
	 */
	public static function process( array $schema, array $answers, array $files, $user_id = 0, array $meta = array() ) {
		$form_id  = isset( $schema['form_id'] ) ? sanitize_key( $schema['form_id'] ) : 'form';
		$prepared = SPS_Answer_Transformer::apply( $answers, $schema );

		// Interne Zusatzdaten (nicht als Antwort übertragen, aber im Fallback-Lead erhalten).
		$owner = $user_id ? get_userdata( $user_id ) : null;
		$prepared['_sps_user_email'] = $owner ? $owner->user_email : '';
		if ( ! empty( $meta['file_descriptions'] ) && is_array( $meta['file_descriptions'] ) ) {
			$descs = array();
			foreach ( $meta['file_descriptions'] as $field => $list ) {
				$descs[ SPS_Answer_Transformer::norm( $field ) ] = array_values( (array) $list );
			}
			$prepared['_sps_file_descriptions'] = $descs;
		}
		$files    = self::normalize_files( $files );

		$use_user = $user_id && (int) SPS_Settings::get_setting( 'submit_as_user', 1 );
		$client   = $use_user ? SPS_NC_Client::for_user( $user_id ) : SPS_NC_Client::service();

		$result = self::send_to_forms( $schema, $prepared, $files, $client );

		if ( is_wp_error( $result ) && ! $client->is_service_account() ) {
			$data = $result->get_error_data();
			$http = isset( $data['http'] ) ? (int) $data['http'] : 0;
			if ( in_array( $http, self::USER_RETRY_CODES, true ) ) {
				SPS_Diagnostics::log( sprintf( 'Übertragung als Benutzer "%s" abgelehnt (HTTP %d, %s) – Wiederholung mit Service-Account. Ist das Formular für die Kundengruppe freigegeben?', $client->get_username(), $http, $result->get_error_message() ), 'warning' );
				$result = self::send_to_forms( $schema, $prepared, $files, SPS_NC_Client::service() );
			}
		}

		if ( ! is_wp_error( $result ) ) {
			SPS_Diagnostics::log( sprintf( 'Formular "%s" an Nextcloud Forms übertragen (NC-Formular %d, als %s).', $form_id, $result['nc_form_id'], $result['as'] ), 'info', array( 'warnings' => $result['warnings'] ) );
			return array(
				'status'     => 'forms',
				'nc_form_id' => $result['nc_form_id'],
			);
		}

		// ── Fallback ──
		$reason = $result->get_error_message();
		SPS_Diagnostics::log( sprintf( 'Forms-Übertragung "%s" fehlgeschlagen (%s) – WebDAV-Fallback.', $form_id, $reason ), 'warning' );

		$user = $user_id ? get_userdata( $user_id ) : null;
		$lead = array(
			'version'      => 2,
			'transformed'  => true,
			'submitted_at' => current_time( 'mysql' ),
			'form_id'      => $form_id,
			'form_type'    => isset( $schema['form_type'] ) ? $schema['form_type'] : '',
			'wp_user_id'   => (int) $user_id,
			'user_email'   => $user ? $user->user_email : '',
			'site'         => home_url(),
			'reason'       => $reason,
			'attempts'     => 1,
			'answers'      => $prepared,
			'meta'         => $meta,
		);

		$webdav = new SPS_NC_WebDAV();
		$stored = $webdav->store_lead( $lead, $files, $form_id );

		if ( is_wp_error( $stored ) ) {
			SPS_Diagnostics::log( 'WebDAV-Fallback fehlgeschlagen: ' . $stored->get_error_message(), 'error' );
			self::notify_admin( $form_id, $reason, $stored->get_error_message(), $lead, $files );
			return new WP_Error( 'sps_fallback_failed', $stored->get_error_message() );
		}

		SPS_Diagnostics::log( sprintf( 'Lead "%s" per WebDAV gespeichert: %s', $form_id, $stored ), 'info' );
		self::notify_admin( $form_id, $reason, '', null, array(), $stored );

		return array(
			'status' => 'fallback',
			'path'   => $stored,
		);
	}

	/**
	 * Übertragung an Nextcloud Forms (ohne Fallback).
	 *
	 * @param array         $schema   Schema.
	 * @param array         $prepared Aufbereitete Antworten.
	 * @param array         $files    norm(feld) => Liste aus array( 'path', 'name', 'type' ).
	 * @param SPS_NC_Client $client   Client für Upload + Submission.
	 * @return array|WP_Error array( 'nc_form_id', 'warnings', 'as' )
	 */
	public static function send_to_forms( array $schema, array $prepared, array $files, SPS_NC_Client $client ) {
		if ( ! $client->is_configured() ) {
			return new WP_Error( 'sps_not_configured', 'Nextcloud-Anbindung ist nicht konfiguriert.' );
		}

		$forms      = new SPS_NC_Forms( $client );
		$nc_form_id = $forms->resolve_form_id( $schema );
		if ( is_wp_error( $nc_form_id ) ) {
			return $nc_form_id;
		}

		$questions = $forms->get_questions( $nc_form_id );
		if ( is_wp_error( $questions ) ) {
			return $questions;
		}
		if ( empty( $questions ) ) {
			return new WP_Error( 'sps_no_questions', sprintf( 'Nextcloud-Formular %d hat keine Fragen.', $nc_form_id ) );
		}

		$mode = self::file_mode();

		// Dateien per WebDAV ablegen (PUT – funktioniert auch bei Multipart-Sperre).
		// Textfragen mit dem Namen des Upload-Felds erhalten Dateinamen + Ablageort,
		// eine Frage "dateiablage" den Ordner.
		if ( 'webdav' === $mode && self::has_files( $files ) ) {
			$stored = self::store_files_webdav( $schema, $prepared, $files, $client );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			if ( ! isset( $prepared['dateiablage'] ) ) {
				$prepared['dateiablage'] = $stored['folder'];
			}
			foreach ( $stored['fields'] as $key => $info ) {
				$prepared[ $key ] = implode( ', ', $info['names'] ) . ' (Nextcloud: ' . $info['path'] . ')';
			}
		}

		// Interne Schlüssel nicht an Forms übertragen.
		foreach ( array_keys( $prepared ) as $k ) {
			if ( 0 === strpos( (string) $k, '_sps_' ) ) {
				unset( $prepared[ $k ] );
			}
		}

		$built = $forms->build_submission( $questions, $prepared, $schema );

		foreach ( $built['files'] as $qid => $key ) {
			if ( empty( $files[ $key ] ) ) {
				continue;
			}
			if ( 'webdav' === $mode ) {
				// Dateifragen in Forms bleiben leer; die Dateien liegen im WebDAV-Ordner.
				unset( $built['answers'][ (string) $qid ] );
				continue;
			}
			$values = $forms->upload_files( $nc_form_id, (int) $qid, $files[ $key ] );
			if ( is_wp_error( $values ) ) {
				return $values;
			}
			$built['answers'][ (string) $qid ] = $values;
		}

		if ( empty( $built['answers'] ) ) {
			return new WP_Error( 'sps_empty_mapping', 'Keine Antwort passt zu einer Frage des Nextcloud-Formulars (technische Namen prüfen: wp sps mapping).' );
		}

		$res = $forms->submit_form( $nc_form_id, $built['answers'] );
		if ( is_wp_error( $res ) ) {
			SPS_NC_Forms::flush_cache( $nc_form_id );
			if ( ! empty( $built['warnings'] ) ) {
				$res->add( 'sps_mapping_warnings', implode( ' | ', $built['warnings'] ) );
			}
			return $res;
		}

		return array(
			'nc_form_id' => $nc_form_id,
			'warnings'   => $built['warnings'],
			'as'         => $client->is_service_account() ? 'Service-Account' : 'Benutzer ' . $client->get_username(),
		);
	}

	/**
	 * Wie gelangen Dateien nach Nextcloud?
	 *  webdav: PUT in den Upload-Ordner des Service-Accounts (Standard, funktioniert immer)
	 *  forms:  Upload-Endpunkt der Forms-API (multipart – bei manchen Hostern gesperrt)
	 *
	 * @return string
	 */
	public static function file_mode() {
		return 'forms' === SPS_Settings::get_setting( 'nc_file_mode', 'webdav' ) ? 'forms' : 'webdav';
	}

	/**
	 * Gibt es Dateien?
	 *
	 * @param array $files Normalisierte Dateien.
	 * @return bool
	 */
	protected static function has_files( array $files ) {
		foreach ( $files as $items ) {
			if ( ! empty( $items ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Dateien einer Übermittlung per WebDAV ablegen.
	 *
	 * Ordner: <webdav_upload_dir>/<formular>/<Ymd_His>_<email>_<rand>/<feld>/<NN_datei>
	 *
	 * @param array         $schema   Schema.
	 * @param array         $prepared Aufbereitete Antworten.
	 * @param array         $files    norm(feld) => Liste aus array( 'path', 'name', 'type' ).
	 * @param SPS_NC_Client $client   Client (WebDAV nutzt immer den Service-Account).
	 * @return array|WP_Error array( 'folder' => …, 'fields' => feld => array( 'names', 'path' ) )
	 */
	protected static function store_files_webdav( array $schema, array $prepared, array $files, SPS_NC_Client $client ) {
		$webdav  = new SPS_NC_WebDAV();
		$base    = trim( (string) SPS_Settings::get_setting( 'webdav_upload_dir', 'SPS_Uploads' ), '/' );
		$base    = '' !== $base ? $base : 'SPS_Uploads';
		$form_id = isset( $schema['form_id'] ) ? sanitize_key( $schema['form_id'] ) : 'formular';

		$who = '';
		foreach ( array( 'e_mail', 'email' ) as $k ) {
			if ( ! empty( $prepared[ $k ] ) && is_string( $prepared[ $k ] ) ) {
				$who = $prepared[ $k ];
				break;
			}
		}
		if ( '' === $who && ! empty( $prepared['_sps_user_email'] ) ) {
			$who = (string) $prepared['_sps_user_email'];
		}
		$who    = '' !== $who ? str_replace( '@', '_at_', sanitize_file_name( $who ) ) : 'anonym';
		$descs  = isset( $prepared['_sps_file_descriptions'] ) && is_array( $prepared['_sps_file_descriptions'] ) ? $prepared['_sps_file_descriptions'] : array();
		$folder = sprintf( '%s/%s/%s_%s_%s', $base, $form_id, gmdate( 'Ymd_His' ), $who, strtolower( wp_generate_password( 4, false, false ) ) );

		$result = array(
			'folder' => $folder,
			'fields' => array(),
		);

		foreach ( $files as $field => $items ) {
			if ( empty( $items ) ) {
				continue;
			}
			$dir = $folder . '/' . sanitize_key( $field );
			$ok  = $webdav->ensure_dir( $dir );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$names = array();
			foreach ( array_values( $items ) as $i => $f ) {
				if ( empty( $f['path'] ) || ! is_readable( $f['path'] ) ) {
					return new WP_Error( 'sps_upload_missing', 'Datei nicht lesbar: ' . ( isset( $f['name'] ) ? $f['name'] : '?' ) );
				}
				$name = sprintf( '%02d_%s', $i + 1, sanitize_file_name( $f['name'] ) );
				$ok   = $webdav->put( $dir . '/' . $name, file_get_contents( $f['path'] ), ! empty( $f['type'] ) ? $f['type'] : 'application/octet-stream' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$desc    = isset( $descs[ $field ][ $i ] ) ? trim( (string) $descs[ $field ][ $i ] ) : '';
				$names[] = '' !== $desc ? $f['name'] . ' – ' . $desc : $f['name'];
			}
			$result['fields'][ $field ] = array(
				'names' => $names,
				'path'  => $dir,
			);
		}

		SPS_Diagnostics::log( sprintf( '%d Datei-Feld(er) per WebDAV abgelegt: %s', count( $result['fields'] ), $folder ), 'info' );
		return $result;
	}

	/**
	 * Hochgeladene Dateien auf Upload-Format bringen.
	 *
	 * @param array $files feld => Liste aus array( 'tmp_name'|'path', 'name', 'type' ).
	 * @return array norm(feld) => Liste aus array( 'path', 'name', 'type', 'tmp_name', 'size' ).
	 */
	protected static function normalize_files( array $files ) {
		$out = array();
		foreach ( $files as $field => $items ) {
			foreach ( (array) $items as $f ) {
				$path = ! empty( $f['path'] ) ? $f['path'] : ( isset( $f['tmp_name'] ) ? $f['tmp_name'] : '' );
				if ( '' === $path ) {
					continue;
				}
				$out[ SPS_Answer_Transformer::norm( $field ) ][] = array(
					'path'     => $path,
					'tmp_name' => $path,
					'name'     => isset( $f['name'] ) ? $f['name'] : basename( $path ),
					'type'     => isset( $f['type'] ) ? $f['type'] : '',
					'size'     => isset( $f['size'] ) ? (int) $f['size'] : 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Admin benachrichtigen.
	 *
	 * Ist auch der Fallback gescheitert, werden die Lead-Daten als Anhang
	 * mitgeschickt – sonst wären sie verloren.
	 *
	 * @param string     $form_id   Formular.
	 * @param string     $reason    Grund Forms.
	 * @param string     $dav_error Fehler WebDAV (leer = Fallback ok).
	 * @param array|null $lead      Lead (nur bei Totalausfall).
	 * @param array      $files     Dateien (nur bei Totalausfall).
	 * @param string     $path      Ablageort (Fallback ok).
	 */
	protected static function notify_admin( $form_id, $reason, $dav_error, $lead = null, array $files = array(), $path = '' ) {
		$admin_email = SPS_Settings::get_setting( 'admin_email', get_option( 'admin_email' ) );
		if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
			return;
		}

		$site = get_bloginfo( 'name' );

		if ( '' === $dav_error ) {
			$subject = sprintf( '[%s] Formular-Fallback aktiv (%s)', $site, $form_id );
			$body    = sprintf(
				"Eine Übermittlung des Formulars \"%s\" konnte nicht an Nextcloud Forms übergeben werden.\n\nGrund: %s\n\nDie Daten liegen in der Nextcloud des Service-Accounts unter:\n%s\n\nSie werden stündlich automatisch erneut übertragen. Manuell: wp sps retry-leads\n\nZeitpunkt: %s\nWebsite: %s",
				$form_id,
				$reason,
				$path,
				current_time( 'mysql' ),
				home_url()
			);
			wp_mail( $admin_email, $subject, $body );
			return;
		}

		$subject     = sprintf( '[%s] DRINGEND: Formulardaten nicht gespeichert (%s)', $site, $form_id );
		$body        = sprintf(
			"Eine Übermittlung des Formulars \"%s\" konnte weder an Nextcloud Forms noch per WebDAV gespeichert werden.\n\nForms: %s\nWebDAV: %s\n\nDie Daten hängen als JSON (und ggf. Dateien) an dieser Mail. Bitte manuell übernehmen.\n\nZeitpunkt: %s\nWebsite: %s",
			$form_id,
			$reason,
			$dav_error,
			current_time( 'mysql' ),
			home_url()
		);
		$attachments = array();
		$tmp         = wp_tempnam( 'sps-lead-' . $form_id . '.json' );
		if ( $tmp && false !== file_put_contents( $tmp, wp_json_encode( $lead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) ) {
			$json_path = preg_replace( '/\.tmp$/', '', $tmp ) . '.json';
			if ( @rename( $tmp, $json_path ) ) {
				$tmp = $json_path;
			}
			$attachments[] = $tmp;
		}
		foreach ( $files as $items ) {
			foreach ( $items as $f ) {
				if ( ! empty( $f['path'] ) && is_readable( $f['path'] ) ) {
					$attachments[] = $f['path'];
				}
			}
		}
		wp_mail( $admin_email, $subject, $body, '', $attachments );
		if ( $tmp && file_exists( $tmp ) ) {
			@unlink( $tmp );
		}
	}

	/**
	 * Fallback-Leads erneut an Nextcloud Forms übertragen.
	 *
	 * @param int  $limit   Maximale Anzahl.
	 * @param bool $dry_run Nur anzeigen.
	 * @return array|WP_Error Liste aus array( 'lead', 'status', 'message' ).
	 */
	public static function retry_pending( $limit = 10, $dry_run = false ) {
		$webdav = new SPS_NC_WebDAV();
		$leads  = $webdav->list_pending_leads();
		if ( is_wp_error( $leads ) ) {
			return $leads;
		}

		$report   = array();
		$renderer = SPS_Form_Renderer::get_instance();

		foreach ( array_slice( $leads, 0, max( 1, (int) $limit ) ) as $folder ) {
			$json = $webdav->get( $folder . '/lead.json' );
			if ( is_wp_error( $json ) ) {
				$report[] = array( 'lead' => $folder, 'status' => 'fehler', 'message' => $json->get_error_message() );
				continue;
			}
			$lead = json_decode( $json, true );
			if ( ! is_array( $lead ) || empty( $lead['form_id'] ) || ! isset( $lead['answers'] ) ) {
				$report[] = array( 'lead' => $folder, 'status' => 'fehler', 'message' => 'lead.json unlesbar' );
				continue;
			}

			if ( ! empty( $lead['done'] ) ) {
				$moved    = $dry_run ? true : $webdav->mark_done( $folder );
				$report[] = array( 'lead' => $folder, 'status' => 'bereits übertragen', 'message' => is_wp_error( $moved ) ? $moved->get_error_message() : 'verschoben' );
				continue;
			}

			$schema = $renderer->load_schema( $lead['form_id'] );
			if ( ! $schema ) {
				$report[] = array( 'lead' => $folder, 'status' => 'fehler', 'message' => 'Schema "' . $lead['form_id'] . '" nicht gefunden' );
				continue;
			}

			if ( $dry_run ) {
				$report[] = array( 'lead' => $folder, 'status' => 'offen', 'message' => (string) ( isset( $lead['reason'] ) ? $lead['reason'] : '' ) );
				continue;
			}

			// Dateien temporär herunterladen.
			$files = array();
			$temps = array();
			$error = '';
			if ( ! empty( $lead['files'] ) && is_array( $lead['files'] ) ) {
				foreach ( $lead['files'] as $field => $items ) {
					foreach ( (array) $items as $f ) {
						$content = $webdav->get( $folder . '/' . $f['path'] );
						if ( is_wp_error( $content ) ) {
							$error = $content->get_error_message();
							break 2;
						}
						$tmp = wp_tempnam( isset( $f['name'] ) ? $f['name'] : 'datei' );
						file_put_contents( $tmp, $content );
						$temps[]           = $tmp;
						$files[ $field ][] = array(
							'path' => $tmp,
							'name' => isset( $f['name'] ) ? $f['name'] : basename( $f['path'] ),
							'type' => isset( $f['type'] ) ? $f['type'] : '',
						);
					}
				}
			}

			if ( '' === $error ) {
				$answers  = (array) $lead['answers'];
				$prepared = empty( $lead['transformed'] ) ? SPS_Answer_Transformer::apply( $answers, $schema ) : $answers;
				$user_id  = isset( $lead['wp_user_id'] ) ? (int) $lead['wp_user_id'] : 0;
				$client   = ( $user_id && (int) SPS_Settings::get_setting( 'submit_as_user', 1 ) ) ? SPS_NC_Client::for_user( $user_id, false ) : SPS_NC_Client::service();
				$result   = self::send_to_forms( $schema, $prepared, self::normalize_files( $files ), $client );
				if ( is_wp_error( $result ) && ! $client->is_service_account() ) {
					$result = self::send_to_forms( $schema, $prepared, self::normalize_files( $files ), SPS_NC_Client::service() );
				}
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				}
			}

			foreach ( $temps as $t ) {
				@unlink( $t );
			}

			if ( '' === $error ) {
				$moved    = $webdav->mark_done( $folder );
				$report[] = array(
					'lead'    => $folder,
					'status'  => 'übertragen',
					'message' => is_wp_error( $moved ) ? 'übertragen, aber nicht verschoben: ' . $moved->get_error_message() : 'nach ' . SPS_NC_WebDAV::DONE_DIR . '/ verschoben',
				);
				SPS_Diagnostics::log( sprintf( 'Fallback-Lead %s nachträglich an Forms übertragen.', $folder ), 'info' );
				if ( is_wp_error( $moved ) ) {
					// Nicht erneut senden: als erledigt markieren.
					$lead['done'] = current_time( 'mysql' );
					$webdav->put( $folder . '/lead.json', wp_json_encode( $lead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'application/json' );
				}
				continue;
			}

			$lead['attempts']   = isset( $lead['attempts'] ) ? (int) $lead['attempts'] + 1 : 2;
			$lead['last_error'] = $error;
			$lead['last_try']   = current_time( 'mysql' );
			$webdav->put( $folder . '/lead.json', wp_json_encode( $lead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'application/json' );
			$report[] = array( 'lead' => $folder, 'status' => 'fehler', 'message' => $error );
		}

		return $report;
	}
}
