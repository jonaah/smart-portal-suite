<?php
/**
 * Offline-Tests für die Steckbrief-Variante (ohne WordPress).
 *
 * Aufruf: php tests/php/test-report-variants.php
 */

define( 'ABSPATH', __DIR__ );

$root = dirname( __DIR__, 2 );
define( 'SPS_PLUGIN_DIR', $root . '/' );
define( 'SPS_PLUGIN_URL', 'https://example.org/wp-content/plugins/smart-portal-suite/' );

// ── Minimale WordPress-Stubs ──
$GLOBALS['sps_options'] = array();
$GLOBALS['sps_filters'] = array();
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['sps_options'] ) ? $GLOBALS['sps_options'][ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['sps_options'][ $k ] = $v;
	return true;
}
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['sps_filters'][ $tag ] ) ? call_user_func( $GLOBALS['sps_filters'][ $tag ], $value ) : $value;
}
function esc_url_raw( $u ) {
	return $u;
}
class WP_Error {
	public $code;
	public $msg;
	public function __construct( $c = '', $m = '', $d = null ) {
		$this->code = $c;
		$this->msg  = $m;
	}
	public function get_error_message() {
		return $this->msg;
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}

require $root . '/includes/class-sps-answer-transformer.php';
require $root . '/includes/class-sps-report-variants.php';

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	echo ( $cond ? "  ok   " : "  FAIL " ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

$schema = array(
	'form_id' => 'gebaeude-check',
	'steps'   => array(),
);

echo "Steckbrief-Variante\n";

$variants = SPS_Report_Variants::get_variants();
check( 'Konfiguration enthält Varianten 1–3', isset( $variants[1], $variants[2], $variants[3] ) );
check( 'Bezeichnungen aus der Konfiguration', 'Überblick' === $variants[2]['label'] && 'Ergebnisbericht' === $variants[3]['label'] );
check( 'Vorschaubild variante-2.png automatisch gefunden', false !== strpos( $variants[2]['preview'], 'assets/img/steckbrief/variante-2.png' ) );
check( 'Standard ist 1', 1 === SPS_Report_Variants::get_default() );
check( 'ohne Einstellung → Standard', 1 === SPS_Report_Variants::get_for_form( 'gebaeude-check' ) );

$out = SPS_Answer_Transformer::apply( array( 'Baujahr' => '1970' ), $schema );
check( 'apply() setzt steckbrief_variante', isset( $out['steckbrief_variante'] ) && 1 === $out['steckbrief_variante'] );

$out = SPS_Answer_Transformer::apply( array( 'steckbrief_variante' => '99' ), $schema );
check( 'Browser-Wert wird überschrieben', 1 === $out['steckbrief_variante'] );

check( 'unbekannte Variante abgelehnt', is_wp_error( SPS_Report_Variants::set_for_form( 'gebaeude-check', 7 ) ) );
check( 'Variante 3 speichern', true === SPS_Report_Variants::set_for_form( 'gebaeude-check', 3 ) );
$out = SPS_Answer_Transformer::apply( array(), $schema );
check( 'apply() überträgt 3', 3 === $out['steckbrief_variante'] );
SPS_Report_Variants::set_for_form( 'gebaeude-check', 0 );

// Weitere Varianten per Filter (wie später aus config/report-variants.json).
$GLOBALS['sps_filters']['sps_report_variants'] = function ( $list ) {
	$list[] = array( 'id' => 8, 'label' => 'Kompakt' );
	$list[] = array( 'id' => 9 );
	return $list;
};
$ref = new ReflectionProperty( 'SPS_Report_Variants', 'config' );
$ref->setAccessible( true );
$ref->setValue( null, null );

$variants = SPS_Report_Variants::get_variants();
check( 'Filter ergänzt Varianten 8 und 9', isset( $variants[8], $variants[9] ) );
check( 'Variante ohne Label → "Variante 9"', 'Variante 9' === $variants[9]['label'] );
check( 'ohne Bild → preview leer', '' === $variants[8]['preview'] );

check( 'Variante 8 speichern', true === SPS_Report_Variants::set_for_form( 'gebaeude-check', 8 ) );
$out = SPS_Answer_Transformer::apply( array(), $schema );
check( 'apply() überträgt 8', 8 === $out['steckbrief_variante'] );
check( 'anderes Formular bleibt Standard', 1 === SPS_Report_Variants::get_for_form( 'projekte-mit-mir' ) );

SPS_Report_Variants::set_for_form( 'gebaeude-check', 0 );
check( '0 setzt auf Standard zurück', 1 === SPS_Report_Variants::get_for_form( 'gebaeude-check' ) );

$off = array_merge( $schema, array( 'submit' => array( 'report_variant_field' => '' ) ) );
$out = SPS_Answer_Transformer::apply( array(), $off );
check( 'Schema kann Übertragung abschalten', ! isset( $out['steckbrief_variante'] ) );

$alt = array_merge( $schema, array( 'submit' => array( 'report_variant_field' => 'Layout-Nr' ) ) );
$out = SPS_Answer_Transformer::apply( array(), $alt );
check( 'eigener Fragename aus Schema', isset( $out['layout_nr'] ) && ! isset( $out['steckbrief_variante'] ) );

echo $fails ? "\n$fails Test(s) fehlgeschlagen.\n" : "\nAlle Tests bestanden.\n";
exit( $fails ? 1 : 0 );
