/**
 * Smart Portal Suite - OpenStreetMap / Nominatim Autocomplete Helper
 */
window.SPS = window.SPS || {};

window.SPS.Autocomplete = {
  /**
   * Search addresses via Nominatim OSM API.
   * Includes addressdetails=1 to retrieve structured address objects.
   *
   * @param {string} query Search query string.
   * @param {Function} callback Result handler.
   */
  search: function(query, callback) {
    if (!query || query.length < 3) {
      if (typeof callback === 'function') callback([]);
      return;
    }

    var endpoint = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&countrycodes=de&limit=5&q=' + encodeURIComponent(query);

    fetch(endpoint, {
      headers: {
        'Accept': 'application/json'
      }
    })
    .then(function(response) {
      if (!response.ok) throw new Error('OSM response status: ' + response.status);
      return response.json();
    })
    .then(function(data) {
      if (typeof callback === 'function') {
        callback(Array.isArray(data) ? data : []);
      }
    })
    .catch(function(err) {
      console.warn('[SPS Autocomplete] Search error:', err);
      if (typeof callback === 'function') callback([]);
    });
  },

  /**
   * Context-aware street search that incorporates PLZ and/or City if available.
   *
   * @param {string} street Street query (min. 3 characters).
   * @param {string} [plz] 5-digit postal code.
   * @param {string} [city] City name.
   * @param {Function} callback Result handler.
   */
  searchStreet: function(street, plz, city, callback) {
    if (!street || street.trim().length < 3) {
      if (typeof callback === 'function') callback([]);
      return;
    }

    var query = street.trim();
    var cleanPlz = (plz || '').trim();
    var cleanCity = (city || '').trim();

    if (cleanPlz && cleanCity && cleanCity !== 'Prüfe PLZ...') {
      query += ', ' + cleanPlz + ' ' + cleanCity;
    } else if (cleanPlz) {
      query += ', ' + cleanPlz;
    } else if (cleanCity && cleanCity !== 'Prüfe PLZ...') {
      query += ', ' + cleanCity;
    }

    this.search(query, callback);
  },

  /**
   * Look up city name for a given 5-digit German postal code (PLZ).
   * Primary provider: api.zippopotam.us
   * Fallback provider: nominatim.openstreetmap.org (postalcode search)
   *
   * @param {string} plz 5-digit postal code.
   * @param {Function} callback Callback receiving (cityName|null).
   */
  lookupPlz: function(plz, callback) {
    var cleanPlz = String(plz || '').trim();
    if (!/^\d{5}$/.test(cleanPlz)) {
      if (typeof callback === 'function') callback(null);
      return;
    }

    // 1. Primary: Zippopotam
    fetch('https://api.zippopotam.us/de/' + encodeURIComponent(cleanPlz))
      .then(function(res) {
        if (!res.ok) throw new Error('Zippopotam returned ' + res.status);
        return res.json();
      })
      .then(function(data) {
        if (data && data.places && data.places.length > 0 && data.places[0]['place name']) {
          if (typeof callback === 'function') {
            callback(data.places[0]['place name']);
          }
        } else {
          throw new Error('No place in Zippopotam');
        }
      })
      .catch(function() {
        // 2. Fallback: Nominatim postal code search
        var fallbackUrl = 'https://nominatim.openstreetmap.org/search?format=json&postalcode=' + encodeURIComponent(cleanPlz) + '&countrycodes=de&limit=1&addressdetails=1';
        fetch(fallbackUrl, {
          headers: { 'Accept': 'application/json' }
        })
        .then(function(res) {
          if (!res.ok) throw new Error('Nominatim returned ' + res.status);
          return res.json();
        })
        .then(function(items) {
          if (Array.isArray(items) && items.length > 0 && items[0].address) {
            var addr = items[0].address;
            var city = addr.city || addr.town || addr.village || addr.municipality || '';
            if (typeof callback === 'function') {
              callback(city || null);
            }
          } else {
            if (typeof callback === 'function') callback(null);
          }
        })
        .catch(function(err) {
          console.warn('[SPS Autocomplete] PLZ lookup error:', err);
          if (typeof callback === 'function') callback(null);
        });
      });
  }
};
