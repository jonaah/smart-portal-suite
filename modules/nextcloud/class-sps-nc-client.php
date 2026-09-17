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

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout,
			'headers' => array_merge( $this->get_headers(), $custom_headers ),
		);

		if ( null !== $body ) {
			$args['body'] = is_array( $body ) ? wp_json_encode( $body ) : $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( '[SPS_NC_Client] Request failed to %s: %s', $url, $response->get_error_message() ) );
		}

		return $response;
	}
}
