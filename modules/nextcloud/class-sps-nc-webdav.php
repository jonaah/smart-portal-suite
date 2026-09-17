<?php
/**
 * Nextcloud WebDAV Client for Fallback Storage
 *
 * @package SmartPortalSuite\Nextcloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_NC_WebDAV {

	/**
	 * HTTP Client instance.
	 *
	 * @var SPS_NC_Client
	 */
	protected $client;

	/**
	 * Base WebDAV directory.
	 *
	 * @var string
	 */
	protected $base_dir;

	/**
	 * Constructor.
	 *
	 * @param SPS_NC_Client|null $client Optional client instance.
	 */
	public function __construct( $client = null ) {
		// WebDAV Fallback must always use the admin service account.
		$this->client   = $client ?: new SPS_NC_Client( '', null, SPS_Settings::get_setting( 'service_account', '' ), SPS_Settings::get_setting( 'app_password', '' ) );
		$this->base_dir = SPS_Settings::get_setting( 'webdav_base_dir', 'SPS_Leads' );
	}

	/**
	 * Store fallback lead data as an individual JSON file.
	 * Format: lead_{Ymd}_{His}_{random}.json
	 *
	 * @param array  $lead_data Data to save.
	 * @param string $form_id Form identifier.
	 * @return array|WP_Error Response or error.
	 */
	public function store_lead_fallback( array $lead_data, $form_id = 'lead' ) {
		$timestamp   = gmdate( 'Ymd_His' );
		$rand        = wp_generate_password( 6, false, false );
		$filename    = sprintf( 'lead_%s_%s_%s.json', sanitize_key( $form_id ), $timestamp, $rand );
		$remote_path = sprintf( 'remote.php/dav/files/%s/%s/%s', rawurlencode( SPS_Settings::get_setting( 'service_account', '' ) ), trim( $this->base_dir, '/' ), $filename );

		$json_content = wp_json_encode( array(
			'submitted_at' => current_time( 'mysql' ),
			'form_id'      => $form_id,
			'data'         => $lead_data,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		return $this->client->request( $remote_path, 'PUT', $json_content, array(
			'Content-Type' => 'application/json',
		) );
	}
}
