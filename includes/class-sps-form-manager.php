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

		// Form Import / Export / Delete & Nextcloud Scaffolder
		add_action( 'wp_ajax_sps_import_form', array( $this, 'ajax_import_form' ) );
		add_action( 'admin_post_sps_export_form', array( $this, 'admin_post_export_form' ) );
		add_action( 'wp_ajax_sps_delete_form', array( $this, 'ajax_delete_form' ) );
		add_action( 'wp_ajax_sps_fetch_nc_forms', array( $this, 'ajax_fetch_nc_forms' ) );
		add_action( 'wp_ajax_sps_scaffold_nc_form', array( $this, 'ajax_scaffold_nc_form' ) );
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

		$css_ver = file_exists( SPS_PLUGIN_DIR . 'assets/css/admin-forms.css' ) ? filemtime( SPS_PLUGIN_DIR . 'assets/css/admin-forms.css' ) : SPS_VERSION;
		$js_ver  = file_exists( SPS_PLUGIN_DIR . 'assets/js/admin-forms.js' ) ? filemtime( SPS_PLUGIN_DIR . 'assets/js/admin-forms.js' ) : SPS_VERSION;

		// Admin Forms CSS
		wp_enqueue_style(
			'sps-admin-forms-css',
			SPS_PLUGIN_URL . 'assets/css/admin-forms.css',
			array( 'wp-color-picker' ),
			$css_ver
		);

		// Admin Forms JS
		wp_enqueue_script(
			'sps-admin-forms-js',
			SPS_PLUGIN_URL . 'assets/js/admin-forms.js',
			array( 'jquery', 'wp-color-picker' ),
			$js_ver,
			true
		);

		wp_localize_script( 'sps-admin-forms-js', 'spsFormsAdmin', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
			'presets'  => self::get_presets(),
			'defaults' => self::get_defaults(),
			'i18n'     => array(
				'saved'             => __( 'Styling erfolgreich gespeichert.', 'smart-portal-suite' ),
				'saveFailed'        => __( 'Fehler beim Speichern.', 'smart-portal-suite' ),
				'reset'             => __( 'Styling auf Standard zurückgesetzt.', 'smart-portal-suite' ),
				'resetConfirm'      => __( 'Möchtest du das Styling dieses Formulars wirklich auf den Standard zurücksetzen?', 'smart-portal-suite' ),
				'saving'            => __( 'Speichern...', 'smart-portal-suite' ),
				'save'              => __( 'Styling speichern', 'smart-portal-suite' ),
				'importSuccess'     => __( 'Formular erfolgreich importiert!', 'smart-portal-suite' ),
				'importFailed'      => __( 'Fehler beim Importieren des Formulars.', 'smart-portal-suite' ),
				'deleteConfirm'     => __( 'Möchtest du dieses Formular wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.', 'smart-portal-suite' ),
				'deleteFailed'      => __( 'Fehler beim Löschen des Formulars.', 'smart-portal-suite' ),
				'ncFetchFailed'     => __( 'Fehler beim Abrufen der Nextcloud-Formulare.', 'smart-portal-suite' ),
				'ncScaffoldSuccess' => __( 'Nextcloud-Formular erfolgreich importiert!', 'smart-portal-suite' ),
				'noFileOrJson'      => __( 'Bitte wähle eine Datei aus oder füge JSON-Inhalt ein.', 'smart-portal-suite' ),
				'importing'         => __( 'Wird importiert...', 'smart-portal-suite' ),
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
			<a href="#sps-import-modal" class="page-title-action sps-open-import-modal-btn">
				<span class="dashicons dashicons-upload" style="vertical-align: text-top; font-size: 16px; margin-right: 2px;"></span>
				<?php esc_html_e( 'Formular importieren', 'smart-portal-suite' ); ?>
			</a>
			<p class="sps-lead-text">
				<?php esc_html_e( 'Übersicht aller registrierten Formulare. Passe das Styling an, exportiere Schemata oder importiere neue Formulare per ZIP/JSON.', 'smart-portal-suite' ); ?>
			</p>
			<hr class="wp-header-end" />

			<table class="wp-list-table widefat fixed striped sps-forms-table">
				<thead>
					<tr>
						<th scope="col" class="column-title"><?php esc_html_e( 'Formular-Name', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-shortcode"><?php esc_html_e( 'Shortcode', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-steps"><?php esc_html_e( 'Schritte', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-source"><?php esc_html_e( 'Typ', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-status"><?php esc_html_e( 'Styling-Status', 'smart-portal-suite' ); ?></th>
						<th scope="col" class="column-actions"><?php esc_html_e( 'Aktionen', 'smart-portal-suite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $forms ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'Keine Formulare gefunden.', 'smart-portal-suite' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $forms as $form ) : ?>
							<?php
							$fid        = sanitize_key( $form['form_id'] );
							$source     = isset( $form['source'] ) ? $form['source'] : 'system';
							$has_custom = ! empty( self::get_theme( $fid ) );
							$edit_url   = admin_url( 'admin.php?page=sps-forms&form_id=' . $fid );
							$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=sps_export_form&form_id=' . urlencode( $fid ) ), 'sps_export_' . $fid );
							$shortcode  = '[sps_form id="' . esc_attr( $fid ) . '"]';
							?>
							<tr data-form-id="<?php echo esc_attr( $fid ); ?>">
								<td class="column-title">
									<strong><?php echo esc_html( $form['title'] ); ?></strong>
								</td>
								<td class="column-shortcode">
									<code class="sps-shortcode-copy" title="<?php esc_attr_e( 'Klicken zum Kopieren', 'smart-portal-suite' ); ?>"><?php echo esc_html( $shortcode ); ?></code>
								</td>
								<td class="column-steps">
									<?php echo intval( $form['steps_count'] ); ?>
								</td>
								<td class="column-source">
									<?php if ( 'custom' === $source ) : ?>
										<span class="sps-badge sps-badge-imported" title="<?php esc_attr_e( 'Benutzerdefiniert in wp-content/uploads', 'smart-portal-suite' ); ?>">
											<span class="dashicons dashicons-upload" style="font-size: 13px; vertical-align: text-top;"></span>
											<?php esc_html_e( 'Importiert', 'smart-portal-suite' ); ?>
										</span>
									<?php else : ?>
										<span class="sps-badge sps-badge-system" title="<?php esc_attr_e( 'Im Plugin-Verzeichnis gebündelt', 'smart-portal-suite' ); ?>">
											<span class="dashicons dashicons-lock" style="font-size: 13px; vertical-align: text-top;"></span>
											<?php esc_html_e( 'System', 'smart-portal-suite' ); ?>
										</span>
									<?php endif; ?>
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
									<div class="sps-actions-group">
										<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary" title="<?php esc_attr_e( 'Styling bearbeiten', 'smart-portal-suite' ); ?>">
											<span class="dashicons dashicons-admin-appearance" style="vertical-align: text-top; font-size: 16px;"></span>
											<?php esc_html_e( 'Styling', 'smart-portal-suite' ); ?>
										</a>
										<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary sps-export-btn" title="<?php esc_attr_e( 'Formular herunterladen (JSON/ZIP mit SVG)', 'smart-portal-suite' ); ?>">
											<span class="dashicons dashicons-download" style="vertical-align: text-top; font-size: 16px;"></span>
											<?php esc_html_e( 'Export', 'smart-portal-suite' ); ?>
										</a>
										<?php if ( 'custom' === $source ) : ?>
											<button type="button" class="button button-link-delete sps-delete-form-btn" data-form-id="<?php echo esc_attr( $fid ); ?>" data-form-title="<?php echo esc_attr( $form['title'] ); ?>" title="<?php esc_attr_e( 'Formular löschen', 'smart-portal-suite' ); ?>">
												<span class="dashicons dashicons-trash" style="vertical-align: text-top; font-size: 16px;"></span>
											</button>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Import Modal Dialog -->
			<div id="sps-import-modal" class="sps-modal-overlay">
				<div class="sps-modal-dialog">
					<div class="sps-modal-header">
						<h2>
							<span class="dashicons dashicons-upload" style="font-size: 24px; vertical-align: middle; margin-right: 6px;"></span>
							<?php esc_html_e( 'Formular importieren', 'smart-portal-suite' ); ?>
						</h2>
						<button type="button" class="sps-modal-close" title="<?php esc_attr_e( 'Schließen', 'smart-portal-suite' ); ?>">&times;</button>
					</div>

					<div class="sps-modal-tabs">
						<button type="button" class="sps-tab-btn active" data-tab="upload">
							<span class="dashicons dashicons-media-archive"></span>
							<?php esc_html_e( 'ZIP- oder JSON-Datei', 'smart-portal-suite' ); ?>
						</button>
						<button type="button" class="sps-tab-btn" data-tab="paste">
							<span class="dashicons dashicons-editor-code"></span>
							<?php esc_html_e( 'JSON einfügen', 'smart-portal-suite' ); ?>
						</button>
						<button type="button" class="sps-tab-btn" data-tab="nextcloud">
							<span class="dashicons dashicons-cloud"></span>
							<?php esc_html_e( 'Aus Nextcloud importieren', 'smart-portal-suite' ); ?>
						</button>
					</div>

					<div class="sps-modal-body">
						<!-- Tab: File Upload -->
						<div class="sps-tab-pane active" id="sps-tab-upload">
							<div class="sps-dropzone" id="sps-file-dropzone">
								<input type="file" id="sps-import-file-input" accept=".json,.zip" style="display:none;" />
								<span class="dashicons dashicons-cloud-upload" style="font-size: 48px; height: 48px; width: 48px; color: #2271b1; margin-bottom: 8px;"></span>
								<p class="sps-dropzone-prompt">
									<strong><?php esc_html_e( 'Datei hier ablegen', 'smart-portal-suite' ); ?></strong>
									<?php esc_html_e( 'oder', 'smart-portal-suite' ); ?>
									<button type="button" class="button" id="sps-browse-file-btn"><?php esc_html_e( 'Datei auswählen', 'smart-portal-suite' ); ?></button>
								</p>
								<p class="sps-dropzone-hint">
									<?php esc_html_e( 'Unterstützt .zip (inkl. SVG-Sprite) oder einzelne .json Schema-Dateien.', 'smart-portal-suite' ); ?>
								</p>
								<div class="sps-selected-file-info" style="display:none;">
									<span class="dashicons dashicons-media-default"></span>
									<span class="sps-filename"></span>
									<button type="button" class="sps-remove-file" title="<?php esc_attr_e( 'Entfernen', 'smart-portal-suite' ); ?>">&times;</button>
								</div>
							</div>
						</div>

						<!-- Tab: JSON Paste -->
						<div class="sps-tab-pane" id="sps-tab-paste" style="display:none;">
							<label for="sps-json-paste-input"><strong><?php esc_html_e( 'JSON-Formular-Schema:', 'smart-portal-suite' ); ?></strong></label>
							<textarea id="sps-json-paste-input" class="large-text code" rows="10" placeholder="<?php esc_attr_e( '{\n  "form_id": "mein_formular",\n  "title": "Mein Formular",\n  "steps": [...]\n}', 'smart-portal-suite' ); ?>"></textarea>
						</div>

						<!-- Tab: Nextcloud Scaffold -->
						<div class="sps-tab-pane" id="sps-tab-nextcloud" style="display:none;">
							<p class="description">
								<?php esc_html_e( 'Ruft vorhandene Formulare aus der verbundenen Nextcloud Forms API ab und erstellt automatisch ein passendes SPS-Formular-Grundgerüst.', 'smart-portal-suite' ); ?>
							</p>
							<div class="sps-nc-fetch-row" style="margin-top: 12px; display: flex; align-items: center; gap: 10px;">
								<button type="button" class="button" id="sps-fetch-nc-forms-btn">
									<span class="dashicons dashicons-update" style="vertical-align: text-top; font-size: 16px;"></span>
									<?php esc_html_e( 'Nextcloud-Formulare abrufen', 'smart-portal-suite' ); ?>
								</button>
								<span class="spinner" id="sps-nc-spinner" style="float:none; margin:0;"></span>
							</div>
							<div id="sps-nc-forms-select-wrap" style="margin-top: 14px; display:none;">
								<label for="sps-nc-form-select"><strong><?php esc_html_e( 'Nextcloud-Formular auswählen:', 'smart-portal-suite' ); ?></strong></label>
								<select id="sps-nc-form-select" style="width: 100%; margin-top: 6px;"></select>
							</div>
						</div>

						<!-- Options -->
						<div class="sps-import-options">
							<label>
								<input type="checkbox" id="sps-import-overwrite" value="1" />
								<?php esc_html_e( 'Bestehendes Formular bei gleicher ID überschreiben', 'smart-portal-suite' ); ?>
							</label>
						</div>

						<div class="sps-import-status" style="margin-top: 12px; display:none;"></div>
					</div>

					<div class="sps-modal-footer">
						<button type="button" class="button button-primary button-hero" id="sps-execute-import-btn">
							<span class="dashicons dashicons-upload" style="vertical-align: text-top; font-size: 18px; margin-right: 4px;"></span>
							<?php esc_html_e( 'Importieren', 'smart-portal-suite' ); ?>
						</button>
						<button type="button" class="button button-secondary sps-modal-close-btn"><?php esc_html_e( 'Abbrechen', 'smart-portal-suite' ); ?></button>
					</div>
				</div>
			</div>
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
	   FORM IMPORT & EXPORT HANDLERS
	   ═══════════════════════════════════════════════════════════════ */

	/**
	 * AJAX: Import form from uploaded ZIP/JSON or pasted JSON text.
	 */
	public function ajax_import_form() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) ), 403 );
		}

		$overwrite    = ! empty( $_POST['overwrite'] ) && '1' === $_POST['overwrite'];
		$json_content = '';
		$svg_content  = '';

		// 1. Check for file upload (ZIP or JSON)
		if ( ! empty( $_FILES['import_file']['tmp_name'] ) ) {
			$file_name = sanitize_file_name( $_FILES['import_file']['name'] );
			$tmp_path  = $_FILES['import_file']['tmp_name'];
			$ext       = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

			if ( 'json' === $ext ) {
				$json_content = file_get_contents( $tmp_path );
			} elseif ( 'zip' === $ext ) {
				if ( ! class_exists( 'ZipArchive' ) ) {
					wp_send_json_error( array( 'message' => __( 'PHP ZipArchive-Erweiterung ist auf diesem Server nicht verfügbar.', 'smart-portal-suite' ) ), 500 );
				}

				$zip = new ZipArchive();
				if ( true !== $zip->open( $tmp_path ) ) {
					wp_send_json_error( array( 'message' => __( 'ZIP-Datei konnte nicht geöffnet werden.', 'smart-portal-suite' ) ), 400 );
				}

				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$stat       = $zip->statIndex( $i );
					$entry_name = basename( $stat['name'] );
					$entry_ext  = strtolower( pathinfo( $entry_name, PATHINFO_EXTENSION ) );

					// Skip macOS metadata and dotfiles
					if ( 0 === strpos( $entry_name, '.' ) || 0 === strpos( $stat['name'], '__MACOSX' ) ) {
						continue;
					}

					if ( 'json' === $entry_ext && empty( $json_content ) ) {
						$json_content = $zip->getFromIndex( $i );
					} elseif ( 'svg' === $entry_ext && empty( $svg_content ) ) {
						$svg_content = $zip->getFromIndex( $i );
					}
				}
				$zip->close();

				if ( empty( $json_content ) ) {
					wp_send_json_error( array( 'message' => __( 'Das ZIP-Archiv enthält keine gültige .json-Datei.', 'smart-portal-suite' ) ), 400 );
				}
			} else {
				wp_send_json_error( array( 'message' => __( 'Ungültiges Dateiformat. Bitte lade eine .json- oder .zip-Datei hoch.', 'smart-portal-suite' ) ), 400 );
			}
		} elseif ( ! empty( $_POST['json_content'] ) ) {
			// 2. Check for pasted JSON text
			$json_content = wp_unslash( $_POST['json_content'] );
		} else {
			wp_send_json_error( array( 'message' => __( 'Keine Datei oder JSON-Inhalte übermittelt.', 'smart-portal-suite' ) ), 400 );
		}

		// 3. Decode & Validate JSON schema
		$schema = json_decode( $json_content, true );
		if ( ! is_array( $schema ) || json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Ungültiges JSON-Format: %s', 'smart-portal-suite' ), json_last_error_msg() ) ), 400 );
		}

		if ( empty( $schema['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Fehler: Dem Formular-Schema fehlt der Titel ("title").', 'smart-portal-suite' ) ), 400 );
		}

		if ( empty( $schema['steps'] ) || ! is_array( $schema['steps'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Fehler: Dem Formular-Schema fehlt das "steps"-Array oder es enthält keine Schritte.', 'smart-portal-suite' ) ), 400 );
		}

		// Determine and sanitize form_id
		$form_id = ! empty( $schema['form_id'] ) ? sanitize_key( $schema['form_id'] ) : '';
		if ( empty( $form_id ) ) {
			$form_id = sanitize_key( sanitize_title( $schema['title'] ) );
		}
		if ( empty( $form_id ) ) {
			$form_id = 'custom_form_' . time();
		}
		$schema['form_id'] = $form_id;

		// Check collision with existing forms
		$renderer        = SPS_Form_Renderer::get_instance();
		$available_forms = $renderer->get_available_forms();
		if ( isset( $available_forms[ $form_id ] ) && ! $overwrite ) {
			wp_send_json_error( array(
				'code'    => 'form_exists',
				'form_id' => $form_id,
				'message' => sprintf( __( 'Ein Formular mit der ID "%s" existiert bereits. Aktiviere "Bestehendes Formular überschreiben", um fortzufahren.', 'smart-portal-suite' ), $form_id ),
			), 409 );
		}

		// 4. Save JSON file to wp-content/uploads/smart-portal-suite/forms/
		$custom_forms_dir = SPS_Form_Renderer::get_custom_forms_dir();
		$target_json_file = $custom_forms_dir . $form_id . '.json';
		$json_output      = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$saved = @file_put_contents( $target_json_file, $json_output );
		if ( false === $saved ) {
			// Fallback to wp_options if filesystem is write-protected
			update_option( 'sps_custom_form_' . $form_id, $schema, false );
		}

		// 5. Save SVG sprite if provided in ZIP
		if ( ! empty( $svg_content ) ) {
			$sanitized_svg = $this->sanitize_svg_content( $svg_content );
			if ( ! empty( $sanitized_svg ) ) {
				$custom_icons_dir = SPS_Form_Renderer::get_custom_icons_dir();
				$target_svg_file  = $custom_icons_dir . $form_id . '.svg';
				@file_put_contents( $target_svg_file, $sanitized_svg );
			}
		}

		wp_send_json_success( array(
			'message' => sprintf( __( 'Formular "%s" (ID: %s) erfolgreich importiert!', 'smart-portal-suite' ), esc_html( $schema['title'] ), esc_html( $form_id ) ),
			'form_id' => $form_id,
		) );
	}

	/**
	 * Admin Post: Export form as standalone JSON or ZIP (with custom SVG).
	 */
	public function admin_post_export_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) );
		}

		$form_id = isset( $_GET['form_id'] ) ? sanitize_key( $_GET['form_id'] ) : '';
		if ( empty( $form_id ) ) {
			wp_die( esc_html__( 'Formular-ID fehlt.', 'smart-portal-suite' ) );
		}

		check_admin_referer( 'sps_export_' . $form_id );

		$renderer = SPS_Form_Renderer::get_instance();
		$schema   = $renderer->load_schema( $form_id );

		if ( empty( $schema ) ) {
			wp_die( esc_html__( 'Formular nicht gefunden.', 'smart-portal-suite' ) );
		}

		// Look for matching SVG sprite
		$svg_file         = '';
		$custom_icons_dir = SPS_Form_Renderer::get_custom_icons_dir();
		$variants         = array_unique( array( $form_id, str_replace( '-', '_', $form_id ), str_replace( '_', '-', $form_id ) ) );

		foreach ( $variants as $v ) {
			if ( file_exists( $custom_icons_dir . $v . '.svg' ) ) {
				$svg_file = $custom_icons_dir . $v . '.svg';
				break;
			}
			if ( file_exists( SPS_PLUGIN_DIR . 'assets/icons/' . $v . '.svg' ) ) {
				$svg_file = SPS_PLUGIN_DIR . 'assets/icons/' . $v . '.svg';
				break;
			}
		}

		$json_output = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// If a matching SVG exists, export as ZIP package
		if ( ! empty( $svg_file ) && file_exists( $svg_file ) && class_exists( 'ZipArchive' ) ) {
			$temp_zip = wp_tempnam( 'sps_' . $form_id . '_' );
			$zip      = new ZipArchive();
			if ( true === $zip->open( $temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				$zip->addFromString( $form_id . '.json', $json_output );
				$zip->addFile( $svg_file, $form_id . '.svg' );
				$zip->close();

				header( 'Content-Type: application/zip' );
				header( 'Content-Disposition: attachment; filename="' . $form_id . '.zip"' );
				header( 'Content-Length: ' . filesize( $temp_zip ) );
				header( 'Pragma: no-cache' );
				header( 'Expires: 0' );
				readfile( $temp_zip );
				@unlink( $temp_zip );
				exit;
			}
		}

		// Standalone JSON download
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $form_id . '.json"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		echo $json_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * AJAX: Delete a custom/imported form.
	 */
	public function ajax_delete_form() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';
		if ( empty( $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Formular-ID fehlt.', 'smart-portal-suite' ) ), 400 );
		}

		$renderer = SPS_Form_Renderer::get_instance();
		$forms    = $renderer->get_available_forms();

		if ( isset( $forms[ $form_id ] ) && 'system' === $forms[ $form_id ]['source'] ) {
			wp_send_json_error( array( 'message' => __( 'System-Formulare sind schreibgeschützt und können nicht gelöscht werden.', 'smart-portal-suite' ) ), 400 );
		}

		$custom_dir       = SPS_Form_Renderer::get_custom_forms_dir();
		$custom_icons_dir = SPS_Form_Renderer::get_custom_icons_dir();
		$variants         = array_unique( array( $form_id, str_replace( '-', '_', $form_id ), str_replace( '_', '-', $form_id ) ) );

		foreach ( $variants as $v ) {
			$json_file = $custom_dir . $v . '.json';
			if ( file_exists( $json_file ) ) {
				@unlink( $json_file );
			}
			$svg_file = $custom_icons_dir . $v . '.svg';
			if ( file_exists( $svg_file ) ) {
				@unlink( $svg_file );
			}
			delete_option( 'sps_custom_form_' . $v );
		}

		self::delete_theme( $form_id );

		wp_send_json_success( array(
			'message' => __( 'Formular erfolgreich gelöscht.', 'smart-portal-suite' ),
			'form_id' => $form_id,
		) );
	}

	/**
	 * AJAX: Fetch available forms from connected Nextcloud instance.
	 */
	public function ajax_fetch_nc_forms() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) ), 403 );
		}

		$account = SPS_Settings::get_setting( 'service_account' );
		$pass    = SPS_Settings::get_setting( 'app_password' );

		if ( empty( $account ) || empty( $pass ) ) {
			wp_send_json_error( array( 'message' => __( 'Nextcloud Service-Account oder App-Passwort nicht in den Einstellungen hinterlegt.', 'smart-portal-suite' ) ), 400 );
		}

		$client   = new SPS_NC_Client( '', null, $account, $pass );
		$response = $client->request( 'ocs/v2.php/apps/forms/api/v3/forms', 'GET' );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 500 );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 401 === $status_code ) {
			wp_send_json_error( array( 'message' => __( 'Nextcloud-Authentifizierung fehlgeschlagen (HTTP 401). Bitte prüfe Service-Account und App-Passwort in den Einstellungen.', 'smart-portal-suite' ) ), 401 );
		} elseif ( 404 === $status_code ) {
			wp_send_json_error( array( 'message' => __( 'Nextcloud Forms-App nicht gefunden oder deaktiviert (HTTP 404).', 'smart-portal-suite' ) ), 404 );
		} elseif ( $status_code >= 400 ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Nextcloud Forms API meldet Fehler (HTTP %d).', 'smart-portal-suite' ), $status_code ) ), (int) $status_code );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$forms = array();

		$raw_forms = array();
		if ( isset( $body['ocs']['data']['forms'] ) && is_array( $body['ocs']['data']['forms'] ) ) {
			$raw_forms = $body['ocs']['data']['forms'];
		} elseif ( isset( $body['ocs']['data'] ) && is_array( $body['ocs']['data'] ) ) {
			$raw_forms = $body['ocs']['data'];
		} elseif ( is_array( $body ) && ! isset( $body['ocs'] ) ) {
			$raw_forms = $body;
		}

		foreach ( $raw_forms as $nc_f ) {
			if ( is_array( $nc_f ) && isset( $nc_f['id'] ) ) {
				$forms[] = array(
					'id'    => (int) $nc_f['id'],
					'title' => ! empty( $nc_f['title'] ) ? $nc_f['title'] : sprintf( __( 'Formular #%d', 'smart-portal-suite' ), (int) $nc_f['id'] ),
				);
			}
		}

		if ( empty( $forms ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Formulare in Nextcloud gefunden. Bitte erstelle zuerst ein Formular in Nextcloud Forms.', 'smart-portal-suite' ) ), 404 );
		}

		wp_send_json_success( array( 'forms' => $forms ) );
	}

	/**
	 * AJAX: Generate an SPS schema scaffold from Nextcloud form questions.
	 */
	public function ajax_scaffold_nc_form() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unzureichende Berechtigungen.', 'smart-portal-suite' ) ), 403 );
		}

		$nc_form_id = isset( $_POST['nc_form_id'] ) ? intval( $_POST['nc_form_id'] ) : 0;
		$nc_title   = isset( $_POST['nc_title'] ) ? sanitize_text_field( wp_unslash( $_POST['nc_title'] ) ) : '';
		$overwrite  = ! empty( $_POST['overwrite'] ) && '1' === $_POST['overwrite'];

		if ( empty( $nc_form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Ungültige Nextcloud-Formular-ID.', 'smart-portal-suite' ) ), 400 );
		}

		$nc_forms  = new SPS_NC_Forms();
		$questions = $nc_forms->get_questions( $nc_form_id );

		if ( is_wp_error( $questions ) || empty( $questions ) ) {
			wp_send_json_error( array( 'message' => __( 'Konnte Fragen für das Nextcloud-Formular nicht abrufen.', 'smart-portal-suite' ) ), 500 );
		}

		$clean_title = ! empty( $nc_title ) ? $nc_title : ( 'Nextcloud Form ' . $nc_form_id );
		$form_id     = sanitize_key( sanitize_title( $clean_title ) );

		if ( empty( $form_id ) ) {
			$form_id = 'nc_form_' . $nc_form_id;
		}

		$renderer = SPS_Form_Renderer::get_instance();
		$avail    = $renderer->get_available_forms();
		if ( isset( $avail[ $form_id ] ) && ! $overwrite ) {
			$form_id .= '_nc';
		}

		// Build steps mapping from Nextcloud questions
		$steps = array();
		foreach ( $questions as $q ) {
			$q_text  = ! empty( $q['text'] ) ? $q['text'] : ( isset( $q['name'] ) && ! empty( $q['name'] ) ? $q['name'] : sprintf( __( 'Frage %d', 'smart-portal-suite' ), $q['id'] ) );
			$q_type  = strtolower( isset( $q['type'] ) ? $q['type'] : 'short' );
			$step_id = sanitize_key( isset( $q['name'] ) && ! empty( $q['name'] ) ? $q['name'] : ( 'q_' . $q['id'] ) );

			$step = array(
				'id'       => $step_id,
				'label'    => $q_text,
				'required' => ! empty( $q['isRequired'] ),
			);

			if ( 'long' === $q_type ) {
				$step['type']     = 'textarea';
				$step['dataType'] = 'string';
			} elseif ( in_array( $q_type, array( 'choose', 'dropdown', 'multiple_unique', 'radio' ), true ) ) {
				$step['type']     = 'radio';
				$step['dataType'] = 'string';
				$step['choices']  = array();
				if ( ! empty( $q['options'] ) ) {
					foreach ( $q['options'] as $opt ) {
						$step['choices'][] = array(
							'text'  => $opt['text'],
							'value' => $opt['text'],
							'icon'  => 'icon-checkmark',
						);
					}
				}
			} elseif ( 'multiple' === $q_type ) {
				$step['type']     = 'checkbox-multi';
				$step['dataType'] = 'array';
				$step['choices']  = array();
				if ( ! empty( $q['options'] ) ) {
					foreach ( $q['options'] as $opt ) {
						$step['choices'][] = array(
							'id'   => sanitize_key( $opt['text'] ),
							'text' => $opt['text'],
							'icon' => 'icon-checkmark',
						);
					}
				}
			} elseif ( 'date' === $q_type || 'datetime' === $q_type ) {
				$step['type']     = 'date';
				$step['dataType'] = 'string';
			} elseif ( 'file' === $q_type ) {
				$step['type']     = 'upload';
				$step['dataType'] = 'array';
			} elseif ( 'linearscale' === $q_type ) {
				$step['type']     = 'radio';
				$step['dataType'] = 'string';
				$step['choices']  = array();
				for ( $i = 1; $i <= 5; $i++ ) {
					$step['choices'][] = array(
						'text'  => (string) $i,
						'value' => (string) $i,
					);
				}
			} else {
				$lower_text = strtolower( $q_text );
				$val_type   = isset( $q['extraSettings']['validationType'] ) ? $q['extraSettings']['validationType'] : '';
				if ( 'number' === $val_type ) {
					$step['type']     = 'number';
					$step['dataType'] = 'number';
				} elseif ( strpos( $lower_text, 'mail' ) !== false ) {
					$step['type']     = 'email';
					$step['dataType'] = 'string';
				} elseif ( strpos( $lower_text, 'telefon' ) !== false || strpos( $lower_text, 'phone' ) !== false || strpos( $lower_text, 'mobil' ) !== false ) {
					$step['type']     = 'tel';
					$step['dataType'] = 'string';
				} else {
					$step['type']     = 'text';
					$step['dataType'] = 'string';
				}
			}

			$steps[] = $step;
		}

		// Add summary step
		$steps[] = array(
			'id'       => 'summary',
			'type'     => 'summary',
			'dataType' => 'summary',
			'label'    => __( 'Ihre Angaben im Überblick', 'smart-portal-suite' ),
		);

		// Add consent step
		$steps[] = array(
			'id'       => 'consent',
			'type'     => 'consent',
			'dataType' => 'bool',
			'required' => true,
			'label'    => __( 'Ich habe die Datenschutzerklärung zur Kenntnis genommen und stimme der Verarbeitung meiner Daten zu.', 'smart-portal-suite' ),
		);

		$schema = array(
			'form_id'       => $form_id,
			'form_type'     => $clean_title,
			'title'         => $clean_title,
			'nc_form_title' => $clean_title,
			'nc_form_id'    => $nc_form_id,
			'steps'         => $steps,
		);

		$custom_forms_dir = SPS_Form_Renderer::get_custom_forms_dir();
		$target_json_file = $custom_forms_dir . $form_id . '.json';
		$json_output      = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		@file_put_contents( $target_json_file, $json_output );
		update_option( 'sps_custom_form_' . $form_id, $schema, false );

		wp_send_json_success( array(
			'message' => sprintf( __( 'Nextcloud-Formular "%s" erfolgreich importiert!', 'smart-portal-suite' ), $clean_title ),
			'form_id' => $form_id,
		) );
	}

	/**
	 * Sanitize raw SVG content against malicious scripts.
	 *
	 * @param string $svg Raw SVG code.
	 * @return string Sanitized SVG or empty string on severe error.
	 */
	private function sanitize_svg_content( $svg ) {
		if ( empty( $svg ) || false === strpos( $svg, '<svg' ) ) {
			return '';
		}

		// Strip script tags
		$clean = preg_replace( '#<script(.*?)>(.*?)</script>#is', '', $svg );
		// Strip inline on* attributes (onload, onclick, etc.)
		$clean = preg_replace( '#\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)#i', '', $clean );
		// Strip javascript: hrefs
		$clean = preg_replace( '#href\s*=\s*["\']javascript:.*?["\']#i', '', $clean );

		return trim( $clean );
	}

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
