<?php
/**
 * Generic Form Container Template
 *
 * @package SmartPortalSuite
 * @var string $form_id Form identifier
 * @var array  $schema  Form schema data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="sps-form-<?php echo esc_attr( $form_id ); ?>" 
     class="sps-form-container" 
     data-sps-form-id="<?php echo esc_attr( $form_id ); ?>" 
     role="region" 
     aria-label="<?php echo esc_attr( isset( $schema['title'] ) ? $schema['title'] : 'Formular' ); ?>">

	<div class="sps-form-content">
		<!-- Dynamic content is mounted by SPS.FormEngine -->
		<noscript>
			<p class="sps-no-js-warning">
				<?php esc_html_e( 'Bitte aktivieren Sie JavaScript in Ihrem Browser, um dieses Formular zu nutzen.', 'smart-portal-suite' ); ?>
			</p>
		</noscript>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var jsDataVar = 'spsFormData_' + '<?php echo esc_js( str_replace( '-', '_', $form_id ) ); ?>';
    if (window[jsDataVar] && window.SPS && window.SPS.FormEngine) {
        var config = window[jsDataVar];
        config.containerId = 'sps-form-<?php echo esc_js( $form_id ); ?>';
        window.SPS.FormEngine.init(config);
    }
});
</script>
