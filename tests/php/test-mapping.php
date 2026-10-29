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

echo "Übersetzung auf Optionen des Nextcloud-Formulars (forms_nc/Gebaeude-Check.json)\n";
$t = SPS_Answer_Transformer::apply( array( 'energietraeger' => 'Gas', 'waermeverbrauch' => 15000, 'dachart' => 'Holzdach', 'kellerdecke' => 'Kappendecke' ), $schema );
check( 'Gas -> Erdgas', 'Erdgas' === $t['energietraeger'] );
check( 'Umrechnung noch mit Gas-Faktor (m³)', false !== strpos( (string) $t['waermeverbrauch_original'], 'm³' ) );
check( 'Holzdach -> Dach in Holzkonstruktion', 'Dach in Holzkonstruktion' === $t['dachart'] );
check( 'Kappendecke -> Ziegel-/Hohlsteindecke', 'Ziegel-/Hohlsteindecke' === $t['kellerdecke'] );
$nc = json_decode( file_get_contents( $root . '/forms_nc/Gebaeude-Check.json' ), true );
$nc = isset( $nc['form'] ) ? $nc['form'] : $nc;
$qid = 0;
foreach ( $nc['questions'] as &$nq ) {
	$nq['id'] = ++$qid;
	$oid = 0;
	$nq['options'] = isset( $nq['options'] ) && is_array( $nq['options'] ) ? $nq['options'] : array();
	foreach ( $nq['options'] as &$no ) {
		$no['id'] = $qid * 100 + ( ++$oid );
	}
	unset( $no );
}
unset( $nq );
$bad = array();
foreach ( SPS_Doctor::mapping_report( $schema, $nc['questions'] ) as $r ) {
	if ( 'ja' === $r['required'] && 'ok' !== $r['status'] ) {
		$bad[] = $r['name'] . ': ' . $r['status'];
	}
}
$lead = array( 'energietraeger' => 'Gas', 'dachart' => 'Holzdach', 'kellerdecke' => 'Holz', 'aussenwand_sanierung_jahr' => 2000, 'baujahr' => 1981, 'waermeverbrauch_original' => '1.714 kWh (Strom)' );
$re   = SPS_Answer_Transformer::apply_value_translations( $lead, $schema );
check( 'Alter Lead: Werte übersetzt', 'Erdgas' === $re['energietraeger'] && 'Dach in Holzkonstruktion' === $re['dachart'] && 'Kellerdecke als Holzbalkendecke' === $re['kellerdecke'] );
check( 'Alter Lead: Ableitungen unverändert', 2000 === $re['aussenwand_sanierung_jahr'] && '1.714 kWh (Strom)' === $re['waermeverbrauch_original'] );
check( 'Übersetzung wiederholbar', $re === SPS_Answer_Transformer::apply_value_translations( $re, $schema ) );
check( 'Alle Pflichtfragen des NC-Formulars passen (' . implode( '; ', $bad ) . ')', empty( $bad ) );

