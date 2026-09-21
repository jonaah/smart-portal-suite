<?php
/**
 * Form Manager – Admin Styling Customizer
 *
 * Provides a dedicated admin submenu (Smart Portal > Formulare) with:
 *  - An overview table of all registered forms and their styling status.
 *  - A per-form styling editor using wp-color-picker for CSS variable overrides.
 *  - Quick-apply theme presets and a live preview mock.
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPS_Form_Manager {

	/**
	 * Nonce action for form theme AJAX save.
	 */
	const NONCE_ACTION = 'sps_form_manager_nonce';

	/**
	 * Option key prefix for per-form themes.
	 */
	const OPTION_PREFIX = 'sps_form_theme_';

	/**
	 * Singleton instance.
	 *
	 * @var SPS_Form_Manager|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SPS_Form_Manager
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
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_sps_save_form_theme', array( $this, 'ajax_save_theme' ) );
		add_action( 'wp_ajax_sps_reset_form_theme', array( $this, 'ajax_reset_theme' ) );
		add_action( 'wp_ajax_sps_preview_mock', array( $this, 'ajax_preview_mock' ) );
	}

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Register the "Formulare" submenu under Smart Portal.
	 */
	public function add_submenu() {
		$this->page_hook = add_submenu_page(
			'smart-portal-suite',
			__( 'Formulare', 'smart-portal-suite' ),
			__( 'Formulare', 'smart-portal-suite' ),
			'manage_options',
			'sps-forms',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles only on our admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		$is_sps_forms = ( ! empty( $this->page_hook ) && $hook === $this->page_hook )
			|| ( isset( $_GET['page'] ) && 'sps-forms' === $_GET['page'] )
			|| ( strpos( $hook, 'sps-forms' ) !== false );

		if ( ! $is_sps_forms ) {
			return;
		}

		// WordPress Color Picker
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		// Portal base CSS (for preview mock)
		wp_enqueue_style(
			'sps-portal-base',
			SPS_PLUGIN_URL . 'assets/css/portal-base.css',
			array(),
			SPS_VERSION
		);

		// Admin Forms CSS
		wp_enqueue_style(
			'sps-admin-forms-css',
			SPS_PLUGIN_URL . 'assets/css/admin-forms.css',
			array( 'wp-color-picker' ),
			SPS_VERSION
		);

		// Admin Forms JS
		wp_enqueue_script(
			'sps-admin-forms-js',
			SPS_PLUGIN_URL . 'assets/js/admin-forms.js',
			array( 'jquery', 'wp-color-picker' ),
			SPS_VERSION,
			true
		);

		wp_localize_script( 'sps-admin-forms-js', 'spsFormsAdmin', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
			'presets'  => self::get_presets(),
			'defaults' => self::get_defaults(),
			'i18n'     => array(
				'saved'         => __( 'Styling erfolgreich gespeichert.', 'smart-portal-suite' ),
				'saveFailed'    => __( 'Fehler beim Speichern.', 'smart-portal-suite' ),
				'reset'         => __( 'Styling auf Standard zurückgesetzt.', 'smart-portal-suite' ),
				'resetConfirm'  => __( 'Möchtest du das Styling dieses Formulars wirklich auf den Standard zurücksetzen?', 'smart-portal-suite' ),
				'saving'        => __( 'Speichern...', 'smart-portal-suite' ),
				'save'          => __( 'Styling speichern', 'smart-portal-suite' ),
			),
		) );
	}

	/**
	 * Route to the correct admin view.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_id = isset( $_GET['form_id'] ) ? sanitize_key( $_GET['form_id'] ) : '';

		if ( ! empty( $form_id ) ) {
			$this->render_styling_editor( $form_id );
		} else {
			$this->render_overview_table();
		}
	}

	/* ═══════════════════════════════════════════════════════════════
	   OVERVIEW TABLE
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * Render the forms overview table.
	 */
	private function render_overview_table() {
		$renderer = SPS_Form_Renderer::get_instance();
		$forms    = $renderer->get_available_forms();
		?>
		<div class="wrap sps-admin-wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-forms" style="font-size: 28px; line-height: 1; margin-right: 6px;"></span>
				<?php esc_html_e( 'Smart Portal Suite – Formulare', 'smart-portal-suite' ); ?>
			</h1>
			<p class="sps-lead-text">
				<?php esc_html_e( 'Übersicht aller registrierten Formulare. Passe das Styling jedes Formulars individuell an.', 'smart-portal-suite' ); ?>
			</p>
			<hr class="wp-header-end" />

			<table class="wp-list-table widefat fixed striped sps-forms-table">
				<thead>
					<tr>
						<th scope="col" class="column-title"><?php esc_html_e( 'Formular-Name', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-shortcode"><?php esc_html_e( 'Shortcode', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-steps"><?php esc_html_e( 'Schritte', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-status"><?php esc_html_e( 'Styling-Status', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-actions"><?php esc_html_e( 'Aktionen', 'smart-portal-suite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $forms ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'Keine Formulare gefunden.', 'smart-portal-suite' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $forms as $form ) : ?>
							<?php
							$fid        = sanitize_key( $form['form_id'] );
							$has_custom = ! empty( self::get_theme( $fid ) );
							$edit_url   = admin_url( 'admin.php?page=sps-forms&form_id=' . $fid );
							$shortcode  = '[sps_form id="' . esc_attr( $fid ) . '"]';
							?>
							<tr>
								<td class="column-title">
									<strong><?php echo esc_html( $form['title'] ); ?></strong>
								</td>
								<td class="column-shortcode">
									<code class="sps-shortcode-copy" title="<?php esc_attr_e( 'Klicken zum Kopieren', 'smart-portal-suite' ); ?>"><?php echo esc_html( $shortcode ); ?></code>
								</td>
								<td class="column-steps">
									<?php echo intval( $form['steps_count'] ); ?>
								</td>
								<td class="column-status">
									<?php if ( $has_custom ) : ?>
										<span class="sps-badge sps-badge-custom">
											<span class="dashicons dashicons-art" style="font-size: 14px; vertical-align: text-top;"></span>
											<?php esc_html_e( 'Individuell', 'smart-portal-suite' ); ?>
										</span>
									<?php else : ?>
										<span class="sps-badge sps-badge-default">
											<?php esc_html_e( 'Standard', 'smart-portal-suite' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td class="column-actions">
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary">
										<span class="dashicons dashicons-admin-appearance" style="vertical-align: text-top; font-size: 16px;"></span>
										<?php esc_html_e( 'Styling bearbeiten', 'smart-portal-suite' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════
	   STYLING EDITOR
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * Render the per-form styling editor.
	 *
	 * @param string $form_id Form identifier.
	 */
	private function render_styling_editor( $form_id ) {
		$renderer = SPS_Form_Renderer::get_instance();
		$forms    = $renderer->get_available_forms();

		// Normalise form_id (try exact, underscore and hyphen variants)
		$resolved_form = null;
		$canonical_id  = sanitize_key( $form_id );
		$fid_variants  = array_unique( array( $form_id, str_replace( '-', '_', $form_id ), str_replace( '_', '-', $form_id ) ) );
		foreach ( $fid_variants as $variant ) {
			if ( isset( $forms[ $variant ] ) ) {
				$resolved_form = $forms[ $variant ];
				$canonical_id  = sanitize_key( $resolved_form['form_id'] );
				break;
			}
		}

		if ( ! $resolved_form ) {
			$schema = $renderer->load_schema( $form_id );
			if ( $schema ) {
				$canonical_id  = sanitize_key( isset( $schema['form_id'] ) ? $schema['form_id'] : $form_id );
				$resolved_form = array(
					'id'          => $canonical_id,
					'form_id'     => $canonical_id,
					'title'       => isset( $schema['title'] ) ? $schema['title'] : $canonical_id,
					'steps_count' => isset( $schema['steps'] ) ? count( $schema['steps'] ) : 0,
				);
			}
		}

		if ( ! $resolved_form ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Formular nicht gefunden.', 'smart-portal-suite' ) . '</p></div></div>';
			return;
		}

		$saved_theme = self::get_theme( $canonical_id );
		$defaults    = self::get_defaults();

		// Merge defaults with saved values
		$current = array();
		foreach ( $defaults as $var_name => $default_value ) {
			$current[ $var_name ] = isset( $saved_theme[ $var_name ] ) ? $saved_theme[ $var_name ] : $default_value;
		}

		$back_url = admin_url( 'admin.php?page=sps-forms' );
		?>
		<div class="wrap sps-admin-wrap sps-styling-editor-wrap">
			<h1 class="wp-heading-inline">
				<a href="<?php echo esc_url( $back_url ); ?>" class="sps-back-link" title="<?php esc_attr_e( 'Zurück zur Übersicht', 'smart-portal-suite' ); ?>">
					<span class="dashicons dashicons-arrow-left-alt"></span>
				</a>
				<?php
				printf(
					/* translators: %s: Form title */
					esc_html__( 'Styling: %s', 'smart-portal-suite' ),
					esc_html( $resolved_form['title'] )
				);
				?>
			</h1>
			<p class="sps-lead-text">
				<?php esc_html_e( 'Passe die Farben und Radien dieses Formulars individuell an. Änderungen werden sofort in der Vorschau sichtbar.', 'smart-portal-suite' ); ?>
			</p>
			<hr class="wp-header-end" />

			<div class="sps-styling-grid" data-form-id="<?php echo esc_attr( $canonical_id ); ?>">
				<!-- Left Column: Settings -->
				<div class="sps-styling-controls">

					<!-- Quick Theme Presets -->
					<div class="postbox sps-presets-box">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Schnell-Themes', 'smart-portal-suite' ); ?></h2>
						</div>
						<div class="inside sps-presets-grid">
							<?php foreach ( self::get_presets() as $preset_key => $preset ) : ?>
								<button type="button"
									class="sps-preset-btn"
									data-preset="<?php echo esc_attr( $preset_key ); ?>"
									style="--preset-bg: <?php echo esc_attr( $preset['values']['--sps-bg-main'] ); ?>; --preset-accent: <?php echo esc_attr( $preset['values']['--sps-accent'] ); ?>;">
									<span class="sps-preset-swatch">
										<span class="sps-swatch-bg"></span>
										<span class="sps-swatch-accent"></span>
									</span>
									<span class="sps-preset-label"><?php echo esc_html( $preset['label'] ); ?></span>
								</button>
							<?php endforeach; ?>
							<button type="button" class="sps-preset-btn sps-preset-reset" data-preset="reset">
								<span class="dashicons dashicons-image-rotate" style="font-size: 20px; margin-bottom: 4px;"></span>
								<span class="sps-preset-label"><?php esc_html_e( 'Auf Standard zurücksetzen', 'smart-portal-suite' ); ?></span>
							</button>
						</div>
					</div>

					<!-- Color Settings -->
					<div class="postbox">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Farben', 'smart-portal-suite' ); ?></h2>
						</div>
						<div class="inside">
							<table class="form-table sps-color-table">
								<tbody>
									<?php
									$color_fields = array(
										'--sps-bg-main'      => __( 'Hintergrund Hauptcontainer', 'smart-portal-suite' ),
										'--sps-card-bg'      => __( 'Karten-Hintergrund', 'smart-portal-suite' ),
										'--sps-accent'       => __( 'Primäre Akzentfarbe (Buttons)', 'smart-portal-suite' ),
										'--sps-accent-green' => __( 'Sekundäre Akzentfarbe (Auswahl)', 'smart-portal-suite' ),
										'--sps-text-main'    => __( 'Textfarbe Primär', 'smart-portal-suite' ),
										'--sps-text-muted'   => __( 'Textfarbe Sekundär', 'smart-portal-suite' ),
									);
									foreach ( $color_fields as $var_name => $label ) :
										$value = isset( $current[ $var_name ] ) ? $current[ $var_name ] : $defaults[ $var_name ];
									?>
									<tr>
										<th scope="row">
											<label for="sps-var-<?php echo esc_attr( $var_name ); ?>"><?php echo esc_html( $label ); ?></label>
										</th>
										<td>
											<input type="text"
												id="sps-var-<?php echo esc_attr( $var_name ); ?>"
												class="sps-color-picker"
												name="<?php echo esc_attr( $var_name ); ?>"
												value="<?php echo esc_attr( $value ); ?>"
												data-default-color="<?php echo esc_attr( $defaults[ $var_name ] ); ?>" />
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Radius Settings -->
					<div class="postbox">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Formen & Radien', 'smart-portal-suite' ); ?></h2>
						</div>
						<div class="inside">
							<table class="form-table sps-radius-table">
								<tbody>
									<?php
									$radius_fields = array(
										'--sps-radius-card' => array(
											'label' => __( 'Karten-Eckenrundung', 'smart-portal-suite' ),
											'min'   => 0,
											'max'   => 30,
											'unit'  => 'px',
										),
										'--sps-radius-btn'  => array(
											'label' => __( 'Button-Eckenrundung', 'smart-portal-suite' ),
											'min'   => 0,
											'max'   => 20,
											'unit'  => 'px',
										),
									);
									foreach ( $radius_fields as $var_name => $config ) :
										$raw_value = isset( $current[ $var_name ] ) ? $current[ $var_name ] : $defaults[ $var_name ];
										$num_value = intval( $raw_value );
									?>
									<tr>
										<th scope="row">
											<label for="sps-var-<?php echo esc_attr( $var_name ); ?>"><?php echo esc_html( $config['label'] ); ?></label>
										</th>
										<td class="sps-radius-control">
											<input type="range"
												id="sps-var-<?php echo esc_attr( $var_name ); ?>"
												class="sps-radius-slider"
												name="<?php echo esc_attr( $var_name ); ?>"
												value="<?php echo esc_attr( $num_value ); ?>"
												min="<?php echo intval( $config['min'] ); ?>"
												max="<?php echo intval( $config['max'] ); ?>"
												data-unit="<?php echo esc_attr( $config['unit'] ); ?>" />
											<span class="sps-radius-value"><?php echo intval( $num_value ); ?><?php echo esc_html( $config['unit'] ); ?></span>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Save Button -->
					<div class="sps-save-bar">
						<button type="button" class="button button-primary button-hero sps-save-theme-btn">
							<span class="dashicons dashicons-saved" style="vertical-align: text-top; font-size: 18px; margin-right: 4px;"></span>
							<?php esc_html_e( 'Styling speichern', 'smart-portal-suite' ); ?>
						</button>
						<span class="sps-save-status"></span>
					</div>
				</div>

				<!-- Right Column: Live Preview -->
				<div class="sps-styling-preview">
					<div class="postbox sps-preview-box">
						<div class="postbox-header">
							<h2><?php esc_html_e( 'Live-Vorschau', 'smart-portal-suite' ); ?></h2>
						</div>
						<div class="inside">
							<div class="sps-preview-container sps-form-container" id="sps-preview-mock" style="<?php echo esc_attr( $this->build_inline_style( $current ) ); ?>">
								<?php $this->render_preview_mock(); ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ═══════════════════════════════════════════════════════════════
	   PREVIEW MOCK
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * Render the inline preview mock HTML.
	 */
	private function render_preview_mock() {
		$template_path = SPS_PLUGIN_DIR . 'templates/admin/preview-mock.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
	}

	/**
	 * AJAX endpoint for serving preview mock HTML (used for reset).
	 */
	public function ajax_preview_mock() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		ob_start();
		$this->render_preview_mock();
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/* ═══════════════════════════════════════════════════════════════
	   AJAX HANDLERS
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * AJAX: Save form theme to wp_options.
	 */
	public function ajax_save_theme() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';
		$theme   = isset( $_POST['theme'] ) ? $_POST['theme'] : array();

		if ( empty( $form_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing form_id.' ), 400 );
		}

		// Parse theme if it arrives as a JSON string
		if ( is_string( $theme ) ) {
			$theme = json_decode( wp_unslash( $theme ), true );
		}

		if ( ! is_array( $theme ) ) {
			wp_send_json_error( array( 'message' => 'Invalid theme data.' ), 400 );
		}

		$sanitized = $this->sanitize_theme_data( $theme );

		if ( empty( $sanitized ) ) {
			self::delete_theme( $form_id );
		} else {
			self::save_theme( $form_id, $sanitized );
		}

		wp_send_json_success( array(
			'message' => __( 'Styling erfolgreich gespeichert.', 'smart-portal-suite' ),
			'form_id' => $form_id,
		) );
	}

	/**
	 * AJAX: Reset form theme (delete option).
	 */
	public function ajax_reset_theme() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';

		if ( empty( $form_id ) ) {
			wp_send_json_error( array( 'message' => 'Missing form_id.' ), 400 );
		}

		self::delete_theme( $form_id );

		wp_send_json_success( array(
			'message' => __( 'Styling auf Standard zurückgesetzt.', 'smart-portal-suite' ),
			'form_id' => $form_id,
		) );
	}

	/* ═══════════════════════════════════════════════════════════════
	   THEME PRESETS & DEFAULTS
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * Get default CSS variable values (matches portal-base.css :root).
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'--sps-bg-main'      => '#2b224a',
			'--sps-card-bg'      => '#5e41bf',
			'--sps-accent'       => '#39baff',
			'--sps-accent-green' => '#88D95C',
			'--sps-text-main'    => '#FFFFFF',
			'--sps-text-muted'   => '#deddea',
			'--sps-radius-card'  => '12px',
			'--sps-radius-btn'   => '10px',
		);
	}

	/**
	 * Get available theme presets.
	 *
	 * @return array
	 */
	public static function get_presets() {
		return array(
			'eh_standard'  => array(
				'label'  => __( 'Effizientes Heim Standard', 'smart-portal-suite' ),
				'values' => array(
					'--sps-bg-main'      => '#2b224a',
					'--sps-card-bg'      => '#5e41bf',
					'--sps-accent'       => '#39baff',
					'--sps-accent-green' => '#88D95C',
					'--sps-text-main'    => '#FFFFFF',
					'--sps-text-muted'   => '#deddea',
					'--sps-radius-card'  => '12px',
					'--sps-radius-btn'   => '10px',
				),
			),
			'ocean_blue'   => array(
				'label'  => __( 'Clean Modern (Ocean Blue)', 'smart-portal-suite' ),
				'values' => array(
					'--sps-bg-main'      => '#0f1729',
					'--sps-card-bg'      => '#1a3a5c',
					'--sps-accent'       => '#4fc3f7',
					'--sps-accent-green' => '#66bb6a',
					'--sps-text-main'    => '#FFFFFF',
					'--sps-text-muted'   => '#b0bec5',
					'--sps-radius-card'  => '16px',
					'--sps-radius-btn'   => '12px',
				),
			),
			'light_minimal' => array(
				'label'  => __( 'Minimalist Light', 'smart-portal-suite' ),
				'values' => array(
					'--sps-bg-main'      => '#f5f5f5',
					'--sps-card-bg'      => '#ffffff',
					'--sps-accent'       => '#1976d2',
					'--sps-accent-green' => '#43a047',
					'--sps-text-main'    => '#212121',
					'--sps-text-muted'   => '#757575',
					'--sps-radius-card'  => '8px',
					'--sps-radius-btn'   => '6px',
				),
			),
		);
	}

	/* ═══════════════════════════════════════════════════════════════
	   HELPER METHODS
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * Retrieve saved theme array for a form, checking all ID variants (canonical, hyphen, underscore).
	 *
	 * @param string $form_id Form identifier.
	 * @return array Saved CSS variable overrides or empty array.
	 */
	public static function get_theme( $form_id ) {
		$form_id  = sanitize_key( $form_id );
		$variants = array_unique( array(
			$form_id,
			str_replace( '-', '_', $form_id ),
			str_replace( '_', '-', $form_id ),
		) );

		foreach ( $variants as $variant ) {
			$saved = get_option( self::OPTION_PREFIX . $variant, null );
			if ( ! empty( $saved ) && is_array( $saved ) ) {
				return $saved;
			}
		}

		return array();
	}

	/**
	 * Save theme overrides for a form under its canonical ID, cleaning up any stale variant keys.
	 *
	 * @param string $form_id Form identifier.
	 * @param array  $theme   Sanitized theme data.
	 * @return bool True if saved.
	 */
	public static function save_theme( $form_id, $theme ) {
		$form_id      = sanitize_key( $form_id );
		$canonical_id = $form_id;

		// Resolve schema form_id if available
		$renderer = SPS_Form_Renderer::get_instance();
		$schema   = $renderer->load_schema( $form_id );
		if ( ! empty( $schema['form_id'] ) ) {
			$canonical_id = sanitize_key( $schema['form_id'] );
		}

		// Save under canonical ID
		$result = update_option( self::OPTION_PREFIX . $canonical_id, $theme, false );

		// Clean up alternate hyphen/underscore key to prevent split-brain
		$alt_id = ( strpos( $canonical_id, '_' ) !== false )
			? str_replace( '_', '-', $canonical_id )
			: str_replace( '-', '_', $canonical_id );
		if ( $alt_id !== $canonical_id ) {
			delete_option( self::OPTION_PREFIX . $alt_id );
		}

		return $result;
	}

	/**
	 * Delete theme overrides for a form across all variants.
	 *
	 * @param string $form_id Form identifier.
	 */
	public static function delete_theme( $form_id ) {
		$form_id  = sanitize_key( $form_id );
		$variants = array_unique( array(
			$form_id,
			str_replace( '-', '_', $form_id ),
			str_replace( '_', '-', $form_id ),
		) );

		foreach ( $variants as $variant ) {
			delete_option( self::OPTION_PREFIX . $variant );
		}
	}

	/**
	 * Sanitize theme data from user input.
	 *
	 * @param array $data Raw theme data.
	 * @return array Sanitized theme array.
	 */
	private function sanitize_theme_data( $data ) {
		$defaults  = self::get_defaults();
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			// Only allow known --sps-* variables
			if ( ! isset( $defaults[ $key ] ) ) {
				continue;
			}

			if ( strpos( $key, 'radius' ) !== false ) {
				// Radius values: integer + unit
				$num = intval( $value );
				$num = max( 0, min( 30, $num ) ); // Clamp 0-30
				$sanitized[ $key ] = $num . 'px';
			} else {
				// Color values
				$color = sanitize_hex_color( $value );
				if ( ! empty( $color ) ) {
					$sanitized[ $key ] = $color;
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Build an inline style string from a theme array.
	 *
	 * @param array $theme Theme variables.
	 * @return string CSS variable declarations.
	 */
	public static function build_inline_style( $theme ) {
		$parts = array();
		foreach ( $theme as $var_name => $var_value ) {
			if ( 0 === strpos( $var_name, '--sps-' ) ) {
				$clean_name = '--' . sanitize_key( ltrim( $var_name, '-' ) );
				$parts[] = $clean_name . ': ' . esc_attr( $var_value );
			}
		}
		return implode( '; ', $parts );
	}
}
