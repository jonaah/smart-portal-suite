/**
 * Smart Portal Suite - OpenStreetMap / Nominatim Autocomplete Helper
 */
window.SPS = window.SPS || {};

window.SPS.Autocomplete = {
  /**
   * Search addresses via Nominatim OSM API.
   *
   * @param {string} query Search query string.
   * @param {Function} callback Result handler.
   */
  search: function(query, callback) {
    if (!query || query.length < 3) {
      if (typeof callback === 'function') callback([]);
      return;
    }

    var endpoint = 'https://nominatim.openstreetmap.org/search?format=json&countrycodes=de&limit=5&q=' + encodeURIComponent(query);

    fetch(endpoint, {
      headers: {
        'Accept': 'application/json'
      }
    })
    .then(function(response) {
      return response.json();
    })
    .then(function(data) {
      if (typeof callback === 'function') {
        callback(data);
      }
    })
    .catch(function(err) {
      console.warn('[SPS Autocomplete] Search error:', err);
      if (typeof callback === 'function') callback([]);
    });
  }
};
