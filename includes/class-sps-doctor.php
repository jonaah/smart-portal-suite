<?php
/**
 * Systemprüfung für `wp sps doctor` und `wp sps mapping`.
 *
 * Jede Prüfung liefert array( 'id', 'label', 'status' => ok|warn|error, 'message' ).
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Doctor {

	/**
	 * Ergebnisse.
	 *
	 * @var array
	 */
	protected $results = array();

	/**
	 * Fehlende Dinge reparieren (WebDAV-Ordner anlegen).
	 *
	 * @var bool
	 */
	protected $fix = false;

	/**
	 * Constructor.
	 *
	 * @param bool $fix Reparaturen ausführen.
	 */
	public function __construct( $fix = false ) {
		$this->fix = (bool) $fix;
	}

	/**
	 * Ergebnis hinzufügen.
	 *
	 * @param string $id      Kennung.
	 * @param string $label   Bezeichnung.
	 * @param string $status  ok|warn|error.
	 * @param string $message Meldung.
	 */
	protected function add( $id, $label, $status, $message ) {
		$this->results[] = array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Alle Prüfungen.
	 *
	 * @param bool $network Netzwerkprüfungen ausführen.
	 * @return array
	 */
	public function run( $network = true ) {
		$this->results = array();

		$config_ok = $this->check_config();
		$this->check_wordpress();

		if ( $network && $config_ok ) {
			$this->check_network();
		} elseif ( $network ) {
			$this->add( 'network', 'Nextcloud-Verbindung', 'error', 'Übersprungen, weil die Konfiguration unvollständig ist.' );
		}

		return $this->results;
	}

	/**
	 * Gibt es Fehler?
	 *
	 * @param array $results Ergebnisse.
	 * @return bool
	 */
	public static function has_errors( array $results ) {
		foreach ( $results as $r ) {
			if ( 'error' === $r['status'] ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────── Konfiguration ─────────────────────────── */

	/**
	 * @return bool Konfiguration vollständig.
	 */
	protected function check_config() {
		$ok  = true;
		$url = (string) SPS_Settings::get_setting( 'nextcloud_url', '' );

		if ( '' === $url ) {
			$this->add( 'nc_url', 'Nextcloud-URL', 'error', 'Nicht gesetzt (wp sps configure --nextcloud-url=...).' );
			$ok = false;
		} else {
			$host      = (string) wp_parse_url( $url, PHP_URL_HOST );
			$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				$this->add( 'nc_url', 'Nextcloud-URL', 'error', $url . ' verwendet kein HTTPS.' );
			} elseif ( self::base_domain( $host ) !== self::base_domain( $site_host ) ) {
				$this->add( 'nc_url', 'Nextcloud-URL', 'warn', sprintf( '%s gehört nicht zur Domain der Website (%s). Absicht? Sonst landen Kundendaten in einer fremden Nextcloud.', $host, $site_host ) );
			} else {
				$this->add( 'nc_url', 'Nextcloud-URL', 'ok', $url );
			}
		}

		$account = (string) SPS_Settings::get_setting( 'service_account', '' );
		if ( '' === $account ) {
			$this->add( 'service_account', 'Service-Account', 'error', 'Nicht gesetzt.' );
			$ok = false;
		} else {
			$this->add( 'service_account', 'Service-Account', 'ok', $account );
		}

		$raw = SPS_Settings::get_raw_setting( 'app_password' );
		$pw  = (string) SPS_Settings::get_setting( 'app_password', '' );
		if ( empty( $raw ) ) {
			$this->add( 'app_password', 'App-Passwort', 'error', 'Nicht gesetzt.' );
			$ok = false;
		} elseif ( '' === $pw ) {
			$this->add( 'app_password', 'App-Passwort', 'error', 'Gespeichert, aber nicht entschlüsselbar (WP-Salts geändert oder doppelt verschlüsselt). Neu setzen: wp sps configure --app-password-stdin' );
			$ok = false;
		} else {
			$this->add( 'app_password', 'App-Passwort', 'ok', 'hinterlegt und entschlüsselbar' );
		}

		if ( SPS_Settings::has_dedicated_key() ) {
			$this->add( 'encryption_key', 'Verschlüsselungsschlüssel', 'ok', 'SPS_ENCRYPTION_KEY ist gesetzt.' );
		} else {
			$this->add( 'encryption_key', 'Verschlüsselungsschlüssel', 'warn', 'SPS_ENCRYPTION_KEY fehlt in wp-config.php – Zugangsdaten hängen an den WP-Salts. Nach dem Setzen Passwörter neu eintragen.' );
		}

		$map = SPS_Settings::get_form_id_map();
		if ( empty( $map ) ) {
			$this->add( 'form_ids', 'Formular-Zuordnung', 'warn', 'Keine festen NC-Formular-IDs – Suche über Titel. Empfohlen: wp sps configure --forms=gebaeude_check=<id>' );
		} else {
			$parts = array();
			foreach ( $map as $f => $id ) {
				$parts[] = $f . '=' . $id;
			}
			$this->add( 'form_ids', 'Formular-Zuordnung', 'ok', implode( ', ', $parts ) );
		}

		return $ok;
	}

	/**
	 * Registrierbare Domain (letzte zwei Labels, ausreichend für .de/.com).
	 *
	 * @param string $host Host.
	 * @return string
	 */
	protected static function base_domain( $host ) {
		$labels = explode( '.', strtolower( $host ) );
		return implode( '.', array_slice( $labels, -2 ) );
	}

	/* ─────────────────────────── WordPress ─────────────────────────── */

	/**
	 * WordPress-Umgebung.
	 */
	protected function check_wordpress() {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$this->add( 'wp_env', 'WP_ENVIRONMENT_TYPE', 'production' === $env ? 'ok' : 'warn', $env );

		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$this->add( 'wp_debug', 'WP_DEBUG', $debug ? 'warn' : 'ok', $debug ? 'aktiv – im Livebetrieb abschalten' : 'aus' );

		$privacy_page = (int) get_option( 'wp_page_for_privacy_policy' );
		$setting      = (string) SPS_Settings::get_setting( 'privacy_url', '' );
		if ( '' !== $setting ) {
			$this->add( 'privacy', 'Datenschutzerklärung', 'ok', $setting . ' (Einstellung)' );
		} elseif ( $privacy_page && 'publish' === get_post_status( $privacy_page ) ) {
			$this->add( 'privacy', 'Datenschutzerklärung', 'ok', get_permalink( $privacy_page ) . ' (WP-Datenschutzseite)' );
		} else {
			$this->add( 'privacy', 'Datenschutzerklärung', 'warn', 'Weder SPS-Einstellung noch veröffentlichte WP-Datenschutzseite – Formular verlinkt auf ' . SPS_Settings::get_privacy_url() );
		}

		if ( SPS_Abilities::is_supported() ) {
			$registered = function_exists( 'wp_get_ability' ) ? count( array_filter( SPS_Abilities::names(), 'wp_get_ability' ) ) : 0;
			$this->add( 'abilities', 'MCP-Abilities', 'ok', sprintf( '%d von %d registriert (z. B. Easy MCP AI: unter "Tools › Abilities" einschalten).', $registered, count( SPS_Abilities::names() ) ) );
		} else {
			$this->add( 'abilities', 'MCP-Abilities', 'warn', 'WordPress-Abilities-API fehlt (erst ab WordPress 6.9) – Steuerung per MCP nicht verfügbar.' );
		}

		$magic = shortcode_exists( 'magic_login_form' );
		$this->add( 'magic_login', 'Magic Login Pro', $magic ? 'ok' : 'warn', $magic ? 'aktiv' : 'nicht aktiv – Login/Registrierung bleiben für Besucher leer' );

		$oauth = class_exists( 'WO_Server' ) || defined( 'WPOAUTH_VERSION' ) || defined( 'WPOAUTH_FILE' );
		$this->add( 'oauth', 'WP OAuth Server', $oauth ? 'ok' : 'warn', $oauth ? 'aktiv' : 'nicht erkannt – SSO zu Nextcloud nicht möglich' );

		// Datei-Uploads (Staging ohne Multipart).
		$staging = SPS_Upload_Staging::dir();
		if ( is_wp_error( $staging ) ) {
			$this->add( 'staging', 'Upload-Staging', 'error', $staging->get_error_message() );
		} else {
			$in_root = SPS_Upload_Staging::is_in_webroot();
			$this->add(
				'staging',
				'Upload-Staging',
				$in_root ? 'warn' : 'ok',
				$staging . ' (max. ' . size_format( SPS_Upload_Staging::max_size() ) . ' je Datei)' . ( $in_root ? ' – liegt im Webroot (per .htaccess gesperrt). Besser: SPS_STAGING_DIR außerhalb des Webroots setzen.' : '' )
			);
		}
		$mode = SPS_Submission::file_mode();
		$this->add( 'file_mode', 'Dateien an Nextcloud', 'ok', 'webdav' === $mode
			? 'WebDAV-Ordner ' . SPS_Settings::get_setting( 'webdav_upload_dir', 'SPS_Uploads' )
			: 'Forms-Dateifrage (Multipart – nur wenn der Hoster es zulässt)' );

		$cron = wp_next_scheduled( SPS_Submission::CRON_HOOK );
		$this->add( 'cron', 'Nachreichen (WP-Cron)', $cron ? 'ok' : 'warn', $cron ? 'nächster Lauf ' . gmdate( 'Y-m-d H:i', $cron ) . ' UTC' : 'nicht eingeplant' );
	}

	/* ─────────────────────────── Netzwerk ─────────────────────────── */

	/**
	 * Nextcloud-Prüfungen.
	 */
	protected function check_network() {
		$client  = SPS_NC_Client::service();
		$account = $client->get_username();

		// Anmeldung.
		$me = SPS_NC_Client::parse_ocs( $client->request( 'ocs/v2.php/cloud/user?format=json' ) );
		if ( is_wp_error( $me ) ) {
			$this->add( 'nc_auth', 'Anmeldung Service-Account', 'error', $me->get_error_message() );
			return;
		}
		$this->add( 'nc_auth', 'Anmeldung Service-Account', 'ok', 'angemeldet als ' . ( isset( $me['id'] ) ? $me['id'] : $account ) );

		// Rechte.
		$groups      = (array) SPS_Settings::get_setting( 'nc_sync_auto_groups', 'Hauseigner' );
		$auto_groups = array_values( array_filter( array_map( 'trim', explode( ',', implode( ',', $groups ) ) ) ) );
		$user_groups = isset( $me['groups'] ) ? (array) $me['groups'] : $client->get_user_groups( $account );
		$is_admin    = is_array( $user_groups ) && in_array( 'admin', $user_groups, true );

		if ( $is_admin ) {
			$this->add( 'nc_rights', 'Rechte Service-Account', 'warn', 'Mitglied der Gruppe admin. Empfohlen: nur Gruppenadmin von ' . implode( ', ', $auto_groups ) . '.' );
		} else {
			$sub = $client->get_subadmin_groups( $account );
			if ( is_wp_error( $sub ) ) {
				$this->add( 'nc_rights', 'Rechte Service-Account', 'error', 'Gruppenadmin-Rechte nicht lesbar: ' . $sub->get_error_message() );
			} else {
				$missing = array_diff( $auto_groups, $sub );
				if ( empty( $missing ) ) {
					$this->add( 'nc_rights', 'Rechte Service-Account', 'ok', 'Gruppenadmin von ' . implode( ', ', $sub ) );
				} else {
					$this->add( 'nc_rights', 'Rechte Service-Account', 'error', 'Kein Gruppenadmin von ' . implode( ', ', $missing ) . ' – Registrierungen schlagen fehl.' );
				}
			}
		}

		foreach ( $auto_groups as $g ) {
			$exists = $client->group_exists( $g );
			if ( is_wp_error( $exists ) ) {
				$this->add( 'nc_group_' . $g, 'Gruppe ' . $g, 'warn', $exists->get_error_message() );
			} else {
				$this->add( 'nc_group_' . $g, 'Gruppe ' . $g, $exists ? 'ok' : 'error', $exists ? 'vorhanden' : 'fehlt in Nextcloud' );
			}
		}

		$this->check_forms( $auto_groups );
		$this->check_webdav( $client );
		$this->check_social_db();
	}

	/**
	 * Formulare + Zuordnung.
	 *
	 * @param string[] $auto_groups Kundengruppen.
	 */
	protected function check_forms( array $auto_groups ) {
		$renderer = SPS_Form_Renderer::get_instance();
		$map      = SPS_Settings::get_form_id_map();
		$forms    = new SPS_NC_Forms();
		$as_user  = (int) SPS_Settings::get_setting( 'submit_as_user', 1 );

		// Gleichnamige NC-Formulare: die Titelsuche nimmt das erste – oft das falsche.
		$titles  = array();
		$nc_list = $forms->list_forms();
		if ( ! is_wp_error( $nc_list ) ) {
			foreach ( $nc_list as $f ) {
				$t              = strtolower( trim( isset( $f['title'] ) ? (string) $f['title'] : '' ) );
				$titles[ $t ][] = (int) $f['id'];
			}
		}

		foreach ( $renderer->get_available_forms() as $form_id => $info ) {
			$schema = $renderer->load_schema( $form_id );
			if ( ! $schema ) {
				continue;
			}
			$strict = isset( $map[ $form_id ] );
			$label  = 'Formular ' . $form_id;

			$this->add( 'form_access_' . $form_id, $label . ' Zugang', 'ok', SPS_Form_Renderer::requires_login( $schema ) ? 'nur angemeldete Nutzer' : 'alle (auch Gäste)' );

			if ( ! $strict ) {
				foreach ( array( 'nc_form_title', 'form_type', 'title' ) as $k ) {
					$t = ! empty( $schema[ $k ] ) ? strtolower( trim( (string) $schema[ $k ] ) ) : '';
					if ( '' !== $t && isset( $titles[ $t ] ) && count( $titles[ $t ] ) > 1 ) {
						$this->add( 'form_dup_' . $form_id, $label . ' Zuordnung', 'warn', sprintf( 'Mehrere NC-Formulare mit Titel "%s" (IDs %s) – fest zuordnen: Smart Portal → Formulare → Styling → Zugang & Nextcloud oder wp sps configure --forms=%s=<id>', $schema[ $k ], implode( ', ', $titles[ $t ] ), $form_id ) );
						break;
					}
				}
			}

			$nc_id = $forms->resolve_form_id( $schema );
			if ( is_wp_error( $nc_id ) ) {
				$this->add( 'form_' . $form_id, $label, $strict ? 'error' : 'warn', $nc_id->get_error_message() );
				continue;
			}

			$nc_form = $forms->get_form( $nc_id, false );
			if ( is_wp_error( $nc_form ) ) {
				$this->add( 'form_' . $form_id, $label, 'error', 'NC-Formular ' . $nc_id . ': ' . $nc_form->get_error_message() );
				continue;
			}

			$report = self::mapping_report( $schema, isset( $nc_form['questions'] ) ? (array) $nc_form['questions'] : array() );
			$errors = array();
			$warns  = array();
			foreach ( $report as $row ) {
				if ( 'FEHLT (Pflicht)' === $row['status'] ) {
					$errors[] = $row['name'];
				} elseif ( 'ja' === $row['required'] && 0 === strpos( $row['status'], 'Optionen fehlen' ) ) {
					$errors[] = $row['name'] . ' (' . $row['status'] . ')';
				} elseif ( 0 === strpos( $row['status'], 'Pflicht-Dateifrage' ) ) {
					$errors[] = $row['name'] . ' (Pflicht-Dateifrage: in NC optional machen oder --file-mode=forms)';
				} elseif ( 'ok' !== $row['status'] && 'Datei' !== $row['status'] && 0 !== strpos( $row['status'], 'Datei →' ) ) {
					$warns[] = $row['name'] . ': ' . $row['status'];
				}
			}

			$title = isset( $nc_form['title'] ) ? $nc_form['title'] : '';
			$msg   = sprintf( 'NC-Formular %d "%s", %d Fragen', $nc_id, $title, count( $report ) );
			if ( ! empty( $errors ) ) {
				$this->add( 'form_' . $form_id, $label, 'error', $msg . ' – Pflichtfragen ohne Quelle: ' . implode( ', ', $errors ) . ' (Details: wp sps mapping ' . $form_id . ')' );
			} elseif ( ! empty( $warns ) ) {
				$this->add( 'form_' . $form_id, $label, 'warn', $msg . ' – ' . implode( '; ', array_slice( $warns, 0, 6 ) ) . ( count( $warns ) > 6 ? ' …' : '' ) . ' (Details: wp sps mapping ' . $form_id . ')' );
			} else {
				$this->add( 'form_' . $form_id, $label, 'ok', $msg . ', alle Fragen zugeordnet' );
			}

			if ( $as_user ) {
				$access  = isset( $nc_form['access'] ) ? (array) $nc_form['access'] : array();
				$shared  = ! empty( $access['permitAllUsers'] );
				$sgroups = array();
				foreach ( isset( $nc_form['shares'] ) ? (array) $nc_form['shares'] : array() as $share ) {
					if ( isset( $share['shareType'], $share['shareWith'] ) && 1 === (int) $share['shareType'] ) {
						$sgroups[] = $share['shareWith'];
					}
				}
				if ( ! $shared && ! array_intersect( $auto_groups, $sgroups ) ) {
					$this->add( 'form_share_' . $form_id, $label . ' Freigabe', 'warn', 'Nicht für ' . implode( ', ', $auto_groups ) . ' freigegeben – Kunden-Übertragungen laufen über den Service-Account.' );
				} else {
					$this->add( 'form_share_' . $form_id, $label . ' Freigabe', 'ok', $shared ? 'für alle Nutzer' : 'für ' . implode( ', ', $sgroups ) );
				}
			}
		}
	}

	/**
	 * WebDAV-Fallback.
	 *
	 * @param SPS_NC_Client $client Service-Client.
	 */
	protected function check_webdav( SPS_NC_Client $client ) {
		$webdav = new SPS_NC_WebDAV( $client );
		$dir    = $webdav->get_base_dir();
		$exists = $webdav->exists( $dir );

		if ( is_wp_error( $exists ) ) {
			$this->add( 'webdav', 'WebDAV-Fallback', 'error', $exists->get_error_message() );
			return;
		}
		if ( ! $exists && $this->fix ) {
			$made   = $webdav->ensure_dir( $dir );
			$exists = ! is_wp_error( $made );
			if ( is_wp_error( $made ) ) {
				$this->add( 'webdav', 'WebDAV-Fallback', 'error', 'Ordner ' . $dir . ' konnte nicht angelegt werden: ' . $made->get_error_message() );
				return;
			}
		}
		if ( ! $exists ) {
			$this->add( 'webdav', 'WebDAV-Fallback', 'warn', 'Ordner ' . $dir . ' fehlt (wird beim ersten Fallback angelegt; sofort: wp sps doctor --fix).' );
			return;
		}

		$pending = $webdav->list_pending_leads();
		if ( is_wp_error( $pending ) ) {
			$this->add( 'webdav', 'WebDAV-Fallback', 'warn', $pending->get_error_message() );
		} elseif ( ! empty( $pending ) ) {
			$this->add( 'webdav', 'WebDAV-Fallback', 'warn', sprintf( '%d Lead(s) warten auf Übertragung (wp sps retry-leads).', count( $pending ) ) );
		} else {
			$this->add( 'webdav', 'WebDAV-Fallback', 'ok', 'Ordner ' . $dir . ' vorhanden, keine offenen Leads' );
		}
	}

	/**
	 * Social-Login-Datenbank.
	 */
	protected function check_social_db() {
		if ( '' === (string) SPS_Settings::get_setting( 'nc_db_host', '' ) ) {
			$this->add( 'social_db', 'Social-Login-Verknüpfung', 'warn', 'NC-Datenbank nicht konfiguriert – Konten werden nicht automatisch mit dem SSO verknüpft.' );
			return;
		}
		$res = SPS_NC_User_Sync::get_instance()->test_nc_db_connection();
		$this->add( 'social_db', 'Social-Login-Verknüpfung', $res['success'] ? 'ok' : 'error', wp_strip_all_tags( $res['message'] ) );
	}

	/* ─────────────────────────── Zuordnungs-Report ─────────────────────────── */

	/**
	 * Für jede NC-Frage ermitteln, woher die Antwort kommt.
	 *
	 * @param array $schema    SPS-Schema.
	 * @param array $questions NC-Fragen.
	 * @return array Zeilen (name, id, type, required, source, status).
	 */
	public static function mapping_report( array $schema, array $questions ) {
		$keys   = self::available_keys( $schema );
		$values = self::possible_values( $schema );
		$map    = array();
		if ( ! empty( $schema['submit']['map'] ) && is_array( $schema['submit']['map'] ) ) {
			foreach ( $schema['submit']['map'] as $nc => $src ) {
				$map[ SPS_Answer_Transformer::norm( $nc ) ] = array_map( array( 'SPS_Answer_Transformer', 'norm' ), (array) $src );
			}
		}

		$rows = array();
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) ) {
				continue;
			}
			$name = isset( $q['name'] ) ? trim( (string) $q['name'] ) : '';
			$type = isset( $q['type'] ) ? (string) $q['type'] : '';
			$req  = ! empty( $q['isRequired'] );
			$row  = array(
				'id'       => (string) $q['id'],
				'name'     => '' !== $name ? $name : '(ohne Namen) ' . ( isset( $q['text'] ) ? $q['text'] : '' ),
				'type'     => $type,
				'required' => $req ? 'ja' : '',
				'source'   => '',
				'status'   => '',
			);

			if ( '' === $name ) {
				$row['status'] = $req ? 'FEHLT (Pflicht)' : 'kein technischer Name';
				$rows[]        = $row;
				continue;
			}

			$key     = SPS_Answer_Transformer::norm( $name );
			$sources = array();
			if ( isset( $map[ $key ] ) ) {
				$sources = array_values( array_intersect( $map[ $key ], $keys ) );
			} elseif ( in_array( $key, $keys, true ) ) {
				$sources = array( $key );
			} elseif ( false !== strpos( $key, '_' ) ) {
				$sources = array_values( array_intersect( explode( '_', $key ), $keys ) );
			}

			if ( 'file' === $type ) {
				$row['source'] = $key;
				if ( ! in_array( $key, $keys, true ) ) {
					$row['status'] = $req ? 'FEHLT (Pflicht)' : 'keine Quelle';
				} elseif ( 'webdav' === SPS_Settings::get_setting( 'nc_file_mode', 'webdav' ) ) {
					$row['status'] = $req ? 'Pflicht-Dateifrage – im WebDAV-Modus nicht befüllbar' : 'Datei → WebDAV (Frage bleibt leer)';
				} else {
					$row['status'] = 'Datei';
				}
				$rows[]        = $row;
				continue;
			}

			if ( empty( $sources ) ) {
				$row['status'] = $req ? 'FEHLT (Pflicht)' : 'keine Quelle';
				$rows[]        = $row;
				continue;
			}

			$row['source'] = implode( '+', $sources );
			$row['status'] = 'ok';

			// Auswahlfragen: passen die möglichen Werte zu den NC-Optionen?
			if ( in_array( $type, SPS_NC_Forms::OPTION_TYPES, true ) && 1 === count( $sources ) && ! empty( $values[ $sources[0] ] ) ) {
				$options = array();
				foreach ( isset( $q['options'] ) ? (array) $q['options'] : array() as $o ) {
					if ( isset( $o['text'] ) ) {
						$options[] = self::lower( $o['text'] );
					}
				}
				$missing = array();
				foreach ( $values[ $sources[0] ] as $text ) {
					if ( ! in_array( self::lower( $text ), $options, true ) ) {
						$missing[] = $text;
					}
				}
				if ( ! empty( $missing ) && empty( $q['extraSettings']['allowOtherAnswer'] ) ) {
					$row['status'] = 'Optionen fehlen in NC: ' . implode( ' | ', array_slice( $missing, 0, 4 ) ) . ( count( $missing ) > 4 ? ' …' : '' );
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Alle Antwortschlüssel, die ein Schema erzeugen kann.
	 *
	 * @param array $schema Schema.
	 * @return string[]
	 */
	public static function available_keys( array $schema ) {
		$keys = array();
		foreach ( isset( $schema['steps'] ) ? (array) $schema['steps'] : array() as $step ) {
			self::collect_step_keys( $step, '', $keys );
		}

		$rules   = isset( $schema['submit']['rules'] ) ? (array) $schema['submit']['rules'] : array();
		$removed = array();
		foreach ( $rules as $rule ) {
			foreach ( array( 'set', 'original', 'kwh' ) as $k ) {
				if ( ! empty( $rule[ $k ] ) ) {
					$keys[] = SPS_Answer_Transformer::norm( $rule[ $k ] );
				}
			}
			if ( isset( $rule['remove'] ) && empty( $rule['when'] ) ) {
				foreach ( (array) $rule['remove'] as $r ) {
					$removed[] = SPS_Answer_Transformer::norm( $r );
				}
			}
		}

		// WebDAV-Dateimodus stellt den Ablageordner bereit.
		foreach ( SPS_Answer_Transformer::flatten_steps( $schema ) as $step ) {
			if ( isset( $step['type'] ) && 'upload' === $step['type'] ) {
				$keys[] = 'dateiablage';
				break;
			}
		}

		$keys = array_values( array_unique( $keys ) );
		$set  = array();
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['set'] ) ) {
				$set[] = SPS_Answer_Transformer::norm( $rule['set'] );
			}
		}
		// Entfernte Schlüssel fallen weg, außer sie werden danach wieder gesetzt.
		return array_values( array_diff( $keys, array_diff( $removed, $set ) ) );
	}

	/**
	 * Schlüssel eines Schritts (inkl. Gruppen und Adressfeldern) sammeln.
	 *
	 * @param array  $step   Schritt.
	 * @param string $group  Gruppen-ID.
	 * @param array  $keys   Ergebnis (per Referenz).
	 */
	protected static function collect_step_keys( $step, $group, array &$keys ) {
		if ( ! is_array( $step ) ) {
			return;
		}
		$type = isset( $step['type'] ) ? $step['type'] : '';
		if ( in_array( $type, array( 'summary' ), true ) ) {
			return;
		}
		$id = isset( $step['id'] ) ? SPS_Answer_Transformer::norm( $step['id'] ) : '';
		if ( '' !== $id ) {
			$keys[] = $id;
			if ( '' !== $group ) {
				$keys[] = $group . '_' . $id;
			}
		}
		if ( 'address-full' === $type || 'group' === $type ) {
			foreach ( array( 'plz', 'ort', 'strasse', 'hausnummer' ) as $a ) {
				if ( 'address-full' === $type ) {
					$keys[] = $a;
					$keys[] = $id . '_' . $a;
				}
			}
		}
		if ( ! empty( $step['fields'] ) && is_array( $step['fields'] ) ) {
			foreach ( $step['fields'] as $f ) {
				self::collect_step_keys( $f, 'row' === $type ? $group : ( '' !== $id ? $id : $group ), $keys );
			}
		}
	}

	/**
	 * Mögliche Werte je Schlüssel (Auswahltexte + feste Regelwerte).
	 *
	 * @param array $schema Schema.
	 * @return array norm(key) => string[]
	 */
	protected static function possible_values( array $schema ) {
		$values = array();
		foreach ( SPS_Answer_Transformer::flatten_steps( $schema ) as $key => $step ) {
			if ( empty( $step['choices'] ) || ! is_array( $step['choices'] ) ) {
				continue;
			}
			foreach ( $step['choices'] as $c ) {
				$values[ $key ][] = is_array( $c ) ? ( isset( $c['text'] ) ? (string) $c['text'] : '' ) : (string) $c;
			}
		}
		$from_rules = array();
		$renames    = array();
		foreach ( isset( $schema['submit']['rules'] ) ? (array) $schema['submit']['rules'] : array() as $rule ) {
			if ( empty( $rule['set'] ) || ! array_key_exists( 'value', $rule ) || ! is_scalar( $rule['value'] ) ) {
				continue;
			}
			$key  = SPS_Answer_Transformer::norm( $rule['set'] );
			$when = isset( $rule['when'] ) && is_array( $rule['when'] ) ? $rule['when'] : array();
			// Übersetzung eines Auswahlwerts: { "set": k, "value": neu, "when": { k: alt } }.
			if ( 1 === count( $when ) && $key === SPS_Answer_Transformer::norm( key( $when ) ) && is_scalar( current( $when ) ) ) {
				$renames[ $key ][ (string) current( $when ) ] = (string) $rule['value'];
				continue;
			}
			$from_rules[ $key ][] = (string) $rule['value'];
		}
		// Feste Werte aus Regeln ergänzen (z. B. kellerdecke = "Massiv", wenn kein Keller).
		foreach ( $from_rules as $k => $v ) {
			$values[ $k ] = array_values( array_unique( array_merge( isset( $values[ $k ] ) ? $values[ $k ] : array(), $v ) ) );
		}
		foreach ( $renames as $k => $map ) {
			if ( isset( $values[ $k ] ) ) {
				foreach ( $values[ $k ] as $i => $v ) {
					if ( isset( $map[ $v ] ) ) {
						$values[ $k ][ $i ] = $map[ $v ];
					}
				}
			}
		}
		return $values;
	}

	/**
	 * Kleinschreibung (UTF-8).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	protected static function lower( $s ) {
		$s = preg_replace( '/\s+/u', ' ', trim( (string) $s ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
