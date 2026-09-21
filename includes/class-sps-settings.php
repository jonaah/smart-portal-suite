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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add administration menu and submenus.
	 */
	public function add_admin_menu() {
		// Hauptmenü
		add_menu_page(
			__( 'Smart Portal Suite', 'smart-portal-suite' ),
			__( 'Smart Portal', 'smart-portal-suite' ),
			'manage_options',
			'smart-portal-suite',
			array( $this, 'render_settings_page' ),
			'dashicons-forms',
			58
		);

		// Erstes Submenü: Einstellungen
		add_submenu_page(
			'smart-portal-suite',
			__( 'Einstellungen', 'smart-portal-suite' ),
			__( 'Einstellungen', 'smart-portal-suite' ),
			'manage_options',
			'smart-portal-suite',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles for SPS admin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Nur auf SPS Admin-Seiten laden
		if ( 'toplevel_page_smart-portal-suite' !== $hook && 'smart-portal_page_sps-diagnostics' !== $hook ) {
			return;
		}

		// WordPress Color Picker
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		// SPS Admin Style
		wp_enqueue_style(
			'sps-admin-css',
			SPS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SPS_VERSION
		);

		// SPS Admin Script
		wp_enqueue_script(
			'sps-admin-js',
			SPS_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			SPS_VERSION,
			true
		);

		wp_localize_script(
			'sps-admin-js',
			'spsAdminData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'diagNonce' => wp_create_nonce( 'sps_diagnostics_nonce' ),
				'i18n'      => array(
					'testing'        => __( 'Verbindung wird geprüft...', 'smart-portal-suite' ),
					'testSuccess'    => __( 'Verbindungstest erfolgreich!', 'smart-portal-suite' ),
					'testFailed'     => __( 'Verbindungstest fehlgeschlagen!', 'smart-portal-suite' ),
					'clearingLog'    => __( 'Log wird geleert...', 'smart-portal-suite' ),
					'logCleared'     => __( 'Debug-Log erfolgreich geleert.', 'smart-portal-suite' ),
					'confirmClear'   => __( 'Möchtest du das Debug-Log wirklich leeren?', 'smart-portal-suite' ),
					'copied'         => __( 'Systembericht in Zwischenablage kopiert!', 'smart-portal-suite' ),
				),
			)
		);
	}

	/**
	 * Register settings, sections and fields via WordPress Settings API.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// Sektion 1: Nextcloud Anbindung
		add_settings_section(
			'sps_section_nextcloud',
			__( 'Nextcloud Anbindung', 'smart-portal-suite' ),
			array( $this, 'render_section_nextcloud_desc' ),
			'smart-portal-suite'
		);

		add_settings_field(
			'nextcloud_url',
			__( 'Nextcloud URL', 'smart-portal-suite' ),
			array( $this, 'render_field_nextcloud_url' ),
			'smart-portal-suite',
			'sps_section_nextcloud'
		);

		add_settings_field(
			'service_account',
			__( 'Service Account Benutzername', 'smart-portal-suite' ),
			array( $this, 'render_field_service_account' ),
			'smart-portal-suite',
			'sps_section_nextcloud'
		);

		add_settings_field(
			'app_password',
			__( 'Nextcloud App-Passwort', 'smart-portal-suite' ),
			array( $this, 'render_field_app_password' ),
			'smart-portal-suite',
			'sps_section_nextcloud'
		);

		add_settings_field(
			'webdav_base_dir',
			__( 'WebDAV Basisordner', 'smart-portal-suite' ),
			array( $this, 'render_field_webdav_base_dir' ),
			'smart-portal-suite',
			'sps_section_nextcloud'
		);

		// Sektion 2: Formular & Design
		add_settings_section(
			'sps_section_design',
			__( 'Design & Datenschutz', 'smart-portal-suite' ),
			array( $this, 'render_section_design_desc' ),
			'smart-portal-suite'
		);

		add_settings_field(
			'primary_color',
			__( 'Primärfarbe (Akzentfarbe)', 'smart-portal-suite' ),
			array( $this, 'render_field_primary_color' ),
			'smart-portal-suite',
			'sps_section_design'
		);

		add_settings_field(
			'privacy_url',
			__( 'Datenschutzerklärung Link', 'smart-portal-suite' ),
			array( $this, 'render_field_privacy_url' ),
			'smart-portal-suite',
			'sps_section_design'
		);

		// Sektion 3: Benachrichtigungen & Fallback
		add_settings_section(
			'sps_section_fallback',
			__( 'Benachrichtigungen & Fallback', 'smart-portal-suite' ),
			array( $this, 'render_section_fallback_desc' ),
			'smart-portal-suite'
		);

		add_settings_field(
			'admin_email',
			__( 'Admin Benachrichtigungs-E-Mail', 'smart-portal-suite' ),
			array( $this, 'render_field_admin_email' ),
			'smart-portal-suite',
			'sps_section_fallback'
		);

		// Sektion 4: Wartung & Debugging
		add_settings_section(
			'sps_section_debug',
			__( 'Wartung & Debugging', 'smart-portal-suite' ),
			array( $this, 'render_section_debug_desc' ),
			'smart-portal-suite'
		);

		add_settings_field(
			'debug_mode',
			__( 'Debug-Modus', 'smart-portal-suite' ),
			array( $this, 'render_field_debug_mode' ),
			'smart-portal-suite',
			'sps_section_debug'
		);
	}

	/* --- Section Descriptions --- */

	public function render_section_nextcloud_desc() {
		echo '<p class="description">' . esc_html__( 'Konfiguration der Nextcloud Forms API v3 Schnittstelle und WebDAV Fallback-Ablage.', 'smart-portal-suite' ) . '</p>';
	}

	public function render_section_design_desc() {
		echo '<p class="description">' . esc_html__( 'Visuelle Einstellungen und rechtliche Links für die Formular-Einbindung.', 'smart-portal-suite' ) . '</p>';
	}

	public function render_section_fallback_desc() {
		echo '<p class="description">' . esc_html__( 'Empfänger für Warnmeldungen, falls die direkte Nextcloud-API-Übertragung fehlschlägt und der WebDAV-Fallback aktiv wird.', 'smart-portal-suite' ) . '</p>';
	}

	public function render_section_debug_desc() {
		echo '<p class="description">' . esc_html__( 'Optionen zur Fehleranalyse und Protokollierung für Entwickler und Administratoren.', 'smart-portal-suite' ) . '</p>';
	}

	/* --- Field Renderers --- */

	public function render_field_nextcloud_url() {
		$value = self::get_setting( 'nextcloud_url', '' );
		?>
		<input type="url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[nextcloud_url]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="https://nc.example.com" />
		<p class="description"><?php esc_html_e( 'Vollständige URL zur Nextcloud-Instanz (z. B. https://nc.effizientes-heim.de).', 'smart-portal-suite' ); ?></p>
		<?php
	}

	public function render_field_service_account() {
		$value = self::get_setting( 'service_account', '' );
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[service_account]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="sps_service_bot" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Benutzername des dedizierten Nextcloud-Service-Accounts.', 'smart-portal-suite' ); ?></p>
		<?php
	}

	public function render_field_app_password() {
		$stored_encrypted = self::get_raw_setting( 'app_password' );
		$is_configured    = ! empty( $stored_encrypted );
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[app_password]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $is_configured ? '••••••••••••••••' : ''; ?>" />
		<?php if ( $is_configured ) : ?>
			<p class="description" style="color: #2e7d32;">
				<span class="dashicons dashicons-yes-alt" style="vertical-align: text-top; font-size: 17px;"></span>
				<?php esc_html_e( 'Passwort ist sicher verschlüsselt hinterlegt. Feld leer lassen, um das bestehende Passwort beizubehalten.', 'smart-portal-suite' ); ?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'App-Passwort aus Nextcloud (unter Persönliche Einstellungen > Sicherheit > App-Passwörter).', 'smart-portal-suite' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_field_webdav_base_dir() {
		$value = self::get_setting( 'webdav_base_dir', 'SPS_Leads' );
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[webdav_base_dir]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="SPS_Leads" />
		<p class="description"><?php esc_html_e( 'Zielordner in Nextcloud für JSON-Fallback-Dateien (ohne führenden Schrägstrich).', 'smart-portal-suite' ); ?></p>
		<?php
	}

	public function render_field_primary_color() {
		$value = self::get_setting( 'primary_color', '#39baff' );
		$customizer_url = admin_url( 'admin.php?page=sps-forms' );
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[primary_color]" value="<?php echo esc_attr( $value ); ?>" class="sps-color-picker" data-default-color="#39baff" />
		<p class="description">
			<?php esc_html_e( 'Globale Standard-Akzentfarbe für interaktive Elemente wie Buttons, Radio-Auswahlen und den Fortschrittsbalken.', 'smart-portal-suite' ); ?><br>
			<?php esc_html_e( 'Tipp: Unter', 'smart-portal-suite' ); ?>
			<a href="<?php echo esc_url( $customizer_url ); ?>"><strong><?php esc_html_e( 'Smart Portal > Formulare', 'smart-portal-suite' ); ?></strong></a>
			<?php esc_html_e( 'kannst du jedes Formular komplett individuell (inkl. Hintergründe, Kartenfarben, Radien & Live-Vorschau) anpassen.', 'smart-portal-suite' ); ?>
		</p>
		<?php
	}

	public function render_field_privacy_url() {
		$value = self::get_setting( 'privacy_url', '' );
		?>
		<input type="url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[privacy_url]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="https://example.com/datenschutz" />
		<p class="description"><?php esc_html_e( 'URL zur Datenschutzerklärung. Wird im Formular-Consent-Feld verlinkt.', 'smart-portal-suite' ); ?></p>
		<?php
	}

	public function render_field_admin_email() {
		$value = self::get_setting( 'admin_email', get_option( 'admin_email' ) );
		?>
		<input type="email" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[admin_email]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="admin@example.com" />
		<p class="description"><?php esc_html_e( 'E-Mail-Adresse für Warnhinweise bei Ausfällen der Nextcloud Forms API.', 'smart-portal-suite' ); ?></p>
		<?php
	}

	public function render_field_debug_mode() {
		$value = self::get_setting( 'debug_mode', 0 );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[debug_mode]" value="1" <?php checked( 1, $value ); ?> />
			<?php esc_html_e( 'Ausführliches Debug-Logging im SPS-Protokoll und error_log aktivieren.', 'smart-portal-suite' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Protokolliert API-Aufrufe, Latenzzeiten und Fehlermeldungen zur detaillierten Diagnose.', 'smart-portal-suite' ); ?></p>
		<?php
	}

	/* --- Encryption Engine (Sodium) --- */

	/**
	 * Get encryption secret key (32 bytes).
	 * Derived deterministically from WordPress salts.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function get_encryption_key() {
		if ( defined( 'SPS_ENCRYPTION_KEY' ) && ! empty( SPS_ENCRYPTION_KEY ) ) {
			return hash( 'sha256', SPS_ENCRYPTION_KEY, true );
		}
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) : wp_salt( 'sps_credentials' );
		return hash( 'sha256', $salt . 'sps_crypto_v1', true );
	}

	/**
	 * Encrypt a plaintext string using sodium_crypto_secretbox.
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string Base64 encoded payload (nonce + ciphertext).
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || null === $plaintext ) {
			return '';
		}

		$key = self::get_encryption_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( (string) $plaintext, $nonce, $key );
			return base64_encode( 'sod:' . $nonce . $ciphertext );
		}

		// Fallback for systems without ext-sodium (e.g. openssl)
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv_len     = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv         = openssl_random_pseudo_bytes( $iv_len );
			$ciphertext = openssl_encrypt( (string) $plaintext, 'aes-256-cbc', $key, 0, $iv );
			return base64_encode( 'ssl:' . $iv . $ciphertext );
		}

		// Letzte Notfall-Option
		return base64_encode( 'raw:' . $plaintext );
	}

	/**
	 * Decrypt an encrypted string.
	 *
	 * @param string $encrypted Base64 encoded payload.
	 * @return string Decrypted plaintext.
	 */
	public static function decrypt( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		$decoded = base64_decode( $encrypted, true );
		if ( false === $decoded ) {
			return '';
		}

		$key = self::get_encryption_key();

		// Sodium-Entschlüsselung
		if ( 0 === strpos( $decoded, 'sod:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw        = substr( $decoded, 4 );
			$nonce_len  = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if ( strlen( $raw ) < $nonce_len ) {
				return '';
			}
			$nonce      = substr( $raw, 0, $nonce_len );
			$ciphertext = substr( $raw, $nonce_len );
			$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
			return false !== $plaintext ? $plaintext : '';
		}

		// OpenSSL Fallback
		if ( 0 === strpos( $decoded, 'ssl:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw        = substr( $decoded, 4 );
			$iv_len     = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv         = substr( $raw, 0, $iv_len );
			$ciphertext = substr( $raw, $iv_len );
			$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, 0, $iv );
			return false !== $plaintext ? $plaintext : '';
		}

		// Raw Fallback
		if ( 0 === strpos( $decoded, 'raw:' ) ) {
			return substr( $decoded, 4 );
		}

		return '';
	}

	/* --- Sanitization Callback --- */

	/**
	 * Sanitize and encrypt sensitive options before saving.
	 *
	 * @param array $input Raw input data from form.
	 * @return array Sanitized and encrypted settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();
		if ( ! is_array( $input ) ) {
			return $sanitized;
		}

		$existing = get_option( self::OPTION_NAME, array() );

		// Nextcloud URL (Trailing slash entfernen)
		$sanitized['nextcloud_url'] = isset( $input['nextcloud_url'] ) ? untrailingslashit( esc_url_raw( trim( $input['nextcloud_url'] ) ) ) : '';

		// Service Account
		$sanitized['service_account'] = isset( $input['service_account'] ) ? sanitize_text_field( trim( $input['service_account'] ) ) : '';

		// App Passwort: nur aktualisieren wenn neues Passwort eingegeben wurde
		if ( ! empty( $input['app_password'] ) ) {
			$sanitized['app_password'] = self::encrypt( trim( $input['app_password'] ) );
		} else {
			// Bestehendes verschlüsseltes Passwort beibehalten
			$sanitized['app_password'] = isset( $existing['app_password'] ) ? $existing['app_password'] : '';
		}

		// WebDAV Basisordner
		$base_dir = isset( $input['webdav_base_dir'] ) ? trim( sanitize_text_field( $input['webdav_base_dir'] ), '/' ) : 'SPS_Leads';
		$sanitized['webdav_base_dir'] = ! empty( $base_dir ) ? $base_dir : 'SPS_Leads';

		// Formular Design: Primärfarbe
		$color = isset( $input['primary_color'] ) ? sanitize_hex_color( $input['primary_color'] ) : '#39baff';
		$sanitized['primary_color'] = ! empty( $color ) ? $color : '#39baff';

		// Datenschutz URL
		$sanitized['privacy_url'] = isset( $input['privacy_url'] ) ? esc_url_raw( trim( $input['privacy_url'] ) ) : '';

		// Admin Benachrichtigungs-E-Mail
		$sanitized['admin_email'] = isset( $input['admin_email'] ) ? sanitize_email( trim( $input['admin_email'] ) ) : get_option( 'admin_email' );

		// Debug Modus
		$sanitized['debug_mode'] = ! empty( $input['debug_mode'] ) ? 1 : 0;

		return $sanitized;
	}

	/* --- Page Rendering --- */

	/**
	 * Render settings page view.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) );
		}
		?>
		<div class="wrap sps-admin-wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-forms" style="font-size: 28px; line-height: 1; margin-right: 6px;"></span>
				<?php esc_html_e( 'Smart Portal Suite - Einstellungen', 'smart-portal-suite' ); ?>
			</h1>
			<p class="sps-lead-text">
				<?php esc_html_e( 'Verwalte hier die Nextcloud-Schnittstelle, das Design der Multi-Step-Formulare sowie Fallback- und Debugging-Optionen.', 'smart-portal-suite' ); ?>
			</p>
			<hr class="wp-header-end" />

			<div class="sps-settings-grid">
				<div class="sps-main-col">
					<form method="post" action="options.php" class="sps-settings-form">
						<?php
						settings_fields( self::OPTION_GROUP );
						do_settings_sections( 'smart-portal-suite' );
						submit_button( __( 'Änderungen speichern', 'smart-portal-suite' ), 'primary large' );
						?>
					</form>
				</div>
				<div class="sps-sidebar-col">
					<div class="postbox sps-info-box">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Shortcode Übersicht', 'smart-portal-suite' ); ?></h2>
						</div>
						<div class="inside">
							<p><?php esc_html_e( 'Binde Formulare einfach über den Shortcode in deine Seiten ein:', 'smart-portal-suite' ); ?></p>
							<code>[sps_form id="gebaeude_check"]</code>
							<br><br>
							<code>[sps_form id="projekte_mit_mir"]</code>
						</div>
					</div>

					<div class="postbox sps-info-box">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Diagnose & Tests', 'smart-portal-suite' ); ?></h2>
						</div>
						<div class="inside">
							<p><?php esc_html_e( 'Überprüfe die Nextcloud-Anbindung und das Live-Protokoll im Diagnose-Werkzeug:', 'smart-portal-suite' ); ?></p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=sps-diagnostics' ) ); ?>" class="button button-secondary">
								<span class="dashicons dashicons-dashboard" style="vertical-align: text-top; font-size: 16px;"></span>
								<?php esc_html_e( 'Zum Diagnose-Werkzeug', 'smart-portal-suite' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* --- Getter Helper Methods --- */

	/**
	 * Retrieve a setting value. If app_password, it is automatically decrypted.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$options = get_option( self::OPTION_NAME, array() );

		if ( ! isset( $options[ $key ] ) ) {
			return $default;
		}

		// Automatisches Entschlüsseln des App-Passworts
		if ( 'app_password' === $key ) {
			$decrypted = self::decrypt( $options[ $key ] );
			return ! empty( $decrypted ) ? $decrypted : $default;
		}

		return $options[ $key ];
	}

	/**
	 * Retrieve the raw (encrypted) setting value.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_raw_setting( $key, $default = null ) {
		$options = get_option( self::OPTION_NAME, array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}
}
