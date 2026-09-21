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

		// Apply saved form-specific theme overrides as inline CSS variables
		add_filter( 'sps_form_container_styles', array( $this, 'apply_saved_theme_styles' ), 10, 3 );
	}

	/**
	 * Apply saved per-form theme styles from wp_options.
	 *
	 * Reads the option `sps_form_theme_{form_id}` (supporting hyphen and underscore variants)
	 * and converts it to a CSS variable declaration string appended to any existing inline styles.
	 * Also falls back to SPS_Settings::primary_color if configured.
	 *
	 * @param string $existing_styles Current inline styles string.
	 * @param string $form_id         Form identifier.
	 * @param array  $schema          Form schema data.
	 * @return string Combined inline styles.
	 */
	public function apply_saved_theme_styles( $existing_styles, $form_id, $schema ) {
		$saved_theme = class_exists( 'SPS_Form_Manager' )
			? SPS_Form_Manager::get_theme( $form_id )
			: array();

		if ( empty( $saved_theme ) ) {
			$variants = array_unique( array(
				sanitize_key( $form_id ),
				sanitize_key( str_replace( '-', '_', $form_id ) ),
				sanitize_key( str_replace( '_', '-', $form_id ) ),
			) );
			foreach ( $variants as $variant ) {
				$opt = get_option( 'sps_form_theme_' . $variant );
				if ( ! empty( $opt ) && is_array( $opt ) ) {
					$saved_theme = $opt;
					break;
				}
			}
		}

		// Fallback for primary accent color from SPS_Settings (if no per-form theme override exists)
		if ( empty( $saved_theme ) || empty( $saved_theme['--sps-accent'] ) ) {
			$global_primary = SPS_Settings::get_setting( 'primary_color', '' );
			if ( ! empty( $global_primary ) && '#00838f' !== $global_primary && '#39baff' !== $global_primary ) {
				if ( empty( $saved_theme ) ) {
					$saved_theme = array();
				}
				$saved_theme['--sps-accent'] = $global_primary;
			}
		}

		if ( empty( $saved_theme ) || ! is_array( $saved_theme ) ) {
			return $existing_styles;
		}

		$vars = array();
		foreach ( $saved_theme as $var_name => $var_value ) {
			// Only allow --sps-* variables
			if ( 0 !== strpos( $var_name, '--sps-' ) ) {
				continue;
			}
			$clean_name = '--' . sanitize_key( ltrim( $var_name, '-' ) );
			$vars[] = $clean_name . ': ' . esc_attr( $var_value );
		}

		if ( empty( $vars ) ) {
			return $existing_styles;
		}

		$theme_styles = implode( '; ', $vars );

		if ( ! empty( $existing_styles ) ) {
			return $existing_styles . '; ' . $theme_styles;
		}

		return $theme_styles;
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

		// Check for login requirement
		if ( ! empty( $schema['requires_login'] ) && ! is_user_logged_in() ) {
			wp_enqueue_style( 'sps-portal-base' );
			$login_redirect = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
			$login_url    = wp_login_url( $login_redirect );
			$register_url = wp_registration_url();

			return sprintf(
				'<div class="sps-form-wrapper sps-login-gate">
					<div class="sps-login-gate-icon">&#128274;</div>
					<h3 class="sps-login-gate-title">%s</h3>
					<p class="sps-login-gate-desc">%s</p>
					<div class="sps-login-gate-actions">
						<a href="%s" class="sps-btn sps-btn-login">%s</a>
						%s
					</div>
				</div>',
				esc_html__( 'Anmeldung erforderlich', 'smart-portal-suite' ),
				esc_html__( 'Dieses Formular steht exklusiv registrierten Partnern und Kunden zur Verfügung. Bitte melden Sie sich an, um fortzufahren.', 'smart-portal-suite' ),
				esc_url( $login_url ),
				esc_html__( 'Jetzt anmelden', 'smart-portal-suite' ),
				get_option( 'users_can_register' ) ? sprintf( '<a href="%s" class="sps-btn sps-btn-register">%s</a>', esc_url( $register_url ), esc_html__( 'Registrieren', 'smart-portal-suite' ) ) : ''
			);
		}

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
				'iconsUrl'   => SPS_PLUGIN_URL . 'assets/icons/portal-icons.svg',
				'siteUrl'    => home_url(),
				'privacyUrl' => SPS_Settings::get_setting( 'privacy_url', '/datenschutz' ),
				'i18n'       => array(
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
					$file_slug    = basename( $file, '.json' );
					$canonical_id = isset( $data['form_id'] ) ? sanitize_key( $data['form_id'] ) : sanitize_key( $file_slug );
					$form_entry   = array(
						'id'          => $canonical_id,
						'file_id'     => $file_slug,
						'form_id'     => $canonical_id,
						'title'       => $data['title'],
						'steps_count' => isset( $data['steps'] ) ? count( $data['steps'] ) : 0,
					);
					// Primary entry by canonical form ID (unique per form)
					$forms[ $canonical_id ] = $form_entry;
				}
			}
		}

		return apply_filters( 'sps_available_forms', $forms );
	}
}
