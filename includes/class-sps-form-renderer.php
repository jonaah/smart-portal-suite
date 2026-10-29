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

		// Print SVG sprites in footer (Frontend und Admin-Vorschau)
		add_action( 'wp_footer', array( $this, 'print_svg_sprites' ), 20 );
		add_action( 'admin_footer', array( $this, 'print_svg_sprites' ), 20 );

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
			echo self::themeable_svg( (string) file_get_contents( $sprite_file ) );
		}
		echo "</div>\n<!-- /Smart Portal Suite SVG Sprites -->\n";
		$this->enqueued_sprites = array();
	}

	/**
	 * Feste Illustrationsfarben (fill/stroke) durch CSS-Variablen mit der
	 * Originalfarbe als Fallback ersetzen, damit sie im Styling-Editor
	 * änderbar sind. Ohne gesetzte Variable bleibt alles wie bisher.
	 *
	 * @param string $svg Sprite-Inhalt.
	 * @return string
	 */
	public static function themeable_svg( $svg ) {
		if ( ! class_exists( 'SPS_Form_Manager' ) ) {
			return $svg;
		}
		$map = SPS_Form_Manager::get_icon_color_map();

		return preg_replace_callback(
			'/<(path|rect|circle|ellipse|polygon|polyline|line|g)\b([^>]*?)(\/?)>/i',
			function ( $m ) use ( $map ) {
				$attrs  = $m[2];
				$styles = array();
				$attrs  = preg_replace_callback(
					'/\s(fill|stroke)="#([0-9a-fA-F]{6})"/',
					function ( $a ) use ( $map, &$styles ) {
						$hex = strtoupper( $a[2] );
						if ( ! isset( $map[ $hex ] ) ) {
							return $a[0];
						}
						$styles[] = $a[1] . ':var(' . $map[ $hex ] . ',#' . $hex . ')';
						return '';
					},
					$attrs
				);
				if ( empty( $styles ) ) {
					return $m[0];
				}
				if ( preg_match( '/\sstyle="([^"]*)"/', $attrs, $st ) ) {
					$attrs = str_replace( $st[0], ' style="' . rtrim( $st[1], '; ' ) . ';' . implode( ';', $styles ) . '"', $attrs );
				} else {
					$attrs .= ' style="' . implode( ';', $styles ) . '"';
				}
				return '<' . $m[1] . $attrs . $m[3] . '>';
			},
			$svg
		);
	}

	/* ═══════════════════════════════════════════════════════════════
	   ZUGANG (nur angemeldete Nutzer)
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * Option mit den Zugangseinstellungen je Formular.
	 */
	const ACCESS_OPTION = 'sps_form_access';

	/**
	 * Zugangseinstellungen eines Formulars.
	 *
	 * @param string $form_id Formular-ID.
	 * @return array{access:string,gate_text:string} access: '' (Schema), 'login' oder 'public'.
	 */
	public static function get_access_settings( $form_id ) {
		$all = get_option( self::ACCESS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$id  = sanitize_key( $form_id );
		foreach ( array( $id, str_replace( '-', '_', $id ), str_replace( '_', '-', $id ) ) as $k ) {
			if ( isset( $all[ $k ] ) && is_array( $all[ $k ] ) ) {
				return array(
					'access'    => isset( $all[ $k ]['access'] ) && in_array( $all[ $k ]['access'], array( 'login', 'public' ), true ) ? $all[ $k ]['access'] : '',
					'gate_text' => isset( $all[ $k ]['gate_text'] ) ? (string) $all[ $k ]['gate_text'] : '',
				);
			}
		}
		return array(
			'access'    => '',
			'gate_text' => '',
		);
	}

	/**
	 * Zugangseinstellungen speichern.
	 *
	 * @param string $form_id   Formular-ID.
	 * @param string $access    '', 'login' oder 'public'.
	 * @param string $gate_text Hinweis für Gäste (leer = Standardtext).
	 */
	public static function save_access_settings( $form_id, $access, $gate_text = '' ) {
		$all = get_option( self::ACCESS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$id  = sanitize_key( $form_id );
		unset( $all[ str_replace( '_', '-', $id ) ], $all[ str_replace( '-', '_', $id ) ] );

		$access = in_array( $access, array( 'login', 'public' ), true ) ? $access : '';
		if ( '' === $access && '' === trim( (string) $gate_text ) ) {
			unset( $all[ $id ] );
		} else {
			$all[ $id ] = array(
				'access'    => $access,
				'gate_text' => sanitize_textarea_field( (string) $gate_text ),
			);
		}
		update_option( self::ACCESS_OPTION, $all, false );
	}

	/**
	 * Muss man für dieses Formular angemeldet sein?
	 *
	 * Einstellung im Formular-Editor (bzw. "wp sps configure --form-access") hat
	 * Vorrang vor "requires_login" im Schema.
	 *
	 * @param array|null $schema Schema.
	 * @return bool
	 */
	public static function requires_login( $schema ) {
		if ( ! is_array( $schema ) ) {
			return false;
		}
		$form_id = isset( $schema['form_id'] ) ? $schema['form_id'] : '';
		$access  = $form_id ? self::get_access_settings( $form_id ) : array( 'access' => '' );
		if ( 'login' === $access['access'] ) {
			$required = true;
		} elseif ( 'public' === $access['access'] ) {
			$required = false;
		} else {
			$required = ! empty( $schema['requires_login'] );
		}
		return (bool) apply_filters( 'sps_form_requires_login', $required, $schema );
	}

	/**
	 * Standardtext des Login-Hinweises.
	 *
	 * @return string
	 */
	public static function default_gate_text() {
		return __( 'Dieses Formular steht registrierten Kundinnen und Kunden zur Verfügung. Bitte melden Sie sich an oder registrieren Sie sich, um fortzufahren.', 'smart-portal-suite' );
	}

	/**
	 * Login-Hinweis statt Formular.
	 *
	 * @param string $form_id Formular-ID.
	 * @param array  $schema  Schema.
	 * @return string HTML.
	 */
	protected function render_login_gate( $form_id, $schema ) {
		wp_enqueue_style( 'sps-portal-base' );

		$current_url = home_url( add_query_arg( array() ) );
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$current_url = home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}
		$current_url = wp_validate_redirect( $current_url, home_url( '/' ) );

		$login_base = (string) SPS_Settings::get_setting( 'auth_login_url', '' );
		if ( '' !== $login_base ) {
			$login_url = add_query_arg( 'redirect_to', rawurlencode( $current_url ), ( 0 === strpos( $login_base, 'http' ) ? $login_base : home_url( $login_base ) ) );
		} else {
			$login_url = wp_login_url( $current_url );
		}

		$register_base = (string) SPS_Settings::get_setting( 'auth_register_url', '' );
		$register_url  = '';
		if ( '' !== $register_base ) {
			$register_url = add_query_arg( 'redirect_to', rawurlencode( $current_url ), ( 0 === strpos( $register_base, 'http' ) ? $register_base : home_url( $register_base ) ) );
		} elseif ( get_option( 'users_can_register' ) ) {
			$register_url = wp_registration_url();
		}

		$access = self::get_access_settings( $form_id );
		$text   = '' !== trim( $access['gate_text'] ) ? $access['gate_text'] : self::default_gate_text();
		$title  = isset( $schema['title'] ) ? $schema['title'] : '';

		// Theme-Farben des Formulars auch für den Hinweis.
		$styles = apply_filters( 'sps_form_container_styles', '', $form_id, $schema );

		ob_start();
		?>
		<div class="sps-form-container sps-login-gate-container" data-sps-form-id="<?php echo esc_attr( $form_id ); ?>"<?php echo $styles ? ' style="' . esc_attr( $styles ) . '"' : ''; ?>>
			<div class="sps-form-wrapper sps-login-gate">
				<div class="sps-login-gate-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10.5" width="16" height="10" rx="2.5"></rect><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"></path></svg>
				</div>
				<?php if ( $title ) : ?>
					<p class="sps-login-gate-kicker"><?php echo esc_html( $title ); ?></p>
				<?php endif; ?>
				<h3 class="sps-login-gate-title"><?php esc_html_e( 'Bitte melden Sie sich an', 'smart-portal-suite' ); ?></h3>
				<p class="sps-login-gate-desc"><?php echo nl2br( esc_html( $text ) ); ?></p>
				<div class="sps-login-gate-actions">
					<a href="<?php echo esc_url( $login_url ); ?>" class="sps-btn sps-btn-login" data-sps-redirect="<?php echo esc_attr( $current_url ); ?>"><?php esc_html_e( 'Anmelden', 'smart-portal-suite' ); ?></a>
					<?php if ( $register_url ) : ?>
						<a href="<?php echo esc_url( $register_url ); ?>" class="sps-btn sps-btn-register" data-sps-redirect="<?php echo esc_attr( $current_url ); ?>"><?php esc_html_e( 'Registrieren', 'smart-portal-suite' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<script>
		(function () {
			document.querySelectorAll('[data-sps-redirect]').forEach(function (a) {
				a.addEventListener('click', function () {
					try {
						var u = a.getAttribute('data-sps-redirect');
						document.cookie = 'sps_redirect=' + encodeURIComponent(u) + '; path=/; max-age=3600; SameSite=Lax';
					} catch (e) {}
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render shortcode [sps_form id="..."].
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'id'      => '',
			'preview' => '',
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

		// Nur für angemeldete Nutzer? (Admin-Vorschau zeigt immer das Formular)
		$is_preview = ! empty( $atts['preview'] ) && is_admin() && current_user_can( 'manage_options' );
		if ( ! $is_preview && self::requires_login( $schema ) && ! is_user_logged_in() ) {
			return $this->render_login_gate( $form_id, $schema );
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
			if ( file_exists( $custom_sprite_path ) ) {
				$this->enqueue_sprite( $custom_sprite_path );
			} else {
				$upload_sprite_path = self::get_custom_icons_dir() . basename( $schema['sprite'] );
				if ( file_exists( $upload_sprite_path ) ) {
					$this->enqueue_sprite( $upload_sprite_path );
				}
			}
		}

		// Attempt to load a form-specific SVG sprite.
		// Normalise the form_id: try exact, hyphen and underscore variants.
		$sprite_id_variants = array_unique( array(
			$form_id,
			str_replace( '-', '_', $form_id ),
			str_replace( '_', '-', $form_id ),
		) );

		$custom_icons_dir = self::get_custom_icons_dir();
		$sprite_loaded    = false;

		// 1. Check custom uploads directory
		foreach ( $sprite_id_variants as $variant ) {
			$upload_sprite = $custom_icons_dir . $variant . '.svg';
			if ( file_exists( $upload_sprite ) ) {
				$this->enqueue_sprite( $upload_sprite );
				$sprite_loaded = true;
				break;
			}
		}

		// 2. Check bundled plugin assets/icons/ if not found in uploads
		if ( ! $sprite_loaded ) {
			foreach ( $sprite_id_variants as $variant ) {
				$form_specific_sprite = SPS_PLUGIN_DIR . 'assets/icons/' . $variant . '.svg';
				if ( file_exists( $form_specific_sprite ) ) {
					$this->enqueue_sprite( $form_specific_sprite );
					break;
				}
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
				'privacyUrl' => SPS_Settings::get_privacy_url(),
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
			'preview'   => $is_preview,
		);

		ob_start();
		$template_path = SPS_PLUGIN_DIR . 'templates/form-container.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
		return ob_get_clean();
	}

	/**
	 * Get persistent directory path for custom/imported forms in wp-content/uploads.
	 *
	 * @return string Absolute directory path with trailing slash.
	 */
	public static function get_custom_forms_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'smart-portal-suite/forms/';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			if ( ! file_exists( $dir . 'index.php' ) ) {
				@file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );
			}
		}
		return $dir;
	}

	/**
	 * Get persistent directory path for custom/imported SVG sprites in wp-content/uploads.
	 *
	 * @return string Absolute directory path with trailing slash.
	 */
	public static function get_custom_icons_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'smart-portal-suite/icons/';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			if ( ! file_exists( $dir . 'index.php' ) ) {
				@file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );
			}
		}
		return $dir;
	}

	/**
	 * Load JSON schema for a given form ID.
	 *
	 * Checks custom uploads directory first, then wp_options fallback,
	 * and finally the bundled plugin config/forms directory.
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

		// 1. Check custom uploads directory first (allows custom forms or overriding bundled ones)
		$custom_dir = self::get_custom_forms_dir();
		foreach ( $candidates as $candidate ) {
			$custom_file = $custom_dir . $candidate . '.json';
			if ( file_exists( $custom_file ) ) {
				$json_content = file_get_contents( $custom_file );
				$decoded      = json_decode( $json_content, true );
				if ( is_array( $decoded ) && ! empty( $decoded['steps'] ) ) {
					return apply_filters( 'sps_form_schema', $decoded, $form_id );
				}
			}
		}

		// 2. Check wp_options fallback (if upload directory was unwriteable)
		foreach ( $candidates as $candidate ) {
			$db_schema = get_option( 'sps_custom_form_' . $candidate );
			if ( ! empty( $db_schema ) && is_array( $db_schema ) && ! empty( $db_schema['steps'] ) ) {
				return apply_filters( 'sps_form_schema', $db_schema, $form_id );
			}
		}

		// 3. Check bundled plugin config/forms directory
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
	 * Get list of all available forms (both system-bundled and custom-imported).
	 *
	 * @return array Map of canonical form IDs to form details.
	 */
	public function get_available_forms() {
		$forms = array();

		// 1. Core / bundled forms in config/forms/
		$files = glob( SPS_PLUGIN_DIR . 'config/forms/*.json' );
		if ( ! empty( $files ) ) {
			foreach ( $files as $file ) {
				$json_content = file_get_contents( $file );
				$data         = json_decode( $json_content, true );
				if ( is_array( $data ) && isset( $data['title'] ) ) {
					$file_slug    = basename( $file, '.json' );
					$canonical_id = isset( $data['form_id'] ) ? sanitize_key( $data['form_id'] ) : sanitize_key( $file_slug );
					$forms[ $canonical_id ] = array(
						'id'          => $canonical_id,
						'file_id'     => $file_slug,
						'form_id'     => $canonical_id,
						'title'       => $data['title'],
						'steps_count' => isset( $data['steps'] ) ? count( $data['steps'] ) : 0,
						'source'      => 'system',
						'file_path'   => $file,
					);
				}
			}
		}

		// 2. Custom / imported forms in wp-content/uploads/smart-portal-suite/forms/
		$custom_dir   = self::get_custom_forms_dir();
		$custom_files = glob( $custom_dir . '*.json' );
		if ( ! empty( $custom_files ) ) {
			foreach ( $custom_files as $file ) {
				$json_content = file_get_contents( $file );
				$data         = json_decode( $json_content, true );
				if ( is_array( $data ) && isset( $data['title'] ) ) {
					$file_slug    = basename( $file, '.json' );
					$canonical_id = isset( $data['form_id'] ) ? sanitize_key( $data['form_id'] ) : sanitize_key( $file_slug );
					$forms[ $canonical_id ] = array(
						'id'          => $canonical_id,
						'file_id'     => $file_slug,
						'form_id'     => $canonical_id,
						'title'       => $data['title'],
						'steps_count' => isset( $data['steps'] ) ? count( $data['steps'] ) : 0,
						'source'      => 'custom',
						'file_path'   => $file,
					);
				}
			}
		}

		return apply_filters( 'sps_available_forms', $forms );
	}
}
