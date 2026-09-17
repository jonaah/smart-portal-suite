<?php
/**
 * Admin Diagnostics Tool
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Diagnostics {

	/**
	 * Singleton instance.
	 *
	 * @var SPS_Diagnostics|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_Diagnostics
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
		add_action( 'admin_menu', array( $this, 'add_diagnostics_submenu' ) );
	}

	/**
	 * Log error messages.
	 *
	 * @param string $message The message to log.
	 */
	public static function log_error( $message ) {
		error_log( '[SPS Error] ' . $message );
	}

	/**
	 * Register diagnostics submenu page.
	 */
	public function add_diagnostics_submenu() {
		add_submenu_page(
			'smart-portal-suite',
			__( 'Diagnose', 'smart-portal-suite' ),
			__( 'Diagnose', 'smart-portal-suite' ),
			'manage_options',
			'sps-diagnostics',
			array( $this, 'render_diagnostics_page' )
		);
	}

	/**
	 * Render diagnostics page.
	 */
	public function render_diagnostics_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) );
		}

		$sodium_available = function_exists( 'sodium_crypto_secretbox' );
		$nextcloud_url    = SPS_Settings::get_setting( 'nextcloud_url', '' );
		$service_account  = SPS_Settings::get_setting( 'service_account', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Smart Portal Suite - Diagnose-Werkzeug', 'smart-portal-suite' ); ?></h1>
			<table class="widefat striped" style="max-width: 800px; margin-top: 20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Prüfung', 'smart-portal-suite' ); ?></th>
						<th><?php esc_html_e( 'Status', 'smart-portal-suite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'PHP Sodium Erweiterung (Verschlüsselung)', 'smart-portal-suite' ); ?></strong></td>
						<td>
							<?php if ( $sodium_available ) : ?>
								<span style="color: green;">✔ <?php esc_html_e( 'Verfügbar', 'smart-portal-suite' ); ?></span>
							<?php else : ?>
								<span style="color: red;">✖ <?php esc_html_e( 'Nicht verfügbar (PHP >= 7.2 erforderlich)', 'smart-portal-suite' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Nextcloud URL hinterlegt', 'smart-portal-suite' ); ?></strong></td>
						<td>
							<?php if ( ! empty( $nextcloud_url ) ) : ?>
								<span style="color: green;">✔ <?php echo esc_html( $nextcloud_url ); ?></span>
							<?php else : ?>
								<span style="color: orange;">⚠ <?php esc_html_e( 'Nicht konfiguriert', 'smart-portal-suite' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Service Account hinterlegt', 'smart-portal-suite' ); ?></strong></td>
						<td>
							<?php if ( ! empty( $service_account ) ) : ?>
								<span style="color: green;">✔ <?php echo esc_html( $service_account ); ?></span>
							<?php else : ?>
								<span style="color: orange;">⚠ <?php esc_html_e( 'Nicht konfiguriert', 'smart-portal-suite' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
