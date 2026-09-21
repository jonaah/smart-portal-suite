/**
 * Smart Portal Suite – Admin Forms Styling Editor
 *
 * Handles the per-form styling customizer UI:
 *  - wp-color-picker initialization with live preview callbacks
 *  - Range slider bindings for radius values
 *  - Preset quick-apply buttons
 *  - AJAX save/reset operations
 *
 * @package SmartPortalSuite
 */

(function($) {
    'use strict';

    var config   = window.spsFormsAdmin || {};
    var $grid    = null;
    var formId   = '';

    /**
     * Initialize the styling editor when the DOM is ready.
     */
    $(document).ready(function() {
        $grid  = $('.sps-styling-grid');
        formId = $grid.data('form-id') || '';

        if ($grid.length && formId) {
            initColorPickers();
            initRadiusSliders();
            initPresetButtons();
            initSaveButton();
        }

        // Shortcode copy-to-clipboard
        initShortcodeCopy();
    });

    /* ═══════════════════════════════════════════════════════════════
       COLOR PICKERS
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Initialize all wp-color-picker fields with a live preview callback.
     */
    function initColorPickers() {
        $grid.find('.sps-color-picker').each(function() {
            var $input = $(this);
            var varName = $input.attr('name');

            $input.wpColorPicker({
                change: function(event, ui) {
                    var color = ui.color.toString();
                    updatePreview(varName, color);
                },
                clear: function() {
                    var defaultColor = $input.data('default-color');
                    updatePreview(varName, defaultColor);
                }
            });
        });
    }

    /* ═══════════════════════════════════════════════════════════════
       RADIUS SLIDERS
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Bind range inputs for radius CSS variables.
     */
    function initRadiusSliders() {
        $grid.find('.sps-radius-slider').on('input change', function() {
            var $slider = $(this);
            var varName = $slider.attr('name');
            var value   = $slider.val();
            var unit    = $slider.data('unit') || 'px';

            // Update displayed value label
            $slider.siblings('.sps-radius-value').text(value + unit);

            // Update preview
            updatePreview(varName, value + unit);
        });
    }

    /* ═══════════════════════════════════════════════════════════════
       PRESET BUTTONS
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Wire up quick-theme preset buttons.
     */
    function initPresetButtons() {
        $grid.find('.sps-preset-btn').on('click', function() {
            var presetKey = $(this).data('preset');

            if (presetKey === 'reset') {
                handleReset();
                return;
            }

            var presets = config.presets || {};
            if (!presets[presetKey]) return;

            var values = presets[presetKey].values;
            applyValues(values);

            // Visual feedback
            $grid.find('.sps-preset-btn').removeClass('active');
            $(this).addClass('active');
        });
    }

    /**
     * Apply a set of CSS variable values to all form controls and the preview.
     *
     * @param {Object} values Key-value map of CSS variables.
     */
    function applyValues(values) {
        $.each(values, function(varName, varValue) {
            if (varName.indexOf('radius') !== -1) {
                // Radius: set slider value
                var numVal = parseInt(varValue, 10) || 0;
                var $slider = $grid.find('.sps-radius-slider[name="' + varName + '"]');
                $slider.val(numVal).trigger('input');
            } else {
                // Color: set wp-color-picker value
                var $picker = $grid.find('.sps-color-picker[name="' + varName + '"]');
                if ($picker.length) {
                    $picker.wpColorPicker('color', varValue);
                }
            }

            updatePreview(varName, varValue);
        });
    }

    /* ═══════════════════════════════════════════════════════════════
       LIVE PREVIEW
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Update a CSS variable on the preview container.
     *
     * @param {string} varName  CSS variable name (e.g. '--sps-accent').
     * @param {string} varValue CSS variable value (e.g. '#39baff').
     */
    function updatePreview(varName, varValue) {
        var previewEl = document.getElementById('sps-preview-mock');
        if (previewEl) {
            previewEl.style.setProperty(varName, varValue);
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       SAVE & RESET
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Initialize the save button.
     */
    function initSaveButton() {
        $grid.find('.sps-save-theme-btn').on('click', handleSave);
    }

    /**
     * Collect current values from all controls and save via AJAX.
     */
    function handleSave() {
        var $btn    = $grid.find('.sps-save-theme-btn');
        var $status = $grid.find('.sps-save-status');
        var theme   = collectCurrentValues();

        $btn.prop('disabled', true).text(config.i18n.saving || 'Speichern...');
        $status.text('').removeClass('sps-status-success sps-status-error');

        $.post(config.ajaxUrl, {
            action:  'sps_save_form_theme',
            nonce:   config.nonce,
            form_id: formId,
            theme:   JSON.stringify(theme)
        })
        .done(function(response) {
            if (response.success) {
                $status.text(config.i18n.saved || 'Gespeichert.').addClass('sps-status-success');
            } else {
                $status.text(config.i18n.saveFailed || 'Fehler.').addClass('sps-status-error');
            }
        })
        .fail(function() {
            $status.text(config.i18n.saveFailed || 'Fehler.').addClass('sps-status-error');
        })
        .always(function() {
            $btn.prop('disabled', false).html(
                '<span class="dashicons dashicons-saved" style="vertical-align: text-top; font-size: 18px; margin-right: 4px;"></span> ' +
                (config.i18n.save || 'Styling speichern')
            );

            // Clear status after 3 seconds
            setTimeout(function() {
                $status.text('').removeClass('sps-status-success sps-status-error');
            }, 3000);
        });
    }

    /**
     * Reset form theme to defaults via AJAX.
     */
    function handleReset() {
        if (!confirm(config.i18n.resetConfirm || 'Styling zurücksetzen?')) {
            return;
        }

        $.post(config.ajaxUrl, {
            action:  'sps_reset_form_theme',
            nonce:   config.nonce,
            form_id: formId
        })
        .done(function(response) {
            if (response.success) {
                // Apply defaults to all controls and preview
                var defaults = config.defaults || {};
                applyValues(defaults);

                var $status = $grid.find('.sps-save-status');
                $status.text(config.i18n.reset || 'Zurückgesetzt.').addClass('sps-status-success');

                $grid.find('.sps-preset-btn').removeClass('active');

                setTimeout(function() {
                    $status.text('').removeClass('sps-status-success');
                }, 3000);
            }
        });
    }

    /**
     * Collect the current values from all form controls.
     *
     * @returns {Object} Current CSS variable values.
     */
    function collectCurrentValues() {
        var values = {};

        // Colors
        $grid.find('.sps-color-picker').each(function() {
            var $input = $(this);
            var colorVal = '';
            try {
                if (typeof $input.wpColorPicker === 'function') {
                    colorVal = $input.wpColorPicker('color');
                }
            } catch (e) {
                // Fall back
            }
            if (!colorVal) {
                colorVal = $input.val();
            }
            values[$input.attr('name')] = colorVal;
        });

        // Radii
        $grid.find('.sps-radius-slider').each(function() {
            var $slider = $(this);
            var unit = $slider.data('unit') || 'px';
            values[$slider.attr('name')] = $slider.val() + unit;
        });

        return values;
    }

    /* ═══════════════════════════════════════════════════════════════
       SHORTCODE COPY
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Copy shortcode to clipboard when clicking on the code element.
     */
    function initShortcodeCopy() {
        $(document).on('click', '.sps-shortcode-copy', function() {
            var text = $(this).text();
            var $el  = $(this);

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    showCopyFeedback($el);
                });
            } else {
                // Fallback
                var $temp = $('<textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $temp.remove();
                showCopyFeedback($el);
            }
        });
    }

    /**
     * Show brief "Kopiert!" tooltip on element.
     *
     * @param {jQuery} $el Target element.
     */
    function showCopyFeedback($el) {
        var originalText = $el.text();
        $el.text('✓ Kopiert!').addClass('sps-copied');
        setTimeout(function() {
            $el.text(originalText).removeClass('sps-copied');
        }, 1500);
    }

})(jQuery);
