window.SPS = window.SPS || {};

SPS.FormEngine = (function() {
    let config = null;
    let schema = null;
    let currentStepIndex = 0;
    let answers = {};
    let container = null;
    let innerContainer = null;
    let progressBar = null;

    // --- State Management ---
    function setAnswer(key, value) {
        if (value === undefined || value === null || value === '') {
            delete answers[key];
        } else {
            answers[key] = value;
        }
        updateDynamicConfigs();
        updateSummary();
    }

    function getAnswer(key) {
        return answers[key];
    }

    // --- Navigation Logic ---
    function isVisible(step) {
        if (!step.showIf) return true;
        const condition = step.showIf;
        const fieldValue = answers[condition.field];
        
        switch (condition.op) {
            case 'eq': return fieldValue == condition.value;
            case 'neq': return fieldValue != condition.value;
            case 'gt': return parseFloat(fieldValue) > parseFloat(condition.value);
            case 'gte': return parseFloat(fieldValue) >= parseFloat(condition.value);
            case 'lt': return parseFloat(fieldValue) < parseFloat(condition.value);
            case 'lte': return parseFloat(fieldValue) <= parseFloat(condition.value);
            case 'in': return Array.isArray(fieldValue) ? fieldValue.includes(condition.value) : fieldValue === condition.value;
            default: return true;
        }
    }

    function findNextVisibleStep(startIndex) {
        for (let i = startIndex + 1; i < schema.steps.length; i++) {
            if (isVisible(schema.steps[i])) return i;
        }
        return -1;
    }

    function findPrevVisibleStep(startIndex) {
        for (let i = startIndex - 1; i >= 0; i--) {
            if (isVisible(schema.steps[i])) return i;
        }
        return -1;
    }

    function validateStep(index) {
        const step = schema.steps[index];
        if (step.required) {
            const val = answers[step.id];
            if (val === undefined || val === null || val === '' || (Array.isArray(val) && val.length === 0)) {
                showError(index, 'Bitte füllen Sie dieses Feld aus.');
                return false;
            }
        }
        hideError(index);
        return true;
    }

    function showError(index, msg) {
        const errEl = document.getElementById(`error-${index}`);
        if (errEl) {
            errEl.textContent = msg;
            errEl.style.display = 'block';
        }
    }

    function hideError(index) {
        const errEl = document.getElementById(`error-${index}`);
        if (errEl) errEl.style.display = 'none';
    }

    function goTo(index) {
        if (index < 0 || index >= schema.steps.length) return;
        
        const currentEl = document.getElementById(`step-${currentStepIndex}`);
        if (currentEl) currentEl.classList.remove('active');

        currentStepIndex = index;
        
        const nextEl = document.getElementById(`step-${currentStepIndex}`);
        if (nextEl) nextEl.classList.add('active');

        updateProgressBar();
    }

    function next() {
        if (!validateStep(currentStepIndex)) return;
        const nextIdx = findNextVisibleStep(currentStepIndex);
        if (nextIdx !== -1) goTo(nextIdx);
    }

    function prev() {
        const prevIdx = findPrevVisibleStep(currentStepIndex);
        if (prevIdx !== -1) goTo(prevIdx);
    }

    function updateProgressBar() {
        if (!progressBar) return;
        const percent = (currentStepIndex / (schema.steps.length - 1)) * 100;
        progressBar.style.width = `${percent}%`;
    }

    function updateDynamicConfigs() {
        schema.steps.forEach(step => {
            if (step.dynamicConfig && isVisible(step)) {
                const depValue = answers[step.dynamicConfig.dependsOn];
                if (depValue && step.dynamicConfig.configs[depValue]) {
                    const cfg = step.dynamicConfig.configs[depValue];
                    const sliderEl = document.getElementById(`input-${step.id}`);
                    const valEl = document.getElementById(`val-${step.id}`);
                    if (sliderEl) {
                        sliderEl.min = cfg.min;
                        sliderEl.max = cfg.max;
                        sliderEl.step = cfg.step;
                        // Don't override user value if it exists, only clamp it
                        let currentVal = parseFloat(sliderEl.value) || cfg.value;
                        currentVal = Math.max(cfg.min, Math.min(cfg.max, currentVal));
                        sliderEl.value = currentVal;
                        if (valEl) valEl.textContent = currentVal + (cfg.suffix || '');
                    }
                }
            }
        });
    }

    function updateSummary() {
        const summaryContainer = document.getElementById('sps-summary-content');
        if (!summaryContainer) return;

        let html = '<div class="sps-summary-list">';
        schema.steps.forEach(step => {
            if (step.id && step.id !== 'summary' && step.id !== 'consent' && isVisible(step)) {
                let val = answers[step.id];
                if (val !== undefined) {
                    if (Array.isArray(val)) val = val.join(', ');
                    if (step.type === 'slider' && step.suffix) val += step.suffix;
                    html += `
                        <div class="sps-summary-item">
                            <div class="sps-summary-label">${step.label || step.id}</div>
                            <div class="sps-summary-value">${val}</div>
                        </div>
                    `;
                }
            }
        });
        html += '</div>';
        summaryContainer.innerHTML = html;
    }

    // --- Renderers ---
    const renderers = {
        radio: (step) => {
            let html = '<div class="sps-radio-grid">';
            step.choices.forEach((choice, i) => {
                const val = choice.value !== undefined ? choice.value : choice.text;
                html += `
                    <label class="sps-radio-card">
                        <input type="radio" name="${step.id}" value="${val}" onchange="SPS.FormEngine.setAnswer('${step.id}', this.value); setTimeout(() => SPS.FormEngine.next(), 300);">
                        <div class="sps-radio-content">
                            ${choice.icon ? `<div class="sps-icon sps-icon-${choice.icon}"></div>` : ''}
                            <div class="sps-radio-text">${choice.text}</div>
                            ${choice.subtitle ? `<div class="sps-radio-subtitle">${choice.subtitle}</div>` : ''}
                        </div>
                    </label>
                `;
            });
            html += '</div>';
            return html;
        },
        slider: (step) => {
            const cfg = step.dynamicConfig && answers[step.dynamicConfig.dependsOn] 
                ? step.dynamicConfig.configs[answers[step.dynamicConfig.dependsOn]] || step
                : step;
                
            return `
                <div class="sps-slider-container">
                    <div class="sps-slider-value" id="val-${step.id}">${cfg.value}${cfg.suffix || ''}</div>
                    <input type="range" id="input-${step.id}" class="sps-slider" 
                           min="${cfg.min}" max="${cfg.max}" step="${cfg.step}" value="${cfg.value}"
                           oninput="document.getElementById('val-${step.id}').textContent = this.value + '${cfg.suffix || ''}'; SPS.FormEngine.setAnswer('${step.id}', this.value);">
                </div>
            `;
        },
        text: (step) => {
            return `<input type="${step.type}" id="input-${step.id}" class="sps-input" placeholder="${step.placeholder || ''}" 
                    maxlength="${step.maxLength || ''}"
                    oninput="SPS.FormEngine.setAnswer('${step.id}', this.value);">`;
        },
        textarea: (step) => {
            return `<textarea id="input-${step.id}" class="sps-input sps-textarea" placeholder="${step.placeholder || ''}" 
                    maxlength="${step.maxLength || ''}" rows="5"
                    oninput="SPS.FormEngine.setAnswer('${step.id}', this.value);"></textarea>`;
        },
        date: (step) => {
            return `<input type="date" id="input-${step.id}" class="sps-input" onchange="SPS.FormEngine.setAnswer('${step.id}', this.value);">`;
        },
        group: (step) => {
            let html = '<div class="sps-group">';
            step.fields.forEach(f => {
                if (f.type === 'row') {
                    html += '<div class="sps-group-row">';
                    f.fields.forEach(rf => {
                        html += `<div style="flex: ${rf.flex || '1'};">
                            <input type="${rf.type === 'number' ? 'number' : 'text'}" id="input-${rf.id}" class="sps-input" placeholder="${rf.placeholder || ''}" oninput="SPS.FormEngine.setAnswer('${rf.id}', this.value);">
                        </div>`;
                    });
                    html += '</div>';
                } else {
                    html += `<input type="${f.type === 'number' ? 'number' : (f.type === 'email' ? 'email' : 'text')}" id="input-${f.id}" class="sps-input" placeholder="${f.placeholder || ''}" oninput="SPS.FormEngine.setAnswer('${f.id}', this.value);">`;
                }
            });
            html += '</div>';
            return html;
        },
        addressFull: (step) => {
            // Simplified stub for address full (would include Nominatim autocomplete in a full version)
            return renderers.group({
                id: step.id,
                fields: [
                    { id: step.id + "_plz", type: "number", placeholder: "PLZ" },
                    { id: step.id + "_ort", type: "text", placeholder: "Ort" },
                    { type: "row", fields: [
                        { id: step.id + "_strasse", type: "text", placeholder: "Straße", flex: "1" },
                        { id: step.id + "_hausnummer", type: "text", placeholder: "Nr.", flex: "0 0 80px" }
                    ]}
                ]
            });
        },
        checkboxMulti: (step) => {
            let html = '<div class="sps-checkbox-grid">';
            step.choices.forEach((choice, i) => {
                const val = choice.id || choice.text;
                html += `
                    <label class="sps-checkbox-card">
                        <input type="checkbox" name="${step.id}" value="${val}" onchange="
                            const checked = Array.from(document.querySelectorAll('input[name=\\'${step.id}\\']:checked')).map(cb => cb.value);
                            SPS.FormEngine.setAnswer('${step.id}', checked);
                        ">
                        <div class="sps-radio-content">
                            <div class="sps-radio-text">${choice.text}</div>
                            ${choice.subtitle ? `<div class="sps-radio-subtitle">${choice.subtitle}</div>` : ''}
                        </div>
                    </label>
                `;
            });
            html += '</div>';
            return html;
        },
        upload: (step) => {
            return `
                <div class="sps-upload-container">
                    <div class="sps-upload-box">
                        <input type="file" id="input-${step.id}" class="sps-file-input" multiple onchange="SPS.FormEngine.setAnswer('${step.id}', this.files.length > 0 ? this.files[0].name + ' (ausgewählt)' : null);">
                        <label for="input-${step.id}" class="sps-btn sps-btn-outline">Datei auswählen</label>
                        <p class="sps-upload-info">PDF, JPG, PNG (max. 10 MB)</p>
                    </div>
                </div>
            `;
        },
        consent: (step) => {
            return `
                <label class="sps-consent-label">
                    <input type="checkbox" id="input-${step.id}" required onchange="SPS.FormEngine.setAnswer('${step.id}', this.checked ? 'Zugestimmt' : null);">
                    <span class="sps-consent-text">Ich stimme der Verarbeitung meiner Daten gemäß der <a href="/datenschutz" target="_blank">Datenschutzerklärung</a> zu.</span>
                </label>
            `;
        },
        summary: (step) => {
            return `<div id="sps-summary-content"></div>`;
        }
    };

    function renderStep(step, index) {
        const type = step.type === 'address-full' ? 'addressFull' : step.type.replace(/-([a-z])/g, g => g[1].toUpperCase());
        const renderer = renderers[type] || renderers.text;
        
        let html = `
            <div class="sps-step" id="step-${index}">
                ${step.label ? `<h2 class="sps-question">${step.label}</h2>` : ''}
                ${step.desc ? `<div class="sps-desc">${step.desc}</div>` : ''}
                ${step.reason ? `<div class="sps-reason-tooltip">? <span class="sps-reason-text">${step.reason}</span></div>` : ''}
                
                <div class="sps-input-wrapper">
                    ${renderer(step)}
                </div>
                
                <div class="sps-error" id="error-${index}" style="display:none;"></div>

                <div class="sps-nav-buttons">
                    ${index > 0 ? `<button type="button" class="sps-btn sps-btn-back" onclick="SPS.FormEngine.prev()">Zurück</button>` : '<div></div>'}
                    ${index < schema.steps.length - 1 
                        ? `<button type="button" class="sps-btn" onclick="SPS.FormEngine.next()">Weiter</button>` 
                        : `<button type="button" class="sps-btn sps-btn-submit" onclick="SPS.FormEngine.submit()">Absenden</button>`
                    }
                </div>
            </div>
        `;
        return html;
    }

    function init(configData) {
        config = configData;
        container = document.getElementById(config.containerId);
        if (!container) return;

        container.innerHTML = `
            <div class="sps-form-wrapper">
                <div class="sps-progress-container"><div class="sps-progress-bar" id="sps-progress-bar"></div></div>
                <div id="sps-form-inner"></div>
            </div>
        `;
        
        innerContainer = document.getElementById('sps-form-inner');
        progressBar = document.getElementById('sps-progress-bar');

        if (config.schema) {
            schema = config.schema;
            renderAllSteps();
        } else if (config.schemaUrl) {
            // Fallback to fetch if schema wasn't localized
            fetch(config.schemaUrl)
                .then(res => res.json())
                .then(data => {
                    schema = data;
                    renderAllSteps();
                })
                .catch(err => {
                    console.error("SPS Schema load error", err);
                    container.innerHTML = "<div class='sps-error'>Formular konnte nicht geladen werden.</div>";
                });
        }
    }

    function renderAllSteps() {
        let html = '';
        schema.steps.forEach((step, idx) => {
            html += renderStep(step, idx);
        });
        innerContainer.innerHTML = html;
        goTo(0);
    }

    function submit() {
        if (!validateStep(currentStepIndex)) return;

        const btn = document.querySelector('.sps-btn-submit');
        if (btn) {
            btn.disabled = true;
            btn.textContent = "Wird verarbeitet...";
        }

        const payload = {
            action: 'sps_submit_form',
            nonce: config.nonce,
            form_type: schema.form_type,
            answers: answers
        };

        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                innerContainer.innerHTML = `<div class="sps-success-msg"><h2>Vielen Dank!</h2><p>Ihre Anfrage wurde erfolgreich übermittelt.</p></div>`;
            } else {
                alert("Fehler: " + (data.data || "Unbekannter Fehler"));
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = "Erneut versuchen";
                }
            }
        })
        .catch(err => {
            console.error("Submit error", err);
            alert("Es ist ein Netzwerkfehler aufgetreten.");
            if (btn) {
                btn.disabled = false;
                btn.textContent = "Erneut versuchen";
            }
        });
    }

    return {
        init,
        next,
        prev,
        goTo,
        setAnswer,
        getAnswer,
        submit
    };
})();
