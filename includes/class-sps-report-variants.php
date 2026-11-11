<?php
/**
 * Steckbrief-Varianten.
 *
 * Der Job auf dem Contabo-Server erzeugt aus der Nextcloud-Formularantwort
 * den PDF-Steckbrief. Welches Layout er verwendet, entscheidet eine Zahl,
 * die das Plugin als Antwort auf die Nextcloud-Frage mit dem technischen
 * Namen `steckbrief_variante` mitschickt.
 *
 *  - Verfügbare Varianten: config/report-variants.json (Filter `sps_report_variants`).
 *  - Auswahl je Formular: Smart Portal → Formulare → Styling → „Steckbrief-Variante“.
 *  - Der Wert wird serverseitig gesetzt (nicht aus dem Browser übernommen).
 *  - Hat das Nextcloud-Formular keine solche Frage, wird nichts übertragen.
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Report_Variants {

	/**
	 * Standardname der Nextcloud-Frage (technischer Name).
	 */
	const FIELD = 'steckbrief_variante';

	/**
	 * Option: form_id => Variantennummer.
	 */
	const OPTION = 'sps_report_variants';

	/**
	 * Cache der Konfiguration.
	 *
	 * @var array|null
	 */
	private static $config = null;

	/**
	 * Konfiguration laden.
	 *
	 * @return array array( 'default' => int, 'variants' => array )
	 */
	protected static function config() {
		if ( null !== self::$config ) {
			return self::$config;
		}

		$raw  = array();
		$file = SPS_PLUGIN_DIR . 'config/report-variants.json';
		if ( file_exists( $file ) ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		$list = isset( $raw['variants'] ) && is_array( $raw['variants'] ) ? $raw['variants'] : array();

		/**
		 * Steckbrief-Varianten erweitern oder ersetzen.
		 *
		 * @param array $list Liste aus array( id, label, description, preview ).
		 */
		$list = apply_filters( 'sps_report_variants', $list );

		$variants = array();
		foreach ( (array) $list as $v ) {
			if ( ! is_array( $v ) || empty( $v['id'] ) || (int) $v['id'] < 1 ) {
				continue;
			}
			$id              = (int) $v['id'];
			$variants[ $id ] = array(
				'id'          => $id,
				'label'       => isset( $v['label'] ) && '' !== trim( (string) $v['label'] ) ? (string) $v['label'] : sprintf( 'Variante %d', $id ),
				'description' => isset( $v['description'] ) ? (string) $v['description'] : '',
				'preview'     => self::preview_url( $id, isset( $v['preview'] ) ? (string) $v['preview'] : '' ),
			);
		}
		if ( empty( $variants ) ) {
			$variants[1] = array(
				'id'          => 1,
				'label'       => 'Standard',
				'description' => '',
				'preview'     => self::preview_url( 1, '' ),
			);
		}
		ksort( $variants );

		$default = isset( $raw['default'] ) ? (int) $raw['default'] : 0;
		if ( ! isset( $variants[ $default ] ) ) {
			$default = (int) key( $variants );
		}

		self::$config = array(
			'default'  => $default,
			'variants' => $variants,
		);
		return self::$config;
	}

	/**
	 * URL des Vorschaubilds (oder leer).
	 *
	 * @param int    $id      Variantennummer.
	 * @param string $preview Angabe aus der Konfiguration (URL oder Pfad relativ zum Plugin).
	 * @return string
	 */
	protected static function preview_url( $id, $preview ) {
		$preview = trim( $preview );
		if ( '' !== $preview ) {
			if ( preg_match( '#^https?://#i', $preview ) ) {
				return esc_url_raw( $preview );
			}
			$rel = ltrim( str_replace( '..', '', $preview ), '/' );
			return file_exists( SPS_PLUGIN_DIR . $rel ) ? SPS_PLUGIN_URL . $rel : '';
		}
		foreach ( array( 'webp', 'png', 'jpg', 'svg' ) as $ext ) {
			$rel = 'assets/img/steckbrief/variante-' . (int) $id . '.' . $ext;
			if ( file_exists( SPS_PLUGIN_DIR . $rel ) ) {
				return SPS_PLUGIN_URL . $rel;
			}
		}
		return '';
	}

	/**
	 * Alle Varianten.
	 *
	 * @return array id => array( id, label, description, preview )
	 */
	public static function get_variants() {
		$c = self::config();
		return $c['variants'];
	}

	/**
	 * Standardvariante.
	 *
	 * @return int
	 */
	public static function get_default() {
		$c = self::config();
		return $c['default'];
	}

	/**
	 * Gespeicherte Variante eines Formulars (0 = nicht gesetzt).
	 *
	 * @param string $form_id Formular-ID.
	 * @return int
	 */
	public static function get_saved( $form_id ) {
		$map = get_option( self::OPTION, array() );
		$key = sanitize_key( $form_id );
		return ( is_array( $map ) && isset( $map[ $key ] ) ) ? (int) $map[ $key ] : 0;
	}

	/**
	 * Wirksame Variante eines Formulars (gespeichert und gültig, sonst Standard).
	 *
	 * @param string $form_id Formular-ID.
	 * @return int
	 */
	public static function get_for_form( $form_id ) {
		$saved    = self::get_saved( $form_id );
		$variants = self::get_variants();
		return isset( $variants[ $saved ] ) ? $saved : self::get_default();
	}

	/**
	 * Variante für ein Formular speichern. 0 = auf Standard zurücksetzen.
	 *
	 * @param string $form_id Formular-ID.
	 * @param int    $id      Variantennummer.
	 * @return true|WP_Error
	 */
	public static function set_for_form( $form_id, $id ) {
		$key = sanitize_key( $form_id );
		$id  = (int) $id;
		if ( '' === $key ) {
			return new WP_Error( 'sps_missing_form', 'Formular-ID fehlt.' );
		}

		$map = get_option( self::OPTION, array() );
		$map = is_array( $map ) ? $map : array();

		if ( 0 === $id ) {
			unset( $map[ $key ] );
		} else {
			$variants = self::get_variants();
			if ( ! isset( $variants[ $id ] ) ) {
				return new WP_Error( 'sps_unknown_variant', sprintf( 'Unbekannte Steckbrief-Variante %d. Verfügbar: %s', $id, implode( ', ', array_keys( $variants ) ) ), array( 'status' => 400 ) );
			}
			$map[ $key ] = $id;
		}

		update_option( self::OPTION, $map, false );
		return true;
	}

	/**
	 * Technischer Name der Nextcloud-Frage für ein Formular.
	 *
	 * Im Schema überschreibbar: "submit": { "report_variant_field": "..." }.
	 * Leerer String im Schema schaltet die Übertragung für dieses Formular ab.
	 *
	 * @param array|null $schema Schema.
	 * @return string Normalisierter Schlüssel oder ''.
	 */
	public static function field_name( $schema ) {
		$name = self::FIELD;
		if ( is_array( $schema ) && isset( $schema['submit'] ) && is_array( $schema['submit'] ) && array_key_exists( 'report_variant_field', $schema['submit'] ) ) {
			$name = (string) $schema['submit']['report_variant_field'];
		}
		$name = apply_filters( 'sps_report_variant_field', $name, $schema );
		return '' === trim( (string) $name ) ? '' : SPS_Answer_Transformer::norm( $name );
	}

	/**
	 * Variante in die aufbereiteten Antworten schreiben (überschreibt Browser-Werte).
	 *
	 * @param array      $answers Aufbereitete Antworten (normalisierte Schlüssel).
	 * @param array|null $schema  Schema.
	 * @return array
	 */
	public static function inject( array $answers, $schema ) {
		if ( ! is_array( $schema ) || empty( $schema['form_id'] ) ) {
			return $answers;
		}
		$field = self::field_name( $schema );
		if ( '' === $field ) {
			return $answers;
		}
		$answers[ $field ] = self::get_for_form( $schema['form_id'] );
		return $answers;
	}
}
