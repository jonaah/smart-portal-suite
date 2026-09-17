<?php
/**
 * Nextcloud Forms API v3 Service
 *
 * @package SmartPortalSuite\Nextcloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_NC_Forms {

	/**
	 * HTTP Client instance.
	 *
	 * @var SPS_NC_Client
	 */
	protected $client;

	/**
	 * Constructor.
	 *
	 * @param SPS_NC_Client|null $client Optional client instance.
	 */
	public function __construct( $client = null ) {
		$this->client = $client ?: new SPS_NC_Client();
	}

	/**
	 * Resolve a form ID by its exact title.
	 *
	 * @param string $title Form Title.
	 * @return int|WP_Error Form ID or Error.
	 */
	public function resolve_form_id( $title ) {
		$transient_key = 'sps_nc_form_id_' . md5( $title );
		$cached = get_transient( $transient_key );
		if ( $cached ) return $cached;

		// Use service account for resolution (creates a separate client)
		$service_client = new SPS_NC_Client( '', null, SPS_Settings::get_setting( 'service_account' ), SPS_Settings::get_setting( 'app_password' ) );
		$response = $service_client->request( 'ocs/v2.php/apps/forms/api/v3/forms?type=owned', 'GET' );

		if ( is_wp_error( $response ) ) return $response;

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['ocs']['data']['forms'] ) ) {
			foreach ( $body['ocs']['data']['forms'] as $form ) {
				if ( $form['title'] === $title ) {
					set_transient( $transient_key, $form['id'], 15 * MINUTE_IN_SECONDS );
					return $form['id'];
				}
			}
		}

		return new WP_Error( 'not_found', 'Formular nicht gefunden.' );
	}

	/**
	 * Fetch questions for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return array|WP_Error
	 */
	public function get_questions( $form_id ) {
		$transient_key = 'sps_nc_form_q_' . $form_id;
		$cached = get_transient( $transient_key );
		if ( $cached ) return $cached;

		$service_client = new SPS_NC_Client( '', null, SPS_Settings::get_setting( 'service_account' ), SPS_Settings::get_setting( 'app_password' ) );
		$response = $service_client->request( 'ocs/v2.php/apps/forms/api/v3/forms/' . $form_id, 'GET' );

		if ( is_wp_error( $response ) ) return $response;

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$questions = $body['ocs']['data']['questions'] ?? [];

		if ( ! empty( $questions ) ) {
			set_transient( $transient_key, $questions, 15 * MINUTE_IN_SECONDS );
		}

		return $questions;
	}

	/**
	 * Map WP answers to NC question IDs.
	 * 
	 * NC question "name" fields with "_" are composite fields (e.g., "plz_ort").
	 * They map to multiple WP fields separated by space.
	 *
	 * @param array $nc_questions Questions from NC API.
	 * @param array $wp_answers Answers from WP Frontend.
	 * @return array Array of mapped answers suitable for submission.
	 */
	public function map_answers( $nc_questions, $wp_answers ) {
		$mapped = [];

		foreach ( $nc_questions as $q ) {
			$q_id = $q['id'];
			$q_name = $q['name'] ?? '';
			
			if ( empty( $q_name ) ) continue;

			$val = '';
			if ( strpos( $q_name, '_' ) !== false ) {
				// Composite field
				$parts = explode( '_', $q_name );
				$collected = [];
				foreach ( $parts as $p ) {
					if ( isset( $wp_answers[ $p ] ) && $wp_answers[ $p ] !== '' ) {
						$collected[] = $wp_answers[ $p ];
					}
				}
				if ( ! empty( $collected ) ) {
					$val = implode( ' ', $collected );
				}
			} else {
				// 1:1 mapping
				if ( isset( $wp_answers[ $q_name ] ) ) {
					$val = $wp_answers[ $q_name ];
				}
			}

			// For dropdowns/radios in NC, we must pass the option ID. 
			// But for v3, we often pass the value or match it. The old code matched options case-insensitively.
			if ( ! empty( $val ) && ! empty( $q['options'] ) ) {
				$found_opt_id = null;
				foreach ( $q['options'] as $opt ) {
					if ( strcasecmp( trim( $opt['text'] ), trim( $val ) ) === 0 ) {
						$found_opt_id = $opt['id'];
						break;
					}
				}
				if ( $found_opt_id !== null ) {
					$val = $found_opt_id;
				} else {
					// Option mismatch handling - fallback to text or throw error
				}
			}

			if ( $val !== '' && $val !== null ) {
				$mapped[] = [
					'questionId' => $q_id,
					'value' => $val,
				];
			}
		}

		return $mapped;
	}

	/**
	 * Submit form submission to Nextcloud Forms API v3.
	 *
	 * @param int|string $nc_form_id Nextcloud Form ID.
	 * @param array      $mapped_answers Mapped form responses.
	 * @return array|WP_Error
	 */
	public function submit_form( $nc_form_id, array $mapped_answers ) {
		$endpoint = sprintf( 'ocs/v2.php/apps/forms/api/v3/forms/%s/submissions', (int) $nc_form_id );

		$payload = array(
			'answers' => $mapped_answers,
		);

		$res = $this->client->request( $endpoint, 'POST', $payload );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$http_code = wp_remote_retrieve_response_code( $res );
		if ( $http_code >= 400 ) {
			return new WP_Error( 'nc_api_error', 'Nextcloud returned HTTP ' . $http_code );
		}

		return json_decode( wp_remote_retrieve_body( $res ), true );
	}
}
