<?php
/**
 * Settings Page and Credentials Management
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Settings {

	/**
	 * Option group name.
	 */
	const OPTION_GROUP = 'sps_settings_group';

	/**
	 * Main option key in wp_options.
	 */
	const OPTION_NAME = 'sps_settings';

	/**
	 * Singleton instance.
	 *
	 * @var SPS_Settings|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add administration menu page.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Smart Portal Suite', 'smart-portal-suite' ),
			__( 'Smart Portal Suite', 'smart-portal-suite' ),
			'manage_options',
			'smart-portal-suite',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings, sections and fields.
	 */
	public function register_settings() {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
	}

	/**
	 * Sanitize and encrypt sensitive options.
	 *
	 * @param array $input Raw input data.
	 * @return array Sanitized and encrypted settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();
		if ( ! is_array( $input ) ) {
			return $sanitized;
		}

		$sanitized['nextcloud_url']   = isset( $input['nextcloud_url'] ) ? esc_url_raw( trim( $input['nextcloud_url'] ) ) : '';
		$sanitized['service_account'] = isset( $input['service_account'] ) ? sanitize_text_field( $input['service_account'] ) : '';
		$sanitized['webdav_base_dir'] = isset( $input['webdav_base_dir'] ) ? sanitize_text_field( $input['webdav_base_dir'] ) : '';
		$sanitized['admin_email']     = isset( $input['admin_email'] ) ? sanitize_email( $input['admin_email'] ) : '';
		$sanitized['privacy_url']     = isset( $input['privacy_url'] ) ? esc_url_raw( trim( $input['privacy_url'] ) ) : '';
		$sanitized['primary_color']   = isset( $input['primary_color'] ) ? sanitize_hex_color( $input['primary_color'] ) : '';

		return $sanitized;
	}

	/**
	 * Render settings page view.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Smart Portal Suite Einstellungen', 'smart-portal-suite' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'smart-portal-suite' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Retrieve a setting value.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$options = get_option( self::OPTION_NAME, array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}
}
