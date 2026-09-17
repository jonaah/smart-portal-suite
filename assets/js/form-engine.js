/**
 * Smart Portal Suite - Modular Form Engine
 *
 * Handles multi-step form rendering, validation, dynamic conditionals,
 * autocomplete, file uploads, and AJAX submissions.
 *
 * @package SmartPortalSuite
 */

window.SPS = window.SPS || {};

(function(SPS) {
    'use strict';

    /**
     * Field Type Renderers Registry.
     * New field types can be registered via SPS.registerField(type, rendererFn).
     */
    const fieldRegistry = {};

    function registerField(type, rendererFn) {
        fieldRegistry[type] = rendererFn;
    }

    function getFieldRenderer(type) {
        // Normalize type names (e.g. 'address-full' -> 'addressFull' or exact match)
        if (fieldRegistry[type]) return fieldRegistry[type];
        const camelType = type.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
        return fieldRegistry[camelType] || fieldRegistry['text'];
    }

    /**
     * Render an SVG icon referencing a symbol in the SVG sprite.
     * Supports both "#icon-dateiname", "icon-dateiname", and "dateiname".
     */
    function renderIcon(iconName, customClass = '') {
        if (!iconName) return '';
        const raw = String(iconName).trim().replace(/^#/, '');
        const iconId = raw.startsWith('icon-') ? raw : 'icon-' + raw;
        const cleanName = raw.replace(/^icon-/, '');

        return `<svg class="sps-icon ${customClass} sps-icon-${escapeAttr(cleanName)}" aria-hidden="true" focusable="false"><use href="#${escapeAttr(iconId)}"></use></svg>`;
    }

    // --- Core Field Renderers ---

    // 1. Radio Cards
    registerField('radio', function(step, form) {
        const currentVal = form.getAnswer(step.id);
        let html = '<div class="sps-radio-grid" role="radiogroup" aria-label="' + SPS.escapeHtml(step.label || '') + '">';
        (step.choices || []).forEach((choice, idx) => {
            const val = choice.value !== undefined ? choice.value : choice.text;
            const isChecked = currentVal !== undefined && String(currentVal) === String(val);
            const inputId = `${form.instanceId}_${step.id}_${idx}`;
            
            html += `
                <label class="sps-radio-card ${isChecked ? 'is-selected' : ''}" for="${inputId}">
                    <input type="radio" id="${inputId}" name="${form.instanceId}_${step.id}" value="${SPS.escapeAttr(val)}" ${isChecked ? 'checked' : ''} class="sps-radio-input">
                    <div class="sps-radio-content">
                        ${choice.icon ? `<div class="sps-choice-icon-wrap">${renderIcon(choice.icon, 'sps-choice-icon')}</div>` : ''}
                        <div class="sps-radio-text">${SPS.escapeHtml(choice.text)}</div>
                        ${choice.subtitle ? `<div class="sps-radio-subtitle">${SPS.escapeHtml(choice.subtitle)}</div>` : ''}
                    </div>
                </label>
            `;
        });
        html += '</div>';
        return html;
    });

    // 2. Slider
    registerField('slider', function(step, form) {
        const cfg = form.getStepConfig(step);
        let currentVal = form.getAnswer(step.id);
        if (currentVal === undefined || currentVal === null) {
            currentVal = cfg.value !== undefined ? cfg.value : (cfg.min || 0);
            form.setAnswer(step.id, currentVal, false);
        }
        
        const displayVal = (SPS.Calculations && SPS.Calculations.formatUnit) 
            ? SPS.Calculations.formatUnit(currentVal, cfg.suffix || '') 
            : currentVal + (cfg.suffix || '');

        return `
            <div class="sps-slider-container">
                <div class="sps-slider-header">
                    <span class="sps-slider-value" id="${form.instanceId}_val_${step.id}">${SPS.escapeHtml(displayVal)}</span>
                </div>
                <div class="sps-slider-track-wrap">
                    <input type="range" 
                           id="${form.instanceId}_input_${step.id}" 
                           class="sps-slider" 
                           min="${cfg.min || 0}" 
                           max="${cfg.max || 100}" 
                           step="${cfg.step || 1}" 
                           value="${currentVal}"
                           aria-label="${SPS.escapeAttr(step.label || '')}"
                           aria-valuemin="${cfg.min || 0}"
                           aria-valuemax="${cfg.max || 100}"
                           aria-valuenow="${currentVal}">
                </div>
                <div class="sps-slider-range-labels">
                    <span>${cfg.min || 0}${SPS.escapeHtml(cfg.suffix || '')}</span>
                    <span>${cfg.max || 100}${SPS.escapeHtml(cfg.suffix || '')}</span>
                </div>
            </div>
        `;
    });

    // 3. Text & Generic Inputs
    registerField('text', function(step, form) {
        const val = form.getAnswer(step.id) || '';
        return `
            <div class="sps-input-group">
                <input type="${step.type === 'email' ? 'email' : (step.type === 'tel' ? 'tel' : 'text')}" 
                       id="${form.instanceId}_input_${step.id}" 
                       class="sps-input" 
                       value="${SPS.escapeAttr(val)}"
                       placeholder="${SPS.escapeAttr(step.placeholder || '')}" 
                       maxlength="${step.maxLength || 255}"
                       autocomplete="on"
                       aria-label="${SPS.escapeAttr(step.label || '')}">
            </div>
        `;
    });

    registerField('email', fieldRegistry['text']);
    registerField('tel', fieldRegistry['text']);
    registerField('number', fieldRegistry['text']);

    // 4. Textarea
    registerField('textarea', function(step, form) {
        const val = form.getAnswer(step.id) || '';
        return `
            <div class="sps-input-group">
                <textarea id="${form.instanceId}_input_${step.id}" 
                          class="sps-input sps-textarea" 
                          rows="4"
                          placeholder="${SPS.escapeAttr(step.placeholder || '')}" 
                          maxlength="${step.maxLength || 2000}"
                          aria-label="${SPS.escapeAttr(step.label || '')}">${SPS.escapeHtml(val)}</textarea>
            </div>
        `;
    });

    // 5. Date
    registerField('date', function(step, form) {
        const val = form.getAnswer(step.id) || '';
        return `
            <div class="sps-input-group">
                <input type="date" 
                       id="${form.instanceId}_input_${step.id}" 
                       class="sps-input" 
                       value="${SPS.escapeAttr(val)}"
                       aria-label="${SPS.escapeAttr(step.label || '')}">
            </div>
        `;
    });

    // 6. Group / Nested Fields (e.g. Address fields or contact rows)
    registerField('group', function(step, form) {
        let html = '<div class="sps-group-wrapper">';
        (step.fields || []).forEach(f => {
            if (f.type === 'row') {
                html += '<div class="sps-group-row">';
                (f.fields || []).forEach(rf => {
                    const val = form.getAnswer(rf.id) || '';
                    html += `
                        <div class="sps-group-col" style="flex: ${rf.flex || '1'};">
                            <input type="${rf.type === 'number' ? 'number' : (rf.type === 'email' ? 'email' : 'text')}" 
                                   id="${form.instanceId}_input_${rf.id}" 
                                   data-group-id="${step.id}"
                                   class="sps-input" 
                                   value="${SPS.escapeAttr(val)}"
                                   placeholder="${SPS.escapeAttr(rf.placeholder || '')}" 
                                   aria-label="${SPS.escapeAttr(rf.placeholder || rf.id)}">
                        </div>
                    `;
                });
                html += '</div>';
            } else {
                const val = form.getAnswer(f.id) || '';
                html += `
                    <div class="sps-group-field">
                        <input type="${f.type === 'number' ? 'number' : (f.type === 'email' ? 'email' : 'text')}" 
                               id="${form.instanceId}_input_${f.id}" 
                               data-group-id="${step.id}"
                               class="sps-input" 
                               value="${SPS.escapeAttr(val)}"
                               placeholder="${SPS.escapeAttr(f.placeholder || '')}" 
                               aria-label="${SPS.escapeAttr(f.placeholder || f.id)}">
                    </div>
                `;
            }
        });
        html += '</div>';
        return html;
    });

    // 7. Full Address with OpenStreetMap Nominatim Autocomplete
    registerField('addressFull', function(step, form) {
        const streetVal = form.getAnswer(step.id + '_strasse') || '';
        const nrVal = form.getAnswer(step.id + '_hausnummer') || '';
        const plzVal = form.getAnswer(step.id + '_plz') || '';
        const ortVal = form.getAnswer(step.id + '_ort') || '';
        const searchVal = form.getAnswer(step.id + '_search') || '';

        return `
            <div class="sps-address-box">
                <div class="sps-address-search-wrap">
                    <input type="text" 
                           id="${form.instanceId}_addr_search" 
                           class="sps-input sps-address-search" 
                           placeholder="Adresse suchen (z. B. Musterstraße 1, 10115 Berlin)..."
                           value="${SPS.escapeAttr(searchVal)}"
                           autocomplete="off">
                    <div class="sps-autocomplete-dropdown" id="${form.instanceId}_addr_dropdown" style="display:none;"></div>
                </div>

                <div class="sps-address-manual-grid">
                    <div class="sps-group-row">
                        <div class="sps-group-col" style="flex: 2;">
                            <label class="sps-field-sublabel">Straße</label>
                            <input type="text" id="${form.instanceId}_input_${step.id}_strasse" class="sps-input" value="${SPS.escapeAttr(streetVal)}" placeholder="Straße">
                        </div>
                        <div class="sps-group-col" style="flex: 1; max-width: 100px;">
                            <label class="sps-field-sublabel">Nr.</label>
                            <input type="text" id="${form.instanceId}_input_${step.id}_hausnummer" class="sps-input" value="${SPS.escapeAttr(nrVal)}" placeholder="Nr.">
                        </div>
                    </div>
                    <div class="sps-group-row">
                        <div class="sps-group-col" style="flex: 1; max-width: 140px;">
                            <label class="sps-field-sublabel">PLZ</label>
                            <input type="text" id="${form.instanceId}_input_${step.id}_plz" class="sps-input" value="${SPS.escapeAttr(plzVal)}" placeholder="PLZ" maxlength="5">
                        </div>
                        <div class="sps-group-col" style="flex: 2;">
                            <label class="sps-field-sublabel">Ort</label>
                            <input type="text" id="${form.instanceId}_input_${step.id}_ort" class="sps-input" value="${SPS.escapeAttr(ortVal)}" placeholder="Ort">
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    registerField('address-full', fieldRegistry['addressFull']);

    // 8. Multi-Checkbox Cards
    registerField('checkboxMulti', function(step, form) {
        const currentVals = form.getAnswer(step.id) || [];
        let html = '<div class="sps-checkbox-grid" role="group" aria-label="' + SPS.escapeHtml(step.label || '') + '">';
        (step.choices || []).forEach((choice, idx) => {
            const val = choice.id !== undefined ? choice.id : choice.text;
            const isChecked = Array.isArray(currentVals) && currentVals.includes(val);
            const inputId = `${form.instanceId}_${step.id}_${idx}`;

            html += `
                <label class="sps-checkbox-card ${isChecked ? 'is-selected' : ''}" for="${inputId}">
                    <input type="checkbox" id="${inputId}" name="${form.instanceId}_${step.id}[]" value="${SPS.escapeAttr(val)}" ${isChecked ? 'checked' : ''} class="sps-checkbox-input">
                    <div class="sps-radio-content">
                        <div class="sps-checkbox-indicator"></div>
                        ${choice.icon ? `<div class="sps-choice-icon-wrap">${renderIcon(choice.icon, 'sps-choice-icon')}</div>` : ''}
                        <div class="sps-radio-text">${SPS.escapeHtml(choice.text)}</div>
                        ${choice.subtitle ? `<div class="sps-radio-subtitle">${SPS.escapeHtml(choice.subtitle)}</div>` : ''}
                    </div>
                </label>
            `;
        });
        html += '</div>';
        return html;
    });
    registerField('checkbox-multi', fieldRegistry['checkboxMulti']);

    // 9. File Upload
    registerField('upload', function(step, form) {
        const files = form.getFiles(step.id) || [];
        const hasFiles = files.length > 0;

        return `
            <div class="sps-upload-container">
                <div class="sps-upload-dropzone ${hasFiles ? 'has-files' : ''}" id="${form.instanceId}_dropzone_${step.id}">
                    <input type="file" 
                           id="${form.instanceId}_input_${step.id}" 
                           class="sps-file-input" 
                           multiple 
                           accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                           aria-label="${SPS.escapeAttr(step.label || 'Dateien hochladen')}">
                    <div class="sps-upload-icon">
                        ${renderIcon('icon-upload', 'sps-upload-svg')}
                    </div>
                    <label for="${form.instanceId}_input_${step.id}" class="sps-btn sps-btn-upload">Dateien auswählen</label>
                    <p class="sps-upload-hint">Oder Dateien hierher ziehen (PDF, JPG, PNG &middot; max. 10 MB pro Datei)</p>
                </div>

                <div class="sps-file-list" id="${form.instanceId}_filelist_${step.id}">
                    ${form.renderFileList(step.id)}
                </div>
            </div>
        `;
    });

    // 10. Summary
    registerField('summary', function(step, form) {
        return `<div class="sps-summary-wrapper" id="${form.instanceId}_summary_content"></div>`;
    });

    // 11. Consent (GDPR)
    registerField('consent', function(step, form) {
        const isChecked = !!form.getAnswer(step.id);
        const inputId = `${form.instanceId}_consent_input`;
        const labelText = step.label || 'Ich habe die Datenschutzerklärung zur Kenntnis genommen und willige in die Verarbeitung meiner Daten ein.';

        return `
            <div class="sps-consent-card">
                <label class="sps-consent-label" for="${inputId}">
                    <input type="checkbox" id="${inputId}" class="sps-consent-checkbox" ${isChecked ? 'checked' : ''} required>
                    <span class="sps-consent-box"></span>
                    <span class="sps-consent-text">
                        ${SPS.escapeHtml(labelText)}
                        <a href="/datenschutz" target="_blank" rel="noopener noreferrer" class="sps-consent-link">Datenschutzerklärung</a>.
                    </span>
                </label>
            </div>
        `;
    });

    /**
     * Form Instance Class
     */
    class FormInstance {
        constructor(container) {
            this.container = container;
            this.instanceId = container.id || 'sps_form_' + Math.random().toString(36).substr(2, 9);
            this.formId = container.getAttribute('data-sps-form-id') || '';
            this.ajaxUrl = container.getAttribute('data-sps-ajax-url') || (window.spsGlobalConfig && window.spsGlobalConfig.ajaxUrl) || '/wp-admin/admin-ajax.php';
            this.nonce = container.getAttribute('data-sps-nonce') || (window.spsGlobalConfig && window.spsGlobalConfig.nonce) || '';
            this.startTime = Date.now();

            this.schema = null;
            this.steps = [];
            this.currentStepIndex = 0;
            this.answers = {};
            this.files = {}; // step.id -> File[]

            this.init();
        }

        init() {
            // Load schema from embedded script tag
            const schemaScript = this.container.querySelector('.sps-schema-data');
            if (schemaScript) {
                try {
                    this.schema = JSON.parse(schemaScript.textContent);
                } catch (err) {
                    console.error('[SPS] JSON Schema parse error:', err);
                }
            }

            if (!this.schema || !this.schema.steps) {
                this.renderError('Formular-Konfiguration konnte nicht geladen werden.');
                return;
            }

            this.steps = this.schema.steps;

            // Render container skeleton
            this.container.classList.add('sps-mounted');
            this.container.innerHTML = `
                <div class="sps-form-wrapper" role="form" aria-label="${SPS.escapeAttr(this.schema.title || 'Formular')}">
                    <div class="sps-progress-header">
                        <div class="sps-step-counter" id="${this.instanceId}_step_counter">Schritt 1</div>
                        <div class="sps-progress-container" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="sps-progress-bar" id="${this.instanceId}_progress_bar"></div>
                        </div>
                    </div>
                    
                    <div class="sps-steps-container" id="${this.instanceId}_steps"></div>
                </div>
            `;

            this.stepsTarget = this.container.querySelector(`#${this.instanceId}_steps`);
            this.progressBar = this.container.querySelector(`#${this.instanceId}_progress_bar`);
            this.counterEl = this.container.querySelector(`#${this.instanceId}_step_counter`);

            // Render all steps into DOM
            this.renderAllSteps();

            // Bind global/container event delegations
            this.bindEvents();

            // Navigate to initial visible step
            const firstIdx = this.findNextVisibleStep(-1);
            this.goTo(firstIdx !== -1 ? firstIdx : 0);
        }

        renderAllSteps() {
            let html = '';
            this.steps.forEach((step, idx) => {
                html += this.renderStepHtml(step, idx);
            });
            this.stepsTarget.innerHTML = html;
        }

        renderStepHtml(step, index) {
            const renderer = getFieldRenderer(step.type);
            const contentHtml = renderer(step, this);
            const isLast = (index === this.steps.length - 1);

            return `
                <div class="sps-step" id="${this.instanceId}_step_${index}" data-step-index="${index}" aria-hidden="true">
                    <div class="sps-step-header">
                        ${step.icon ? `<div class="sps-step-icon-wrap">${renderIcon(step.icon, 'sps-step-icon')}</div>` : ''}
                        ${step.label ? `<h3 class="sps-question">${SPS.escapeHtml(step.label)}</h3>` : ''}
                        ${step.desc ? `<div class="sps-desc">${step.desc}</div>` : ''}
                        ${step.reason ? `<div class="sps-reason-box"><span class="sps-reason-icon">&#9432;</span> <span class="sps-reason-text">${SPS.escapeHtml(step.reason)}</span></div>` : ''}
                    </div>

                    <div class="sps-step-body">
                        ${contentHtml}
                    </div>

                    <div class="sps-error-banner" id="${this.instanceId}_err_${index}" style="display:none;" role="alert"></div>

                    <div class="sps-nav-buttons">
                        ${index > 0 
                            ? `<button type="button" class="sps-btn sps-btn-back" data-action="prev">Zurück</button>` 
                            : '<div></div>'}
                        
                        ${!isLast 
                            ? `<button type="button" class="sps-btn sps-btn-next" data-action="next">Weiter</button>` 
                            : `<button type="button" class="sps-btn sps-btn-submit" data-action="submit">Absenden</button>`}
                    </div>
                </div>
            `;
        }

        bindEvents() {
            // Click delegations for navigation and choices
            this.container.addEventListener('click', (e) => {
                // Navigation buttons
                const btn = e.target.closest('[data-action]');
                if (btn) {
                    const action = btn.getAttribute('data-action');
                    if (action === 'next') this.next();
                    if (action === 'prev') this.prev();
                    if (action === 'submit') this.submit();
                    return;
                }

                // Radio card selection
                const radioCard = e.target.closest('.sps-radio-card');
                if (radioCard) {
                    const input = radioCard.querySelector('input[type="radio"]');
                    if (input) {
                        const stepEl = radioCard.closest('.sps-step');
                        const stepIdx = parseInt(stepEl.getAttribute('data-step-index'), 10);
                        const step = this.steps[stepIdx];
                        
                        input.checked = true;
                        this.setAnswer(step.id, input.value);

                        // Highlight selected
                        stepEl.querySelectorAll('.sps-radio-card').forEach(c => c.classList.remove('is-selected'));
                        radioCard.classList.add('is-selected');

                        // Auto-advance after smooth feedback delay
                        setTimeout(() => {
                            if (this.currentStepIndex === stepIdx) {
                                this.next();
                            }
                        }, 260);
                    }
                }
            });

            // Change event delegations (inputs, sliders, uploads, checkboxes)
            this.container.addEventListener('change', (e) => {
                const target = e.target;
                const stepEl = target.closest('.sps-step');
                if (!stepEl) return;
                const stepIdx = parseInt(stepEl.getAttribute('data-step-index'), 10);
                const step = this.steps[stepIdx];

                // Checkbox multi
                if (target.type === 'checkbox' && target.name.endsWith('[]')) {
                    const checked = Array.from(stepEl.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                    this.setAnswer(step.id, checked);
                    
                    const card = target.closest('.sps-checkbox-card');
                    if (card) {
                        card.classList.toggle('is-selected', target.checked);
                    }
                    return;
                }

                // Consent checkbox
                if (target.type === 'checkbox' && step.type === 'consent') {
                    this.setAnswer(step.id, target.checked ? true : null);
                    return;
                }

                // File Upload
                if (target.type === 'file' && step.type === 'upload') {
                    this.handleFileSelect(step.id, target.files);
                    return;
                }

                // Group inputs
                if (target.dataset.groupId) {
                    const fieldId = target.id.replace(`${this.instanceId}_input_`, '');
                    this.setAnswer(fieldId, target.value);
                    return;
                }

                // General input / date
                if (step) {
                    this.setAnswer(step.id, target.value);
                }
            });

            // Input events for text and sliders (live updates)
            this.container.addEventListener('input', (e) => {
                const target = e.target;
                const stepEl = target.closest('.sps-step');
                if (!stepEl) return;
                const stepIdx = parseInt(stepEl.getAttribute('data-step-index'), 10);
                const step = this.steps[stepIdx];

                // Slider live value
                if (target.type === 'range') {
                    const cfg = this.getStepConfig(step);
                    const valEl = stepEl.querySelector(`#${this.instanceId}_val_${step.id}`);
                    if (valEl) {
                        const displayVal = (SPS.Calculations && SPS.Calculations.formatUnit) 
                            ? SPS.Calculations.formatUnit(target.value, cfg.suffix || '') 
                            : target.value + (cfg.suffix || '');
                        valEl.textContent = displayVal;
                    }
                    this.setAnswer(step.id, parseFloat(target.value));
                    return;
                }

                // Text inputs live sync
                if (target.dataset.groupId) {
                    const fieldId = target.id.replace(`${this.instanceId}_input_`, '');
                    this.setAnswer(fieldId, target.value);
                } else if (step) {
                    this.setAnswer(step.id, target.value);
                }
            });

            // Keyboard navigation (Enter to advance)
            this.container.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    this.next();
                }
            });

            // Drag and drop for uploads
            this.container.addEventListener('dragover', (e) => {
                const dropzone = e.target.closest('.sps-upload-dropzone');
                if (dropzone) {
                    e.preventDefault();
                    dropzone.classList.add('is-dragover');
                }
            });

            this.container.addEventListener('dragleave', (e) => {
                const dropzone = e.target.closest('.sps-upload-dropzone');
                if (dropzone) dropzone.classList.remove('is-dragover');
            });

            this.container.addEventListener('drop', (e) => {
                const dropzone = e.target.closest('.sps-upload-dropzone');
                if (dropzone) {
                    e.preventDefault();
                    dropzone.classList.remove('is-dragover');
                    const fileInput = dropzone.querySelector('.sps-file-input');
                    const stepEl = dropzone.closest('.sps-step');
                    const stepIdx = parseInt(stepEl.getAttribute('data-step-index'), 10);
                    const step = this.steps[stepIdx];

                    if (e.dataTransfer && e.dataTransfer.files) {
                        this.handleFileSelect(step.id, e.dataTransfer.files);
                    }
                }
            });

            // Autocomplete setup for address-full steps
            this.setupAddressAutocomplete();
        }

        setupAddressAutocomplete() {
            const searchInput = this.container.querySelector('.sps-address-search');
            if (!searchInput || !SPS.Autocomplete) return;

            const dropdown = this.container.querySelector('.sps-autocomplete-dropdown');
            let debounceTimer = null;

            searchInput.addEventListener('input', (e) => {
                const query = e.target.value;
                clearTimeout(debounceTimer);

                if (query.length < 3) {
                    if (dropdown) dropdown.style.display = 'none';
                    return;
                }

                debounceTimer = setTimeout(() => {
                    SPS.Autocomplete.search(query, (results) => {
                        if (!results || results.length === 0) {
                            if (dropdown) dropdown.style.display = 'none';
                            return;
                        }

                        let html = '';
                        results.forEach((item) => {
                            html += `
                                <div class="sps-autocomplete-item" data-address="${SPS.escapeAttr(JSON.stringify(item))}">
                                    <div class="sps-item-main">${SPS.escapeHtml(item.display_name.split(',')[0])}</div>
                                    <div class="sps-item-sub">${SPS.escapeHtml(item.display_name)}</div>
                                </div>
                            `;
                        });
                        dropdown.innerHTML = html;
                        dropdown.style.display = 'block';
                    });
                }, 300);
            });

            // Click on autocomplete item
            if (dropdown) {
                dropdown.addEventListener('click', (e) => {
                    const itemEl = e.target.closest('.sps-autocomplete-item');
                    if (!itemEl) return;

                    try {
                        const data = JSON.parse(itemEl.getAttribute('data-address'));
                        this.applyAddressData(data);
                        dropdown.style.display = 'none';
                    } catch (err) {
                        console.error('Address parsing error', err);
                    }
                });
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (dropdown && !dropdown.contains(e.target) && e.target !== searchInput) {
                    dropdown.style.display = 'none';
                }
            });
        }

        applyAddressData(data) {
            const addr = data.address || {};
            const road = addr.road || addr.pedestrian || addr.suburb || '';
            const houseNr = addr.house_number || '';
            const postcode = addr.postcode || '';
            const city = addr.city || addr.town || addr.village || addr.municipality || '';

            // Find address inputs
            const strasseInput = this.container.querySelector(`[id$="_strasse"]`);
            const nrInput = this.container.querySelector(`[id$="_hausnummer"]`);
            const plzInput = this.container.querySelector(`[id$="_plz"]`);
            const ortInput = this.container.querySelector(`[id$="_ort"]`);

            if (strasseInput) { strasseInput.value = road; strasseInput.dispatchEvent(new Event('input')); }
            if (nrInput) { nrInput.value = houseNr; nrInput.dispatchEvent(new Event('input')); }
            if (plzInput) { plzInput.value = postcode; plzInput.dispatchEvent(new Event('input')); }
            if (ortInput) { ortInput.value = city; ortInput.dispatchEvent(new Event('input')); }

            const searchInput = this.container.querySelector('.sps-address-search');
            if (searchInput) searchInput.value = data.display_name;
        }

        // --- File Upload State & Rendering ---
        handleFileSelect(stepId, fileList) {
            if (!fileList || fileList.length === 0) return;
            this.files[stepId] = this.files[stepId] || [];

            const maxFileSize = 10 * 1024 * 1024; // 10MB
            const allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];

            for (let i = 0; i < fileList.length; i++) {
                const file = fileList[i];
                const ext = file.name.split('.').pop().toLowerCase();

                if (file.size > maxFileSize) {
                    this.showError(this.currentStepIndex, `Die Datei "${file.name}" ist größer als 10 MB.`);
                    continue;
                }
                if (!allowedExts.includes(ext)) {
                    this.showError(this.currentStepIndex, `Dateityp ".${ext}" wird nicht unterstützt.`);
                    continue;
                }

                // Avoid duplicate names
                if (!this.files[stepId].some(f => f.name === file.name && f.size === file.size)) {
                    this.files[stepId].push(file);
                }
            }

            this.setAnswer(stepId, this.files[stepId].map(f => f.name));
            this.updateFileList(stepId);
        }

        removeFile(stepId, fileIndex) {
            if (this.files[stepId]) {
                this.files[stepId].splice(fileIndex, 1);
                this.setAnswer(stepId, this.files[stepId].length > 0 ? this.files[stepId].map(f => f.name) : null);
                this.updateFileList(stepId);
            }
        }

        renderFileList(stepId) {
            const files = this.files[stepId] || [];
            if (files.length === 0) return '';

            let html = '<ul class="sps-file-items">';
            files.forEach((file, idx) => {
                const sizeKb = Math.round(file.size / 1024);
                const sizeStr = sizeKb > 1024 ? (sizeKb / 1024).toFixed(1) + ' MB' : sizeKb + ' KB';
                html += `
                    <li class="sps-file-item">
                        <span class="sps-file-icon">&#128196;</span>
                        <span class="sps-file-name" title="${SPS.escapeAttr(file.name)}">${SPS.escapeHtml(file.name)}</span>
                        <span class="sps-file-size">(${sizeStr})</span>
                        <button type="button" class="sps-file-remove" onclick="SPS.getForm('${this.instanceId}').removeFile('${stepId}', ${idx})" aria-label="Datei entfernen">&times;</button>
                    </li>
                `;
            });
            html += '</ul>';
            return html;
        }

        updateFileList(stepId) {
            const listEl = this.container.querySelector(`#${this.instanceId}_filelist_${stepId}`);
            if (listEl) {
                listEl.innerHTML = this.renderFileList(stepId);
            }
        }

        getFiles(stepId) {
            return this.files[stepId];
        }

        // --- State Management ---
        setAnswer(key, value, triggerUpdates = true) {
            if (value === undefined || value === null || value === '') {
                delete this.answers[key];
            } else {
                this.answers[key] = value;
            }

            if (triggerUpdates) {
                this.hideError(this.currentStepIndex);
                this.updateDynamicConfigs();
                this.updateSummary();
            }
        }

        getAnswer(key) {
            return this.answers[key];
        }

        getStepConfig(step) {
            if (step.dynamicConfig && step.dynamicConfig.dependsOn) {
                const depVal = this.getAnswer(step.dynamicConfig.dependsOn);
                if (depVal && step.dynamicConfig.configs && step.dynamicConfig.configs[depVal]) {
                    return Object.assign({}, step, step.dynamicConfig.configs[depVal]);
                }
            }
            return step;
        }

        updateDynamicConfigs() {
            this.steps.forEach(step => {
                if (step.dynamicConfig && this.isVisible(step)) {
                    const cfg = this.getStepConfig(step);
                    const sliderEl = this.container.querySelector(`#${this.instanceId}_input_${step.id}`);
                    const valEl = this.container.querySelector(`#${this.instanceId}_val_${step.id}`);

                    if (sliderEl) {
                        sliderEl.min = cfg.min;
                        sliderEl.max = cfg.max;
                        sliderEl.step = cfg.step;

                        let cur = parseFloat(sliderEl.value);
                        if (isNaN(cur) || cur < cfg.min) cur = cfg.value || cfg.min;
                        if (cur > cfg.max) cur = cfg.max;

                        sliderEl.value = cur;
                        if (valEl) {
                            valEl.textContent = (SPS.Calculations && SPS.Calculations.formatUnit) 
                                ? SPS.Calculations.formatUnit(cur, cfg.suffix || '') 
                                : cur + (cfg.suffix || '');
                        }
                    }
                }
            });
        }

        updateSummary() {
            const summaryEl = this.container.querySelector(`#${this.instanceId}_summary_content`);
            if (!summaryEl) return;

            let html = '<div class="sps-summary-list">';
            this.steps.forEach(step => {
                if (step.id && step.id !== 'summary' && step.id !== 'consent' && this.isVisible(step)) {
                    let val = this.getAnswer(step.id);
                    if (val !== undefined && val !== null && val !== '') {
                        if (Array.isArray(val)) val = val.join(', ');
                        if (step.type === 'slider' && step.suffix) val += step.suffix;
                        
                        html += `
                            <div class="sps-summary-item">
                                <div class="sps-summary-label">${SPS.escapeHtml(step.label || step.id)}</div>
                                <div class="sps-summary-value">${SPS.escapeHtml(String(val))}</div>
                            </div>
                        `;
                    }
                }
            });
            html += '</div>';
            summaryEl.innerHTML = html;
        }

        // --- Navigation & Visibility ---
        isVisible(step) {
            if (!step.showIf) return true;
            const cond = step.showIf;
            const fieldValue = this.answers[cond.field];

            if (fieldValue === undefined || fieldValue === null) return false;

            switch (cond.op) {
                case 'eq': return String(fieldValue) === String(cond.value);
                case 'neq': return String(fieldValue) !== String(cond.value);
                case 'gt': return parseFloat(fieldValue) > parseFloat(cond.value);
                case 'gte': return parseFloat(fieldValue) >= parseFloat(cond.value);
                case 'lt': return parseFloat(fieldValue) < parseFloat(cond.value);
                case 'lte': return parseFloat(fieldValue) <= parseFloat(cond.value);
                case 'in': 
                    return Array.isArray(fieldValue) 
                        ? fieldValue.includes(cond.value) 
                        : String(fieldValue) === String(cond.value);
                default: return true;
            }
        }

        findNextVisibleStep(startIndex) {
            for (let i = startIndex + 1; i < this.steps.length; i++) {
                if (this.isVisible(this.steps[i])) return i;
            }
            return -1;
        }

        findPrevVisibleStep(startIndex) {
            for (let i = startIndex - 1; i >= 0; i--) {
                if (this.isVisible(this.steps[i])) return i;
            }
            return -1;
        }

        goTo(index) {
            if (index < 0 || index >= this.steps.length) return;

            // Deactivate current
            const curEl = this.container.querySelector(`#${this.instanceId}_step_${this.currentStepIndex}`);
            if (curEl) {
                curEl.classList.remove('active');
                curEl.setAttribute('aria-hidden', 'true');
            }

            this.currentStepIndex = index;

            // Activate new
            const nextEl = this.container.querySelector(`#${this.instanceId}_step_${this.currentStepIndex}`);
            if (nextEl) {
                nextEl.classList.add('active');
                nextEl.setAttribute('aria-hidden', 'false');

                // Auto-focus first input
                const firstInput = nextEl.querySelector('input:not([type="hidden"]), select, textarea');
                if (firstInput && firstInput.type !== 'radio' && firstInput.type !== 'range') {
                    firstInput.focus();
                }
            }

            this.updateProgress();
            this.updateSummary();
        }

        next() {
            if (!this.validateStep(this.currentStepIndex)) return;
            const nextIdx = this.findNextVisibleStep(this.currentStepIndex);
            if (nextIdx !== -1) {
                this.goTo(nextIdx);
            }
        }

        prev() {
            const prevIdx = this.findPrevVisibleStep(this.currentStepIndex);
            if (prevIdx !== -1) {
                this.goTo(prevIdx);
            }
        }

        updateProgress() {
            // Count total visible steps
            const visibleIndices = [];
            this.steps.forEach((s, i) => {
                if (this.isVisible(s)) visibleIndices.push(i);
            });

            const currentPos = visibleIndices.indexOf(this.currentStepIndex) + 1;
            const total = visibleIndices.length;
            const percent = total > 1 ? Math.round(((currentPos - 1) / (total - 1)) * 100) : 100;

            if (this.progressBar) {
                this.progressBar.style.width = `${percent}%`;
                this.progressBar.parentElement.setAttribute('aria-valuenow', percent);
            }
            if (this.counterEl) {
                this.counterEl.textContent = `Schritt ${currentPos} von ${total}`;
            }
        }

        validateStep(index) {
            const step = this.steps[index];
            if (!step || !this.isVisible(step)) return true;

            if (step.required) {
                if (step.type === 'consent') {
                    const consentVal = this.answers[step.id];
                    if (!consentVal) {
                        this.showError(index, 'Bitte stimmen Sie der Datenschutzerklärung zu, um fortzufahren.');
                        return false;
                    }
                } else if (step.type === 'upload') {
                    const files = this.files[step.id] || [];
                    if (files.length === 0) {
                        this.showError(index, 'Bitte laden Sie die erforderliche Datei hoch.');
                        return false;
                    }
                } else if (step.type === 'addressFull' || step.type === 'address-full') {
                    const street = this.getAnswer(step.id + '_strasse');
                    const plz = this.getAnswer(step.id + '_plz');
                    const ort = this.getAnswer(step.id + '_ort');
                    if (!street || !plz || !ort) {
                        this.showError(index, 'Bitte vervollständigen Sie die Adresse (Straße, PLZ, Ort).');
                        return false;
                    }
                } else {
                    const val = this.answers[step.id];
                    if (val === undefined || val === null || val === '' || (Array.isArray(val) && val.length === 0)) {
                        this.showError(index, 'Bitte füllen Sie dieses Feld aus.');
                        return false;
                    }
                }
            }

            // Email validation
            if (step.type === 'email' || (step.fields && step.fields.some(f => f.type === 'email'))) {
                const emailVal = this.answers[step.id] || this.answers['E-Mail'] || this.answers['email'];
                if (emailVal && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                    this.showError(index, 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
                    return false;
                }
            }

            this.hideError(index);
            return true;
        }

        showError(index, msg) {
            const errEl = this.container.querySelector(`#${this.instanceId}_err_${index}`);
            if (errEl) {
                errEl.textContent = msg;
                errEl.style.display = 'block';
            }
        }

        hideError(index) {
            const errEl = this.container.querySelector(`#${this.instanceId}_err_${index}`);
            if (errEl) {
                errEl.style.display = 'none';
            }
        }

        // --- Submission Flow ---
        submit() {
            if (!this.validateStep(this.currentStepIndex)) return;

            const submitBtn = this.container.querySelector('.sps-btn-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="sps-spinner"></span> Wird übertragen...';
            }

            // Anti-Bot Honeypot check
            const hpInput = this.container.querySelector('input[name="sps_hp"]');
            const hpValue = hpInput ? hpInput.value : '';

            // Construct FormData (supports all data types and binary file uploads)
            const formData = new FormData();
            formData.append('action', 'sps_submit_form');
            formData.append('nonce', this.nonce);
            formData.append('form_type', this.schema.form_type || this.schema.title);
            formData.append('form_id', this.schema.form_id || this.formId);
            formData.append('answers', JSON.stringify(this.answers));
            formData.append('sps_hp', hpValue);
            formData.append('sps_duration_ms', Date.now() - this.startTime);

            // Append all uploaded files
            Object.keys(this.files).forEach(fieldId => {
                const fileList = this.files[fieldId];
                if (Array.isArray(fileList)) {
                    fileList.forEach((file, idx) => {
                        formData.append(`files_${fieldId}[${idx}]`, file, file.name);
                    });
                }
            });

            fetch(this.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.renderSuccess(data.data && data.data.message ? data.data.message : 'Ihre Anfrage wurde erfolgreich übermittelt.');
                } else {
                    const errMsg = (data.data && data.data.message) ? data.data.message : (data.data || 'Ein Fehler ist aufgetreten.');
                    this.showError(this.currentStepIndex, errMsg);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Erneut versuchen';
                    }
                }
            })
            .catch(err => {
                console.error('[SPS] Form submission error:', err);
                this.showError(this.currentStepIndex, 'Netzwerkfehler. Bitte prüfen Sie Ihre Verbindung und versuchen Sie es erneut.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Erneut versuchen';
                }
            });
        }

        renderSuccess(message) {
            this.container.innerHTML = `
                <div class="sps-form-wrapper sps-success-box" role="alert">
                    <div class="sps-success-icon">${renderIcon('icon-check', 'sps-success-svg')}</div>
                    <h3 class="sps-success-title">Vielen Dank!</h3>
                    <p class="sps-success-desc">${SPS.escapeHtml(message)}</p>
                    <p class="sps-success-note">Wir haben Ihre Angaben erhalten und werden uns schnellstmöglich bei Ihnen melden.</p>
                </div>
            `;
        }

        renderError(message) {
            this.container.innerHTML = `
                <div class="sps-form-wrapper sps-error-box" role="alert">
                    <div class="sps-error-icon">&#9888;</div>
                    <h3 class="sps-error-title">Formular-Fehler</h3>
                    <p class="sps-error-desc">${SPS.escapeHtml(message)}</p>
                </div>
            `;
        }
    }

    // --- Form Instances Registry ---
    const instances = {};

    function mount(container) {
        if (!container || container.classList.contains('sps-mounted')) return null;
        const instance = new FormInstance(container);
        instances[instance.instanceId] = instance;
        return instance;
    }

    function initAll() {
        const containers = document.querySelectorAll('.sps-form-container:not(.sps-mounted)');
        containers.forEach(el => mount(el));
    }

    function getForm(instanceId) {
        return instances[instanceId];
    }

    // --- Utilities ---
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(str) {
        return escapeHtml(str);
    }

    // Export public API
    SPS.FormInstance = FormInstance;
    SPS.mount = mount;
    SPS.initAll = initAll;
    SPS.getForm = getForm;
    SPS.registerField = registerField;
    SPS.renderIcon = renderIcon;
    SPS.escapeHtml = escapeHtml;
    SPS.escapeAttr = escapeAttr;

    // Automatic Mounting Lifecycle
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    window.addEventListener('load', initAll);

})(window.SPS);
