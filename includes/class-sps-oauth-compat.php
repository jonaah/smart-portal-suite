<?php
/**
 * Kompatibilität WP OAuth Server <-> Easy MCP AI.
 *
 * Problem: Easy MCP AI fängt Anfragen an /oauth/authorize/ ab und lehnt normale
 * Client-IDs ab ("Unknown OAuth client."), weil es URLs als Client-ID (CIMD) erwartet.
 * Der Social Login (Nextcloud -> WordPress) nutzt aber genau diese URL von WP OAuth Server.
 *
 * Lösung: Anfragen an /oauth/authorize/ mit normaler Client-ID werden intern auf die
 * gleichwertige Query-Form /?oauth=authorize umgeschrieben. Die URL im Browser und in der
 * Nextcloud-Konfiguration bleibt unverändert. MCP-Clients (Client-ID beginnt mit http)
 * sind nicht betroffen.
 *
 * Abschaltbar per Filter: add_filter( 'sps_oauth_authorize_compat', '__return_false' );
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_OAuth_Compat {

	/**
	 * Schreibt die Anfrage um, falls nötig. Muss so früh wie möglich laufen.
	 */
	public static function maybe_rewrite_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}

		$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		$query = $parts[1] ?? '';

		// Nur /oauth/authorize bzw. /oauth/authorize/ (auch in Unterverzeichnis-Installationen).
		if ( ! preg_match( '#/oauth/authorize/?$#', $path ) ) {
			return;
		}

		$params = array();
		parse_str( $query, $params );
		$client_id = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';

		// MCP-Clients (CIMD: Client-ID ist eine URL) und leere Anfragen nicht anfassen.
		if ( '' === $client_id || 0 === stripos( $client_id, 'http' ) ) {
			return;
		}

		if ( ! apply_filters( 'sps_oauth_authorize_compat', true ) ) {
			return;
		}

		$base = preg_replace( '#oauth/authorize/?$#', '', $path );
		$new_query = 'oauth=authorize' . ( '' !== $query ? '&' . $query : '' );

		$_SERVER['REQUEST_URI']  = $base . '?' . $new_query;
		$_SERVER['QUERY_STRING'] = $new_query;
		$_GET['oauth']           = 'authorize';
		$_REQUEST['oauth']       = 'authorize';
	}
}

// Beim Laden der Plugin-Datei ausführen (früher als jeder Hook).
SPS_OAuth_Compat::maybe_rewrite_request();
