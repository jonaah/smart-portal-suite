<?php
/**
 * Nextcloud HTTP/OCS Client
 *
 * @package SmartPortalSuite\Nextcloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_NC_Client {

	/**
	 * Nextcloud base URL.
	 *
	 * @var string
	 */
	protected $base_url;

	/**
	 * Service account username.
	 *
	 * @var string
	 */
	protected $username;

	/**
	 * Application password.
	 *
	 * @var string
	 */
	protected $password;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected $timeout = 15;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Nextcloud Base URL.
	 * @param int|null $user_id WP User ID to use for credentials.
	 * @param string $override_username Optional username override (e.g. for service account).
	 * @param string $override_password Optional password override.
	 */
	public function __construct( $base_url = '', $user_id = null, $override_username = '', $override_password = '' ) {
		$this->base_url = rtrim( $base_url ?: SPS_Settings::get_setting( 'nextcloud_url', 'https://nc.effizientes-heim.de' ), '/' );
		
		if ( ! empty( $override_username ) && ! empty( $override_password ) ) {
			$this->username = $override_username;
			$this->password = $override_password;
			return;
		}

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id ) {
			$nc_username = get_user_meta( $user_id, 'nextcloud_username', true );
			$nc_password_enc = get_user_meta( $user_id, 'nextcloud_password_enc', true );
			
			// On the fly sync if missing and the old function exists
			if ( ( empty( $nc_username ) || empty( $nc_password_enc ) ) && function_exists( 'sync_wp_user_to_nextcloud' ) ) {
				sync_wp_user_to_nextcloud( $user_id );
				$nc_username = get_user_meta( $user_id, 'nextcloud_username', true );
				$nc_password_enc = get_user_meta( $user_id, 'nextcloud_password_enc', true );
			}
			
			if ( $nc_username && $nc_password_enc && function_exists( 'nc_decrypt' ) ) {
				$this->username = $nc_username;
				$this->password = nc_decrypt( $nc_password_enc );
			}
		}

		// Fallback to service account if no user credentials could be resolved
		if ( empty( $this->username ) || empty( $this->password ) ) {
			$this->username = SPS_Settings::get_setting( 'service_account', '' );
			$this->password = SPS_Settings::get_setting( 'app_password', '' );
		}
	}

	/**
	 * Build common authentication headers.
	 *
	 * @return array
	 */
	protected function get_headers() {
		return array(
			'Authorization'    => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
			'OCS-APIRequest'   => 'true',
			'Accept'           => 'application/json',
			'Content-Type'     => 'application/json',
			'User-Agent'       => 'SPS-Client/1.0',
		);
	}

	/**
	 * Execute HTTP request to Nextcloud.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param string $method HTTP method (GET, POST, PUT, etc.).
	 * @param mixed  $body Request payload.
	 * @param array  $custom_headers Optional extra headers.
	 * @return array|WP_Error Response or error.
	 */
	public function request( $endpoint, $method = 'GET', $body = null, $custom_headers = array() ) {
		$url = $this->base_url . '/' . ltrim( $endpoint, '/' );

		$headers = array_merge( $this->get_headers(), $custom_headers );

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			if ( is_array( $body ) && isset( $headers['Content-Type'] ) && false !== strpos( $headers['Content-Type'], 'x-www-form-urlencoded' ) ) {
				$args['body'] = http_build_query( $body );
			} elseif ( is_array( $body ) ) {
				$args['body'] = wp_json_encode( $body );
			} else {
				$args['body'] = $body;
			}
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( '[SPS_NC_Client] Request failed to %s: %s', $url, $response->get_error_message() ) );
		}

		return $response;
	}

	/**
	 * Check if a Nextcloud user exists via OCS API.
	 *
	 * @param string $username Nextcloud username / email.
	 * @return bool
	 */
	public function user_exists( $username ) {
		$endpoint = 'ocs/v1.php/cloud/users/' . rawurlencode( $username ) . '?format=json';
		$response = $this->request( $endpoint, 'GET' );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$statuscode = isset( $body['ocs']['meta']['statuscode'] ) ? (int) $body['ocs']['meta']['statuscode'] : null;

		return ( 200 === $code && 100 === $statuscode );
	}

	/**
	 * Create a user in Nextcloud via OCS API.
	 *
	 * @param string $username Nextcloud username / email.
	 * @param string $password Initial user password.
	 * @param string $email User email address.
	 * @param string $display_name User display name.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function create_user( $username, $password, $email = '', $display_name = '' ) {
		$endpoint = 'ocs/v1.php/cloud/users?format=json';
		$body = array(
			'userid'      => $username,
			'password'    => $password,
			'email'       => ! empty( $email ) ? $email : $username,
			'displayName' => ! empty( $display_name ) ? $display_name : $username,
		);

		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		$response = $this->request( $endpoint, 'POST', $body, $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$statuscode = isset( $data['ocs']['meta']['statuscode'] ) ? (int) $data['ocs']['meta']['statuscode'] : null;
		$message    = isset( $data['ocs']['meta']['message'] ) ? $data['ocs']['meta']['message'] : 'Nextcloud User creation failed';

		if ( 200 === $code && 100 === $statuscode ) {
			return true;
		}

		return new WP_Error( 'nc_user_create_failed', sprintf( '%s (HTTP %d, OCS %s)', $message, $code, var_export( $statuscode, true ) ) );
	}

	/**
	 * Add a Nextcloud user to a specific group via OCS API.
	 *
	 * @param string $username Nextcloud username / email.
	 * @param string $group_id Nextcloud group ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function add_user_to_group( $username, $group_id ) {
		$endpoint = 'ocs/v1.php/cloud/users/' . rawurlencode( $username ) . '/groups?format=json';
		$body = array(
			'groupid' => $group_id,
		);

		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		$response = $this->request( $endpoint, 'POST', $body, $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$statuscode = isset( $data['ocs']['meta']['statuscode'] ) ? (int) $data['ocs']['meta']['statuscode'] : null;
		$message    = isset( $data['ocs']['meta']['message'] ) ? $data['ocs']['meta']['message'] : 'Add to group failed';

		// 100 = success, 102 = already exists in group (both acceptable)
		if ( 200 === $code && ( 100 === $statuscode || 102 === $statuscode ) ) {
			return true;
		}

		return new WP_Error( 'nc_group_add_failed', sprintf( '%s (HTTP %d, OCS %s)', $message, $code, var_export( $statuscode, true ) ) );
	}

	/**
	 * Set or reset password for a Nextcloud user via OCS API.
	 *
	 * @param string $username Nextcloud username / email.
	 * @param string $password New password.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function set_user_password( $username, $password ) {
		$endpoint = 'ocs/v1.php/cloud/users/' . rawurlencode( $username ) . '?format=json';
		$body = array(
			'key'   => 'password',
			'value' => $password,
		);

		$headers = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		);

		$response = $this->request( $endpoint, 'PUT', $body, $headers );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$statuscode = isset( $data['ocs']['meta']['statuscode'] ) ? (int) $data['ocs']['meta']['statuscode'] : null;
		$message    = isset( $data['ocs']['meta']['message'] ) ? $data['ocs']['meta']['message'] : 'Password reset failed';

		if ( 200 === $code && 100 === $statuscode ) {
			return true;
		}

		return new WP_Error( 'nc_password_reset_failed', sprintf( '%s (HTTP %d, OCS %s)', $message, $code, var_export( $statuscode, true ) ) );
	}
}
