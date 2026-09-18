/**
 * Smart Portal Suite – Calculations Helper
 *
 * Generische, vollständig schema-getriebene Hilfsfunktionen für:
 *  - Zahlenformatierung nach deutschem Standard
 *  - Diskrete Werteberechnung für Schieberegler
 *  - Deklarative Umrechnungsanzeigen (schema.conversion)
 *  - Deklarative Referenz-Badges (schema.referenceBadge)
 *  - Slider-Track-Farbverlauf
 *
 * WICHTIG: Diese Datei enthält KEINERLEI formularspezifische Logik
 * (keine Feld-IDs, keine Brennstofffaktoren, keine Haushaltsreferenzwerte).
 * Alle solchen Daten gehören ausschließlich ins JSON-Schema des jeweiligen Formulars.
 *
 * @package SmartPortalSuite
 */

window.SPS = window.SPS || {};

window.SPS.Calculations = {

  /**
   * Formatiert eine Zahl nach deutschem Standard (Tausenderpunkte, Komma-Dezimale).
   *
   * @param {number|string} value     Numerischer Wert.
   * @param {number}        [decimals=0] Anzahl Nachkommastellen.
   * @return {string} Formatierter String, z. B. "15.000" oder "3,5".
   */
  formatGermanNumber: function (value, decimals) {
    var num = parseFloat(value);
    if (isNaN(num)) return String(value || '');
    return new Intl.NumberFormat('de-DE', {
      minimumFractionDigits: decimals || 0,
      maximumFractionDigits: decimals !== undefined ? decimals : 0
    }).format(num);
  },

  /**
   * Kombiniert einen formatierten Zahlenwert mit einer Einheit.
   * Delegiert die Zahlenformatierung an formatGermanNumber().
   *
   * @param {number|string} value     Numerischer Wert.
   * @param {string}        [suffix=''] Einheit (z. B. " kWh", " Cent/kWh").
   * @param {number}        [decimals=0] Nachkommastellen.
   * @return {string} Formatierter Anzeigestring.
   */
  formatUnit: function (value, suffix, decimals) {
    var formatted = this.formatGermanNumber(value, decimals);
    return suffix ? formatted + suffix : formatted;
  },

  /**
   * Ermittelt, ob ein Slider genau 2 bis maxThreshold diskrete Stufen hat.
   * Falls ja, gibt die Funktion ein Array aller Stufenwerte zurück,
   * damit die Form Engine anstelle des kontinuierlichen Sliders
   * klickbare Auswahlbuttons rendern kann.
   *
   * Beispiel: min=0, max=3, step=1 → [0, 1, 2, 3]  (4 Stufen, passt)
   * Beispiel: min=0, max=10, step=1 → []             (11 Stufen, zu viele)
   *
   * @param {number} min           Untere Grenze.
   * @param {number} max           Obere Grenze.
   * @param {number} step          Schrittweite.
   * @param {number} [maxThreshold=7] Maximale Anzahl Stufen, ab der noch diskrete
   *                               Buttons gerendert werden.
   * @return {number[]} Array der diskreten Stufenwerte oder leeres Array.
   */
  getSliderStepValues: function (min, max, step, maxThreshold) {
    min = Number(min);
    max = Number(max);
    step = Number(step || 1);
    maxThreshold = maxThreshold !== undefined ? Number(maxThreshold) : 7;

    if (
      !Number.isFinite(min) || !Number.isFinite(max) ||
      !Number.isFinite(step) || step <= 0 || max < min
    ) {
      return [];
    }

    var totalSteps = Math.floor((max - min) / step) + 1;
    if (totalSteps < 2 || totalSteps > maxThreshold) {
      return [];
    }

    var values = [];
    for (var i = 0; i < totalSteps; i++) {
      values.push(min + i * step);
    }
    return values;
  },

  /**
   * Berechnet einen Referenzwert aus einem deklarativen Badge-Objekt im Schema.
   *
   * Das Schema-Objekt `referenceBadge` sieht so aus:
   *   { "unitPerStep": 1500, "template": "Ø {count} Personen", "min": 1, "max": 8 }
   *
   * Die Funktion teilt den aktuellen Wert durch `unitPerStep` und rundet auf,
   * begrenzt auf [min, max]. Anschließend wird das Template befüllt.
   *
   * @param {number|string} value          Aktueller Feldwert (z. B. kWh).
   * @param {Object}        badgeConfig    Das `referenceBadge`-Objekt aus dem Schema.
   * @param {number}        badgeConfig.unitPerStep   Verbrauch pro Einheit (z. B. 1500 kWh/Person).
   * @param {string}        badgeConfig.template      Template-String mit `{count}`-Platzhalter.
   * @param {number}        [badgeConfig.min=1]       Untergrenze des berechneten Werts.
   * @param {number}        [badgeConfig.max=99]      Obergrenze des berechneten Werts.
   * @return {string|null} Befüllter Template-String oder null wenn Berechnung nicht möglich.
   */
  calculateReferenceBadge: function (value, badgeConfig) {
    if (!badgeConfig || !badgeConfig.unitPerStep || !badgeConfig.template) return null;

    var num = parseFloat(value);
    if (isNaN(num) || num <= 0) return null;

    var min = badgeConfig.min !== undefined ? Number(badgeConfig.min) : 1;
    var max = badgeConfig.max !== undefined ? Number(badgeConfig.max) : 99;
    var count = Math.max(min, Math.min(max, Math.ceil(num / badgeConfig.unitPerStep)));

    return badgeConfig.template.replace('{count}', count);
  },

  /**
   * Löst eine deklarative Umrechnung (schema.conversion) für den aktuellen Feldwert auf.
   *
   * Das Schema-Objekt `conversion` sieht so aus:
   * {
   *   "dependsOn": "energietraeger",      // ID des steuernden Feldes
   *   "factors": {
   *     "Öl":   { "factor": 9.8,  "unit": "Liter" },
   *     "Gas":  { "factor": 10.1, "unit": "m³" },
   *     "Wärmepumpe": {
   *       "factorField": "umrechnungsquote",  // Faktor aus anderem Antwortfeld lesen
   *       "defaultFactor": 3.5,
   *       "unit": "kWh (Strom)"
   *     }
   *   },
   *   "format":         "{primary} kWh / {converted} {unit}",  // wenn Umrechnung aktiv
   *   "fallbackFormat": "{primary} kWh"                        // wenn kein passender Faktor
   * }
   *
   * Platzhalter:
   *   {primary}   – formatierter Primärwert (z. B. "15.000")
   *   {converted} – formatierter umgerechneter Wert
   *   {unit}      – Einheit der Sekundärgröße (z. B. "Liter")
   *
   * @param {number|string} value           Aktueller Rohwert des Slider-Feldes (kWh).
   * @param {Object}        conversionConfig Das `conversion`-Objekt aus dem Schema.
   * @param {Object}        answers          Aktuelle Antworten der FormInstance ({fieldId: value}).
   * @return {string} Formatierten Anzeigestring für den Slider-Wertbereich.
   */
  resolveConversion: function (value, conversionConfig, answers) {
    if (!conversionConfig) return this.formatGermanNumber(value);

    var primaryNum = parseFloat(value);
    if (isNaN(primaryNum)) return String(value || '');

    var primaryFormatted = this.formatGermanNumber(primaryNum);

    // Steuerndes Feld auslesen
    var dependsOn = conversionConfig.dependsOn;
    var selectedKey = dependsOn ? (answers ? answers[dependsOn] : null) : null;

    // Passenden Faktor-Eintrag ermitteln
    var factorEntry = selectedKey && conversionConfig.factors
      ? conversionConfig.factors[selectedKey]
      : null;

    if (!factorEntry) {
      // Kein passender Energieträger → Fallback-Format
      var fallback = conversionConfig.fallbackFormat || '{primary}';
      return fallback.replace('{primary}', primaryFormatted);
    }

    // Umrechnungsfaktor ermitteln: statisch oder dynamisch aus einem Antwortfeld
    var factor = factorEntry.factor;
    if (!factor && factorEntry.factorField && answers) {
      var raw = parseFloat(answers[factorEntry.factorField]);
      factor = isNaN(raw) ? (factorEntry.defaultFactor || 1) : raw;
    }
    factor = factor || factorEntry.defaultFactor || 1;

    var unit = factorEntry.unit || '';
    var convertedNum = primaryNum / factor;
    var convertedFormatted = this.formatGermanNumber(convertedNum, 0);

    var template = conversionConfig.format || '{primary} / {converted} {unit}';
    return template
      .replace('{primary}', primaryFormatted)
      .replace('{converted}', convertedFormatted)
      .replace('{unit}', unit);
  },

  /**
   * Aktualisiert den CSS-Farbverlauf eines Range-Sliders, sodass der befüllte
   * Bereich in der Akzentfarbe (--sps-accent) und der leere Bereich dezent
   * transparent dargestellt wird.
   *
   * Setzt die CSS-Custom-Property `--sps-slider-fill` auf den berechneten
   * Prozentwert, damit der Verlauf in CSS weiterverarbeitet werden kann.
   *
   * @param {HTMLInputElement} sliderEl Das <input type="range">-Element.
   */
  updateSliderGradient: function (sliderEl) {
    if (!sliderEl) return;

    var min   = parseFloat(sliderEl.min)   || 0;
    var max   = parseFloat(sliderEl.max)   || 100;
    var value = parseFloat(sliderEl.value) || min;

    var pct = max > min ? ((value - min) / (max - min)) * 100 : 0;
    pct = Math.min(100, Math.max(0, pct));

    sliderEl.style.setProperty('--sps-slider-fill', pct.toFixed(2) + '%');

    // Direktes Gradient-Styling als Fallback für Browser ohne Custom-Property-Support
    var fillColor   = getComputedStyle(sliderEl).getPropertyValue('--sps-accent').trim()   || '#39baff';
    var trackColor  = getComputedStyle(sliderEl).getPropertyValue('--sps-slider-track').trim() || 'rgba(255,255,255,0.15)';
    sliderEl.style.background =
      'linear-gradient(to right, ' +
      fillColor + ' 0%, ' +
      fillColor + ' ' + pct.toFixed(2) + '%, ' +
      trackColor + ' ' + pct.toFixed(2) + '%, ' +
      trackColor + ' 100%)';
  }

};
