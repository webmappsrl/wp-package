/*
 * Tracce per la mappa dei risultati (oc:8952): dalle feature di wmWpTrackApi alla FeatureCollection che la mappa
 * (assets/dist/wp-package-map.js, il componente FeatureCollectionMap di wm-package) disegna. Le proprietà sono quelle
 * che il componente legge: `strokeColor` e `strokeWidth` per la linea, `lineLabel` per l'etichetta lungo la linea,
 * `tooltip` al passaggio del mouse; `id` per il riquadro al clic (popup della mappa) e `url`, l'indirizzo della scheda,
 * su cui la mappa mette il puntatore a mano senza aprirlo. Nel browser è `window.wmWpTrackMap`.
 *
 *   wmWpTrackMap.collection(features, info, style, selected) → FeatureCollection
 *   info:  id → { name, code, url } (dai risultati della ricerca); style: { color, width, layerColors }
 *   layerColors: [{ id, color }] dei layer dell'app, nel loro ordine (config.json, includes/map.php): una traccia prende
 *   il colore del primo layer colorato a cui appartiene (`properties.layers`), come nella webapp; senza, `color`.
 *   selected: id dei percorsi selezionati. Se fra le feature ce n'è almeno uno, i selezionati restano come sempre più
 *   un contorno bianco (`casing`), e gli altri diventano sfondo: tratteggiati blu ardesia, più sottili, senza
 *   etichetta e con `context: true`, che per il componente vuol dire «disegnata sotto le altre e fuori
 *   dall'inquadratura» (oc:8747). La mappa quindi si inquadra sui selezionati.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.wmWpTrackMap = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  // Linee rosse da 4px, come la mappa dei risultati della tavola 2 del wireframe (`.res-map`)
  var COLOR = '#DE1B0D';
  var WIDTH = 4;
  // Percorsi selezionati in primo piano: contorno bianco sotto la linea rossa. Gli altri risultati come i «sentieri
  // collegati» della mappa del dettaglio nella tavola del wireframe (`.lg-near`: tratteggio da 2,5px, #5B7A99): un
  // grigio, provato prima, sulla carta OpenStreetMap si confondeva con strade e sentieri
  var CASING = { color: '#FFFFFF', width: 8 };
  var CONTEXT_COLOR = '#5B7A99';
  var CONTEXT_WIDTH = 2.5;
  var CONTEXT_DASH = [7, 5];

  function text(value) {
    return value === null || value === undefined ? '' : String(value).trim();
  }

  function layerColor(feature, layerColors) {
    var layers = (feature.properties && feature.properties.layers) || [];
    for (var i = 0; i < (layerColors || []).length; i++) {
      if (layers.indexOf(layerColors[i].id) !== -1) {
        return layerColors[i].color;
      }
    }
    return '';
  }

  function collection(features, info, style, selected) {
    var color = (style && style.color) || COLOR;
    var width = (style && style.width) || WIDTH;
    var layerColors = (style && style.layerColors) || [];
    var list = features || [];
    var chosen = {};
    (selected || []).forEach(function (id) { chosen[String(id)] = true; });
    var focus = list.some(function (feature) { return chosen[String(feature.properties.id)]; });
    return {
      type: 'FeatureCollection',
      features: list.map(function (feature) {
        var id = feature.properties.id;
        var data = (info && info[id]) || {};
        var name = text(data.name);
        var code = text(data.code) || text(feature.properties.ref);
        var properties = {
          id: id,
          name: name,
          tooltip: [code, name].filter(Boolean).join(' · ') || String(id),
          lineLabel: code,
          strokeColor: layerColor(feature, layerColors) || color,
          strokeWidth: width,
          url: data.url || null
        };
        if (focus && chosen[String(id)]) {
          properties.casing = { color: CASING.color, width: CASING.width };
        } else if (focus) {
          properties.context = true;
          properties.strokeColor = CONTEXT_COLOR;
          properties.strokeWidth = CONTEXT_WIDTH;
          properties.strokeDash = CONTEXT_DASH.slice();
          properties.lineLabel = '';
        }
        return { type: 'Feature', geometry: feature.geometry, properties: properties };
      })
    };
  }

  return { collection: collection, COLOR: COLOR, WIDTH: WIDTH };
});
