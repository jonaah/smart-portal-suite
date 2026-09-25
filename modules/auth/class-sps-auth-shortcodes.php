<?php
/**
 * Authentication Shortcodes & Form Renderers
 *
 * @package SmartPortalSuite\Auth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Auth_Shortcodes {

	/**
	 * Singleton instance.
	 *
	 * @var SPS_Auth_Shortcodes|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_Auth_Shortcodes
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
		// Assets registrieren
		add_action( 'wp_enqueue_scripts', array( $this, 'register_auth_assets' ) );

		// Shortcodes registrieren
		add_shortcode( 'sps_auth_buttons', array( $this, 'render_auth_buttons' ) );
		add_shortcode( 'sps_login', array( $this, 'render_login_form' ) );
		add_shortcode( 'sps_register', array( $this, 'render_registration_form' ) );

		// Rückwärtskompatible Aliase
		add_shortcode( 'auth_buttons', array( $this, 'render_auth_buttons' ) );
		add_shortcode( 'energie_login', array( $this, 'render_login_form' ) );
		add_shortcode( 'energie_registrierung', array( $this, 'render_registration_form' ) );
	}

	/**
	 * Registriert Frontend-Assets für Auth-Formulare.
	 */
	public function register_auth_assets() {
		wp_register_style(
			'sps-auth-forms-css',
			SPS_PLUGIN_URL . 'assets/css/auth-forms.css',
			array(),
			SPS_VERSION
		);

		wp_register_script(
			'sps-auth-forms-js',
			SPS_PLUGIN_URL . 'assets/js/auth-forms.js',
			array(),
			SPS_VERSION,
			true
		);
	}

	/**
	 * Lädt die Auth-Assets on demand.
	 */
	private function enqueue_auth_assets() {
		wp_enqueue_style( 'sps-auth-forms-css' );
		wp_enqueue_script( 'sps-auth-forms-js' );
	}

	/**
	 * Shortcode: Header Auth Buttons [sps_auth_buttons]
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string HTML-Ausgabe.
	 */
	public function render_auth_buttons( $atts ) {
		$this->enqueue_auth_assets();

		$default_login    = SPS_Settings::get_setting( 'auth_login_url', '/login/' );
		$default_register = SPS_Settings::get_setting( 'auth_register_url', '/registrierung/' );

		$args = shortcode_atts(
			array(
				'login_url'    => ! empty( $default_login ) ? $default_login : home_url( '/login/' ),
				'register_url' => ! empty( $default_register ) ? $default_register : home_url( '/registrierung/' ),
				'confirm_text' => __( 'Möchtest du dich wirklich abmelden?', 'smart-portal-suite' ),
			),
			$atts,
			'sps_auth_buttons'
		);

		$login_url    = esc_url( $args['login_url'] );
		$register_url = esc_url( $args['register_url'] );

		ob_start();
		?>
		<div class="sps-header-auth-buttons">
			<?php if ( is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" 
				   class="btn-logout" 
				   onclick="return confirm('<?php echo esc_js( $args['confirm_text'] ); ?>');" 
				   title="<?php esc_attr_e( 'Abmelden', 'smart-portal-suite' ); ?>">
					<svg viewBox="0 0 24 24">
						<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
						<polyline points="16 17 21 12 16 7"></polyline>
						<line x1="21" y1="12" x2="9" y2="12"></line>
					</svg>
				</a>
			<?php else : ?>
				<a href="<?php echo $login_url; ?>" class="btn-login" title="<?php esc_attr_e( 'Einloggen', 'smart-portal-suite' ); ?>">
					<svg viewBox="0 0 24 24">
						<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
						<polyline points="10 17 15 12 10 7"></polyline>
						<line x1="15" y1="12" x2="3" y2="12"></line>
					</svg>
				</a>
				<a href="<?php echo $register_url; ?>" class="btn-register" title="<?php esc_attr_e( 'Registrieren', 'smart-portal-suite' ); ?>">
					<svg viewBox="0 0 24 24">
						<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
						<circle cx="8.5" cy="7" r="4"></circle>
						<line x1="20" y1="8" x2="20" y2="14"></line>
						<line x1="23" y1="11" x2="17" y2="11"></line>
					</svg>
				</a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shortcode: Custom Magic Link Login Form [sps_login]
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string HTML-Ausgabe.
	 */
	public function render_login_form( $atts ) {
		$this->enqueue_auth_assets();

		$default_redirect = SPS_Settings::get_setting( 'login_redirect_url', home_url( '/' ) );
		$default_register = SPS_Settings::get_setting( 'auth_register_url', '/registrierung/' );

		// Falls Redirect-Cookie existiert, bevorzugt verwenden
		if ( ! empty( $_COOKIE['sps_redirect'] ) ) {
			$cookie_url = esc_url_raw( wp_unslash( $_COOKIE['sps_redirect'] ) );
			if ( ! empty( $cookie_url ) ) {
				$default_redirect = $cookie_url;
			}
		} elseif ( ! empty( $_COOKIE['qc_redirect'] ) ) {
			$cookie_url = esc_url_raw( wp_unslash( $_COOKIE['qc_redirect'] ) );
			if ( ! empty( $cookie_url ) ) {
				$default_redirect = $cookie_url;
			}
		}

		$args = shortcode_atts(
			array(
				'redirect_to'  => $default_redirect,
				'register_url' => $default_register,
				'title'        => __( 'Login', 'smart-portal-suite' ),
				'description'  => __( 'Melde dich an, um auf den Gebäude-Check und deine Nextcloud zuzugreifen.', 'smart-portal-suite' ),
			),
			$atts,
			'sps_login'
		);

		ob_start();
		?>
		<div class="sps-auth-wrapper sps-login-wrapper">
			<div class="sps-auth-header">
				<h1><?php echo esc_html( $args['title'] ); ?></h1>
				<?php if ( ! empty( $args['description'] ) ) : ?>
					<p><?php echo esc_html( $args['description'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="sps-form-box">
				<?php
				if ( shortcode_exists( 'magic_login_form' ) ) {
					echo do_shortcode( sprintf( '[magic_login_form redirect_to="%s"]', esc_url( $args['redirect_to'] ) ) );
				} elseif ( current_user_can( 'manage_options' ) ) {
					?>
					<div class="sps-auth-notice">
						<strong><?php esc_html_e( 'Hinweis für Administratoren:', 'smart-portal-suite' ); ?></strong>
						<p><?php esc_html_e( 'Das Plugin "Magic Login Pro" ist nicht aktiv. Bitte installieren und aktivieren, um das Anmeldeformular darzustellen.', 'smart-portal-suite' ); ?></p>
					</div>
					<?php
				}
				?>

				<!-- Registrierungs-Footer -->
				<div class="sps-auth-footer">
					<div class="sps-auth-footer-divider"></div>
					<p><?php esc_html_e( 'Noch kein Konto vorhanden?', 'smart-portal-suite' ); ?></p>
					<a href="<?php echo esc_url( $args['register_url'] ); ?>" class="sps-switch-btn">
						<?php esc_html_e( 'Jetzt registrieren', 'smart-portal-suite' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shortcode: Custom Magic Link Registrierungs-Form [sps_register]
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string HTML-Ausgabe.
	 */
	public function render_registration_form( $atts ) {
		$this->enqueue_auth_assets();

		$default_login   = SPS_Settings::get_setting( 'auth_login_url', '/login/' );
		$default_privacy = SPS_Settings::get_setting( 'privacy_url', '/datenschutzerklaerung' );

		$args = shortcode_atts(
			array(
				'login_url'       => $default_login,
				'privacy_url'     => $default_privacy,
				'title'           => __( 'Registrierung', 'smart-portal-suite' ),
				'description'     => __( 'Registriere dich und erhalte Zugriff auf Funktionen wie den Gebäude-Check und deine eigene Nextcloud.', 'smart-portal-suite' ),
				'button_text'     => __( 'Registrieren', 'smart-portal-suite' ),
				'success_message' => __( 'Registrierung erfolgreich, bitte prüfe dein E-Mail-Postfach.', 'smart-portal-suite' ),
			),
			$atts,
			'sps_register'
		);

		ob_start();
		?>
		<div class="sps-auth-wrapper sps-registration-wrapper">
			<div class="sps-auth-header">
				<h2><?php echo esc_html( $args['title'] ); ?></h2>
				<?php if ( ! empty( $args['description'] ) ) : ?>
					<p><?php echo esc_html( $args['description'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="sps-form-box">
				<?php if ( is_user_logged_in() ) : ?>
					<div class="sps-already-logged-in">
						<p><?php esc_html_e( 'Du bist bereits erfolgreich angemeldet.', 'smart-portal-suite' ); ?></p>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="sps-btn-home">
							<?php esc_html_e( 'Zur Startseite', 'smart-portal-suite' ); ?>
						</a>
					</div>
				<?php else : ?>
					<?php
					if ( shortcode_exists( 'magic_login_registration_form' ) ) {
						$terms_html = sprintf(
							__( 'Ich habe die <a href="%s" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und stimme zu.', 'smart-portal-suite' ),
							esc_url( $args['privacy_url'] )
						);

						$shortcode_str = sprintf(
							'[magic_login_registration_form show_name="true" require_name="true" show_terms="true" require_terms="true" registration_terms="%s" button_text="%s" info_message="" success_message="%s"]',
							esc_attr( $terms_html ),
							esc_attr( $args['button_text'] ),
							esc_attr( $args['success_message'] )
						);

						echo do_shortcode( $shortcode_str );
					} elseif ( current_user_can( 'manage_options' ) ) {
						?>
						<div class="sps-auth-notice">
							<strong><?php esc_html_e( 'Hinweis für Administratoren:', 'smart-portal-suite' ); ?></strong>
							<p><?php esc_html_e( 'Das Plugin "Magic Login Pro" ist nicht aktiv. Bitte installieren und aktivieren, um das Registrierungsformular darzustellen.', 'smart-portal-suite' ); ?></p>
						</div>
						<?php
					}
					?>

					<!-- Login-Footer -->
					<div class="sps-auth-footer">
						<div class="sps-auth-footer-divider"></div>
						<p><?php esc_html_e( 'Bereits ein Konto vorhanden?', 'smart-portal-suite' ); ?></p>
						<a href="<?php echo esc_url( $args['login_url'] ); ?>" class="sps-switch-btn">
							<?php esc_html_e( 'Direkt zum Login', 'smart-portal-suite' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
