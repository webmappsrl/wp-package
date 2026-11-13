/*
 * Tracce per la mappa dei risultati (oc:8952): dalle feature di wmWpTrackApi alla FeatureCollection che la mappa
 * (assets/dist/wp-package-map.js, il componente FeatureCollectionMap di wm-package) disegna. Le proprietà sono quelle
 * che il componente legge: `strokeColor` e `strokeWidth` per la linea, `lineLabel` per l'etichetta lungo la linea,
 * `tooltip` al passaggio del mouse; `id` per il riquadro al clic (popup della mappa) e `url`, l'indirizzo della scheda,
 * su cui la mappa mette il puntatore a mano senza aprirlo. Nel browser è `window.wmWpTrackMap`.
 *
 *   wmWpTrackMap.collection(features, info, style, selected) → FeatureCollection
 *   wmWpTrackMap.detail(features, { tracks, points }) → FeatureCollection della mappa del dettaglio (oc:8969)
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
  // Verde della partenza: come il cerchio di GeoHub, più scuro perché stacchi dal bordo bianco (4,2:1, WCAG 1.4.11)
  var START = '#1F8F35';
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

  // Mappa del dettaglio di una traccia o di un POI (oc:8969): ogni traccia con lo stile che sceglie il tema (la
  // principale, le collegate tratteggiate e `context`, quelle di un altro tipo col loro colore), nell'ordine dato, poi i
  // punti. Un punto con `number` è più grande e porta il numero, che la mappa scrive al centro (map/src/index.js).
  // Con `url` il clic apre la scheda (`clickAction: 'link'` del componente). Una traccia con `ends` porta partenza e
  // arrivo (dopo le tracce, prima dei punti), come la mappa di GeoHub: `endpoint: 'start'|'end'` nelle properties, che
  // la mappa disegna (map/src/index.js); in un anello, con la fine entro 50 m dall'inizio, solo la partenza. Il cerchio è
  // grande quanto quello di GeoHub (raggio 15, circa 30 px) e più dei punti (raggio 9): un punto di interesse sopra la
  // partenza lascia vedere un anello largo intorno (Seleni: la partenza è a 5 m dal POI 3).
  //   spec.tracks: [{ id, color, width, dash, context, label, tooltip, url, ends: { start, end } }]
  //   spec.points: [{ coordinates: [lon, lat], color, number, tooltip, url }]
  function detail(features, spec) {
    var byId = {};
    (features || []).forEach(function (feature) { byId[String(feature.properties.id)] = feature; });
    var out = [];
    var ends = [];
    ((spec && spec.tracks) || []).forEach(function (track) {
      var feature = byId[String(track.id)];
      if (!feature) {
        return;
      }
      var properties = {
        id: feature.properties.id,
        tooltip: text(track.tooltip) || text(feature.properties.ref) || String(feature.properties.id),
        lineLabel: text(track.label),
        strokeColor: text(track.color) || COLOR,
        strokeWidth: track.width || WIDTH
      };
      if (track.dash && track.dash.length) {
        properties.strokeDash = track.dash.slice();
      }
      if (track.context) {
        properties.context = true;
      }
      if (text(track.url)) {
        properties.clickAction = 'link';
        properties.link = text(track.url);
      }
      out.push({ type: 'Feature', geometry: feature.geometry, properties: properties });
      if (track.ends) {
        ends = ends.concat(endpoints(feature.geometry, track.ends));
      }
    });
    out = out.concat(ends);
    ((spec && spec.points) || []).forEach(function (point) {
      var coordinates = point && point.coordinates;
      if (!coordinates || !isFinite(coordinates[0]) || !isFinite(coordinates[1]) || typeof coordinates[0] !== 'number' || typeof coordinates[1] !== 'number') {
        return;
      }
      var number = text(point.number);
      var properties = {
        tooltip: text(point.tooltip),
        pointFillColor: text(point.color) || '#1A1A1A',
        pointStrokeColor: '#FFFFFF',
        pointStrokeWidth: 2,
        pointRadius: number ? 9 : 7
      };
      if (number) {
        properties.number = number;
      }
      if (text(point.url)) {
        properties.clickAction = 'link';
        properties.link = text(point.url);
      }
      out.push({ type: 'Feature', geometry: { type: 'Point', coordinates: [coordinates[0], coordinates[1]] }, properties: properties });
    });
    return { type: 'FeatureCollection', features: out };
  }

  // Distanza in metri fra due punti [lon, lat] (formula dell'emisenoverso)
  function meters(a, b) {
    var rad = Math.PI / 180;
    var dLat = (b[1] - a[1]) * rad;
    var dLon = (b[0] - a[0]) * rad;
    var h = Math.pow(Math.sin(dLat / 2), 2) + Math.cos(a[1] * rad) * Math.cos(b[1] * rad) * Math.pow(Math.sin(dLon / 2), 2);
    return 2 * 6371000 * Math.asin(Math.min(1, Math.sqrt(h)));
  }

  // Punti di partenza e arrivo di una LineString o MultiLineString (prima e ultima linea)
  function endpoints(geometry, labels) {
    var lines = geometry && geometry.type === 'MultiLineString' ? geometry.coordinates
      : (geometry && geometry.type === 'LineString' ? [geometry.coordinates] : []);
    lines = (lines || []).filter(function (line) { return line && line.length; });
    if (!lines.length) {
      return [];
    }
    var first = lines[0][0];
    var last = lines[lines.length - 1][lines[lines.length - 1].length - 1];
    var point = function (coordinates, kind, label) {
      return {
        type: 'Feature',
        geometry: { type: 'Point', coordinates: [coordinates[0], coordinates[1]] },
        properties: { endpoint: kind, tooltip: text(label), pointFillColor: kind === 'start' ? START : '#FFFFFF', pointStrokeColor: '#FFFFFF', pointStrokeWidth: 3, pointRadius: 15 }
      };
    };
    var out = [point(first, 'start', labels.start)];
    if (meters(first, last) > 50) {
      out.push(point(last, 'end', labels.end));
    }
    return out;
  }

  return { collection: collection, detail: detail, COLOR: COLOR, WIDTH: WIDTH };
});
