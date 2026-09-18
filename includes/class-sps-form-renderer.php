<?php
/**
 * Form Renderer, Shortcode Handler, and Schema Registry
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
	 * Registered SVG sprites to output in footer.
	 *
	 * @var array
	 */
	private $enqueued_sprites = array();

	/**
	 * Flag whether master sprite is registered.
	 *
	 * @var bool
	 */
	private $master_sprite_enqueued = false;

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

		// Register assets on init to guarantee availability in FSE block themes & Gutenberg
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// Print SVG sprites in footer
		add_action( 'wp_footer', array( $this, 'print_svg_sprites' ), 20 );
	}

	/**
	 * Register frontend assets (idempotent).
	 */
	public function register_assets() {
		if ( ! wp_style_is( 'sps-portal-base', 'registered' ) ) {
			wp_register_style(
				'sps-portal-base',
				SPS_PLUGIN_URL . 'assets/css/portal-base.css',
				array(),
				SPS_VERSION
			);
		}

		if ( ! wp_script_is( 'sps-osm-autocomplete', 'registered' ) ) {
			wp_register_script(
				'sps-osm-autocomplete',
				SPS_PLUGIN_URL . 'assets/js/osm-autocomplete.js',
				array(),
				SPS_VERSION,
				true
			);
		}

		if ( ! wp_script_is( 'sps-calculations', 'registered' ) ) {
			wp_register_script(
				'sps-calculations',
				SPS_PLUGIN_URL . 'assets/js/calculations.js',
				array(),
				SPS_VERSION,
				true
			);
		}

		if ( ! wp_script_is( 'sps-form-engine', 'registered' ) ) {
			wp_register_script(
				'sps-form-engine',
				SPS_PLUGIN_URL . 'assets/js/form-engine.js',
				array( 'sps-osm-autocomplete', 'sps-calculations' ),
				SPS_VERSION,
				true
			);
		}
	}

	/**
	 * Enqueue an SVG sprite file to be inlined into the page.
	 *
	 * @param string $file_path Absolute path to SVG sprite file.
	 */
	public function enqueue_sprite( $file_path ) {
		if ( file_exists( $file_path ) && ! in_array( $file_path, $this->enqueued_sprites, true ) ) {
			$this->enqueued_sprites[] = $file_path;
		}
	}

	/**
	 * Print all enqueued SVG sprites inside hidden container in footer.
	 */
	public function print_svg_sprites() {
		if ( empty( $this->enqueued_sprites ) ) {
			return;
		}

		echo "\n<!-- Smart Portal Suite SVG Sprites -->\n";
		echo '<div class="sps-svg-sprite-storage" style="display:none !important;" aria-hidden="true">';
		foreach ( $this->enqueued_sprites as $sprite_file ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo file_get_contents( $sprite_file );
		}
		echo "</div>\n<!-- /Smart Portal Suite SVG Sprites -->\n";
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

		$raw_id = sanitize_key( $atts['id'] );
		if ( empty( $raw_id ) ) {
			return '<!-- SPS: Formular-ID fehlt im Shortcode [sps_form id="..."] -->';
		}

		// Ensure assets are registered even if FSE evaluated this block prior to init/enqueue hooks
		$this->register_assets();

		// Load schema
		$schema = $this->load_schema( $raw_id );
		if ( ! $schema ) {
			return sprintf( '<!-- SPS: Schema für Formular "%s" nicht gefunden -->', esc_html( $raw_id ) );
		}

		$form_id = sanitize_key( isset( $schema['form_id'] ) ? $schema['form_id'] : $raw_id );

		// Enqueue styles & scripts
		wp_enqueue_style( 'sps-portal-base' );
		wp_enqueue_script( 'sps-form-engine' );

		// Enqueue master sprite
		$master_sprite = SPS_PLUGIN_DIR . 'assets/icons/portal-icons.svg';
		$this->enqueue_sprite( $master_sprite );

		// Check for custom sprite declared in schema or dedicated form sprite file
		if ( ! empty( $schema['sprite'] ) ) {
			$custom_sprite_path = SPS_PLUGIN_DIR . ltrim( $schema['sprite'], '/' );
			$this->enqueue_sprite( $custom_sprite_path );
		}

		// Attempt to load a form-specific SVG sprite.
		// Normalise the form_id: try both hyphen and underscore variants so that
		// e.g. "gebaeude_check" finds "gebaeude-check.svg" and vice versa.
		$sprite_id_variants = array_unique( array(
			$form_id,
			str_replace( '-', '_', $form_id ),
			str_replace( '_', '-', $form_id ),
		) );
		foreach ( $sprite_id_variants as $variant ) {
			$form_specific_sprite = SPS_PLUGIN_DIR . 'assets/icons/' . $variant . '.svg';
			if ( file_exists( $form_specific_sprite ) ) {
				$this->enqueue_sprite( $form_specific_sprite );
				break; // Only load the first matching sprite per form
			}
		}

		// Global shared configuration
		static $config_localized = false;
		if ( ! $config_localized ) {
			wp_localize_script( 'sps-form-engine', 'spsGlobalConfig', array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( SPS_Ajax_Handler::NONCE_ACTION ),
				'iconsUrl'  => SPS_PLUGIN_URL . 'assets/icons/portal-icons.svg',
				'siteUrl'   => home_url(),
				'i18n'      => array(
					'required'       => __( 'Bitte füllen Sie dieses Feld aus.', 'smart-portal-suite' ),
					'invalidEmail'   => __( 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'smart-portal-suite' ),
					'fileTooLarge'   => __( 'Datei ist zu groß (max. 10 MB).', 'smart-portal-suite' ),
					'fileTypeError'  => __( 'Dieser Dateityp ist nicht erlaubt.', 'smart-portal-suite' ),
					'submitting'     => __( 'Wird gesendet...', 'smart-portal-suite' ),
					'submit'         => __( 'Absenden', 'smart-portal-suite' ),
					'retry'          => __( 'Erneut versuchen', 'smart-portal-suite' ),
					'networkError'   => __( 'Netzwerkfehler. Bitte versuchen Sie es erneut.', 'smart-portal-suite' ),
					'next'           => __( 'Weiter', 'smart-portal-suite' ),
					'back'           => __( 'Zurück', 'smart-portal-suite' ),
				),
			) );
			$config_localized = true;
		}

		// Prepare container data
		$form_config = array(
			'formId'    => $form_id,
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( SPS_Ajax_Handler::NONCE_ACTION ),
			'iconsUrl'  => SPS_PLUGIN_URL . 'assets/icons/portal-icons.svg',
			'schema'    => $schema,
		);

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
	 * Checks exact match, underscore, and hyphen variations.
	 *
	 * @param string $form_id Form identifier.
	 * @return array|null Decoded schema array or null on failure.
	 */
	public function load_schema( $form_id ) {
		$candidates = array(
			$form_id,
			str_replace( '_', '-', $form_id ),
			str_replace( '-', '_', $form_id ),
		);

		$candidates = array_unique( $candidates );

		foreach ( $candidates as $candidate ) {
			$file_path = SPS_PLUGIN_DIR . 'config/forms/' . $candidate . '.json';
			if ( file_exists( $file_path ) ) {
				$json_content = file_get_contents( $file_path );
				$decoded      = json_decode( $json_content, true );
				if ( is_array( $decoded ) && ! empty( $decoded['steps'] ) ) {
					return apply_filters( 'sps_form_schema', $decoded, $form_id );
				}
			}
		}

		return null;
	}

	/**
	 * Get list of all available forms in config/forms/ directory.
	 *
	 * @return array Array of [ 'id' => ..., 'title' => ..., 'file' => ... ]
	 */
	public function get_available_forms() {
		$forms = array();
		$files = glob( SPS_PLUGIN_DIR . 'config/forms/*.json' );

		if ( ! empty( $files ) ) {
			foreach ( $files as $file ) {
				$json_content = file_get_contents( $file );
				$data         = json_decode( $json_content, true );
				if ( is_array( $data ) && isset( $data['title'] ) ) {
					$id = basename( $file, '.json' );
					$forms[ $id ] = array(
						'id'          => $id,
						'form_id'     => isset( $data['form_id'] ) ? $data['form_id'] : $id,
						'title'       => $data['title'],
						'steps_count' => isset( $data['steps'] ) ? count( $data['steps'] ) : 0,
					);
				}
			}
		}

		return apply_filters( 'sps_available_forms', $forms );
	}
}
