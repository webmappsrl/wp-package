/*
 * Shortcode [wmwp_map] (oc:8952): monta la mappa di wp-package (window.wmWpMap) su ogni elemento con
 * `data-wmwp-map`, scritto da includes/map.php: { height, tracks: [id], download_url } oppure { height, geojson }.
 * Le tracce si leggono da `api/ec/track/multiple` con wmWpTrackApi, come nella ricerca.
 */
(function () {
  'use strict';

  var i18n = (window.wp && window.wp.i18n) || {};
  var __ = i18n.__ || function (text) { return text; };

  function fail(element) {
    var note = document.createElement('p');
    note.className = 'wmwp-map-embed__error';
    note.textContent = __('The map is not available right now. Please try again later.', 'wp-package');
    element.textContent = '';
    element.appendChild(note);
  }

  function start(element) {
    var options;
    try {
      options = JSON.parse(element.getAttribute('data-wmwp-map') || '{}');
    } catch (error) {
      return;
    }
    if (!window.wmWpMap) {
      fail(element);
      return;
    }
    var height = options.height || 420;
    var labels = {
      zoomIn: __('Zoom in on the map', 'wp-package'),
      zoomOut: __('Zoom out of the map', 'wp-package'),
      fullScreen: __('Show the map full screen', 'wp-package'),
      exitFullScreen: __('Exit full screen', 'wp-package')
    };
    if (options.tracks && options.tracks.length && options.download_url && window.wmWpTrackApi && window.wmWpTrackMap) {
      window.wmWpTrackApi.create(options.download_url).features(options.tracks).then(function (result) {
        if (!result.features.length) {
          fail(element);
          return;
        }
        window.wmWpMap.mount(element, { geojson: window.wmWpTrackMap.collection(result.features, {}, { layerColors: options.layer_colors || [] }), height: height, fullScreen: true, labels: labels, tiles: options.tiles || null });
      }, function () {
        fail(element);
      });
    } else if (options.geojson) {
      window.wmWpMap.mount(element, { url: options.geojson, height: height, fullScreen: true, labels: labels, tiles: options.tiles || null });
    }
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-wmwp-map]'), start);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
