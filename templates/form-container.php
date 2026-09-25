<?php
/**
 * Generic Form Container Template
 *
 * @package SmartPortalSuite
 * @var string $form_id     Form identifier
 * @var array  $schema      Form schema data
 * @var array  $form_config Form configuration array
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$unique_container_id = wp_unique_id( 'sps-form-' . sanitize_key( $form_id ) . '-' );
$form_title = isset( $schema['title'] ) ? $schema['title'] : __( 'Formular', 'smart-portal-suite' );
$ajax_url   = isset( $form_config['ajaxUrl'] ) ? $form_config['ajaxUrl'] : admin_url( 'admin-ajax.php' );
$nonce      = isset( $form_config['nonce'] ) ? $form_config['nonce'] : wp_create_nonce( SPS_Ajax_Handler::NONCE_ACTION );

// Extract custom CSS variables if defined in schema or customized in admin
$container_styles = array();
if ( ! empty( $schema['styles'] ) && is_array( $schema['styles'] ) ) {
	foreach ( $schema['styles'] as $var_key => $var_val ) {
		if ( 0 === strpos( $var_key, '--sps-' ) ) {
			$container_styles[] = sanitize_key( $var_key ) . ': ' . esc_attr( $var_val );
		}
	}
}
$inline_style_str = implode( '; ', $container_styles );
$custom_styles    = apply_filters( 'sps_form_container_styles', $inline_style_str, $form_id, $schema );
?>

<div id="<?php echo esc_attr( $unique_container_id ); ?>" 
     class="sps-form-container" 
     <?php if ( ! empty( $custom_styles ) ) : ?>style="<?php echo esc_attr( $custom_styles ); ?>"<?php endif; ?>
     data-sps-form-id="<?php echo esc_attr( $form_id ); ?>"
     data-sps-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
     data-sps-nonce="<?php echo esc_attr( $nonce ); ?>"
     role="region" 
     aria-label="<?php echo esc_attr( $form_title ); ?>">

	<!-- Embedded Form Schema (No race conditions with wp_localize_script) -->
	<script type="application/json" class="sps-schema-data">
		<?php echo wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ); ?>
	</script>

	<!-- Anti-Bot Honeypot field (hidden from screen readers and real users) -->
	<div class="sps-hp-wrapper" style="display:none !important; position:absolute !important; left:-9999px !important;" aria-hidden="true">
		<label for="<?php echo esc_attr( $unique_container_id ); ?>-hp">Leave this field blank</label>
		<input type="text" id="<?php echo esc_attr( $unique_container_id ); ?>-hp" name="sps_hp" value="" tabindex="-1" autocomplete="off">
	</div>

	<!-- Dynamic Mounting Target -->
	<div class="sps-form-mount">
		<!-- Initial loading state placeholder until JS mounts -->
		<div class="sps-loading-skeleton" aria-hidden="true">
			<div class="sps-skeleton-progress"></div>
			<div class="sps-skeleton-title"></div>
			<div class="sps-skeleton-body"></div>
		</div>

		<noscript>
			<div class="sps-no-js-warning">
				<p><?php esc_html_e( 'Bitte aktivieren Sie JavaScript in Ihrem Browser, um dieses Formular zu nutzen.', 'smart-portal-suite' ); ?></p>
			</div>
		</noscript>
	</div>
</div>

<script>
(function() {
    function mountThisForm() {
        var el = document.getElementById('<?php echo esc_js( $unique_container_id ); ?>');
        if (el && window.SPS && typeof window.SPS.mount === 'function') {
            window.SPS.mount(el);
        }
    }
    if (window.SPS && typeof window.SPS.mount === 'function') {
        mountThisForm();
    } else {
        document.addEventListener('DOMContentLoaded', mountThisForm);
        window.addEventListener('load', mountThisForm);
    }
})();
</script>
