<?php
/**
 * Offline-Tests für den Austauschordner (ohne WordPress, Nextcloud simuliert).
 *
 * Aufruf: php tests/php/test-exchange.php
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

// ── Minimale WordPress-Stubs ──
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
class WP_User {
	public $ID;
	public $user_email;
	public $user_login;
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
function is_email( $e ) {
	return false !== filter_var( $e, FILTER_VALIDATE_EMAIL );
}
function apply_filters( $tag, $value ) {
	return $value;
}
function add_action() {}
function get_option( $k, $d = null ) {
	return $d;
}
$GLOBALS['transients'] = array();
function get_transient( $k ) {
	return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false;
}
function set_transient( $k, $v ) {
	$GLOBALS['transients'][ $k ] = $v;
}
$GLOBALS['meta'] = array();
function get_user_meta( $id, $k, $single = true ) {
	return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : '';
}
function update_user_meta( $id, $k, $v ) {
	$GLOBALS['meta'][ $id ][ $k ] = $v;
}
$GLOBALS['users'] = array();
function get_userdata( $id ) {
	return isset( $GLOBALS['users'][ $id ] ) ? $GLOBALS['users'][ $id ] : false;
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['code'];
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}
function wp_list_pluck( $list, $field ) {
	return array_values( array_map( function ( $e ) use ( $field ) { return $e[ $field ]; }, $list ) );
}
function wp_next_scheduled() {
	return false;
}
function wp_schedule_single_event() {}

class SPS_Diagnostics {
	public static function log( $m ) {}
}
class SPS_Settings {
	public static $s = array();
	public static function get_setting( $k, $d = null ) {
		return array_key_exists( $k, self::$s ) ? self::$s[ $k ] : $d;
	}
}

// ── Simulierte Nextcloud ──
class SPS_NC_Client {
	public static $dirs   = array();
	public static $shares = array(); // path => [uid => perm]
	public static $users  = array(); // uid => email
	public static $calls  = array();
	public static $owner  = array(); // path => owner-id

	public static function service() {
		return new self();
	}
	public function is_configured() {
		return true;
	}
	public function get_username() {
		return 'service-login@example.org';
	}
	public function get_uid() {
		return 'sps_service';
	}
	public function get_base_url() {
		return 'https://nc.example.org';
	}
	protected static function ocs( $data, $code = 200 ) {
		return array( 'code' => $code, 'body' => json_encode( array( 'ocs' => array( 'meta' => array( 'statuscode' => $code ), 'data' => $data ) ) ) );
	}
	public function request( $endpoint, $method = 'GET', $body = null ) {
		self::$calls[] = $method . ' ' . $endpoint;
		$q = array();
		parse_str( (string) parse_url( $endpoint, PHP_URL_QUERY ), $q );
		if ( 'PROPFIND' === $method ) {
			$path = rawurldecode( substr( $endpoint, strlen( 'remote.php/dav/files/sps_service/' ) ) );
			if ( ! in_array( $path, self::$dirs, true ) ) {
				return array( 'code' => 404, 'body' => '' );
			}
			$o = isset( self::$owner[ $path ] ) ? self::$owner[ $path ] : 'sps_service';
			return array( 'code' => 207, 'body' => '<d:multistatus><d:response><oc:owner-id>' . $o . '</oc:owner-id></d:response></d:multistatus>' );
		}
		if ( false !== strpos( $endpoint, 'cloud/users/details' ) ) {
			$out = array();
			foreach ( self::$users as $uid => $mail ) {
				if ( false !== stripos( $mail, $q['search'] ) || false !== stripos( $uid, $q['search'] ) ) {
					$out[ $uid ] = array( 'id' => $uid, 'email' => $mail );
				}
			}
			return self::ocs( array( 'users' => $out ) );
		}
		if ( false !== strpos( $endpoint, 'sharees' ) ) {
			return self::ocs( array( 'exact' => array( 'users' => array() ) ) );
		}
		if ( false !== strpos( $endpoint, 'files_sharing/api/v1/shares' ) ) {
			if ( 'POST' === $method ) {
				self::$shares[ $body['path'] ][ $body['shareWith'] ] = $body['permissions'];
				return self::ocs( array( 'id' => 1 ) );
			}
			$path = $q['path'];
			if ( ! in_array( ltrim( $path, '/' ), self::$dirs, true ) ) {
				return self::ocs( array(), 404 );
			}
			$out = array();
			foreach ( isset( self::$shares[ $path ] ) ? self::$shares[ $path ] : array() as $uid => $perm ) {
				$out[] = array( 'share_type' => 0, 'share_with' => $uid, 'permissions' => $perm );
			}
			return self::ocs( $out );
		}
		return array( 'code' => 500, 'body' => '' );
	}
	public static function parse_ocs( $r ) {
		$d = json_decode( $r['body'], true );
		if ( $r['code'] >= 200 && $r['code'] < 300 ) {
			return $d['ocs']['data'];
		}
		return new WP_Error( 'nc', 'HTTP ' . $r['code'], array( 'http' => $r['code'] ) );
	}
}
class SPS_NC_WebDAV {
	public function __construct( $c = null ) {}
	public function exists( $p ) {
		return in_array( $p, SPS_NC_Client::$dirs, true );
	}
	public function list_dir( $p ) {
		$out = array();
		foreach ( SPS_NC_Client::$dirs as $d ) {
			if ( 0 === strpos( $d, $p . '/' ) && false === strpos( substr( $d, strlen( $p ) + 1 ), '/' ) ) {
				$out[] = array( 'name' => substr( $d, strlen( $p ) + 1 ), 'is_dir' => true );
			}
		}
		return $out;
	}
	public function ensure_dir( $p ) {
		$acc = '';
		foreach ( explode( '/', $p ) as $seg ) {
			$acc = ltrim( $acc . '/' . $seg, '/' );
			if ( ! in_array( $acc, SPS_NC_Client::$dirs, true ) ) {
				SPS_NC_Client::$dirs[] = $acc;
			}
		}
		return true;
	}
}

require __DIR__ . '/../../modules/nextcloud/class-sps-nc-exchange.php';

$fails = 0;
function check( $name, $cond ) {
	global $fails;
	echo ( $cond ? 'OK   ' : 'FAIL ' ) . $name . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

// ── Fixture ──
$u             = new WP_User();
$u->ID         = 7;
$u->user_email = 'Max.Muster@Example.org';
$u->user_login = 'max';
$GLOBALS['users'][7] = $u;
SPS_Settings::$s['admin_email'] = 'berater@example.org';
SPS_NC_Client::$users = array(
	'sps_service' => 'service@example.org',
	'berater'     => 'berater@example.org',
	'wordpress-7' => 'max.muster@example.org',
);
$answers = array( 'plz' => 12345, 'ort' => 'Musterstadt', 'strasse' => 'Am Muster_weg', 'hausnummer' => '1a' );
$name    = 'max.muster@example.org_12345_Musterstadt_Am Muster weg_1a';

function reset_nc() {
	SPS_NC_Client::$dirs   = array();
	SPS_NC_Client::$shares = array();
	SPS_NC_Client::$owner  = array();
	$GLOBALS['transients'] = array();
	$GLOBALS['meta'][7]    = array();
}

// 0. Namenskonvention
check( 'Name E-Mail_PLZ_Ort_Straße_Hausnr', $name === SPS_NC_Exchange::object_name( $u->user_email, $answers ) );
check( 'Name ohne Adresse = E-Mail', 'max.muster@example.org' === SPS_NC_Exchange::object_name( $u->user_email, array() ) );
check( 'Umlaute bleiben', 'a@b.de_73614_Schönaich_Hauptstraße_2' === SPS_NC_Exchange::object_name( 'a@b.de', array( 'plz' => '73614', 'ort' => 'Schönaich', 'strasse' => 'Hauptstraße', 'hausnummer' => '2' ) ) );
check( 'Vorhandener Ordner mit Zusatz', 'x_1_O_S_2_Fenster_BEG' === SPS_NC_Exchange::match_existing( array( 'andere', 'x_1_O_S_2_Fenster_BEG' ), 'x_1_O_S_2' ) );
check( 'Kein Treffer bei anderer Hausnr', '' === SPS_NC_Exchange::match_existing( array( 'x_1_O_S_22' ), 'x_1_O_S_2' ) );

// 1. Ohne NC-Konto → pending
reset_nc();
SPS_NC_Exchange::on_submission( 'gebaeude_check', 7, 'forms', array( 'submit' => array( 'exchange_folder' => true ) ), $answers );
check( 'Objekt gemerkt', array( $name ) === SPS_NC_Exchange::objects( 7 ) );
$r = SPS_NC_Exchange::ensure( 7 );
check( 'ohne NC-Konto: pending', is_wp_error( $r ) && 'pending' === get_user_meta( 7, 'sps_exchange_status' ) );

// 2. Berater hat „Projekte“ mit dem Service-Account geteilt
update_user_meta( 7, 'nextcloud_username', 'wordpress-7' );
SPS_NC_Client::$dirs[]              = 'Projekte';
SPS_NC_Client::$owner['Projekte']   = 'berater';
$r    = SPS_NC_Exchange::ensure( 7 );
$path = 'Projekte/' . $name;
check( 'Berater-Modus', ! is_wp_error( $r ) && 'berater' === $r['mode'] );
check( 'Ordner im Projekte des Beraters', in_array( $path, SPS_NC_Client::$dirs, true ) );
check( 'Nur Kunde freigegeben (15)', array( 'wordpress-7' => 15 ) === SPS_NC_Client::$shares[ '/' . $path ] );
check( 'Idempotent', empty( SPS_NC_Exchange::ensure( 7 )['actions'] ) );

// 3. Vorhandener Projektordner (mit Zusatz) wird wiederverwendet
reset_nc();
update_user_meta( 7, 'nextcloud_username', 'wordpress-7' );
update_user_meta( 7, 'sps_exchange_objects', array( $name ) );
SPS_NC_Client::$dirs              = array( 'Projekte', 'Projekte/' . $name . '_Fenstertausch_BEG' );
SPS_NC_Client::$owner['Projekte'] = 'berater';
$r = SPS_NC_Exchange::ensure( 7 );
check( 'Vorhandenen Ordner genutzt', array( 'Projekte/' . $name . '_Fenstertausch_BEG' ) === $r['folders'] );
check( 'Kein zweiter Ordner', 2 === count( SPS_NC_Client::$dirs ) );

// 4. Ohne Freigabe des Beraters: beim Service-Account + Berater-Freigabe
reset_nc();
update_user_meta( 7, 'nextcloud_username', 'wordpress-7' );
update_user_meta( 7, 'sps_exchange_objects', array( $name ) );
$r = SPS_NC_Exchange::ensure( 7 );
check( 'Service-Modus', ! is_wp_error( $r ) && 'service' === $r['mode'] );
check( 'Kunde (15) + Berater (31)', array( 'wordpress-7' => 15, 'berater' => 31 ) === SPS_NC_Client::$shares[ '/' . $path ] );

// 5. Berater = Service-Account → nur Kunde
reset_nc();
update_user_meta( 7, 'nextcloud_username', 'wordpress-7' );
update_user_meta( 7, 'sps_exchange_objects', array( $name ) );
SPS_Settings::$s['admin_email'] = 'service@example.org';
$r = SPS_NC_Exchange::ensure( 7 );
check( 'Berater = Service-Account: nur Kunde', ! is_wp_error( $r ) && array( 'wordpress-7' => 15 ) === SPS_NC_Client::$shares[ '/' . $path ] );

// 6. Unbekannte Berater-Mail → Fehler mit Hinweis
$GLOBALS['transients'] = array();
SPS_Settings::$s['admin_email'] = 'niemand@example.org';
$r = SPS_NC_Exchange::ensure( 7 );
check( 'Unbekannter Berater: Fehler', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Berater-Konto' ) );

// 7. Festes Berater-Konto
SPS_Settings::$s['exchange_advisor_user'] = 'berater';
$r = SPS_NC_Exchange::ensure( 7 );
check( 'Festes Berater-Konto', ! is_wp_error( $r ) && 'berater' === $r['advisor'] );

// 8. Dry-run ändert nichts
reset_nc();
update_user_meta( 7, 'nextcloud_username', 'wordpress-7' );
update_user_meta( 7, 'sps_exchange_objects', array( $name ) );
$r = SPS_NC_Exchange::ensure( 7, true );
check( 'Dry-run: Aktionen gemeldet', 4 === count( $r['actions'] ) );
check( 'Dry-run: nichts angelegt', empty( SPS_NC_Client::$dirs ) && empty( SPS_NC_Client::$shares ) );

// 9. Formular-Auswahl
SPS_Settings::$s['exchange_enabled'] = 1;
check( 'Schema-Option an', SPS_NC_Exchange::form_wants_folder( array( 'submit' => array( 'exchange_folder' => true ) ) ) );
check( 'Schema-Option aus', ! SPS_NC_Exchange::form_wants_folder( array( 'form_id' => 'gebaeude_check', 'submit' => array( 'exchange_folder' => false ) ) ) );
check( 'Gebäude-Check ohne Option: an', SPS_NC_Exchange::form_wants_folder( array( 'form_id' => 'gebaeude-check' ) ) );
check( 'Anderes Formular ohne Option: aus', ! SPS_NC_Exchange::form_wants_folder( array( 'form_id' => 'projekte_mit_mir' ) ) );
SPS_Settings::$s['exchange_enabled'] = 0;
check( 'Global aus', ! SPS_NC_Exchange::form_wants_folder( array( 'submit' => array( 'exchange_folder' => true ) ) ) );

echo $fails ? "\n$fails Test(s) fehlgeschlagen\n" : "\nAlle Tests bestanden\n";
exit( $fails ? 1 : 0 );
