<?php
/**
 * Offline-Test: Bestätigung nach dem Absenden – Knopf in die Nextcloud oder in die App.
 *
 * Aufruf: php tests/php/test-success-link.php
 */

define( 'ABSPATH', __DIR__ );

// ── Minimale WordPress-Stubs ──
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function __( $s, $d = null ) {
	return $s;
}
function untrailingslashit( $s ) {
	return rtrim( (string) $s, '/\\' );
}
function add_action() {}
function add_filter() {}

require dirname( __DIR__, 2 ) . '/includes/class-sps-ajax-handler.php';

$fails = 0;
function check( $label, $cond ) {
	global $fails;
	echo ( $cond ? 'OK   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

$cfg = array(
	'title'          => 'Danke!',
	'message'        => 'Sie finden die Auswertung in der Nextcloud.',
	'message_app'    => 'Sie finden die Auswertung in der App.',
	'note'           => 'Melden Sie sich dort an.',
	'link_label'     => 'Zur Nextcloud',
	'link_label_app' => 'Zur App',
);

// Ohne App: wie bisher
$r = SPS_Ajax_Handler::success_payload( $cfg, 'https://nc.example.org', '' );
check( 'ohne App: Knopf in die Nextcloud', 'https://nc.example.org' === $r['link_url'] && 'Zur Nextcloud' === $r['link_label'] );
check( 'ohne App: Nextcloud-Text', 'Sie finden die Auswertung in der Nextcloud.' === $r['message'] );

$r = SPS_Ajax_Handler::success_payload( $cfg, '', '' );
check( 'ohne App und ohne Nextcloud: kein Knopf', ! isset( $r['link_label'] ) && ! isset( $r['link_url'] ) );

// Mit App
$r = SPS_Ajax_Handler::success_payload( $cfg, 'https://nc.example.org/', 'https://app.example.org/' );
check( 'mit App: Knopf in die App mit ?nc=', 'https://app.example.org/?nc=https%3A%2F%2Fnc.example.org' === $r['link_url'] );
check( 'mit App: Beschriftung', 'Zur App' === $r['link_label'] );
check( 'mit App: App-Text', 'Sie finden die Auswertung in der App.' === $r['message'] );
check( 'mit App: ohne _app-Variante bleibt der normale Text', 'Melden Sie sich dort an.' === $r['note'] );

$r = SPS_Ajax_Handler::success_payload( $cfg, 'https://nc.example.org', 'https://app.example.org/?mandant=1' );
check( 'mit App und Query: &nc=', 'https://app.example.org/?mandant=1&nc=https%3A%2F%2Fnc.example.org' === $r['link_url'] );

$ohne_label = $cfg;
unset( $ohne_label['link_label_app'] );
$r = SPS_Ajax_Handler::success_payload( $ohne_label, 'https://nc.example.org', 'https://app.example.org' );
check( 'mit App ohne link_label_app: „Zur App“', 'Zur App' === $r['link_label'] );

echo $fails ? "\n$fails Test(s) fehlgeschlagen\n" : "\nAlle Tests bestanden\n";
exit( $fails ? 1 : 0 );
