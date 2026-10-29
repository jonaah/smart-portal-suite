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
            initHeightControl();
            initPresetButtons();
            initSaveButton();
            initAccessBox();
        }

        // Shortcode copy-to-clipboard
        initShortcodeCopy();

        // Form Import Modal & Delete handler
        initImportModal();
        initDeleteButtons();
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
                    updatePreview(varName, ui.color.toString());
                },
                clear: function() {
                    // Leer = automatisch/Standard aus dem Stylesheet
                    updatePreview(varName, '');
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

    /**
     * Formularhöhe: Schieberegler + "Automatisch".
     */
    function initHeightControl() {
        var $slider = $grid.find('.sps-height-slider');
        var $auto   = $grid.find('.sps-height-auto');
        var $label  = $slider.siblings('.sps-radius-value');

        function apply() {
            if ($auto.is(':checked')) {
                $slider.prop('disabled', true);
                $label.text('automatisch');
                updatePreview('--sps-form-height', 'auto');
            } else {
                $slider.prop('disabled', false);
                $label.text($slider.val() + 'px');
                updatePreview('--sps-form-height', $slider.val() + 'px');
            }
        }
        $slider.on('input change', apply);
        $auto.on('change', apply);
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
        var defaults = config.defaults || {};

        // Farben: Wert aus dem Preset, sonst Standard (leer = automatisch)
        $grid.find('.sps-color-picker').each(function() {
            var $picker = $(this);
            var varName = $picker.attr('name');
            var value   = Object.prototype.hasOwnProperty.call(values, varName) ? values[varName] : (defaults[varName] || '');
            setColor($picker, value);
            updatePreview(varName, value);
        });

        $.each(values, function(varName, varValue) {
            if (varName.indexOf('radius') !== -1) {
                var numVal = parseInt(varValue, 10) || 0;
                $grid.find('.sps-radius-slider[name="' + varName + '"]').val(numVal).trigger('input');
            } else if (varName === '--sps-form-height') {
                var $auto = $grid.find('.sps-height-auto');
                if (varValue === 'auto') {
                    $auto.prop('checked', true).trigger('change');
                } else {
                    $auto.prop('checked', false);
                    $grid.find('.sps-height-slider').val(parseInt(varValue, 10) || 760).trigger('input');
                }
            }
        });
    }

    /**
     * Farbe in einem wp-color-picker setzen oder leeren.
     */
    function setColor($picker, value) {
        if (value) {
            $picker.wpColorPicker('color', value);
        } else {
            $picker.val('');
            var $container = $picker.closest('.wp-picker-container');
            $container.find('.wp-color-result').css('background-color', '');
        }
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
        var previewEl = document.querySelector('#sps-preview-frame .sps-form-container');
        if (!previewEl) return;
        if (varValue === '' || varValue === null || varValue === undefined) {
            previewEl.style.removeProperty(varName);
        } else {
            previewEl.style.setProperty(varName, varValue);
        }
        // Slider-Spuren werden per JS eingefärbt
        if (window.SPS && SPS.Calculations && SPS.Calculations.updateSliderGradient) {
            previewEl.querySelectorAll('input[type="range"]').forEach(function(el) {
                SPS.Calculations.updateSliderGradient(el);
            });
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

        // Farben (leer = automatisch, wird nicht gespeichert)
        $grid.find('.sps-color-picker').each(function() {
            var $input = $(this);
            values[$input.attr('name')] = String($input.val() || '').trim();
        });

        // Radien
        $grid.find('.sps-radius-slider').each(function() {
            var $slider = $(this);
            values[$slider.attr('name')] = $slider.val() + ($slider.data('unit') || 'px');
        });

        // Höhe
        var $height = $grid.find('.sps-height-slider');
        if ($height.length) {
            values['--sps-form-height'] = $grid.find('.sps-height-auto').is(':checked') ? 'auto' : $height.val() + 'px';
        }

        return values;
    }

    /* ═══════════════════════════════════════════════════════════════
       ZUGANG & NEXTCLOUD
       ═══════════════════════════════════════════════════════════════ */

    function initAccessBox() {
        var $box = $('.sps-access-box');
        if (!$box.length) return;

        var $select  = $box.find('#sps-nc-form-id');
        var $result  = $box.find('.sps-nc-result');
        var $spinner = $box.find('.sps-access-spinner');
        var $status  = $box.find('.sps-access-status');

        function esc(t) {
            return $('<div>').text(t === undefined || t === null ? '' : String(t)).html();
        }
        function busy(on) {
            $spinner.toggleClass('is-active', !!on);
        }

        $box.on('click', '.sps-nc-load-forms', function() {
            busy(true);
            $result.empty();
            $.post(config.ajaxUrl, { action: 'sps_nc_form_list', nonce: config.nonce })
                .done(function(res) {
                    if (!res.success) {
                        $result.html('<div class="notice notice-error inline"><p>' + esc(res.data && res.data.message) + '</p></div>');
                        return;
                    }
                    var current = String($select.val() || $select.data('current') || '0');
                    var first   = $select.find('option').first().clone();
                    $select.empty().append(first);
                    var dups = [];
                    res.data.forms.forEach(function(f) {
                        var label = '#' + f.id + ' – ' + f.title + (f.owner ? ' (' + f.owner + ')' : '') + (f.lastUpdated ? ', geändert ' + f.lastUpdated : '') + (f.expired ? ' [abgelaufen]' : '');
                        $select.append($('<option>').val(f.id).text(label));
                        if (f.duplicate) dups.push('#' + f.id + ' ' + f.title);
                    });
                    $select.val(current);
                    if (!$select.val()) $select.val('0');
                    if (dups.length) {
                        $result.html('<div class="notice notice-warning inline"><p><strong>Mehrere Formulare mit gleichem Titel:</strong> ' + esc(dups.join(', ')) + '. Bitte das richtige fest auswählen und mit „Zuordnung prüfen" kontrollieren.</p></div>');
                    } else {
                        $result.html('<p class="description">' + res.data.forms.length + ' Formulare gefunden.</p>');
                    }
                })
                .fail(function() {
                    $result.html('<div class="notice notice-error inline"><p>Nextcloud nicht erreichbar.</p></div>');
                })
                .always(function() { busy(false); });
        });

        $box.on('click', '.sps-nc-check-mapping', function() {
            busy(true);
            $result.empty();
            $.post(config.ajaxUrl, {
                action: 'sps_nc_mapping',
                nonce: config.nonce,
                form_id: formId,
                nc_form_id: $select.val() || 0
            })
                .done(function(res) {
                    if (!res.success) {
                        $result.html('<div class="notice notice-error inline"><p>' + esc(res.data && res.data.message) + '</p></div>');
                        return;
                    }
                    var bad = 0;
                    var rows = res.data.rows.map(function(r) {
                        var ok = r.status === 'ok' || r.status.indexOf('WebDAV') !== -1 || r.status === 'Datei';
                        var critical = r.status.indexOf('FEHLT') === 0 || r.status.indexOf('Pflicht-Dateifrage') === 0 || (r.required === 'ja' && r.status.indexOf('Optionen fehlen') === 0);
                        if (critical) bad++;
                        return '<tr class="' + (critical ? 'sps-map-bad' : (ok ? 'sps-map-ok' : 'sps-map-warn')) + '">' +
                            '<td>' + esc(r.name) + (r.required ? ' <strong>*</strong>' : '') + '</td>' +
                            '<td>' + esc(r.type) + '</td>' +
                            '<td><code>' + esc(r.source) + '</code></td>' +
                            '<td>' + esc(r.status) + '</td></tr>';
                    }).join('');
                    var head = bad
                        ? '<div class="notice notice-error inline"><p><strong>' + bad + ' Problem(e)</strong> in NC-Formular #' + res.data.nc_form_id + ' – Übertragungen dorthin scheitern, bis das behoben ist (technischen Namen bzw. Optionstexte in Nextcloud anpassen).</p></div>'
                        : '<div class="notice notice-success inline"><p>NC-Formular #' + res.data.nc_form_id + ': alle Pflichtfragen zugeordnet.</p></div>';
                    var unused = res.data.unused && res.data.unused.length
                        ? '<p class="description">Antworten ohne passende NC-Frage (werden nicht übertragen): <code>' + esc(res.data.unused.join(', ')) + '</code></p>'
                        : '';
                    $result.html(head + '<table class="widefat striped sps-mapping-table"><thead><tr><th>NC-Frage (technischer Name)</th><th>Typ</th><th>Quelle</th><th>Status</th></tr></thead><tbody>' + rows + '</tbody></table>' + unused);
                })
                .fail(function() {
                    $result.html('<div class="notice notice-error inline"><p>Nextcloud nicht erreichbar.</p></div>');
                })
                .always(function() { busy(false); });
        });

        $box.on('click', '.sps-save-access-btn', function() {
            var $btn = $(this).prop('disabled', true);
            $status.text('').removeClass('sps-status-success sps-status-error');
            $.post(config.ajaxUrl, {
                action: 'sps_save_form_access',
                nonce: config.nonce,
                form_id: formId,
                access: $box.find('#sps-access-mode').val(),
                gate_text: $box.find('#sps-access-gate-text').val(),
                nc_form_id: $select.val() || 0
            })
                .done(function(res) {
                    if (res.success) {
                        $status.text(res.data.message).addClass('sps-status-success');
                    } else {
                        $status.text((res.data && res.data.message) || 'Fehler.').addClass('sps-status-error');
                    }
                })
                .fail(function() {
                    $status.text('Fehler beim Speichern.').addClass('sps-status-error');
                })
                .always(function() { $btn.prop('disabled', false); });
        });
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

    /* ═══════════════════════════════════════════════════════════════
       IMPORT MODAL & WORKFLOW
       ═══════════════════════════════════════════════════════════════ */

    var selectedFile = null;

    /**
     * Initialize the Import Form modal dialog and handlers.
     */
    function initImportModal() {
        var $modal     = $('#sps-import-modal');
        var $openBtn   = $('.sps-open-import-modal-btn');
        var $closeBtns = $modal.find('.sps-modal-close, .sps-modal-close-btn');
        var $tabs      = $modal.find('.sps-tab-btn');
        var $dropzone  = $('#sps-file-dropzone');
        var $fileInput = $('#sps-import-file-input');
        var $importBtn = $('#sps-execute-import-btn');
        var $status    = $modal.find('.sps-import-status');

        if (!$modal.length) return;

        // Open modal (delegated on document for 100% reliability)
        $(document).on('click', '.sps-open-import-modal-btn', function(e) {
            e.preventDefault();
            resetImportModal();
            $modal.addClass('active').show();
        });

        // Close modal
        $(document).on('click', '.sps-modal-close, .sps-modal-close-btn', function(e) {
            e.preventDefault();
            $modal.removeClass('active').hide();
        });

        // Close on backdrop click
        $modal.on('click', function(e) {
            if ($(e.target).is($modal)) {
                $modal.removeClass('active').hide();
            }
        });

        // Close on ESC key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && ($modal.hasClass('active') || $modal.is(':visible'))) {
                $modal.removeClass('active').hide();
            }
        });

        // Tabs
        $tabs.on('click', function() {
            var targetTab = $(this).data('tab');
            $tabs.removeClass('active');
            $(this).addClass('active');

            $modal.find('.sps-tab-pane').hide();
            $('#sps-tab-' + targetTab).show();
            $status.hide().removeClass('sps-status-success sps-status-error').text('');
        });

        // Browse button
        $('#sps-browse-file-btn').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $fileInput.trigger('click');
        });

        $dropzone.on('click', function(e) {
            if (!$(e.target).is('#sps-browse-file-btn, .sps-remove-file')) {
                $fileInput.trigger('click');
            }
        });

        // File selected via file input
        $fileInput.on('change', function() {
            if (this.files && this.files.length) {
                handleFileChosen(this.files[0]);
            }
        });

        // Drag & Drop events
        $dropzone.on('dragover dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.addClass('dragover');
        });

        $dropzone.on('dragleave dragend drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.removeClass('dragover');
        });

        $dropzone.on('drop', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files && files.length) {
                handleFileChosen(files[0]);
            }
        });

        // Remove file
        $dropzone.on('click', '.sps-remove-file', function(e) {
            e.preventDefault();
            e.stopPropagation();
            clearSelectedFile();
        });

        // Nextcloud fetch forms
        $('#sps-fetch-nc-forms-btn').on('click', function() {
            var $btn     = $(this);
            var $spinner = $('#sps-nc-spinner');
            var $wrap    = $('#sps-nc-forms-select-wrap');
            var $select  = $('#sps-nc-form-select');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.hide();

            $.post(config.ajaxUrl, {
                action: 'sps_fetch_nc_forms',
                nonce:  config.nonce
            })
            .done(function(response) {
                if (response.success && response.data.forms && response.data.forms.length) {
                    $select.empty();
                    $.each(response.data.forms, function(i, f) {
                        $select.append($('<option>').val(f.id).text(f.title + ' (ID: ' + f.id + ')').data('title', f.title));
                    });
                    $wrap.slideDown(200);
                } else {
                    var errMsg = (response.data && response.data.message) ? response.data.message : (config.i18n.ncFetchFailed || 'Fehler beim Abrufen.');
                    showImportStatus(errMsg, 'error');
                }
            })
            .fail(function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : (config.i18n.ncFetchFailed || 'Netzwerkfehler');
                showImportStatus(msg, 'error');
            })
            .always(function() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });

        // Execute Import
        $importBtn.on('click', function() {
            var activeTab = $tabs.filter('.active').data('tab');
            var overwrite = $('#sps-import-overwrite').is(':checked') ? '1' : '0';

            $status.hide();

            if (activeTab === 'upload') {
                if (!selectedFile) {
                    showImportStatus(config.i18n.noFileOrJson || 'Bitte wähle eine Datei aus.', 'error');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'sps_import_form');
                formData.append('nonce', config.nonce);
                formData.append('import_file', selectedFile);
                formData.append('overwrite', overwrite);

                startImporting();

                $.ajax({
                    url:         config.ajaxUrl,
                    type:        'POST',
                    data:        formData,
                    processData: false,
                    contentType: false
                })
                .done(handleImportResponse)
                .fail(handleImportFailure)
                .always(finishImporting);

            } else if (activeTab === 'paste') {
                var jsonContent = $.trim($('#sps-json-paste-input').val());
                if (!jsonContent) {
                    showImportStatus(config.i18n.noFileOrJson || 'Bitte füge JSON-Inhalt ein.', 'error');
                    return;
                }

                startImporting();

                $.post(config.ajaxUrl, {
                    action:       'sps_import_form',
                    nonce:        config.nonce,
                    json_content: jsonContent,
                    overwrite:    overwrite
                })
                .done(handleImportResponse)
                .fail(handleImportFailure)
                .always(finishImporting);

            } else if (activeTab === 'nextcloud') {
                var ncFormId = $('#sps-nc-form-select').val();
                var ncTitle  = $('#sps-nc-form-select option:selected').data('title') || '';

                if (!ncFormId) {
                    showImportStatus('Bitte wähle zuerst ein Nextcloud-Formular aus.', 'error');
                    return;
                }

                startImporting();

                $.post(config.ajaxUrl, {
                    action:     'sps_scaffold_nc_form',
                    nonce:      config.nonce,
                    nc_form_id: ncFormId,
                    nc_title:   ncTitle,
                    overwrite:  overwrite
                })
                .done(handleImportResponse)
                .fail(handleImportFailure)
                .always(finishImporting);
            }
        });

        function handleFileChosen(file) {
            var ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'json' && ext !== 'zip') {
                showImportStatus('Bitte nur .json oder .zip Dateien hochladen.', 'error');
                return;
            }
            selectedFile = file;
            $dropzone.find('.sps-dropzone-prompt, .sps-dropzone-hint, .dashicons-cloud-upload').hide();
            $dropzone.find('.sps-selected-file-info').show().find('.sps-filename').text(file.name + ' (' + Math.round(file.size / 1024) + ' KB)');
            $status.hide();
        }

        function clearSelectedFile() {
            selectedFile = null;
            $fileInput.val('');
            $dropzone.find('.sps-selected-file-info').hide();
            $dropzone.find('.sps-dropzone-prompt, .sps-dropzone-hint, .dashicons-cloud-upload').show();
        }

        function resetImportModal() {
            clearSelectedFile();
            $('#sps-json-paste-input').val('');
            $('#sps-import-overwrite').prop('checked', false);
            $('#sps-nc-forms-select-wrap').hide();
            $status.hide().removeClass('sps-status-success sps-status-error').text('');
            $tabs.filter('[data-tab="upload"]').trigger('click');
        }

        function startImporting() {
            $importBtn.prop('disabled', true).text(config.i18n.importing || 'Wird importiert...');
        }

        function finishImporting() {
            $importBtn.prop('disabled', false).html(
                '<span class="dashicons dashicons-upload" style="vertical-align: text-top; font-size: 18px; margin-right: 4px;"></span> ' +
                (config.i18n.save || 'Importieren')
            );
        }

        function handleImportResponse(res) {
            if (res.success) {
                showImportStatus(res.data.message || config.i18n.importSuccess, 'success');
                setTimeout(function() {
                    window.location.reload();
                }, 1200);
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : (config.i18n.importFailed || 'Fehler beim Import.');
                showImportStatus(msg, 'error');
            }
        }

        function handleImportFailure(xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                ? xhr.responseJSON.data.message
                : (config.i18n.importFailed || 'Fehler beim Import.');
            showImportStatus(msg, 'error');
        }

        function showImportStatus(text, type) {
            $status.removeClass('sps-status-success sps-status-error')
                .addClass('sps-status-' + type)
                .text(text)
                .fadeIn(150);
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       DELETE CUSTOM FORMS
       ═══════════════════════════════════════════════════════════════ */

    /**
     * Wire up delete buttons for custom/imported forms.
     */
    function initDeleteButtons() {
        $(document).on('click', '.sps-delete-form-btn', function() {
            var $btn      = $(this);
            var formId    = $btn.data('form-id');
            var formTitle = $btn.data('form-title') || formId;

            var msg = (config.i18n.deleteConfirm || 'Formular wirklich löschen?') + '\n\n' + formTitle + ' (' + formId + ')';
            if (!confirm(msg)) {
                return;
            }

            $btn.prop('disabled', true);

            $.post(config.ajaxUrl, {
                action:  'sps_delete_form',
                nonce:   config.nonce,
                form_id: formId
            })
            .done(function(res) {
                if (res.success) {
                    var $row = $('tr[data-form-id="' + formId + '"]');
                    $row.css('background-color', '#ffebee').fadeOut(400, function() {
                        $(this).remove();
                    });
                } else {
                    alert((res.data && res.data.message) ? res.data.message : config.i18n.deleteFailed);
                    $btn.prop('disabled', false);
                }
            })
            .fail(function(xhr) {
                var errMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                    ? xhr.responseJSON.data.message
                    : config.i18n.deleteFailed;
                alert(errMsg);
                $btn.prop('disabled', false);
            });
        });
    }

})(jQuery);