echo "Alle Antwortwege gegen das NC-Formular (Pflichtfragen befüllt?)\n";
$base = array(
	'typ' => 'Freistehendes Haus', 'nutzung' => 'Eigennutzung (ausschließlich)', 'flaeche' => 125, 'wohneinheiten' => 1,
	'geschosse' => 3, 'baujahr' => 1963, 'geschosshoehe' => 2.6, 'vollgeschosse' => 2, 'dachgeschoss' => 1, 'beheizt' => 3,
	'energieverbrauch' => 4000, 'kosten' => 32, 'waermeverbrauch' => 18000, 'waermekosten' => 11, 'umrechnungsquote' => 3.5,
	'dachart' => 'Holzdach', 'zinssatz' => 3.5, 'plz' => '73760', 'ort' => 'Ostfildern', 'strasse' => 'Hauptstraße', 'hausnummer' => '1', 'consent' => true,
);
$walls = array(
	array( 'aussenwand_typ' => 'Zweischalig', 'aussenwand_daemmung' => 'Ja' ),
	array( 'aussenwand_typ' => 'Zweischalig', 'aussenwand_daemmung' => 'Nein' ),
	array( 'aussenwand_typ' => 'Massivwand', 'aussenwand_material' => 'Hochlochziegel' ),
	array( 'aussenwand_typ' => 'Massivwand', 'aussenwand_material' => 'Vollziegel', 'aussenwand_dicke_voll' => '< 20 cm' ),
	array( 'aussenwand_typ' => 'Massivwand', 'aussenwand_material' => 'Vollziegel', 'aussenwand_dicke_voll' => '20–30 cm' ),
	array( 'aussenwand_typ' => 'Massivwand', 'aussenwand_material' => 'Vollziegel', 'aussenwand_dicke_voll' => '> 30 cm' ),
	array( 'aussenwand_typ' => 'Massivwand', 'aussenwand_material' => 'Sonstige', 'aussenwand_dicke_sonst' => '< 20 cm' ),
	array( 'aussenwand_typ' => 'Massivwand', 'aussenwand_material' => 'Sonstige', 'aussenwand_dicke_sonst' => '> 20 cm' ),
	array( 'aussenwand_typ' => 'Fachwerk', 'aussenwand_fachwerk' => 'Lehm' ),
	array( 'aussenwand_typ' => 'Fachwerk', 'aussenwand_fachwerk' => 'Ziegel / Naturstein' ),
	array( 'aussenwand_typ' => 'Holzbau / Fertighaus' ),
);
$windows = array(
	array( 'fenster_typ' => 'Holzfenster', 'verglasung' => 'Einfachverglasung' ),
	array( 'fenster_typ' => 'Holzfenster', 'verglasung' => 'Doppelverglasung' ),
	array( 'fenster_typ' => 'Kunststofffenster' ),
	array( 'fenster_typ' => 'Aluminium-/Stahl-Fenster' ),
);
$cellars = array(
	array( 'keller' => 0 ),
	array( 'keller' => 1, 'kellerdecke' => 'Holz', 'keller_sanierung' => 'Nein, es gab keine Sanierung' ),
	array( 'keller' => 1, 'kellerdecke' => 'Massiv', 'keller_sanierung' => 'Ja, es gab eine Sanierung', 'keller_sanierung_jahr' => 1990 ),
	array( 'keller' => 2, 'kellerdecke' => 'Kappendecke' ),
);
$carriers = array();
foreach ( $schema['steps'] as $st ) {
	if ( 'energietraeger' === $st['id'] ) {
		foreach ( $st['choices'] as $c ) { $carriers[] = $c['text']; }
	}
}
$fails_paths = array();
$n           = 0;
foreach ( $walls as $w ) {
	foreach ( $windows as $win ) {
		foreach ( $cellars as $cel ) {
			foreach ( $carriers as $et ) {
				foreach ( array( 'Holzdach', 'Massives Dach', 'Flachdach' ) as $dach ) {
					$n++;
					$a   = array_merge( $base, $w, $win, $cel, array( 'energietraeger' => $et, 'dachart' => $dach ) );
					$pr  = SPS_Answer_Transformer::apply( $a, $schema );
					$res = $forms->build_submission( $nc['questions'], $pr, $schema );
					foreach ( $res['report'] as $r ) {
						if ( ! empty( $r['required'] ) && 'ok' !== $r['status'] ) {
							$fails_paths[ $r['name'] . ': ' . $r['status'] ] = json_encode( array_merge( $w, $win, $cel, array( 'et' => $et, 'dach' => $dach ) ) );
						}
					}
				}
			}
		}
	}
}
check( sprintf( '%d Antwortwege, alle Pflichtfragen befüllt%s', $n, $fails_paths ? ' – ' . implode( ' | ', array_slice( array_map( function ( $k, $v ) { return "$k ($v)"; }, array_keys( $fails_paths ), $fails_paths ), 0, 3 ) ) : '' ), empty( $fails_paths ) );
$t0 = SPS_Answer_Transformer::apply( array_merge( $base, array( 'keller' => 0 ) ), $schema );
check( 'Ohne Keller: Kellerdecke = Stahlbeton, massiv', 'Kellerdecke aus Stahlbeton, massiv' === $t0['kellerdecke'] );

echo "Heizenergie in Rechnungseinheit\n";
$u = SPS_Answer_Transformer::apply( array( 'energietraeger' => 'Öl', 'waermeverbrauch' => 1, 'waermeverbrauch_eingabe' => 2000, 'waermeverbrauch_einheit' => 'Liter' ), $schema );
check( 'Öl 2.000 Liter → 19.600 kWh', 19600 === $u['waermeverbrauch'] && '2.000 Liter' === $u['waermeverbrauch_original'] && 19600 === $u['waermeverbrauch_kwh'] );
check( 'Eingabefelder entfernt', ! isset( $u['waermeverbrauch_eingabe'] ) && ! isset( $u['waermeverbrauch_einheit'] ) );
$u = SPS_Answer_Transformer::apply( array( 'energietraeger' => 'Gas', 'waermeverbrauch_eingabe' => 1800, 'waermeverbrauch_einheit' => 'm³' ), $schema );
check( 'Gas 1.800 m³ → 18.180 kWh, Erdgas', 18180 === $u['waermeverbrauch'] && 'Erdgas' === $u['energietraeger'] );
$u = SPS_Answer_Transformer::apply( array( 'energietraeger' => 'Gas', 'waermeverbrauch_eingabe' => 21000, 'waermeverbrauch_einheit' => 'kWh' ), $schema );
check( 'Gas in kWh 1:1', 21000 === $u['waermeverbrauch'] );
$u = SPS_Answer_Transformer::apply( array( 'energietraeger' => 'Wärmepumpe', 'umrechnungsquote' => 4, 'waermeverbrauch_eingabe' => 4500, 'waermeverbrauch_einheit' => 'kWh Strom' ), $schema );
check( 'Wärmepumpe 4.500 kWh Strom × JAZ 4 → 18.000 kWh', 18000 === $u['waermeverbrauch'] && '4.500 kWh Strom' === $u['waermeverbrauch_original'] );
$u = SPS_Answer_Transformer::apply( array( 'energietraeger' => 'Öl', 'waermeverbrauch' => 15000, 'waermeverbrauch_eingabe' => 5, 'waermeverbrauch_einheit' => 'm³' ), $schema );
check( 'Einheit passt nicht zum Energieträger → alter Weg', 15000 === $u['waermeverbrauch'] );

echo $fails ? "\n$fails Test(s) fehlgeschlagen\n" : "\nAlle Tests bestanden\n";
exit( $fails ? 1 : 0 );
