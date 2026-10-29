<?php
/**
 * MCP-Anbindung über die WordPress-Abilities-API (ab WordPress 6.9).
 *
 * Registriert die Funktionen der Smart Portal Suite als Abilities. MCP-Server
 * wie "Easy MCP AI" oder der offizielle "MCP Adapter" stellen sie als
 * Werkzeuge bereit (z. B. wp_ability_smart_portal_suite_update_form_theme).
 * In Easy MCP AI einmalig unter "Tools › Abilities" einschalten.
 *
 * Sicherheit: alle Abilities nur für Benutzer mit manage_options. Zugangsdaten
 * (App-Passwort, DB-Passwort) werden weder ausgegeben noch über MCP gesetzt –
 * dafür bleibt "wp sps configure" per SSH.
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Abilities {

	/**
	 * Namensraum der Abilities.
	 */
	const NS = 'smart-portal-suite';

	/**
	 * Kategorie.
	 */
	const CATEGORY = 'smart-portal-suite';

	/**
	 * Einstellungen, die über MCP geändert werden dürfen (keine Zugangsdaten).
	 */
	const EDITABLE_SETTINGS = array(
		'submit_as_user'      => 'boolean',
		'nc_file_mode'        => 'string',
		'webdav_upload_dir'   => 'string',
		'webdav_base_dir'     => 'string',
		'auth_login_url'      => 'string',
		'auth_register_url'   => 'string',
		'login_redirect_url'  => 'string',
		'privacy_url'         => 'string',
		'admin_email'         => 'string',
		'nc_sync_auto_groups' => 'string',
		'primary_color'       => 'string',
		'debug_mode'          => 'boolean',
	);

	/**
	 * Hooks registrieren (nur wenn die Abilities-API vorhanden ist).
	 */
	public static function init() {
		if ( ! self::is_supported() ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Abilities-API verfügbar (WordPress 6.9+ oder Feature-Plugin)?
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Namen aller Abilities (für Diagnose).
	 *
	 * @return string[]
	 */
	public static function names() {
		return array_map(
			function ( $slug ) {
				return self::NS . '/' . $slug;
			},
			array_keys( self::definitions() )
		);
	}

	/**
	 * Kategorie registrieren.
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Smart Portal Suite', 'smart-portal-suite' ),
				'description' => __( 'Formulare (Gebäude-Check u. a.), deren Design, Zugang und Anbindung an Nextcloud Forms.', 'smart-portal-suite' ),
			)
		);
	}

	/**
	 * Abilities registrieren.
	 */
	public static function register_abilities() {
		foreach ( self::definitions() as $slug => $def ) {
			$readonly = ! empty( $def['readonly'] );
			wp_register_ability(
				self::NS . '/' . $slug,
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => $def['input'],
					'output_schema'       => isset( $def['output'] ) ? $def['output'] : array( 'type' => 'object' ),
					'execute_callback'    => self::wrap( $slug, $def['callback'], $readonly ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => $readonly,
							'destructive' => ! empty( $def['destructive'] ),
							'idempotent'  => ! empty( $def['idempotent'] ),
						),
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
					),
				)
			);
		}
	}

	/**
	 * Berechtigung: nur Administratoren.
	 *
	 * @return bool|WP_Error
	 */
	public static function can_manage() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'sps_forbidden', __( 'Nur Administratoren dürfen die Smart Portal Suite steuern.', 'smart-portal-suite' ), array( 'status' => 403 ) );
	}

	/**
	 * Callback mit Eingabe-Normalisierung und Protokoll für schreibende Aufrufe.
	 *
	 * @param string   $slug     Ability.
	 * @param callable $callback Ausführung.
	 * @param bool     $readonly Nur lesend.
	 * @return callable
	 */
	protected static function wrap( $slug, $callback, $readonly ) {
		return function ( $input = null ) use ( $slug, $callback, $readonly ) {
			$input  = is_array( $input ) ? $input : array();
			$result = call_user_func( $callback, $input );
			if ( ! $readonly && ! is_wp_error( $result ) ) {
				$user = wp_get_current_user();
				SPS_Diagnostics::log( sprintf( 'MCP/Ability "%s" ausgeführt von %s.', $slug, $user && $user->exists() ? $user->user_login : 'unbekannt' ), 'info', array( 'input' => self::redact( $input ) ) );
			}
			return $result;
		};
	}

	/**
	 * Lange Werte im Protokoll kürzen.
	 *
	 * @param array $input Eingabe.
	 * @return array
	 */
	protected static function redact( array $input ) {
		$json = wp_json_encode( $input );
		if ( is_string( $json ) && strlen( $json ) > 2000 ) {
			return array( 'gekürzt' => substr( $json, 0, 2000 ) );
		}
		return $input;
	}

	/* ═══════════════════════════════════════════════════════════════
	   DEFINITIONEN
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * Schema-Baustein: Formular-ID.
	 *
	 * @return array
	 */
	protected static function form_id_prop() {
		return array(
			'type'        => 'string',
			'description' => 'SPS-Formular-ID, z. B. "gebaeude_check" (siehe list-forms).',
			'minLength'   => 1,
		);
	}

	/**
	 * Alle Abilities.
	 *
	 * @return array
	 */
	protected static function definitions() {
		$form_only = array(
			'type'                 => 'object',
			'properties'           => array( 'form_id' => self::form_id_prop() ),
			'required'             => array( 'form_id' ),
			'additionalProperties' => false,
		);
		$empty     = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);

		return array(
			'list-forms'             => array(
				'label'       => 'Formulare auflisten',
				'description' => 'Listet alle Formulare der Smart Portal Suite mit Shortcode, Zugang (nur angemeldete / alle), zugeordnetem Nextcloud-Formular, Styling-Status und den Seiten, auf denen das Formular eingebunden ist.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => $empty,
				'callback'    => array( __CLASS__, 'list_forms' ),
			),
			'get-form-schema'        => array(
				'label'       => 'Formularaufbau lesen',
				'description' => 'Schritte eines Formulars (ID, Typ, Frage, Pflicht, Auswahloptionen) und alle Antwortschlüssel, die an Nextcloud übertragen werden können.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => $form_only,
				'callback'    => array( __CLASS__, 'get_form_schema' ),
			),
			'get-form-theme'         => array(
				'label'       => 'Formular-Design lesen',
				'description' => 'Aktuelle Farben, Radien und Formularhöhe eines Formulars, alle einstellbaren CSS-Variablen mit Beschreibung und Standardwert sowie die verfügbaren Vorlagen (Presets). Leere Werte bedeuten: automatisch aus den Grundfarben abgeleitet.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => $form_only,
				'callback'    => array( __CLASS__, 'get_form_theme' ),
			),
			'update-form-theme'      => array(
				'label'       => 'Formular-Design ändern',
				'description' => 'Setzt Farben (#rgb, #rrggbb oder #rrggbbaa), Eckenrundungen (Pixel) und die Formularhöhe ("screen", "auto" oder 480–1100 px) eines Formulars. Schlüssel sind die CSS-Variablen aus get-form-theme, z. B. "--sps-accent". Leerer String oder null entfernt eine Überschreibung. Mit "preset" wird zuerst eine Vorlage angewendet, mit "reset" alles auf Standard gesetzt. Gibt ungültige Schlüssel/Werte in "rejected" zurück.',
				'readonly'    => false,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id' => self::form_id_prop(),
						'values'  => array(
							'type'                 => 'object',
							'description'          => 'CSS-Variable => Wert, z. B. {"--sps-accent": "#1976d2", "--sps-form-height": "screen"}.',
							'additionalProperties' => array( 'type' => array( 'string', 'null' ) ),
						),
						'preset'  => array(
							'type'        => 'string',
							'description' => 'Vorlage aus get-form-theme (z. B. "light_minimal"), wird vor "values" angewendet.',
						),
						'reset'   => array(
							'type'        => 'boolean',
							'description' => 'Vorher alle Überschreibungen entfernen.',
						),
					),
					'required'             => array( 'form_id' ),
					'additionalProperties' => false,
				),
				'callback'    => array( __CLASS__, 'update_form_theme' ),
			),
			'set-form-access'        => array(
				'label'       => 'Formular-Zugang festlegen',
				'description' => 'Legt fest, ob ein Formular nur für angemeldete Nutzer ("login"), für alle ("public") oder wie im Formular-Schema ("schema") verfügbar ist. Optional mit eigenem Hinweistext für Gäste. Danach den Seiten-Cache leeren.',
				'readonly'    => false,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'   => self::form_id_prop(),
						'access'    => array(
							'type' => 'string',
							'enum' => array( 'login', 'public', 'schema' ),
						),
						'gate_text' => array(
							'type'        => array( 'string', 'null' ),
							'description' => 'Hinweis für Gäste. Leer = Standardtext, weglassen = unverändert.',
						),
					),
					'required'             => array( 'form_id', 'access' ),
					'additionalProperties' => false,
				),
				'callback'    => array( __CLASS__, 'set_form_access' ),
			),
			'list-nextcloud-forms'   => array(
				'label'       => 'Nextcloud-Formulare auflisten',
				'description' => 'Alle Formulare in Nextcloud Forms, auf die der Service-Account Zugriff hat (eigene und geteilte), mit ID und Hinweis auf gleichnamige Formulare.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => $empty,
				'callback'    => array( __CLASS__, 'list_nextcloud_forms' ),
			),
			'set-nextcloud-form'     => array(
				'label'       => 'Nextcloud-Formular zuordnen',
				'description' => 'Ordnet einem SPS-Formular fest ein Nextcloud-Formular zu (nc_form_id). 0 entfernt die feste Zuordnung (dann Suche über den Titel). Prüft vorher, ob das Formular in Nextcloud erreichbar ist.',
				'readonly'    => false,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'    => self::form_id_prop(),
						'nc_form_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					'required'             => array( 'form_id', 'nc_form_id' ),
					'additionalProperties' => false,
				),
				'callback'    => array( __CLASS__, 'set_nextcloud_form' ),
			),
			'check-form-mapping'     => array(
				'label'       => 'Zuordnung zu Nextcloud prüfen',
				'description' => 'Zeigt für jede Frage des Nextcloud-Formulars, welches Formularfeld sie befüllt, und listet Probleme, an denen Übertragungen scheitern (fehlende Pflichtfragen, fehlende Optionen). Ohne nc_form_id wird die aktuelle Zuordnung geprüft.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id'    => self::form_id_prop(),
						'nc_form_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					'required'             => array( 'form_id' ),
					'additionalProperties' => false,
				),
				'callback'    => array( __CLASS__, 'check_form_mapping' ),
			),
			'preview-submission'     => array(
				'label'       => 'Übertragung testen (ohne Senden)',
				'description' => 'Bereitet Beispiel-Antworten wie beim echten Absenden auf (Regeln, Umrechnungen) und zeigt, was an welche Nextcloud-Frage ginge. Sendet nichts.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'form_id' => self::form_id_prop(),
						'answers' => array(
							'type'        => 'object',
							'description' => 'Antworten wie vom Formular, z. B. {"typ": "Reihenmittelhaus", "baujahr": 1981}.',
						),
					),
					'required'             => array( 'form_id', 'answers' ),
					'additionalProperties' => false,
				),
				'callback'    => array( __CLASS__, 'preview_submission' ),
			),
			'run-diagnostics'        => array(
				'label'       => 'Diagnose ausführen',
				'description' => 'Gesamtprüfung wie "wp sps doctor": Konfiguration, WordPress, Nextcloud-Anmeldung, Formular-Zuordnungen und Freigaben, WebDAV-Fallback, offene Leads.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'offline' => array(
							'type'        => 'boolean',
							'description' => 'Nur Konfiguration und WordPress prüfen, keine Nextcloud-Anfragen.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'callback'    => array( __CLASS__, 'run_diagnostics' ),
			),
			'list-pending-leads'     => array(
				'label'       => 'Offene Leads anzeigen',
				'description' => 'Anfragen, die nicht an Nextcloud Forms übertragen werden konnten und im WebDAV-Fallback liegen, mit Grund.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'callback'    => array( __CLASS__, 'list_pending_leads' ),
			),
			'retry-pending-leads'    => array(
				'label'       => 'Offene Leads nachreichen',
				'description' => 'Überträgt offene Leads erneut an Nextcloud Forms (wie der stündliche Cron). Erfolgreiche Leads werden nach "_uebertragen" verschoben.',
				'readonly'    => false,
				'idempotent'  => false,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'callback'    => array( __CLASS__, 'retry_pending_leads' ),
			),
			'get-settings'           => array(
				'label'       => 'Einstellungen lesen',
				'description' => 'Einstellungen der Smart Portal Suite ohne Zugangsdaten (Passwörter werden nur als "hinterlegt ja/nein" gemeldet), Plugin-Version und welche Werte über update-settings änderbar sind.',
				'readonly'    => true,
				'idempotent'  => true,
				'input'       => $empty,
				'callback'    => array( __CLASS__, 'get_settings' ),
			),
			'update-settings'        => array(
				'label'       => 'Einstellungen ändern',
				'description' => 'Ändert Einstellungen ohne Zugangsdaten: submit_as_user, nc_file_mode (webdav|forms), webdav_upload_dir, webdav_base_dir, auth_login_url, auth_register_url, login_redirect_url, privacy_url, admin_email, nc_sync_auto_groups, primary_color, debug_mode. Nextcloud-URL, Service-Account und Passwörter nur per "wp sps configure".',
				'readonly'    => false,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'settings' => array(
							'type'        => 'object',
							'description' => 'Schlüssel => neuer Wert.',
						),
					),
					'required'             => array( 'settings' ),
					'additionalProperties' => false,
				),
				'callback'    => array( __CLASS__, 'update_settings' ),
			),
			'sync-user'              => array(
				'label'       => 'Kundenkonto mit Nextcloud abgleichen',
				'description' => 'Legt für einen WordPress-Benutzer das Nextcloud-Konto an bzw. prüft es und verknüpft es mit dem Single Sign-on (wie "wp sps sync-user").',
				'readonly'    => false,
				'idempotent'  => true,
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'user'  => array(
							'type'        => array( 'string', 'integer' ),
							'description' => 'WP-Benutzer-ID oder E-Mail.',
						),
						'force' => array(
							'type'        => 'boolean',
							'description' => 'Nextcloud-Passwort neu setzen.',
						),
					),
					'required'             => array( 'user' ),
					'additionalProperties' => false,
				),
				'callback'    => array( __CLASS__, 'sync_user' ),
			),
		);
	}

	/* ═══════════════════════════════════════════════════════════════
	   HILFSFUNKTIONEN
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * Schema laden oder Fehler.
	 *
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	protected static function schema_from_input( array $input ) {
		$id     = isset( $input['form_id'] ) ? sanitize_key( (string) $input['form_id'] ) : '';
		$schema = '' !== $id ? SPS_Form_Renderer::get_instance()->load_schema( $id ) : null;
		if ( ! is_array( $schema ) ) {
			return new WP_Error( 'sps_form_not_found', sprintf( 'Formular "%s" nicht gefunden. Verfügbare IDs liefert list-forms.', $id ), array( 'status' => 404 ) );
		}
		if ( empty( $schema['form_id'] ) ) {
			$schema['form_id'] = $id;
		}
		$schema['form_id'] = sanitize_key( $schema['form_id'] );
		return $schema;
	}

	/**
	 * Seiten/Beiträge, die ein Formular per Shortcode einbinden.
	 *
	 * @return array form_id => list of pages.
	 */
	protected static function pages_by_form() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','private','draft') AND post_type NOT IN ('revision','nav_menu_item')
			 AND post_content LIKE '%sps_form%' LIMIT 200"
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( preg_match_all( '/\[sps_form[^\]]*\bid\s*=\s*["\']?([a-zA-Z0-9_-]+)/', (string) $r->post_content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $fid ) {
					$key           = sanitize_key( str_replace( '-', '_', $fid ) );
					$out[ $key ][] = array(
						'id'     => (int) $r->ID,
						'title'  => (string) $r->post_title,
						'status' => (string) $r->post_status,
						'url'    => (string) get_permalink( $r->ID ),
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Effektive NC-Formular-Zuordnung.
	 *
	 * @param array $schema Schema.
	 * @return array nc_form_id, source.
	 */
	protected static function nc_assignment( array $schema ) {
		$map = SPS_Settings::get_form_id_map();
		$fid = $schema['form_id'];
		foreach ( array( $fid, str_replace( '_', '-', $fid ), str_replace( '-', '_', $fid ) ) as $k ) {
			if ( ! empty( $map[ $k ] ) ) {
				return array(
					'nc_form_id' => (int) $map[ $k ],
					'source'     => 'fest',
				);
			}
		}
		if ( ! empty( $schema['nc_form_id'] ) && is_numeric( $schema['nc_form_id'] ) ) {
			return array(
				'nc_form_id' => (int) $schema['nc_form_id'],
				'source'     => 'schema',
			);
		}
		return array(
			'nc_form_id' => 0,
			'source'     => 'titelsuche',
		);
	}

	/* ═══════════════════════════════════════════════════════════════
	   CALLBACKS
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * @param array $input Eingabe.
	 * @return array
	 */
	public static function list_forms( array $input ) {
		$renderer = SPS_Form_Renderer::get_instance();
		$pages    = self::pages_by_form();
		$out      = array();

		foreach ( $renderer->get_available_forms() as $form_id => $info ) {
			$schema = $renderer->load_schema( $form_id );
			if ( ! is_array( $schema ) ) {
				continue;
			}
			$schema['form_id'] = sanitize_key( ! empty( $schema['form_id'] ) ? $schema['form_id'] : $form_id );
			$access            = SPS_Form_Renderer::get_access_settings( $schema['form_id'] );
			$nc                = self::nc_assignment( $schema );
			$key               = sanitize_key( str_replace( '-', '_', $schema['form_id'] ) );

			$out[] = array(
				'form_id'          => $schema['form_id'],
				'title'            => isset( $schema['title'] ) ? (string) $schema['title'] : $schema['form_id'],
				'shortcode'        => '[sps_form id="' . $schema['form_id'] . '"]',
				'steps'            => isset( $schema['steps'] ) ? count( (array) $schema['steps'] ) : 0,
				'source'           => isset( $info['source'] ) ? $info['source'] : 'system',
				'requires_login'   => SPS_Form_Renderer::requires_login( $schema ),
				'access_mode'      => '' === $access['access'] ? 'schema' : $access['access'],
				'nc_form_id'       => $nc['nc_form_id'],
				'nc_form_source'   => $nc['source'],
				'theme_customized' => ! empty( SPS_Form_Manager::get_theme( $schema['form_id'] ) ),
				'pages'            => isset( $pages[ $key ] ) ? $pages[ $key ] : array(),
			);
		}

		return array(
			'forms'          => $out,
			'plugin_version' => SPS_VERSION,
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function get_form_schema( array $input ) {
		$schema = self::schema_from_input( $input );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		$steps = array();
		foreach ( isset( $schema['steps'] ) ? (array) $schema['steps'] : array() as $i => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$choices = array();
			foreach ( isset( $step['choices'] ) ? (array) $step['choices'] : array() as $c ) {
				if ( is_array( $c ) ) {
					$choices[] = array_filter(
						array(
							'text'  => isset( $c['text'] ) ? (string) $c['text'] : null,
							'value' => isset( $c['value'] ) ? $c['value'] : null,
						),
						function ( $v ) {
							return null !== $v;
						}
					);
				} elseif ( is_scalar( $c ) ) {
					$choices[] = array( 'text' => (string) $c );
				}
			}
			$fields = array();
			foreach ( array( 'fields', 'inputs', 'items' ) as $k ) {
				foreach ( isset( $step[ $k ] ) && is_array( $step[ $k ] ) ? $step[ $k ] : array() as $f ) {
					if ( is_array( $f ) && ! empty( $f['id'] ) ) {
						$fields[] = (string) $f['id'];
					}
				}
			}
			$steps[] = array_filter(
				array(
					'index'     => (int) $i,
					'id'        => isset( $step['id'] ) ? (string) $step['id'] : '',
					'type'      => isset( $step['type'] ) ? (string) $step['type'] : '',
					'label'     => isset( $step['label'] ) ? wp_strip_all_tags( (string) $step['label'] ) : '',
					'required'  => empty( $step['optional'] ) && ( ! isset( $step['required'] ) || ! empty( $step['required'] ) ),
					'condition' => isset( $step['condition'] ) ? $step['condition'] : ( isset( $step['showIf'] ) ? $step['showIf'] : null ),
					'choices'   => $choices,
					'fields'    => $fields,
				),
				function ( $v ) {
					return null !== $v && array() !== $v && '' !== $v;
				}
			);
		}

		return array(
			'form_id'        => $schema['form_id'],
			'title'          => isset( $schema['title'] ) ? (string) $schema['title'] : '',
			'requires_login' => SPS_Form_Renderer::requires_login( $schema ),
			'steps'          => $steps,
			'answer_keys'    => array_values( SPS_Doctor::available_keys( $schema ) ),
			'submit_rules'   => isset( $schema['submit']['rules'] ) ? count( (array) $schema['submit']['rules'] ) : 0,
			'submit_map'     => isset( $schema['submit']['map'] ) ? (object) $schema['submit']['map'] : new stdClass(),
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function get_form_theme( array $input ) {
		$schema = self::schema_from_input( $input );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		$saved    = SPS_Form_Manager::get_theme( $schema['form_id'] );
		$defaults = SPS_Form_Manager::get_defaults();

		$variables = array();
		foreach ( SPS_Form_Manager::get_color_groups() as $group_key => $group ) {
			foreach ( $group['fields'] as $var => $field ) {
				$variables[] = array(
					'variable' => $var,
					'group'    => $group_key,
					'label'    => $field[0],
					'type'     => 'color',
					'default'  => '' !== $field[1] ? $field[1] : 'automatisch',
					'value'    => isset( $saved[ $var ] ) ? $saved[ $var ] : '',
				);
			}
		}
		foreach ( array(
			'--sps-radius-card'  => 'Karten-Eckenrundung (px)',
			'--sps-radius-input' => 'Eingabefeld-Eckenrundung (px)',
			'--sps-radius-btn'   => 'Button-Eckenrundung (px)',
			'--sps-form-height'  => 'Formularhöhe: "screen" (Standard: genau ein Bildschirm, wird beim Laden in den Blick gescrollt), 480–1100px (feste Höhe) oder "auto"',
		) as $var => $label ) {
			$variables[] = array(
				'variable' => $var,
				'group'    => 'layout',
				'label'    => $label,
				'type'     => '--sps-form-height' === $var ? 'height' : 'radius',
				'default'  => $defaults[ $var ],
				'value'    => isset( $saved[ $var ] ) ? $saved[ $var ] : '',
			);
		}

		$presets = array();
		foreach ( SPS_Form_Manager::get_presets() as $key => $preset ) {
			$presets[] = array(
				'key'    => $key,
				'label'  => $preset['label'],
				'values' => $preset['values'],
			);
		}

		return array(
			'form_id'   => $schema['form_id'],
			'saved'     => (object) $saved,
			'variables' => $variables,
			'presets'   => $presets,
			'note'      => 'Grundfarben (group "base") bestimmen die abgeleiteten Farben. Werte der Gruppen "fine" und "icons" nur setzen, wenn sie gezielt abweichen sollen.',
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function update_form_theme( array $input ) {
		$schema = self::schema_from_input( $input );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		$form_id  = $schema['form_id'];
		$defaults = SPS_Form_Manager::get_defaults();
		$theme    = ! empty( $input['reset'] ) ? array() : SPS_Form_Manager::get_theme( $form_id );

		if ( ! empty( $input['preset'] ) ) {
			$presets = SPS_Form_Manager::get_presets();
			$key     = sanitize_key( (string) $input['preset'] );
			if ( ! isset( $presets[ $key ] ) ) {
				return new WP_Error( 'sps_unknown_preset', sprintf( 'Unbekannte Vorlage "%s". Verfügbar: %s', $key, implode( ', ', array_keys( $presets ) ) ), array( 'status' => 400 ) );
			}
			// Wie im Editor: Vorlage ersetzt alle Farben, Layout bleibt.
			foreach ( $theme as $var => $v ) {
				if ( false === strpos( $var, 'radius' ) && '--sps-form-height' !== $var ) {
					unset( $theme[ $var ] );
				}
			}
			$theme = array_merge( $theme, $presets[ $key ]['values'] );
		}

		$rejected = array();
		$values   = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		foreach ( $values as $var => $value ) {
			$var = (string) $var;
			if ( 0 !== strpos( $var, '--' ) ) {
				$var = '--' . ltrim( $var, '-' );
			}
			if ( ! array_key_exists( $var, $defaults ) ) {
				$rejected[ $var ] = 'unbekannte Variable';
				continue;
			}
			if ( null === $value || '' === trim( (string) $value ) ) {
				unset( $theme[ $var ] );
				continue;
			}
			$clean = SPS_Form_Manager::sanitize_theme_data( array( $var => $value ) );
			if ( ! isset( $clean[ $var ] ) ) {
				$rejected[ $var ] = 'ungültiger Wert "' . $value . '"';
				continue;
			}
			$theme[ $var ] = $clean[ $var ];
		}

		$theme = SPS_Form_Manager::sanitize_theme_data( $theme );
		if ( empty( $theme ) ) {
			SPS_Form_Manager::delete_theme( $form_id );
		} else {
			SPS_Form_Manager::save_theme( $form_id, $theme );
		}

		return array(
			'form_id'  => $form_id,
			'saved'    => (object) SPS_Form_Manager::get_theme( $form_id ),
			'rejected' => (object) $rejected,
			'note'     => 'Gespeichert. Seiten-Cache leeren, damit Besucher die Änderung sehen.',
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function set_form_access( array $input ) {
		$schema = self::schema_from_input( $input );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		$current = SPS_Form_Renderer::get_access_settings( $schema['form_id'] );
		$access  = 'schema' === $input['access'] ? '' : (string) $input['access'];
		$text    = array_key_exists( 'gate_text', $input ) ? (string) $input['gate_text'] : $current['gate_text'];

		SPS_Form_Renderer::save_access_settings( $schema['form_id'], $access, $text );
		$after = SPS_Form_Renderer::get_access_settings( $schema['form_id'] );

		return array(
			'form_id'        => $schema['form_id'],
			'access_mode'    => '' === $after['access'] ? 'schema' : $after['access'],
			'requires_login' => SPS_Form_Renderer::requires_login( $schema ),
			'gate_text'      => '' !== $after['gate_text'] ? $after['gate_text'] : SPS_Form_Renderer::default_gate_text(),
			'note'           => 'Seiten-Cache leeren, damit Gäste die Änderung sehen.',
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function list_nextcloud_forms( array $input ) {
		$forms = SPS_Form_Manager::nc_forms_summary();
		if ( is_wp_error( $forms ) ) {
			return $forms;
		}
		return array( 'forms' => $forms );
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function set_nextcloud_form( array $input ) {
		$schema = self::schema_from_input( $input );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		$form_id = $schema['form_id'];
		$nc_id   = (int) $input['nc_form_id'];
		$title   = '';

		if ( $nc_id > 0 ) {
			$nc_form = ( new SPS_NC_Forms() )->get_form( $nc_id, false );
			if ( is_wp_error( $nc_form ) ) {
				return new WP_Error( 'sps_nc_form_unreachable', sprintf( 'Nextcloud-Formular %d nicht erreichbar: %s', $nc_id, $nc_form->get_error_message() ), array( 'status' => 400 ) );
			}
			$title = isset( $nc_form['title'] ) ? (string) $nc_form['title'] : '';
		}

		$map = SPS_Settings::get_form_id_map();
		unset( $map[ str_replace( '_', '-', $form_id ) ] );
		if ( $nc_id > 0 ) {
			$map[ $form_id ] = $nc_id;
			SPS_NC_Forms::flush_cache( $nc_id );
		} else {
			unset( $map[ $form_id ] );
		}
		SPS_Settings::update_raw( 'nc_form_ids', $map );

		return array(
			'form_id'    => $form_id,
			'nc_form_id' => $nc_id,
			'nc_title'   => $title,
			'note'       => $nc_id ? 'Fest zugeordnet. Mit check-form-mapping prüfen.' : 'Feste Zuordnung entfernt – Suche über den Titel.',
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function check_form_mapping( array $input ) {
		$schema = self::schema_from_input( $input );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		$result = SPS_Form_Manager::mapping_check( $schema, isset( $input['nc_form_id'] ) ? (int) $input['nc_form_id'] : 0 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['form_id'] = $schema['form_id'];
		$result['ok']      = empty( $result['problems'] );
		return $result;
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function preview_submission( array $input ) {
		$schema = self::schema_from_input( $input );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		$answers  = isset( $input['answers'] ) && is_array( $input['answers'] ) ? $input['answers'] : array();
		$prepared = SPS_Answer_Transformer::apply( $answers, $schema );

		$forms = new SPS_NC_Forms();
		$nc_id = $forms->resolve_form_id( $schema );
		if ( is_wp_error( $nc_id ) ) {
			return $nc_id;
		}
		$questions = $forms->get_questions( $nc_id, false );
		if ( is_wp_error( $questions ) ) {
			return $questions;
		}
		$built = $forms->build_submission( $questions, $prepared, $schema );

		$missing = array();
		foreach ( $built['report'] as $row ) {
			if ( ! empty( $row['required'] ) && 'ok' !== $row['status'] && 'Datei' !== $row['status'] ) {
				$missing[] = $row['name'];
			}
		}

		return array(
			'form_id'        => $schema['form_id'],
			'nc_form_id'     => (int) $nc_id,
			'prepared'       => (object) $prepared,
			'report'         => $built['report'],
			'warnings'       => $built['warnings'],
			'would_succeed'  => empty( $missing ),
			'missing_answers' => $missing,
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array
	 */
	public static function run_diagnostics( array $input ) {
		$results = ( new SPS_Doctor( false ) )->run( empty( $input['offline'] ) );
		$count   = array(
			'ok'    => 0,
			'warn'  => 0,
			'error' => 0,
		);
		foreach ( $results as $r ) {
			if ( isset( $count[ $r['status'] ] ) ) {
				$count[ $r['status'] ]++;
			}
		}
		return array(
			'summary' => sprintf( '%d Fehler, %d Warnungen, %d OK', $count['error'], $count['warn'], $count['ok'] ),
			'errors'  => $count['error'],
			'results' => array_values( $results ),
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function list_pending_leads( array $input ) {
		$report = SPS_Submission::retry_pending( isset( $input['limit'] ) ? (int) $input['limit'] : 50, true );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		return array(
			'count' => count( $report ),
			'leads' => array_values( $report ),
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function retry_pending_leads( array $input ) {
		$report = SPS_Submission::retry_pending( isset( $input['limit'] ) ? (int) $input['limit'] : 20, false );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		return array(
			'count'   => count( $report ),
			'results' => array_values( $report ),
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array
	 */
	public static function get_settings( array $input ) {
		$raw = get_option( SPS_Settings::OPTION_NAME, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$settings = array();
		foreach ( array_keys( self::EDITABLE_SETTINGS ) as $key ) {
			$settings[ $key ] = isset( $raw[ $key ] ) ? $raw[ $key ] : null;
		}

		return array(
			'plugin_version'     => SPS_VERSION,
			'nextcloud_url'      => isset( $raw['nextcloud_url'] ) ? (string) $raw['nextcloud_url'] : '',
			'service_account'    => isset( $raw['service_account'] ) ? (string) $raw['service_account'] : '',
			'app_password_set'   => '' !== (string) SPS_Settings::get_setting( 'app_password', '' ),
			'nc_db_configured'   => '' !== (string) SPS_Settings::get_setting( 'nc_db_host', '' ),
			'encryption_key_set' => SPS_Settings::has_dedicated_key(),
			'nc_form_ids'        => (object) SPS_Settings::get_form_id_map(),
			'privacy_url_used'   => SPS_Settings::get_privacy_url(),
			'settings'           => $settings,
			'editable'           => array_keys( self::EDITABLE_SETTINGS ),
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function update_settings( array $input ) {
		$changes = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		if ( empty( $changes ) ) {
			return new WP_Error( 'sps_no_settings', 'Keine Einstellungen angegeben.', array( 'status' => 400 ) );
		}

		$unknown = array_diff( array_keys( $changes ), array_keys( self::EDITABLE_SETTINGS ) );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'sps_setting_not_editable',
				sprintf( 'Nicht über MCP änderbar: %s. Erlaubt: %s. Zugangsdaten nur per "wp sps configure".', implode( ', ', $unknown ), implode( ', ', array_keys( self::EDITABLE_SETTINGS ) ) ),
				array( 'status' => 400 )
			);
		}
		if ( isset( $changes['nc_file_mode'] ) && ! in_array( $changes['nc_file_mode'], array( 'webdav', 'forms' ), true ) ) {
			return new WP_Error( 'sps_invalid_value', 'nc_file_mode muss "webdav" oder "forms" sein.', array( 'status' => 400 ) );
		}

		$current = get_option( SPS_Settings::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();

		// Wie "wp sps configure": aktuelle Werte, Passwörter leer = unverändert.
		$data                   = $current;
		$data['app_password']   = '';
		$data['nc_db_password'] = '';
		$data['nc_form_ids']    = SPS_Settings::get_form_id_map();
		$data['submit_as_user'] = (int) SPS_Settings::get_setting( 'submit_as_user', 1 );
		foreach ( $changes as $key => $value ) {
			$data[ $key ] = 'boolean' === self::EDITABLE_SETTINGS[ $key ] ? ( $value ? 1 : 0 ) : (string) $value;
		}

		$sanitized = SPS_Settings::get_instance()->sanitize_settings( $data );
		remove_all_filters( 'sanitize_option_' . SPS_Settings::OPTION_NAME );
		update_option( SPS_Settings::OPTION_NAME, $sanitized );

		$after = array();
		foreach ( array_keys( $changes ) as $key ) {
			$after[ $key ] = isset( $sanitized[ $key ] ) ? $sanitized[ $key ] : null;
		}

		return array(
			'updated' => (object) $after,
			'note'    => 'Gespeichert (Werte nach Bereinigung).',
		);
	}

	/**
	 * @param array $input Eingabe.
	 * @return array|WP_Error
	 */
	public static function sync_user( array $input ) {
		$ref  = $input['user'];
		$user = is_numeric( $ref ) ? get_userdata( (int) $ref ) : get_user_by( 'email', sanitize_email( (string) $ref ) );
		if ( ! $user ) {
			return new WP_Error( 'sps_user_not_found', 'Benutzer nicht gefunden.', array( 'status' => 404 ) );
		}
		$res = SPS_NC_User_Sync::get_instance()->sync_user( $user->ID, ! empty( $input['force'] ) );
		if ( empty( $res['success'] ) ) {
			return new WP_Error( 'sps_sync_failed', isset( $res['message'] ) ? $res['message'] : 'Sync fehlgeschlagen.' );
		}
		return array(
			'user_id' => (int) $user->ID,
			'email'   => $user->user_email,
			'message' => $res['message'],
		);
	}
}
