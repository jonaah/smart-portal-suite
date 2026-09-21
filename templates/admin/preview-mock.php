<?php
/**
 * Preview Mock Template for Admin Styling Editor
 *
 * Renders a minimal form mock that demonstrates the effect of
 * CSS variable overrides. Used inline in the styling editor and
 * served via AJAX for reset operations.
 *
 * @package SmartPortalSuite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="sps-form-wrapper">
	<!-- Progress Bar Mock -->
	<div class="sps-progress-bar">
		<div class="sps-progress-fill" style="width: 60%;"></div>
	</div>
	<div class="sps-progress-label" style="text-align: center; padding: 8px 0; color: var(--sps-text-muted); font-size: 13px;">
		<?php esc_html_e( 'Schritt 3 von 5', 'smart-portal-suite' ); ?>
	</div>

	<!-- Step Title Mock -->
	<div class="sps-step-header" style="text-align: center; padding: 16px 20px 8px;">
		<h2 class="sps-step-title" style="color: var(--sps-text-main); font-size: 20px; margin: 0 0 6px;">
			<?php esc_html_e( 'Welchen Gebäudetyp haben Sie?', 'smart-portal-suite' ); ?>
		</h2>
		<p class="sps-step-subtitle" style="color: var(--sps-text-muted); font-size: 14px; margin: 0;">
			<?php esc_html_e( 'Bitte wählen Sie eine der folgenden Optionen.', 'smart-portal-suite' ); ?>
		</p>
	</div>

	<!-- Radio Cards Mock -->
	<div class="sps-options-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px 20px;">
		<!-- Selected Card -->
		<div class="sps-radio-card selected" style="background: var(--sps-card-bg); border: 2px solid var(--sps-accent-green); border-radius: var(--sps-radius-card); padding: 18px 14px; text-align: center; cursor: pointer; position: relative; transition: all 0.2s ease;">
			<div style="font-size: 28px; margin-bottom: 8px;">🏠</div>
			<div style="color: var(--sps-text-main); font-weight: 600; font-size: 14px;">
				<?php esc_html_e( 'Einfamilienhaus', 'smart-portal-suite' ); ?>
			</div>
			<div class="sps-check-indicator" style="position: absolute; top: 8px; right: 8px; width: 20px; height: 20px; background: var(--sps-accent-green); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
			</div>
		</div>

		<!-- Unselected Card -->
		<div class="sps-radio-card" style="background: var(--sps-card-bg); border: 2px solid var(--sps-border-color, rgba(255,255,255,0.08)); border-radius: var(--sps-radius-card); padding: 18px 14px; text-align: center; cursor: pointer; transition: all 0.2s ease;">
			<div style="font-size: 28px; margin-bottom: 8px;">🏢</div>
			<div style="color: var(--sps-text-main); font-weight: 600; font-size: 14px;">
				<?php esc_html_e( 'Mehrfamilienhaus', 'smart-portal-suite' ); ?>
			</div>
		</div>
	</div>

	<!-- Navigation Buttons Mock -->
	<div class="sps-nav-buttons" style="display: flex; justify-content: space-between; padding: 12px 20px 20px; gap: 12px;">
		<button type="button" class="sps-btn sps-btn-back" style="background: transparent; border: 1px solid var(--sps-border-color, rgba(255,255,255,0.15)); color: var(--sps-text-muted); padding: 12px 28px; border-radius: var(--sps-radius-btn); font-size: 15px; cursor: pointer;">
			<?php esc_html_e( 'Zurück', 'smart-portal-suite' ); ?>
		</button>
		<button type="button" class="sps-btn sps-btn-next" style="background: var(--sps-accent); color: #fff; border: none; padding: 12px 36px; border-radius: var(--sps-radius-btn); font-size: 15px; font-weight: 600; cursor: pointer;">
			<?php esc_html_e( 'Weiter', 'smart-portal-suite' ); ?>
		</button>
	</div>
</div>
