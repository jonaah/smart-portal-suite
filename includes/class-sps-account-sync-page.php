<?php
/**
 * Account-Sync Admin Dashboard & User Provisioning Monitor
 *
 * @package SmartPortalSuite\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Account_Sync_Page {

	/**
	 * Singleton instance.
	 *
	 * @var SPS_Account_Sync_Page|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_Account_Sync_Page
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
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX Endpunkte
		add_action( 'wp_ajax_sps_sync_single_user', array( $this, 'ajax_sync_single_user' ) );
		add_action( 'wp_ajax_sps_test_nc_db', array( $this, 'ajax_test_nc_db' ) );
	}

	/**
	 * Fügt die Account-Sync Untermenüseite hinzu.
	 */
	public function add_menu_page() {
		add_submenu_page(
			'smart-portal-suite',
			__( 'Account-Sync & Status', 'smart-portal-suite' ),
			__( 'Account-Sync', 'smart-portal-suite' ),
			'manage_options',
			'sps-account-sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Lädt Admin-Assets für die Account-Sync-Seite.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'smart-portal_page_sps-account-sync' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sps-admin-sync-css',
			SPS_PLUGIN_URL . 'assets/css/admin-sync.css',
			array(),
			SPS_VERSION
		);

		wp_enqueue_script(
			'sps-admin-sync-js',
			SPS_PLUGIN_URL . 'assets/js/admin-sync.js',
			array( 'jquery' ),
			SPS_VERSION,
			true
		);

		wp_localize_script(
			'sps-admin-sync-js',
			'spsSyncData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sps_sync_nonce' ),
				'i18n'    => array(
					'testingDb'     => __( 'Prüfe Nextcloud-DB...', 'smart-portal-suite' ),
					'syncing'       => __( 'Synchronisiere...', 'smart-portal-suite' ),
					'resync'        => __( 'Erneut syncen', 'smart-portal-suite' ),
					'created'       => __( 'Angelegt', 'smart-portal-suite' ),
					'linked'        => __( 'Verknüpft', 'smart-portal-suite' ),
					'dbMissing'     => __( 'Keine DB-Verknüpfung', 'smart-portal-suite' ),
					'confirmBatch'  => __( 'Möchtest du alle ausstehenden Benutzer jetzt mit Nextcloud synchronisieren?', 'smart-portal-suite' ),
					'startingBatch' => __( 'Starte Stapelverarbeitung...', 'smart-portal-suite' ),
					'noPending'     => __( 'Keine ausstehenden Benutzer vorhanden.', 'smart-portal-suite' ),
					'batchComplete' => __( 'Stapelverarbeitung abgeschlossen:', 'smart-portal-suite' ),
					'usersSynced'   => __( 'Benutzer synchronisiert.', 'smart-portal-suite' ),
					'syncingUser'   => __( 'Synchronisiere Benutzer-ID', 'smart-portal-suite' ),
				),
			)
		);
	}

	/**
	 * AJAX Handler: Einzelnen Benutzer synchronisieren.
	 */
	public function ajax_sync_single_user() {
		check_ajax_referer( 'sps_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Ungültige Benutzer-ID.', 'smart-portal-suite' ) ) );
		}

		$sync_engine = SPS_NC_User_Sync::get_instance();
		$result = $sync_engine->sync_user( $user_id, true );

		if ( $result['success'] ) {
			$status = $sync_engine->get_user_sync_status( $user_id );
			wp_send_json_success( array(
				'message'       => $result['message'],
				'synced_at'     => $status['synced_at'],
				'social_linked' => $status['is_social_linked'],
			) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX Handler: Nextcloud-Datenbankverbindung testen.
	 */
	public function ajax_test_nc_db() {
		check_ajax_referer( 'sps_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) ), 403 );
		}

		$sync_engine = SPS_NC_User_Sync::get_instance();
		$result = $sync_engine->test_nc_db_connection();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Rendert die Admin-Dashboard-Seite.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) );
		}

		$sync_engine = SPS_NC_User_Sync::get_instance();
		$stats       = $sync_engine->get_sync_statistics();

		// System- und Plugin-Status ermitteln
		$magic_login_active = shortcode_exists( 'magic_login_form' ) || is_plugin_active( 'magic-login-pro/magic-login-pro.php' );
		$oauth_server_active = class_exists( 'WO_Server' ) || defined( 'WPOAUTH_DIR' ) || is_plugin_active( 'wp-oauth-server/wp-oauth-server.php' );

		$nc_url          = SPS_Settings::get_setting( 'nextcloud_url', '' );
		$service_account = SPS_Settings::get_setting( 'service_account', '' );
		$has_app_pw      = ! empty( SPS_Settings::get_raw_setting( 'app_password' ) );
		$nc_api_ready    = ( ! empty( $nc_url ) && ! empty( $service_account ) && $has_app_pw );

		$nc_db_host      = SPS_Settings::get_setting( 'nc_db_host', '' );
		$nc_db_name      = SPS_Settings::get_setting( 'nc_db_name', '' );
		$nc_db_user      = SPS_Settings::get_setting( 'nc_db_user', '' );
		$nc_db_ready     = ( ! empty( $nc_db_host ) && ! empty( $nc_db_name ) && ! empty( $nc_db_user ) );

		// Filter und Suchparameter
		$search_query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter       = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'all';

		// Benutzerabfrage vorbereiten
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$limit = 25;

		$user_query_args = array(
			'number' => $limit,
			'offset' => ( $paged - 1 ) * $limit,
			'search' => ! empty( $search_query ) ? '*' . $search_query . '*' : '',
		);

		if ( 'pending' === $filter ) {
			$user_query_args['meta_query'] = array(
				array(
					'key'     => 'nextcloud_linked',
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( 'synced' === $filter ) {
			$user_query_args['meta_query'] = array(
				array(
					'key'     => 'nextcloud_linked',
					'value'   => '1',
					'compare' => '=',
				),
			);
		} elseif ( 'error' === $filter ) {
			$user_query_args['meta_query'] = array(
				array(
					'key'     => 'nextcloud_sync_error',
					'compare' => 'EXISTS',
				),
			);
		}

		$user_search = new WP_User_Query( $user_query_args );
		$users       = $user_search->get_results();
		$total_filtered = $user_search->get_total_users();
		$total_pages    = ceil( $total_filtered / $limit );
		?>
		<div class="wrap sps-sync-wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-admin-users" style="font-size: 28px; line-height: 1; margin-right: 6px;"></span>
				<?php esc_html_e( 'Smart Portal Suite - Account-Sync & Status', 'smart-portal-suite' ); ?>
			</h1>
			<p class="sps-lead-text">
				<?php esc_html_e( 'Übersicht über die Nextcloud-Anbindung, Social-Login-Schnittstellen und Synchronisation aller WordPress-Benutzer.', 'smart-portal-suite' ); ?>
			</p>
			<hr class="wp-header-end" />

			<!-- 1. Schnittstellen & Status-Karten -->
			<div class="sps-status-cards-grid">
				<!-- Nextcloud OCS API -->
				<div class="sps-status-card">
					<div class="sps-status-card-icon icon-blue">
						<span class="dashicons dashicons-cloud"></span>
					</div>
					<div class="sps-status-card-info">
						<div class="sps-status-card-title"><?php esc_html_e( 'Nextcloud OCS API', 'smart-portal-suite' ); ?></div>
						<div class="sps-status-card-value">
							<?php if ( $nc_api_ready ) : ?>
								<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Konfiguriert', 'smart-portal-suite' ); ?></span>
							<?php else : ?>
								<span class="sps-badge sps-badge-warning">⚠ <?php esc_html_e( 'Unvollständig', 'smart-portal-suite' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="sps-status-card-desc"><?php echo esc_html( $service_account ? 'Bot: ' . $service_account : 'Kein Account' ); ?></div>
					</div>
				</div>

				<!-- Nextcloud Datenbank -->
				<div class="sps-status-card">
					<div class="sps-status-card-icon icon-purple">
						<span class="dashicons dashicons-database"></span>
					</div>
					<div class="sps-status-card-info">
						<div class="sps-status-card-title"><?php esc_html_e( 'Nextcloud Datenbank (PDO)', 'smart-portal-suite' ); ?></div>
						<div class="sps-status-card-value">
							<?php if ( $nc_db_ready ) : ?>
								<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Hinterlegt', 'smart-portal-suite' ); ?></span>
							<?php else : ?>
								<span class="sps-badge sps-badge-warning">⚠ <?php esc_html_e( 'Nicht hinterlegt', 'smart-portal-suite' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="sps-status-card-desc"><?php echo esc_html( $nc_db_host ? $nc_db_host . ' (' . $nc_db_name . ')' : 'In Einstellungen pflegen' ); ?></div>
					</div>
				</div>

				<!-- Magic Login Pro -->
				<div class="sps-status-card">
					<div class="sps-status-card-icon icon-amber">
						<span class="dashicons dashicons-admin-network"></span>
					</div>
					<div class="sps-status-card-info">
						<div class="sps-status-card-title"><?php esc_html_e( 'Magic Login Pro', 'smart-portal-suite' ); ?></div>
						<div class="sps-status-card-value">
							<?php if ( $magic_login_active ) : ?>
								<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Aktiviert', 'smart-portal-suite' ); ?></span>
							<?php else : ?>
								<span class="sps-badge sps-badge-error">✖ <?php esc_html_e( 'Nicht aktiv', 'smart-portal-suite' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="sps-status-card-desc"><?php esc_html_e( 'Formular-Engine für Magic Links', 'smart-portal-suite' ); ?></div>
					</div>
				</div>

				<!-- WP OAuth Server -->
				<div class="sps-status-card">
					<div class="sps-status-card-icon icon-emerald">
						<span class="dashicons dashicons-shield"></span>
					</div>
					<div class="sps-status-card-info">
						<div class="sps-status-card-title"><?php esc_html_e( 'WP OAuth Server', 'smart-portal-suite' ); ?></div>
						<div class="sps-status-card-value">
							<?php if ( $oauth_server_active ) : ?>
								<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Aktiviert', 'smart-portal-suite' ); ?></span>
							<?php else : ?>
								<span class="sps-badge sps-badge-warning">ℹ <?php esc_html_e( 'Prüfen', 'smart-portal-suite' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="sps-status-card-desc"><?php esc_html_e( 'Für Nextcloud Gebäudeportal-Login', 'smart-portal-suite' ); ?></div>
					</div>
				</div>
			</div>

			<!-- 2. Metriken / Statistiken -->
			<div class="sps-metrics-grid">
				<div class="sps-metric-box">
					<div class="sps-metric-num"><?php echo esc_html( $stats['total'] ); ?></div>
					<div class="sps-metric-label"><?php esc_html_e( 'WordPress Benutzer', 'smart-portal-suite' ); ?></div>
				</div>
				<div class="sps-metric-box metric-synced">
					<div class="sps-metric-num"><?php echo esc_html( $stats['synced'] ); ?></div>
					<div class="sps-metric-label"><?php esc_html_e( 'Nextcloud Accounts', 'smart-portal-suite' ); ?></div>
				</div>
				<div class="sps-metric-box metric-linked">
					<div class="sps-metric-num"><?php echo esc_html( $stats['social_linked'] ); ?></div>
					<div class="sps-metric-label"><?php esc_html_e( 'Social-Login verknüpft', 'smart-portal-suite' ); ?></div>
				</div>
				<div class="sps-metric-box metric-pending">
					<div class="sps-metric-num"><?php echo esc_html( $stats['pending'] ); ?></div>
					<div class="sps-metric-label"><?php esc_html_e( 'Ausstehend / Offen', 'smart-portal-suite' ); ?></div>
				</div>
			</div>

			<!-- 3. Aktionsleiste & Filter -->
			<div class="sps-sync-toolbar">
				<div class="sps-toolbar-actions">
					<button type="button" id="sps-batch-sync-btn" class="button button-primary" <?php disabled( 0 === $stats['pending'] ); ?>>
						<span class="dashicons dashicons-update" style="vertical-align: text-top; font-size: 16px;"></span>
						<?php printf( esc_html__( 'Alle ausstehenden Benutzer synchronisieren (%d)', 'smart-portal-suite' ), $stats['pending'] ); ?>
					</button>

					<button type="button" id="sps-test-db-btn" class="button button-secondary">
						<span class="dashicons dashicons-database" style="vertical-align: text-top; font-size: 16px;"></span>
						<?php esc_html_e( 'Nextcloud-DB testen', 'smart-portal-suite' ); ?>
					</button>
				</div>

				<div class="sps-toolbar-filter">
					<form method="get" action="">
						<input type="hidden" name="page" value="sps-account-sync" />
						<select name="filter" onchange="this.form.submit()">
							<option value="all" <?php selected( 'all', $filter ); ?>><?php esc_html_e( 'Alle Benutzer anzeigen', 'smart-portal-suite' ); ?></option>
							<option value="pending" <?php selected( 'pending', $filter ); ?>><?php esc_html_e( 'Nur Ausstehende', 'smart-portal-suite' ); ?></option>
							<option value="synced" <?php selected( 'synced', $filter ); ?>><?php esc_html_e( 'Nur Synchronisierte', 'smart-portal-suite' ); ?></option>
							<option value="error" <?php selected( 'error', $filter ); ?>><?php esc_html_e( 'Nur Fehlerhafte', 'smart-portal-suite' ); ?></option>
						</select>
						<input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Name oder E-Mail...', 'smart-portal-suite' ); ?>" />
						<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Suchen', 'smart-portal-suite' ); ?>" />
					</form>
				</div>
			</div>

			<!-- DB Test Ergebnis Container -->
			<div id="sps-db-test-result" style="display: none;"></div>

			<!-- Stapelverarbeitungs Fortschrittsbalken -->
			<div id="sps-batch-progress-box" class="sps-batch-progress-box">
				<div class="sps-progress-text" id="sps-batch-progress-text"><?php esc_html_e( 'Synchronisiere Benutzer...', 'smart-portal-suite' ); ?></div>
				<div class="sps-progress-bar-wrap">
					<div class="sps-progress-bar-fill" id="sps-batch-progress-bar"></div>
				</div>
			</div>

			<!-- 4. Benutzer-Tabelle -->
			<div class="sps-users-card">
				<div class="postbox-header">
					<h2>
						<span class="dashicons dashicons-list-view" style="margin-right: 6px;"></span>
						<?php printf( esc_html__( 'Benutzerliste (%d Treffer)', 'smart-portal-suite' ), $total_filtered ); ?>
					</h2>
				</div>
				<div class="inside" style="padding: 0;">
					<table class="widefat striped sps-users-table">
						<thead>
							<tr>
								<th style="width: 50px;"><?php esc_html_e( 'ID', 'smart-portal-suite' ); ?></th>
								<th><?php esc_html_e( 'Benutzer', 'smart-portal-suite' ); ?></th>
								<th><?php esc_html_e( 'E-Mail', 'smart-portal-suite' ); ?></th>
								<th><?php esc_html_e( 'Registriert', 'smart-portal-suite' ); ?></th>
								<th><?php esc_html_e( 'Nextcloud Sync', 'smart-portal-suite' ); ?></th>
								<th><?php esc_html_e( 'Social-Login', 'smart-portal-suite' ); ?></th>
								<th><?php esc_html_e( 'Letzter Sync', 'smart-portal-suite' ); ?></th>
								<th style="width: 170px; text-align: right;"><?php esc_html_e( 'Aktionen', 'smart-portal-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $users ) ) : ?>
								<tr>
									<td colspan="8" style="text-align: center; padding: 24px; color: #646970;">
										<?php esc_html_e( 'Keine Benutzer gefunden.', 'smart-portal-suite' ); ?>
									</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $users as $u ) : ?>
									<?php
									$u_id     = $u->ID;
									$u_status = $sync_engine->get_user_sync_status( $u_id );
									$is_protected = $sync_engine->is_protected_account( $u->user_email );
									?>
									<tr id="sps-user-row-<?php echo esc_attr( $u_id ); ?>">
										<td><code>#<?php echo esc_html( $u_id ); ?></code></td>
										<td>
											<strong><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></strong>
											<?php if ( $is_protected ) : ?>
												<span class="sps-badge sps-badge-info" style="margin-left: 6px;"><?php esc_html_e( 'Service Account', 'smart-portal-suite' ); ?></span>
											<?php endif; ?>
										</td>
										<td><code><?php echo esc_html( $u->user_email ); ?></code></td>
										<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $u->user_registered ) ); ?></td>
										<td class="cell-nc-status">
											<?php if ( $u_status['is_linked'] ) : ?>
												<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Angelegt', 'smart-portal-suite' ); ?></span>
											<?php elseif ( ! empty( $u_status['sync_error'] ) ) : ?>
												<span class="sps-badge sps-badge-error" title="<?php echo esc_attr( $u_status['sync_error'] ); ?>">✖ <?php esc_html_e( 'Fehler', 'smart-portal-suite' ); ?></span>
											<?php else : ?>
												<span class="sps-badge sps-badge-muted">○ <?php esc_html_e( 'Ausstehend', 'smart-portal-suite' ); ?></span>
											<?php endif; ?>
										</td>
										<td class="cell-social-status">
											<?php if ( $u_status['is_social_linked'] ) : ?>
												<span class="sps-badge sps-badge-success">✔ <?php esc_html_e( 'Verknüpft', 'smart-portal-suite' ); ?></span>
											<?php else : ?>
												<span class="sps-badge sps-badge-muted">○ <?php esc_html_e( 'Nicht verknüpft', 'smart-portal-suite' ); ?></span>
											<?php endif; ?>
										</td>
										<td class="cell-synced-at">
											<?php echo ! empty( $u_status['synced_at'] ) ? esc_html( mysql2date( 'd.m.Y H:i', $u_status['synced_at'] ) ) : '<span class="description">—</span>'; ?>
										</td>
										<td style="text-align: right;">
											<?php if ( ! $is_protected ) : ?>
												<button type="button" 
												        class="button button-small sps-sync-user-btn <?php echo $u_status['is_linked'] ? 'button-secondary' : 'button-primary'; ?>" 
												        data-user-id="<?php echo esc_attr( $u_id ); ?>"
												        data-pending="<?php echo $u_status['is_linked'] ? '0' : '1'; ?>">
													<?php echo $u_status['is_linked'] ? esc_html__( 'Erneut syncen', 'smart-portal-suite' ) : esc_html__( 'Jetzt syncen', 'smart-portal-suite' ); ?>
												</button>
											<?php else : ?>
												<span class="description"><?php esc_html_e( 'Geschützt', 'smart-portal-suite' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top: 14px;">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $paged,
						) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
