/**
 * Smart Portal Suite - Modular Form Engine
 *
 * Client-side multi-step form engine for WordPress.
 *
 * Architecture & Responsibilities:
 * --------------------------------
 * 1. Schema Parsing: Reads JSON form configurations directly from embedded <script class="sps-schema-data">
 *    elements, avoiding race conditions with standard wp_localize_script execution.
 * 2. Step Lifecycle: Pre-renders all steps into the DOM and activates them via CSS classes (.sps-step.active),
 *    providing smooth transitions, fast response times, and preserved DOM state across steps.
 * 3. Dynamic Branching: Evaluates "showIf" conditional rules (eq, neq, gt, gte, lt, lte, in) on-the-fly
 *    during navigation, calculating the next/previous visible steps seamlessly.
 * 4. Dynamic Step Configs: Re-configures slider ranges (min, max, step, suffix) based on preceding choices
 *    (e.g. heating type determining heating demand sliders).
 * 5. State Management: Centralized key-value answer dictionary (answers{}) and binary file map (files{}).
 * 6. File Uploads: Drag-and-drop dropzone, size limit enforcement (10MB), file extension filtering,
 *    and FormData binary streaming.
 * 7. OpenStreetMap Integration: Live Nominatim address autocompletion with 300ms debouncing and automatic
 *    field distribution (street, house number, zip, city).
 * 8. Validation & Anti-Bot: Granular step-by-step validation, honeypot spam protection, completion duration
 *    measurement, and secure WordPress AJAX submission with nonce verification.
 *
 * @package SmartPortalSuite
 * @author  Smart Portal Suite Contributors
 * @version 1.0.0
 */

window.SPS = window.SPS || {};

