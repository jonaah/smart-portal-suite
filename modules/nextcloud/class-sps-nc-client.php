<?php
/**
 * Nextcloud HTTP/OCS Client
 *
 * Kein eingebauter Rückfallwert für die Nextcloud-URL: Ist die Instanz nicht
 * konfiguriert, liefert jeder Request einen WP_Error (`sps_not_configured`).
 * So können Kundendaten nie in einer fremden Nextcloud landen.
 *
 * @package SmartPortalSuite\Nextcloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_NC_Client {

	/**
	 * Nextcloud base URL (ohne abschließenden Slash).
	 *
	 * @var string
	 */
	protected $base_url;

	/**
	 * Benutzername für Basic Auth.
	 *
	 * @var string
	 */
	protected $username = '';

	/**
	 * Passwort / App-Passwort für Basic Auth.
	 *
	 * @var string
	 */
	protected $password = '';

	/**
	 * True, wenn die Zugangsdaten des Service-Accounts verwendet werden.
	 *
	 * @var bool
	 */
	protected $is_service = false;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout = 20;

	/**
	 * Cookie-Jar: Nextcloud bindet z. B. Forms-Upload-Tokens an die Session.
	 * Alle Requests eines Client-Objekts teilen sich daher die Session-Cookies.
	 *
	 * @var WP_Http_Cookie[]
	 */
	protected $cookies = array();

	/**
	 * Constructor.
	 *
	 * @param string   $base_url          Nextcloud Base URL (leer = aus Einstellungen).
	 * @param int|null $user_id           WP User ID, deren NC-Zugangsdaten genutzt werden sollen.
	 *                                    null = aktueller Benutzer. Ohne gültige Zugangsdaten → Service-Account.
	 * @param string   $override_username Optionaler Benutzername (z. B. Service-Account).
	 * @param string   $override_password Optionales Passwort.
	 */
	public function __construct( $base_url = '', $user_id = null, $override_username = '', $override_password = '' ) {
		$url            = $base_url ? $base_url : SPS_Settings::get_setting( 'nextcloud_url', '' );
		$this->base_url = rtrim( (string) $url, '/' );

		if ( '' !== $override_username && '' !== $override_password ) {
			$this->username   = $override_username;
			$this->password   = $override_password;
			$this->is_service = ( $override_username === SPS_Settings::get_setting( 'service_account', '' ) );
			return;
		}

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id ) {
			$creds = self::get_user_credentials( $user_id );
			if ( $creds ) {
				$this->username = $creds['username'];
				$this->password = $creds['password'];
			}
		}

		if ( '' === $this->username || '' === $this->password ) {
			$this->use_service_account();
		}
	}

	/**
	 * Client mit den Zugangsdaten des Service-Accounts.
	 *
	 * @return SPS_NC_Client
	 */
	public static function service() {
		return new self(
			'',
			0,
			(string) SPS_Settings::get_setting( 'service_account', '' ),
			(string) SPS_Settings::get_setting( 'app_password', '' )
		);
	}

	/**
	 * Client mit den Zugangsdaten eines WP-Benutzers.
	 * Fehlen diese, wird der Benutzer einmalig synchronisiert (optional);
	 * gelingt das nicht, wird der Service-Account verwendet.
	 *
	 * @param int  $user_id    WP User ID.
	 * @param bool $allow_sync Fehlende Zugangsdaten per Sync anlegen.
	 * @return SPS_NC_Client
	 */
	public static function for_user( $user_id, $allow_sync = true ) {
		$user_id = (int) $user_id;
		if ( $user_id && $allow_sync && ! self::get_user_credentials( $user_id ) && class_exists( 'SPS_NC_User_Sync' ) ) {
			SPS_NC_User_Sync::get_instance()->sync_user( $user_id );
		}
		return new self( '', $user_id ? $user_id : 0 );
	}

	/**
	 * Entschlüsselte NC-Zugangsdaten eines WP-Benutzers.
	 *
	 * @param int $user_id WP User ID.
	 * @return array|null array( 'username' => ..., 'password' => ... ) oder null.
	 */
	public static function get_user_credentials( $user_id ) {
		$nc_username     = get_user_meta( $user_id, 'nextcloud_username', true );
		$nc_password_enc = get_user_meta( $user_id, 'nextcloud_password_enc', true );

		if ( empty( $nc_username ) || empty( $nc_password_enc ) ) {
			return null;
		}

		$password = SPS_Settings::decrypt( $nc_password_enc );
		if ( '' === $password ) {
			return null;
		}

		return array(
			'username' => (string) $nc_username,
			'password' => $password,
		);
	}

	/**
	 * Auf Service-Account umschalten.
	 */
	protected function use_service_account() {
		$this->username   = (string) SPS_Settings::get_setting( 'service_account', '' );
		$this->password   = (string) SPS_Settings::get_setting( 'app_password', '' );
		$this->is_service = true;
	}

	/**
	 * Ist URL + Zugangsdaten vorhanden?
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->base_url && '' !== $this->username && '' !== $this->password;
	}

	/**
	 * @return string Benutzer, mit dem Requests ausgeführt werden.
	 */
	public function get_username() {
		return $this->username;
	}

	/**
	 * @return bool True, wenn der Service-Account genutzt wird.
	 */
	public function is_service_account() {
		return $this->is_service;
	}

	/**
	 * @return string Basis-URL.
	 */
	public function get_base_url() {
		return $this->base_url;
	}

	/**
	 * Build common authentication headers.
	 *
	 * @return array
	 */
	protected function get_headers() {
		return array(
			'Authorization'  => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
			'OCS-APIRequest' => 'true',
			'Accept'         => 'application/json',
			'Content-Type'   => 'application/json',
			'User-Agent'     => 'SPS-Client/' . ( defined( 'SPS_VERSION' ) ? SPS_VERSION : '1.0' ),
		);
	}

	/**
	 * Execute HTTP request to Nextcloud.
	 *
	 * @param string $endpoint       API endpoint path.
	 * @param string $method         HTTP method.
	 * @param mixed  $body           Payload (Array → JSON bzw. urlencoded, String → roh).
	 * @param array  $custom_headers Zusätzliche Header.
	 * @return array|WP_Error
	 */
	public function request( $endpoint, $method = 'GET', $body = null, $custom_headers = array() ) {
		if ( '' === $this->base_url ) {
			return new WP_Error( 'sps_not_configured', 'Nextcloud-URL ist nicht in den Einstellungen hinterlegt.' );
		}
		if ( '' === $this->username || '' === $this->password ) {
			return new WP_Error( 'sps_not_configured', 'Nextcloud-Zugangsdaten (Service-Account/App-Passwort) fehlen.' );
		}

		$url     = $this->base_url . '/' . ltrim( $endpoint, '/' );
		$headers = array_merge( $this->get_headers(), $custom_headers );

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => $this->timeout,
			'headers'     => $headers,
			'redirection' => 0,
		);

		if ( ! empty( $this->cookies ) ) {
			$args['cookies'] = $this->cookies;
		}

		if ( null !== $body ) {
			$content_type = isset( $headers['Content-Type'] ) ? $headers['Content-Type'] : '';
			if ( is_array( $body ) && false !== strpos( $content_type, 'x-www-form-urlencoded' ) ) {
				$args['body'] = http_build_query( $body );
			} elseif ( is_array( $body ) ) {
				$args['body'] = wp_json_encode( $body );
			} else {
				$args['body'] = $body;
			}
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			SPS_Diagnostics::log( sprintf( '[NC-Client] Request an %s fehlgeschlagen: %s', $url, $response->get_error_message() ), 'error' );
			return $response;
		}

		$this->remember_cookies( $response );

		return $response;
	}

	/**
	 * Session-Cookies aus einer Antwort übernehmen.
	 *
	 * @param array $response HTTP-Antwort.
	 */
	protected function remember_cookies( $response ) {
		$new = wp_remote_retrieve_cookies( $response );
		if ( empty( $new ) ) {
			return;
		}
		foreach ( $new as $cookie ) {
			if ( $cookie instanceof WP_Http_Cookie ) {
				$this->cookies[ $cookie->name ] = $cookie;
			}
		}
	}

	/**
	 * Multipart-Upload (z. B. Forms-Datei-Upload).
	 *
	 * @param string $endpoint   Endpoint.
	 * @param string $field_name Name des Datei-Felds, z. B. "files[]".
	 * @param array  $files      Liste aus array( 'path' => ..., 'name' => ..., 'type' => ... ).
	 * @return array|WP_Error
	 */
	public function upload_multipart( $endpoint, $field_name, array $files ) {
		$boundary = 'sps' . wp_generate_password( 24, false, false );
		$body     = '';

		foreach ( $files as $file ) {
			if ( empty( $file['path'] ) || ! is_readable( $file['path'] ) ) {
				return new WP_Error( 'sps_upload_missing', 'Upload-Datei nicht lesbar: ' . ( isset( $file['name'] ) ? $file['name'] : '?' ) );
			}
			$name  = str_replace( array( '"', "\r", "\n" ), '', isset( $file['name'] ) ? $file['name'] : basename( $file['path'] ) );
			$type  = ! empty( $file['type'] ) ? $file['type'] : 'application/octet-stream';
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $name . "\"\r\n";
			$body .= 'Content-Type: ' . $type . "\r\n\r\n";
			$body .= file_get_contents( $file['path'] ) . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";

		return $this->request( $endpoint, 'POST', $body, array(
			'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
		) );
	}

	/**
	 * OCS-Antwort auswerten.
	 *
	 * @param array|WP_Error $response   HTTP-Antwort.
	 * @param int[]          $ok_status  Akzeptierte OCS-Statuscodes (v1: 100, v2: 200).
	 * @return array|WP_Error `ocs.data` bei Erfolg.
	 */
	public static function parse_ocs( $response, $ok_status = array( 100, 200 ) ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		$status  = isset( $data['ocs']['meta']['statuscode'] ) ? (int) $data['ocs']['meta']['statuscode'] : null;
		$message = isset( $data['ocs']['meta']['message'] ) ? (string) $data['ocs']['meta']['message'] : '';

		if ( $code >= 200 && $code < 300 && null !== $status && in_array( $status, $ok_status, true ) ) {
			return isset( $data['ocs']['data'] ) ? $data['ocs']['data'] : array();
		}

		if ( '' === $message ) {
			$message = 'Nextcloud-Fehler';
		}

		return new WP_Error(
			'nc_ocs_error',
			sprintf( '%s (HTTP %d, OCS %s)', $message, $code, null === $status ? '-' : $status ),
			array( 'http' => $code, 'ocs' => $status )
		);
	}

	/**
	 * Check if a Nextcloud user exists via OCS API.
	 *
	 * Hinweis: Ein Gruppenadmin sieht nur Konten seiner Gruppen. Für ihn
	 * ist ein Konto außerhalb seiner Gruppen „nicht vorhanden“.
	 *
	 * @param string $username Nextcloud username.
	 * @return bool
	 */
	public function user_exists( $username ) {
		$res = self::parse_ocs( $this->request( 'ocs/v1.php/cloud/users/' . rawurlencode( $username ) . '?format=json' ) );
		return ! is_wp_error( $res );
	}

	/**
	 * Create a user in Nextcloud via OCS API.
	 *
	 * Gruppen werden direkt bei der Anlage übergeben. Das ist für Gruppenadmins
	 * Pflicht ("No group specified (required for sub-admins)").
	 *
	 * @param string   $username     Nextcloud username.
	 * @param string   $password     Initial password.
	 * @param string   $email        E-Mail.
	 * @param string   $display_name Anzeigename.
	 * @param string[] $groups       Gruppen.
	 * @return true|WP_Error WP_Error-Code `nc_user_exists`, wenn das Konto schon existiert.
	 */
	public function create_user( $username, $password, $email = '', $display_name = '', $groups = array() ) {
		$body = array(
			'userid'      => $username,
			'password'    => $password,
			'email'       => '' !== $email ? $email : $username,
			'displayName' => '' !== $display_name ? $display_name : $username,
		);
		$groups = array_values( array_filter( array_map( 'strval', (array) $groups ) ) );
		if ( ! empty( $groups ) ) {
			$body['groups'] = $groups;
		}

		$res = self::parse_ocs(
			$this->request( 'ocs/v1.php/cloud/users?format=json', 'POST', $body, array( 'Content-Type' => 'application/x-www-form-urlencoded' ) ),
			array( 100 )
		);

		if ( is_wp_error( $res ) ) {
			$err = $res->get_error_data();
			if ( isset( $err['ocs'] ) && 102 === $err['ocs'] ) {
				return new WP_Error( 'nc_user_exists', $res->get_error_message() );
			}
			return new WP_Error( 'nc_user_create_failed', $res->get_error_message(), $err );
		}
		return true;
	}

	/**
	 * Add a Nextcloud user to a group via OCS API.
	 *
	 * @param string $username Nextcloud username.
	 * @param string $group_id Gruppe.
	 * @return true|WP_Error
	 */
	public function add_user_to_group( $username, $group_id ) {
		$res = self::parse_ocs(
			$this->request(
				'ocs/v1.php/cloud/users/' . rawurlencode( $username ) . '/groups?format=json',
				'POST',
				array( 'groupid' => $group_id ),
				array( 'Content-Type' => 'application/x-www-form-urlencoded' )
			),
			array( 100, 102 )
		);
		return is_wp_error( $res ) ? new WP_Error( 'nc_group_add_failed', $res->get_error_message() ) : true;
	}

	/**
	 * Set password for a Nextcloud user via OCS API.
	 *
	 * @param string $username Nextcloud username.
	 * @param string $password Neues Passwort.
	 * @return true|WP_Error
	 */
	public function set_user_password( $username, $password ) {
		$res = self::parse_ocs(
			$this->request(
				'ocs/v1.php/cloud/users/' . rawurlencode( $username ) . '?format=json',
				'PUT',
				array( 'key' => 'password', 'value' => $password ),
				array( 'Content-Type' => 'application/x-www-form-urlencoded' )
			),
			array( 100 )
		);
		return is_wp_error( $res ) ? new WP_Error( 'nc_password_reset_failed', $res->get_error_message() ) : true;
	}

	/**
	 * Gruppen eines Benutzers.
	 *
	 * @param string $username Nextcloud username.
	 * @return string[]|WP_Error
	 */
	public function get_user_groups( $username ) {
		$res = self::parse_ocs( $this->request( 'ocs/v1.php/cloud/users/' . rawurlencode( $username ) . '/groups?format=json' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['groups'] ) ? (array) $res['groups'] : array();
	}

	/**
	 * Gruppen, die ein Benutzer als Gruppenadmin verwaltet.
	 *
	 * @param string $username Nextcloud username.
	 * @return string[]|WP_Error
	 */
	public function get_subadmin_groups( $username ) {
		$res = self::parse_ocs( $this->request( 'ocs/v1.php/cloud/users/' . rawurlencode( $username ) . '/subadmins?format=json' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return is_array( $res ) ? array_values( $res ) : array();
	}

	/**
	 * Existiert eine Gruppe?
	 *
	 * @param string $group Gruppen-ID.
	 * @return bool|WP_Error
	 */
	public function group_exists( $group ) {
		$res = self::parse_ocs( $this->request( 'ocs/v1.php/cloud/groups?format=json&search=' . rawurlencode( $group ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$groups = isset( $res['groups'] ) ? (array) $res['groups'] : array();
		return in_array( $group, $groups, true );
	}
}
