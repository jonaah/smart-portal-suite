<?php
/**
 * Kompatibilität WP OAuth Server <-> Easy MCP AI.
 *
 * Problem: Easy MCP AI fängt Anfragen an /oauth/authorize/, /oauth/token und /oauth/me ab
 * ("Unknown OAuth client.", "code and code_verifier are required."). Der Social Login
 * (Nextcloud -> WordPress) nutzt aber genau diese URLs von WP OAuth Server.
 *
 * Lösung: Diese Anfragen werden intern auf die gleichwertige Query-Form
 * /?oauth=authorize|token|me umgeschrieben. Die URLs in der Nextcloud-Konfiguration bleiben
 * unverändert. Easy MCP AI nutzt eigene Endpunkte (/easy-mcp-ai/authorize,
 * /wp-json/easy-mcp-ai/v1/oauth/token) und ist nicht betroffen; Autorisierungen mit einer
 * URL als Client-ID (CIMD) werden weiter nicht angefasst.
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
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		$query = $parts[1] ?? '';

		// REST-Pfade (z. B. /wp-json/easy-mcp-ai/v1/oauth/token) gehören anderen Plugins.
		if ( false !== strpos( $path, '/wp-json/' ) || false !== strpos( $path, '/easy-mcp-ai/' ) ) {
			return;
		}

		// Nur /oauth/authorize, /oauth/token, /oauth/me (auch in Unterverzeichnis-Installationen).
		if ( ! preg_match( '#/oauth/(authorize|token|me)/?$#', $path, $m ) ) {
			return;
		}
		$endpoint = $m[1];

		if ( 'authorize' === $endpoint ) {
			$params = array();
			parse_str( $query, $params );
			$client_id = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';

			// MCP-Clients (CIMD: Client-ID ist eine URL) und leere Anfragen nicht anfassen.
			if ( '' === $client_id || 0 === stripos( $client_id, 'http' ) ) {
				return;
			}
		}

		if ( ! apply_filters( 'sps_oauth_authorize_compat', true ) ) {
			return;
		}

		$base      = preg_replace( '#oauth/(authorize|token|me)/?$#', '', $path );
		$new_query = 'oauth=' . $endpoint . ( '' !== $query ? '&' . $query : '' );

		$_SERVER['REQUEST_URI']  = $base . '?' . $new_query;
		$_SERVER['QUERY_STRING'] = $new_query;
		$_GET['oauth']           = $endpoint;
		$_REQUEST['oauth']       = $endpoint;
	}
}

// Beim Laden der Plugin-Datei ausführen (früher als jeder Hook).
SPS_OAuth_Compat::maybe_rewrite_request();