(function(SPS) {
    'use strict';

    /**
     * Internal registry mapping field type strings (e.g. 'radio', 'slider', 'addressFull')
     * to their respective HTML generator functions.
     *
     * @type {Object.<string, function(Object, FormInstance): string>}
     */
    const fieldRegistry = {};

    /**
     * Registers a custom field renderer in the global SPS registry.
     * Third-party add-ons or custom form scripts can call SPS.registerField() to add new field types.
     *
     * @param {string} type - Unique identifier for the field type (e.g. 'signature', 'date-range').
     * @param {function(Object, FormInstance): string} rendererFn - Callback that takes (stepConfig, formInstance) and returns HTML string.
     */
    function registerField(type, rendererFn) {
        fieldRegistry[type] = rendererFn;
    }

    /**
     * Resolves the renderer function for a given step type.
     * Automatically normalizes hyphenated names (e.g. 'address-full' -> 'addressFull')
     * and falls back to the default 'text' input renderer if an unknown type is encountered.
     *
     * @param {string} type - The field type string from the JSON schema.
     * @returns {function(Object, FormInstance): string} The resolved renderer function.
     */
    function getFieldRenderer(type) {
        // Normalize type names (e.g. 'address-full' -> 'addressFull' or exact match)
        if (fieldRegistry[type]) return fieldRegistry[type];
        const camelType = type.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
        return fieldRegistry[camelType] || fieldRegistry['text'];
    }

    /**
     * Generates an SVG element referencing a sprite symbol, or returns an inline SVG string directly.
     *
     * Supported formats:
     *  - "#icon-house"  → sprite symbol reference
     *  - "icon-house"   → sprite symbol reference
     *  - "<svg ...>"    → returned as-is (inline SVG)
     *
     * @param {string} iconName    - Symbol ID, or raw inline SVG markup.
     * @param {string} [customClass=''] - CSS class appended to the <svg> wrapper.
     * @returns {string} SVG markup string.
     */
    function renderIcon(iconName, customClass = '') {
        if (!iconName) return '';
        const trimmed = String(iconName).trim();

        // Raw inline SVG string: pass through directly, wrapped in a div for sizing
        if (trimmed.startsWith('<svg') || trimmed.startsWith('<SVG')) {
            return `<span class="sps-icon-inline ${customClass}" aria-hidden="true">${trimmed}</span>`;
        }

        // Sprite symbol reference
        const raw       = trimmed.replace(/^#/, '');
        const iconId    = raw.startsWith('icon-') ? raw : 'icon-' + raw;
        const cleanName = raw.replace(/^icon-/, '');

        return `<svg class="sps-icon ${customClass} sps-icon-${escapeAttr(cleanName)}" aria-hidden="true" focusable="false"><use href="#${escapeAttr(iconId)}"></use></svg>`;
    }

    // --- Core Field Renderers ---

    /**
     * 1. Radio Cards Renderer ('radio')
     * Renders a responsive grid of selectable option cards with optional icons, titles, and subtitles.
     * When selected, adds an '.is-selected' CSS class and triggers a smooth auto-advance after 260ms.
     *
     * @param {Object} step - Step configuration from JSON schema.
     * @param {string} step.id - Unique field identifier.
     * @param {string} [step.label] - Accessible label for the radio group.
     * @param {Array.<{text: string, value?: *, icon?: string, subtitle?: string}>} step.choices - Available options.
     * @param {FormInstance} form - Active FormInstance owning this step.
     * @returns {string} HTML markup for the radio cards grid.
     */
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

    /**
     * 2. Range Slider Renderer ('slider')
     *
     * Renders a two-column wrapper on desktop:
     *   - Left column (.sps-slider-hero-icon): 180px fixed SVG illustration from step.icon.
     *   - Right column (.sps-slider-container): value badge, discrete buttons OR range track.
     *
     * Discrete mode activates automatically when the step has 2–7 discrete values
     * (determined via SPS.Calculations.getSliderStepValues). In this mode the range
     * input is hidden (.is-hidden) and clickable buttons (.sps-slider-option) are shown instead.
     *
     * Optional schema fields processed here (no hardcoding of form-specific logic):
     *  - step.referenceBadge  → displays a dynamically calculated badge (e.g. "Ø 3 Personen")
     *  - step.conversion      → displays a secondary formatted value (e.g. "15.000 kWh / 1.531 Liter")
     *
     * @param {Object}       step - Step configuration from JSON schema.
     * @param {FormInstance} form - Active FormInstance.
     * @returns {string} HTML markup.
     */
    registerField('slider', function(step, form) {
        const cfg         = form.getStepConfig(step);
        const Calc        = SPS.Calculations;
        let   currentVal  = form.getAnswer(step.id);

        if (currentVal === undefined || currentVal === null) {
            currentVal = cfg.value !== undefined ? cfg.value : (cfg.min || 0);
            form.setAnswer(step.id, currentVal, false);
        }

        // Determine discrete values (2–7 steps → show buttons)
        const discreteValues = (Calc && Calc.getSliderStepValues)
            ? Calc.getSliderStepValues(cfg.min, cfg.max, cfg.step)
            : [];
        const isDiscrete = discreteValues.length > 0;

        // Compute initial display value (conversion takes priority over plain suffix)
        let displayVal;
        if (step.conversion && Calc && Calc.resolveConversion) {
            displayVal = Calc.resolveConversion(currentVal, step.conversion, form.answers);
        } else {
            displayVal = Calc ? Calc.formatUnit(currentVal, cfg.suffix || '') : (currentVal + (cfg.suffix || ''));
        }

        // Compute initial reference badge text (e.g. "Ø 3 Personen")
        let badgeText = '';
        if (step.referenceBadge && Calc && Calc.calculateReferenceBadge) {
            badgeText = Calc.calculateReferenceBadge(currentVal, step.referenceBadge) || '';
        }

        // Discrete option buttons HTML
        let discreteHtml = '';
        if (isDiscrete) {
            discreteHtml = `<div class="sps-slider-options active" data-target="${escapeAttr(step.id)}" role="group" aria-label="Wertauswahl">`;
            discreteValues.forEach(val => {
                const isActive = Math.abs(Number(currentVal) - val) < 1e-9;
                discreteHtml += `<button type="button" class="sps-slider-option${isActive ? ' active' : ''}" data-slider-id="${escapeAttr(step.id)}" data-value="${val}">${val}</button>`;
            });
            discreteHtml += '</div>';
        }

        return `
            <div class="sps-slider-wrapper">
                ${step.icon ? `<div class="sps-slider-hero-icon">${renderIcon(step.icon, 'sps-hero-svg')}</div>` : ''}
                <div class="sps-slider-container">
                    ${badgeText ? `<div class="sps-slider-badge" id="${escapeAttr(form.instanceId)}_badge_${escapeAttr(step.id)}" aria-live="polite">${SPS.escapeHtml(badgeText)}</div>` : ''}
                    <span class="sps-slider-val" id="${escapeAttr(form.instanceId)}_val_${escapeAttr(step.id)}">${SPS.escapeHtml(displayVal)}</span>
                    <input type="range"
                           id="${escapeAttr(form.instanceId)}_input_${escapeAttr(step.id)}"
                           class="sps-slider${isDiscrete ? ' is-hidden' : ''}"
                           min="${cfg.min !== undefined ? cfg.min : 0}"
                           max="${cfg.max !== undefined ? cfg.max : 100}"
                           step="${cfg.step !== undefined ? cfg.step : 1}"
                           value="${currentVal}"
                           aria-label="${SPS.escapeAttr(step.label || '')}"
                           aria-valuemin="${cfg.min !== undefined ? cfg.min : 0}"
                           aria-valuemax="${cfg.max !== undefined ? cfg.max : 100}"
                           aria-valuenow="${currentVal}">
                    ${isDiscrete ? discreteHtml : `<div class="sps-slider-options" data-target="${escapeAttr(step.id)}"></div>`}
                    ${step.conversion ? `<div class="sps-slider-conversion" id="${escapeAttr(form.instanceId)}_conv_${escapeAttr(step.id)}" aria-live="polite"></div>` : ''}
                </div>
            </div>
        `;
    });


    /**
     * 3. Text & Generic Inputs Renderer ('text', 'email', 'tel', 'number')
     * Renders standard single-line HTML5 inputs with length constraints and accessible labeling.
     *
     * @param {Object} step - Step configuration.
     * @param {string} step.id - Unique field identifier.
     * @param {string} [step.placeholder] - Placeholder text.
     * @param {number} [step.maxLength=255] - Maximum character limit.
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup for the text input group.
     */
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

    // Aliases for standard HTML5 input variants sharing the text renderer logic
    registerField('email', fieldRegistry['text']);
    registerField('tel', fieldRegistry['text']);
    registerField('number', fieldRegistry['text']);

    /**
     * 4. Textarea Renderer ('textarea')
     * Renders a multi-line text input for longer comments, project descriptions, or inquiries.
     *
     * @param {Object} step - Step configuration.
     * @param {string} step.id - Unique field identifier.
     * @param {string} [step.placeholder] - Placeholder text.
     * @param {number} [step.maxLength=2000] - Maximum allowed characters.
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup for the textarea group.
     */
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

    /**
     * 5. Date Picker Renderer ('date')
     * Renders a native HTML5 date input with localized date formatting support in modern browsers.
     *
     * @param {Object} step - Step configuration.
     * @param {string} step.id - Unique field identifier.
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup for the date input.
     */
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

    /**
     * 6. Nested Field Groups Renderer ('group')
     * Supports complex multi-input layouts arranged in flexible horizontal rows or vertical stacks
     * (e.g. combined First Name + Last Name rows, or Phone + Email combos).
     *
     * @param {Object} step - Step configuration.
     * @param {string} step.id - Parent group ID.
     * @param {Array.<Object>} step.fields - Array of child fields or row definitions.
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup for nested input structures.
     */
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

    /**
     * 7. Full Address with OpenStreetMap Nominatim Autocomplete ('addressFull', 'address-full')
     * Renders a live autocomplete search bar connected to the OpenStreetMap Nominatim API,
     * coupled with discrete manual input fields for Straße (street), Hausnummer (house number),
     * PLZ (postal code), and Ort (city). Selecting an autocomplete match automatically populates
     * and dispatches change events to all manual fields.
     *
     * @param {Object} step - Step configuration.
     * @param {string} step.id - Base address field identifier (creates sub-keys: id_strasse, id_hausnummer, id_plz, id_ort).
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup for the compound address widget.
     */
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

    /**
     * 8. Multi-Checkbox Cards Renderer ('checkboxMulti', 'checkbox-multi')
     * Renders a grid of cards allowing multiple choices to be selected simultaneously.
     * Selected values are stored as an array of IDs in answers[step.id].
     *
     * @param {Object} step - Step configuration.
     * @param {string} step.id - Unique field identifier.
     * @param {Array.<{id?: string, text: string, subtitle?: string, icon?: string}>} step.choices - Selectable options.
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup for the multi-select checkbox grid.
     */
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

    /**
     * 9. File Upload Dropzone Renderer ('upload')
     * Renders an interactive drag-and-drop file upload zone supporting multi-file selection,
     * visual dragover feedback, client-side size (10MB) & extension validation,
     * and removable file badge chips.
     *
     * @param {Object} step - Step configuration.
     * @param {string} step.id - Unique field identifier.
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup for the dropzone and file list.
     */
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

    /**
     * 10. Summary Review Renderer ('summary')
     * Renders a placeholder container that dynamically compiles and lists all previously answered
     * questions before the final submission step.
     *
     * @param {Object} step - Step configuration.
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup placeholder for dynamic summary injection.
     */
    registerField('summary', function(step, form) {
        return `<div class="sps-summary-wrapper" id="${form.instanceId}_summary_content"></div>`;
    });

    /**
     * 11. Consent / GDPR Checkbox Renderer ('consent')
     * Renders a compliant consent agreement card with a required checkbox and a link to the privacy policy.
     *
     * @param {Object} step - Step configuration.
     * @param {string} step.id - Field identifier.
     * @param {string} [step.label] - Custom consent text.
     * @param {FormInstance} form - Form instance.
     * @returns {string} HTML markup for the consent card.
     */
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
     * FormInstance Class
     *
     * Encapsulates the entire runtime state, DOM lifecycle, dynamic branching,
     * validation rules, and AJAX network communication for an individual form container.
     */
    class FormInstance {
        /**
         * Initializes a new FormInstance tied to a specific DOM container.
         *
         * @param {HTMLElement} container - The wrapper element with class .sps-form-container.
         */
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
            this.files = {}; // Map of step.id -> File[]

            this.init();
        }

        /**
         * Bootstraps the form lifecycle:
         * 1. Extracts the JSON schema from the inline <script class="sps-schema-data"> tag
         *    (with a fallback to window['spsFormData_' + formId] for testing).
         * 2. Renders the structural container skeleton (header, progress bar, step container).
         * 3. Pre-renders all steps into the DOM for fast zero-latency step transitions.
         * 4. Attaches container-wide event delegation listeners.
         * 5. Navigates to the first visible step.
         */
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

            // Fallback for standalone test suites or legacy configurations
            if (!this.schema && this.formId && window['spsFormData_' + this.formId]) {
                const legacy = window['spsFormData_' + this.formId];
                this.schema = legacy.schema || legacy;
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
                        <div class="sps-progress-container" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="sps-progress-bar" id="${this.instanceId}_progress_bar"></div>
                        </div>
                    </div>
                    
                    <div class="sps-steps-container" id="${this.instanceId}_steps"></div>
                </div>
            `;

            this.stepsTarget = this.container.querySelector(`#${this.instanceId}_steps`);
            this.progressBar = this.container.querySelector(`#${this.instanceId}_progress_bar`);
            this.counterEl = null;

            // Render all steps into DOM
            this.renderAllSteps();

            // Bind global/container event delegations
            this.bindEvents();

            // Navigate to initial visible step
            const firstIdx = this.findNextVisibleStep(-1);
            this.goTo(firstIdx !== -1 ? firstIdx : 0);
        }

        /**
         * Renders all form steps into the steps container DOM node.
         * Steps are initially placed into the DOM with aria-hidden="true" and without
         * the '.active' class, ensuring instant switching with no DOM re-creation costs.
         */
        renderAllSteps() {
            let html = '';
            this.steps.forEach((step, idx) => {
                html += this.renderStepHtml(step, idx);
            });
            this.stepsTarget.innerHTML = html;
        }

        /**
         * Compiles the complete outer HTML for an individual step, including:
         * - Step Header: Optional SVG icon, question title (h3), description, and info/reason box.
         * - Step Body: Generated by the dedicated field renderer matching step.type.
         * - Error Banner: Hidden by default, activated during validation errors.
         * - Navigation Footer: Back button (if index > 0), Next button (or Submit button on final step).
         *
         * @param {Object} step - Step configuration object from schema.
         * @param {number} index - 0-based index of the step in this.steps.
         * @returns {string} Step HTML markup.
         */
        renderStepHtml(step, index) {
            const renderer    = getFieldRenderer(step.type);
            const contentHtml = renderer(step, this);
            const isLast      = (index === this.steps.length - 1);
            // For sliders, the icon is rendered inside the sps-slider-wrapper (hero position).
            // Therefore we must NOT render it again in the step header above the question.
            const showHeaderIcon = step.icon && step.type !== 'slider';

            return `
                <div class="sps-step" id="${this.instanceId}_step_${index}" data-step-index="${index}" aria-hidden="true">
                    <div class="sps-step-header">
                        ${showHeaderIcon ? `<div class="sps-step-icon-wrap">${renderIcon(step.icon, 'sps-step-icon')}</div>` : ''}
                        ${step.label ? `<h3 class="sps-question">${SPS.escapeHtml(step.label)}</h3>` : ''}
                        ${step.desc  ? `<div class="sps-desc">${step.desc}</div>` : ''}
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

        /**
         * Binds centralized event delegations on the container DOM node.
         * Using container delegation guarantees that all dynamically created or updated
         * inputs and buttons retain functioning event handlers without memory leaks.
         *
         * Delegated Event Handlers:
         * - 'click': Navigation action buttons (next, prev, submit) and radio card selection with 260ms auto-advance.
         * - 'change': Multi-checkbox array syncing, consent checkbox, file upload trigger, and standard input syncing.
         * - 'input': Real-time range slider value display formatting (via SPS.Calculations) and live text answer sync.
         * - 'keydown': Advances to the next step when the Enter key is pressed (excluding multiline textareas).
         * - 'dragover', 'dragleave', 'drop': File drag-and-drop mechanics on .sps-upload-dropzone elements.
         */
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

                // Discrete slider option buttons
                const sliderOptionBtn = e.target.closest('.sps-slider-option');
                if (sliderOptionBtn) {
                    const sliderId = sliderOptionBtn.getAttribute('data-slider-id');
                    const value    = parseFloat(sliderOptionBtn.getAttribute('data-value'));
                    if (sliderId === undefined || isNaN(value)) return;

                    // Sync the hidden range input
                    const stepEl  = sliderOptionBtn.closest('.sps-step');
                    const stepIdx = parseInt(stepEl.getAttribute('data-step-index'), 10);
                    const step    = this.steps[stepIdx];
                    const inputEl = document.getElementById(`${this.instanceId}_input_${sliderId}`);
                    if (inputEl) inputEl.value = value;

                    // Store the answer
                    this.setAnswer(sliderId, value);

                    // Update active class
                    const optionBtns = sliderOptionBtn.closest('.sps-slider-options');
                    if (optionBtns) {
                        optionBtns.querySelectorAll('.sps-slider-option').forEach(b => {
                            b.classList.toggle('active', Math.abs(parseFloat(b.getAttribute('data-value')) - value) < 1e-9);
                        });
                    }

                    // Update the displayed value
                    this._updateSliderDisplay(stepEl, step, value);
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
                const step    = this.steps[stepIdx];

                // Slider live value update
                if (target.type === 'range') {
                    const value = parseFloat(target.value);
                    this.setAnswer(step.id, value);
                    this._updateSliderDisplay(stepEl, step, value);
                    // Update gradient fill
                    if (SPS.Calculations && SPS.Calculations.updateSliderGradient) {
                        SPS.Calculations.updateSliderGradient(target);
                    }
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

        /**
         * Updates the displayed value, optional reference badge, and optional conversion text
         * for a slider step. Fully schema-driven: reads step.conversion and step.referenceBadge
         * from the JSON schema — no hardcoded field names or fuel factors anywhere in this method.
         *
         * Called both from the 'input' event handler (continuous sliders) and from the
         * discrete button click handler (.sps-slider-option).
         *
         * @param {HTMLElement} stepEl - The .sps-step DOM element containing the slider.
         * @param {Object}      step   - The step config from this.steps[].
         * @param {number}      value  - The new numeric slider value.
         */
        _updateSliderDisplay(stepEl, step, value) {
            const Calc = SPS.Calculations;
            const cfg  = this.getStepConfig(step);

            // 1. Main value display
            const valEl = document.getElementById(`${this.instanceId}_val_${step.id}`);
            if (valEl) {
                let displayVal;
                if (step.conversion && Calc && Calc.resolveConversion) {
                    displayVal = Calc.resolveConversion(value, step.conversion, this.answers);
                } else if (Calc && Calc.formatUnit) {
                    displayVal = Calc.formatUnit(value, cfg.suffix || '');
                } else {
                    displayVal = value + (cfg.suffix || '');
                }
                valEl.textContent = displayVal;
            }

            // 2. Reference badge (e.g. "Ø 3 Personen") — deklarativ aus step.referenceBadge
            if (step.referenceBadge) {
                const badgeEl = document.getElementById(`${this.instanceId}_badge_${step.id}`);
                if (badgeEl && Calc && Calc.calculateReferenceBadge) {
                    const text = Calc.calculateReferenceBadge(value, step.referenceBadge);
                    if (text) {
                        badgeEl.textContent = text;
                        badgeEl.hidden = false;
                    } else {
                        badgeEl.hidden = true;
                    }
                }
            }

            // 3. Update track gradient
            const sliderInput = document.getElementById(`${this.instanceId}_input_${step.id}`);
            if (sliderInput && Calc && Calc.updateSliderGradient) {
                Calc.updateSliderGradient(sliderInput);
            }
        }

        /**
         * Initializes OpenStreetMap Nominatim live address suggestions for compound address steps.
         * Debounces user keystrokes by 300ms, requires at least 3 characters before querying,
         * populates dropdown choices with main/sub titles, and dismisses dropdown on outside clicks.
         */
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

        /**
         * Takes parsed OpenStreetMap address details and distributes them into the discrete manual
         * input fields (Straße, Hausnummer, PLZ, Ort). Dispatches native 'input' events so the
         * form answers store updates immediately.
         *
         * @param {Object} data - Nominatim result object.
         * @param {Object} [data.address] - Structured address properties (road, house_number, postcode, city/town).
         * @param {string} data.display_name - Formatted full address string for the search input.
         */
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

        /**
         * Validates and stages files selected by the user for a given upload step.
         * Enforces a maximum file size of 10 MB per file and checks against the extension whitelist:
         * pdf, jpg, jpeg, png, webp, doc, docx.
         * Prevents duplicate files based on name and size.
         *
         * @param {string} stepId - Field ID of the upload step.
         * @param {FileList|File[]} fileList - Newly selected files.
         */
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

        /**
         * Removes a staged file by its array index and updates both the answer state and DOM list.
         *
         * @param {string} stepId - Field ID of the upload step.
         * @param {number} fileIndex - Index of the file to remove.
         */
        removeFile(stepId, fileIndex) {
            if (this.files[stepId]) {
                this.files[stepId].splice(fileIndex, 1);
                this.setAnswer(stepId, this.files[stepId].length > 0 ? this.files[stepId].map(f => f.name) : null);
                this.updateFileList(stepId);
            }
        }

        /**
         * Generates HTML markup for the staged files list, rendering file badges with name,
         * human-readable size (KB/MB), and interactive remove trigger buttons.
         *
         * @param {string} stepId - Field ID of the upload step.
         * @returns {string} Staged files list HTML.
         */
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

        /**
         * Updates the file list DOM container for a given upload step.
         *
         * @param {string} stepId - Field ID of the upload step.
         */
        updateFileList(stepId) {
            const listEl = this.container.querySelector(`#${this.instanceId}_filelist_${stepId}`);
            if (listEl) {
                listEl.innerHTML = this.renderFileList(stepId);
            }
        }

        /**
         * Returns the staged File objects array for an upload step.
         *
         * @param {string} stepId - Field ID of the upload step.
         * @returns {File[]|undefined} Array of File objects or undefined.
         */
        getFiles(stepId) {
            return this.files[stepId];
        }

        // --- State Management ---

        /**
         * Sets or deletes a key-value pair in the instance's answers dictionary.
         * If value is empty, null, or undefined, the key is removed.
         * When triggerUpdates is true, clears active step error banners, re-evaluates
         * dynamic slider configurations, and refreshes the summary review screen.
         *
         * @param {string} key - Identifier for the answer (usually step.id or subfield id).
         * @param {*} value - Value to record (string, number, boolean, array).
         * @param {boolean} [triggerUpdates=true] - Whether to trigger reactive UI updates.
         */
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

        /**
         * Retrieves the current answer for a given field key.
         *
         * @param {string} key - Field identifier.
         * @returns {*} Recorded value or undefined.
         */
        getAnswer(key) {
            return this.answers[key];
        }

        /**
         * Resolves dynamic step configurations based on prior answers.
         * If a step defines "dynamicConfig" with a "dependsOn" field (e.g. heating type),
         * this method looks up the chosen option and merges the specific overrides (e.g. min, max, step, suffix).
         *
         * @param {Object} step - Step definition from schema.
         * @returns {Object} Effective step configuration with dynamic overrides applied.
         */
        getStepConfig(step) {
            if (step.dynamicConfig && step.dynamicConfig.dependsOn) {
                const depVal = this.getAnswer(step.dynamicConfig.dependsOn);
                if (depVal && step.dynamicConfig.configs && step.dynamicConfig.configs[depVal]) {
                    return Object.assign({}, step, step.dynamicConfig.configs[depVal]);
                }
            }
            return step;
        }

        /**
         * Re-evaluates dynamic step configurations across all visible steps and updates
         * the active DOM range slider attributes (min, max, step, current value, and unit badge).
         */
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

        /**
         * Re-compiles the HTML for the summary review step, displaying a structured list
         * of all answered questions and their formatted responses before final submission.
         */
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

        /**
         * Evaluates whether a given step should be displayed or skipped based on its "showIf" conditional rule.
         *
         * Supported Operators:
         * - 'eq': Strict string equality (e.g. fieldValue === cond.value)
         * - 'neq': Strict string inequality (e.g. fieldValue !== cond.value)
         * - 'gt': Numerical greater than (e.g. fieldValue > cond.value)
         * - 'gte': Numerical greater than or equal (e.g. fieldValue >= cond.value)
         * - 'lt': Numerical less than (e.g. fieldValue < cond.value)
         * - 'lte': Numerical less than or equal (e.g. fieldValue <= cond.value)
         * - 'in': Value inclusion (checks if cond.value is in fieldValue array or string equality)
         *
         * @param {Object} step - Step configuration object from schema.
         * @param {Object} [step.showIf] - Conditional visibility rule.
         * @param {string} step.showIf.field - ID of the dependency field.
         * @param {string} step.showIf.op - Operator ('eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in').
         * @param {*} step.showIf.value - Target comparison value.
         * @returns {boolean} True if the step should be displayed; false if it should be skipped.
         */
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

        /**
         * Finds the index of the next step after startIndex that satisfies isVisible().
         *
         * @param {number} startIndex - Starting step index.
         * @returns {number} Next visible step index, or -1 if no further steps exist.
         */
        findNextVisibleStep(startIndex) {
            for (let i = startIndex + 1; i < this.steps.length; i++) {
                if (this.isVisible(this.steps[i])) return i;
            }
            return -1;
        }

        /**
         * Finds the index of the preceding step before startIndex that satisfies isVisible().
         *
         * @param {number} startIndex - Starting step index.
         * @returns {number} Preceding visible step index, or -1 if no prior steps exist.
         */
        findPrevVisibleStep(startIndex) {
            for (let i = startIndex - 1; i >= 0; i--) {
                if (this.isVisible(this.steps[i])) return i;
            }
            return -1;
        }

        /**
         * Switches the active view to the specified step index:
         * - Deactivates the current step (.sps-step.active, aria-hidden="true").
         * - Activates the targeted step (.active, aria-hidden="false").
         * - Moves input focus to the first interactive field for seamless keyboard typing.
         * - Synchronizes the progress bar width and counter badge.
         * - Refreshes the summary screen if applicable.
         *
         * @param {number} index - Index of the step to activate.
         */
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

        /**
         * Validates the active step and advances forward to the next visible step.
         */
        next() {
            if (!this.validateStep(this.currentStepIndex)) return;
            const nextIdx = this.findNextVisibleStep(this.currentStepIndex);
            if (nextIdx !== -1) {
                this.goTo(nextIdx);
            }
        }

        /**
         * Navigates backward to the preceding visible step.
         */
        prev() {
            const prevIdx = this.findPrevVisibleStep(this.currentStepIndex);
            if (prevIdx !== -1) {
                this.goTo(prevIdx);
            }
        }

        /**
         * Calculates the dynamic progress percentage and updates the progress bar and counter badge.
         * Measures progress exclusively among currently visible steps, so skipped questions
         * do not artificially skew completion percentages.
         */
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

        /**
         * Performs client-side validation on the specified step before allowing forward navigation or submission.
         *
         * Checks performed:
         * - Required check on standard inputs and multi-checkboxes.
         * - Consent check (GDPR checkbox must be checked).
         * - Upload check (at least one valid file must be staged).
         * - Address check (Straße, PLZ, and Ort are mandatory).
         * - Email syntax check via regular expression.
         *
         * @param {number} index - Index of the step to validate.
         * @returns {boolean} True if all validation rules pass; false otherwise.
         */
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

        /**
         * Renders and displays an alert message banner for a step.
         *
         * @param {number} index - Index of the step.
         * @param {string} msg - Error message text.
         */
        showError(index, msg) {
            const errEl = this.container.querySelector(`#${this.instanceId}_err_${index}`);
            if (errEl) {
                errEl.textContent = msg;
                errEl.style.display = 'block';
            }
        }

        /**
         * Clears and hides the error message banner for a step.
         *
         * @param {number} index - Index of the step.
         */
        hideError(index) {
            const errEl = this.container.querySelector(`#${this.instanceId}_err_${index}`);
            if (errEl) {
                errEl.style.display = 'none';
            }
        }

        // --- Submission Flow ---

        /**
         * Handles the asynchronous form submission to the WordPress backend:
         * 1. Validates the final step.
         * 2. Sets loading spinner state on the submit button.
         * 3. Reads honeypot input (sps_hp) and records completion duration (sps_duration_ms).
         * 4. Assembles a multipart/form-data payload with answers JSON and staged binary files.
         * 5. Performs a fetch() POST request to admin-ajax.php (action: 'sps_submit_form').
         * 6. Renders the success confirmation view or reports error messages.
         */
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

        /**
         * Replaces the form container markup with an elegant success confirmation screen.
         *
         * @param {string} message - Success description message.
         */
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

        /**
         * Displays a fatal error view when schema loading or critical initialization fails.
         *
         * @param {string} message - Error description text.
         */
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

    /**
     * Internal registry mapping form instance IDs to their active FormInstance objects.
     * @type {Object.<string, FormInstance>}
     */
    const instances = {};

    /**
     * Mounts and initializes a form on the specified DOM container element.
     * Prevents double mounting via the '.sps-mounted' class check.
     *
     * @param {HTMLElement} container - DOM container element with class .sps-form-container.
     * @returns {FormInstance|null} Newly created FormInstance or null if invalid/already mounted.
     */
    function mount(container) {
        if (!container || container.classList.contains('sps-mounted')) return null;
        const instance = new FormInstance(container);
        instances[instance.instanceId] = instance;
        return instance;
    }

    /**
     * Automatically queries the entire DOM for all unmounted form containers
     * and initializes them. Safe to call multiple times or after AJAX page loads.
     */
    function initAll() {
        const containers = document.querySelectorAll('.sps-form-container:not(.sps-mounted)');
        containers.forEach(el => mount(el));
    }

    /**
     * Retrieves an active FormInstance by its instance ID.
     *
     * @param {string} instanceId - Unique instance identifier.
     * @returns {FormInstance|undefined} Matching form instance.
     */
    function getForm(instanceId) {
        return instances[instanceId];
    }

    // --- Utilities ---

    /**
     * Escapes special characters in a string to prevent Cross-Site Scripting (XSS) in HTML bodies.
     *
     * @param {*} str - Raw input value.
     * @returns {string} Sanitized string safe for HTML output.
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Escapes special characters in a string to prevent attribute injection vulnerabilities.
     *
     * @param {*} str - Raw input value.
     * @returns {string} Sanitized string safe for attribute output.
     */
    function escapeAttr(str) {
        return escapeHtml(str);
    }

    // --- Public API Exports ---
    SPS.FormInstance = FormInstance;
    SPS.mount = mount;
    SPS.initAll = initAll;
    SPS.getForm = getForm;
    SPS.registerField = registerField;
    SPS.renderIcon = renderIcon;
    SPS.escapeHtml = escapeHtml;
    SPS.escapeAttr = escapeAttr;

    // --- Automatic Lifecycle Hooks ---
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    window.addEventListener('load', initAll);

})(window.SPS);

