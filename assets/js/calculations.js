/**
 * Smart Portal Suite - Calculations Helper
 */
window.SPS = window.SPS || {};

window.SPS.Calculations = {
  /**
   * Format numbers with units.
   *
   * @param {number} value Numeric value.
   * @param {string} suffix Unit suffix (e.g. ' kWh').
   * @return {string}
   */
  formatUnit: function(value, suffix) {
    var formatted = new Intl.NumberFormat('de-DE').format(value);
    return suffix ? formatted + suffix : formatted;
  }
};
