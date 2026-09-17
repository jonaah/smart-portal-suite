<?php
/**
 * Form Renderer and Shortcode Handler
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Form_Renderer {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE_TAG = 'sps_form';

	/**
	 * Singleton instance.
	 *
	 * @var SPS_Form_Renderer|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_Form_Renderer
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
		add_shortcode( self::SHORTCODE_TAG, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register frontend assets.
	 */
	public function register_assets() {
		wp_register_style(
			'sps-portal-base',
			SPS_PLUGIN_URL . 'assets/css/portal-base.css',
			array(),
			SPS_VERSION
		);

		wp_register_script(
			'sps-osm-autocomplete',
			SPS_PLUGIN_URL . 'assets/js/osm-autocomplete.js',
			array(),
			SPS_VERSION,
			true
		);

		wp_register_script(
			'sps-calculations',
			SPS_PLUGIN_URL . 'assets/js/calculations.js',
			array(),
			SPS_VERSION,
			true
		);

		wp_register_script(
			'sps-form-engine',
			SPS_PLUGIN_URL . 'assets/js/form-engine.js',
			array( 'sps-osm-autocomplete', 'sps-calculations' ),
			SPS_VERSION,
			true
		);
	}

	/**
	 * Render shortcode [sps_form id="..."].
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'id' => '',
		), $atts, self::SHORTCODE_TAG );

		$form_id = sanitize_key( $atts['id'] );
		if ( empty( $form_id ) ) {
			return '<!-- SPS: Formular-ID fehlt -->';
		}

		$schema = $this->load_schema( $form_id );
		if ( ! $schema ) {
			return sprintf( '<!-- SPS: Schema für Formular "%s" nicht gefunden -->', esc_html( $form_id ) );
		}

		// Enqueue styles & scripts
		wp_enqueue_style( 'sps-portal-base' );
		wp_enqueue_script( 'sps-form-engine' );

		wp_localize_script( 'sps-form-engine', 'spsFormData_' . str_replace( '-', '_', $form_id ), array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( SPS_Ajax_Handler::NONCE_ACTION ),
			'schema'    => $schema,
			'iconsUrl'  => SPS_PLUGIN_URL . 'assets/icons/portal-icons.svg',
			'timestamp' => time(),
		) );

		ob_start();
		$template_path = SPS_PLUGIN_DIR . 'templates/form-container.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
		return ob_get_clean();
	}

	/**
	 * Load JSON schema for a given form ID.
	 *
	 * @param string $form_id Form identifier.
	 * @return array|null Decoded schema array or null on failure.
	 */
	public function load_schema( $form_id ) {
		$file_path = SPS_PLUGIN_DIR . 'config/forms/' . $form_id . '.json';
		if ( ! file_exists( $file_path ) ) {
			// Fallback: try hyphenated or underscored version
			$alt_id    = str_replace( '_', '-', $form_id );
			$file_path = SPS_PLUGIN_DIR . 'config/forms/' . $alt_id . '.json';
			if ( ! file_exists( $file_path ) ) {
				return null;
			}
		}

		$json_content = file_get_contents( $file_path );
		$decoded      = json_decode( $json_content, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
