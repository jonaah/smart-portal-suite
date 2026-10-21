<?php
/**
 * Offline-Tests für Antwort-Aufbereitung und Forms-Zuordnung (ohne WordPress).
 *
 * Aufruf: php tests/php/test-mapping.php
 */

define( 'ABSPATH', __DIR__ );

// ── Minimale WordPress-Stubs ──
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
class WP_Error {
	public $code;
	public $msg;
	public $data;
	public function __construct( $c = '', $m = '', $d = null ) {
		$this->code = $c;
		$this->msg  = $m;
		$this->data = $d;
	}
	public function get_error_message() {
		return $this->msg;
	}
	public function get_error_data() {
		return $this->data;
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
class SPS_Settings {
	public static $values = array();
	public static function get_setting( $k, $d = null ) {
		return isset( self::$values[ $k ] ) ? self::$values[ $k ] : $d;
	}
	public static function get_form_id_map() {
		return isset( self::$values['nc_form_ids'] ) ? self::$values['nc_form_ids'] : array();
	}
}
class SPS_NC_Client {
	public static function service() {
		return new self();
	}
}

$root = dirname( __DIR__, 2 );
require $root . '/includes/class-sps-answer-transformer.php';
require $root . '/modules/nextcloud/class-sps-nc-forms.php';
require $root . '/includes/class-sps-doctor.php';

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	echo ( $cond ? "  ok   " : "  FAIL " ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

$schema = json_decode( file_get_contents( $root . '/config/forms/gebaeude-check.json' ), true );

// Antworten wie vom neuen Frontend (Radio-Werte als Strings, Schlüssel aus sanitize_key).
$raw = array(
	'typ'                       => 'Reihenmittelhaus',
	'flaeche'                   => '125',
	'baujahr'                   => '2010',
	'baujahr_detail'            => '2011',
	'geschosshoehe'             => '2.85',
	'dachgeschoss'              => '0',
	'energietraeger'            => 'Öl',
	'waermeverbrauch'           => '15000',
	'aussenwand_typ'            => 'Massivwand',
	'aussenwand_material'       => 'Vollziegel',
	'aussenwand_dicke_voll'     => '20–30 cm',
	'aussenwand_sanierung'      => 'Nein, es gab keine Sanierung',
	'aussenwand_sanierung_jahr' => '1999',
	'fenster_typ'               => 'Holzfenster',
	'verglasung'                => 'Doppelverglasung',
	'fenster_sanierung'         => 'Ja, es gab eine Sanierung',
	'fenster_sanierung_jahr'    => '2018',
	'plz'                       => '73760',
	'ort'                       => 'Ostfildern',
	'vorname'                   => 'Erika',
	'nachname'                  => 'Muster',
	'e-mail'                    => 'erika@example.com',
	'consent'                   => true,
);

echo "Transformer\n";
$p = SPS_Answer_Transformer::apply( $raw, $schema );
check( 'Baujahr aus Detail (2011, int)', 2011 === $p['baujahr'] );
check( 'baujahr_detail entfernt', ! isset( $p['baujahr_detail'] ) );
check( 'Außenwand abgeleitet', 'Massivwand aus Vollziegel / Naturstein / Kalksandstein / Bims-Vollstein 20–30 cm' === $p['aussenwand'] );
check( 'Rohfelder Außenwand entfernt', ! isset( $p['aussenwand_typ'] ) && ! isset( $p['aussenwand_material'] ) );
check( 'Fenster abgeleitet', 'Holzfenster, 2-Scheiben-Isolierverglasung / Kastenfenster / Verbundfenster' === $p['fenster'] );
check( 'Sanierung Nein → Jahr = Baujahr', 2011 === $p['aussenwand_sanierung_jahr'] );
check( 'Sanierung Ja → Jahr bleibt', 2018 === $p['fenster_sanierung_jahr'] );
check( 'Dach ohne Antwort → Jahr = Baujahr', 2011 === $p['dach_sanierung_jahr'] );
check( 'Wärmeverbrauch original (Öl)', '1.531 Liter' === $p['waermeverbrauch_original'] );
check( 'Wärmeverbrauch kWh', 15000 === $p['waermeverbrauch_kwh'] );
check( 'Fläche int', 125 === $p['flaeche'] );
check( 'Geschosshöhe double', 2.85 === $p['geschosshoehe'] );
check( 'Dachgeschoss 0 bleibt erhalten', 0 === $p['dachgeschoss'] );

$p2 = SPS_Answer_Transformer::apply( array( 'energietraeger' => 'Wärmepumpe', 'waermeverbrauch' => '7000', 'umrechnungsquote' => '3.5' ), $schema );
check( 'Wärmepumpe mit Quote', '2.000 kWh (Strom)' === $p2['waermeverbrauch_original'] );
$p3 = SPS_Answer_Transformer::apply( array( 'energietraeger' => 'Strom', 'waermeverbrauch' => '15000' ), $schema );
check( 'Strom direkt in kWh', '15.000 kWh' === $p3['waermeverbrauch_original'] );
$p4 = SPS_Answer_Transformer::apply( array( 'baujahr' => '1963', 'baujahr_detail' => '2011' ), $schema );
check( 'Baujahr < 2002 bleibt', 1963 === $p4['baujahr'] );

echo "Zuordnung\n";
$questions = array(
	array( 'id' => 1, 'name' => 'typ', 'type' => 'multiple_unique', 'isRequired' => true, 'options' => array( array( 'id' => 11, 'text' => 'Freistehendes Haus' ), array( 'id' => 12, 'text' => 'Reihenmittelhaus' ) ) ),
	array( 'id' => 2, 'name' => 'flaeche', 'type' => 'dropdown', 'options' => array( array( 'id' => 21, 'text' => '<100 qm' ), array( 'id' => 22, 'text' => '100-150 qm' ) ) ),
	array( 'id' => 3, 'name' => 'baujahr', 'type' => 'short' ),
	array( 'id' => 4, 'name' => 'aussenwand', 'type' => 'short' ),
	array( 'id' => 5, 'name' => 'aussenwand-sanierung-jahr', 'type' => 'short' ),
	array( 'id' => 6, 'name' => 'waermeverbrauch-original', 'type' => 'short' ),
	array( 'id' => 7, 'name' => 'plz_ort', 'type' => 'short' ),
	array( 'id' => 8, 'name' => 'Vorname_Nachname', 'type' => 'short' ),
	array( 'id' => 9, 'name' => 'E-Mail', 'type' => 'short', 'isRequired' => true ),
	array( 'id' => 10, 'name' => 'dachgeschoss', 'type' => 'short' ),
	array( 'id' => 13, 'name' => 'geschosshoehe', 'type' => 'short' ),
	array( 'id' => 14, 'name' => 'telefon', 'type' => 'short', 'isRequired' => true ),
	array( 'id' => 15, 'name' => '', 'type' => 'short' ),
	array( 'id' => 16, 'name' => 'fenster', 'type' => 'dropdown', 'options' => array( array( 'id' => 61, 'text' => 'Kunststofffenster, Isolierverglasung' ) ) ),
);

$forms = new SPS_NC_Forms();
$b     = $forms->build_submission( $questions, $p, $schema );
$a     = $b['answers'];

check( 'Option per Text → ID', array( 12 ) === $a['1'] );
check( 'Option per Wert 125 → Text "100-150 qm" → ID', array( 22 ) === $a['2'] );
check( 'Text als Array', array( '2011' ) === $a['3'] );
check( 'Abgeleitete Außenwand', isset( $a['4'] ) && 0 === strpos( $a['4'][0], 'Massivwand aus Vollziegel' ) );
check( 'Name mit "-" findet Antwort mit "_"', array( '2011' ) === $a['5'] );
check( 'waermeverbrauch-original', array( '1.531 Liter' ) === $a['6'] );
check( 'Zusammengesetzt plz_ort', array( '73760 Ostfildern' ) === $a['7'] );
check( 'Großschreibung Vorname_Nachname', array( 'Erika Muster' ) === $a['8'] );
check( 'E-Mail (Bindestrich, groß)', array( 'erika@example.com' ) === $a['9'] );
check( 'Wert 0 wird übertragen', array( '0' ) === $a['10'] );
check( 'Kommazahl', array( '2.85' ) === $a['13'] );
check( 'fehlende Pflichtfrage nicht gesendet', ! isset( $a['14'] ) );
check( 'Warnung für fehlende Pflichtfrage', (bool) preg_grep( '/telefon/', $b['warnings'] ) );
check( 'Frage ohne Namen ignoriert', ! isset( $a['15'] ) );
check( 'unpassende Option → nicht gesendet + Warnung', ! isset( $a['16'] ) && (bool) preg_grep( '/fenster/', $b['warnings'] ) );

$json = json_encode( array( 'answers' => (object) $a ) );
check( 'Payload ist Objekt mit Frage-IDs', 0 === strpos( $json, '{"answers":{"1":[12]' ) );

echo "Mapping-Report\n";
$rows = array();
foreach ( SPS_Doctor::mapping_report( $schema, $questions ) as $row ) {
	$rows[ $row['id'] ] = $row;
}
check( 'fehlende Option erkannt (typ: NC hat 2 von 3)', 0 === strpos( $rows['1']['status'], 'Optionen fehlen in NC: Reihenendhaus' ) );
check( 'aussenwand aus Regel verfügbar', 'ok' === $rows['4']['status'] );
check( 'waermeverbrauch-original verfügbar', 'ok' === $rows['6']['status'] );
check( 'plz_ort zusammengesetzt', 'plz+ort' === $rows['7']['source'] );
check( 'telefon Pflicht fehlt', 'FEHLT (Pflicht)' === $rows['14']['status'] );
check( 'fenster: Regelwerte fehlen als Optionen', 0 === strpos( $rows['16']['status'], 'Optionen fehlen' ) );
$keys = SPS_Doctor::available_keys( $schema );
check( 'entfernte Rohfelder nicht verfügbar', ! in_array( 'aussenwand_typ', $keys, true ) );
check( 'Sanierungsjahr verfügbar', in_array( 'aussenwand_sanierung_jahr', $keys, true ) );

echo "Regel-Bedingungen\n";
check( 'ne bei fehlendem Feld = wahr', SPS_Answer_Transformer::matches( array(), array( 'x' => array( 'ne' => 'a' ) ) ) );
check( 'gte', SPS_Answer_Transformer::matches( array( 'x' => 2002 ), array( 'x' => array( 'gte' => 2002 ) ) ) );
check( 'Liste = in', SPS_Answer_Transformer::matches( array( 'x' => 'b' ), array( 'x' => array( 'a', 'b' ) ) ) );

echo $fails ? "\n$fails Test(s) fehlgeschlagen\n" : "\nAlle Tests bestanden\n";
exit( $fails ? 1 : 0 );
