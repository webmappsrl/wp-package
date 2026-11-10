/*
 * Scarico di più percorsi (oc:8952): unisce le risposte di `api/ec/track/multiple` e le converte in GPX, KML o
 * GeoJSON. Niente DOM e niente rete, così si prova con `node --test tests/js/*.test.js`. Nel browser è
 * `window.wmWpTrackExport`, in Node `require()`.
 *
 * Le tracce arrivano da assets/js/track-api.js. La conversione si fa qui e non con le rotte GPX e KML dell'API, che
 * sul dev rispondono 500 (09/11): è come fa la webapp, che converte nel browser il GeoJSON della traccia. Le
 * librerie della webapp non si usano: per linee con nome e quota bastano poche righe, e il file resta fuori dalla
 * build della mappa (map/), che si carica solo per la vista mappa.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.wmWpTrackExport = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var FORMATS = {
    gpx: { mime: 'application/gpx+xml', extension: 'gpx' },
    kml: { mime: 'application/vnd.google-earth.kml+xml', extension: 'kml' },
    geojson: { mime: 'application/geo+json', extension: 'geojson' }
  };

  // Linee di una geometria (LineString o MultiLineString); le altre non sono percorsi
  function lines(geometry) {
    if (!geometry || !Array.isArray(geometry.coordinates)) {
      return [];
    }
    if (geometry.type === 'LineString') {
      return [geometry.coordinates];
    }
    if (geometry.type === 'MultiLineString') {
      return geometry.coordinates.filter(Array.isArray);
    }
    return [];
  }

  // Quota vera solo se almeno un punto ce l'ha diversa da 0: lo shard scrive 0 dove il DEM non è calcolato, e una
  // quota 0 direbbe «a livello del mare»
  function hasElevation(parts) {
    return parts.some(function (line) {
      return line.some(function (point) { return point.length > 2 && Number(point[2]) !== 0 && isFinite(Number(point[2])); });
    });
  }

  // Le risposte di più richieste, nell'ordine degli id scelti e senza doppioni. `missing`: gli id senza tracciato
  // (l'API non li restituisce, o li restituisce senza geometria)
  function merge(collections, ids) {
    var byId = {};
    (collections || []).forEach(function (collection) {
      ((collection && collection.features) || []).forEach(function (feature) {
        var id = feature && feature.properties ? String(feature.properties.id) : '';
        if (id && !byId[id] && lines(feature.geometry).length) {
          byId[id] = feature;
        }
      });
    });
    var features = [];
    var missing = [];
    ids.forEach(function (id) {
      if (byId[String(id)]) {
        features.push(byId[String(id)]);
      } else {
        missing.push(String(id));
      }
    });
    return { collection: { type: 'FeatureCollection', features: features }, missing: missing };
  }

  function escapeXml(text) {
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function nameOf(feature, names) {
    var id = feature.properties.id;
    var name = names && names[id];
    return String(name || feature.properties.ref || id).trim();
  }

  function toGpx(collection, names) {
    var out = ['<?xml version="1.0" encoding="UTF-8"?>\n<gpx version="1.1" creator="wp-package" xmlns="http://www.topografix.com/GPX/1/1">'];
    collection.features.forEach(function (feature) {
      var parts = lines(feature.geometry);
      var ele = hasElevation(parts);
      out.push('<trk><name>' + escapeXml(nameOf(feature, names)) + '</name>');
      parts.forEach(function (line) {
        out.push('<trkseg>' + line.map(function (point) {
          var attrs = '<trkpt lat="' + point[1] + '" lon="' + point[0] + '"';
          return ele && point.length > 2 ? attrs + '><ele>' + point[2] + '</ele></trkpt>' : attrs + '/>';
        }).join('') + '</trkseg>');
      });
      out.push('</trk>');
    });
    out.push('</gpx>\n');
    return out.join('\n');
  }

  function kmlCoordinates(line, ele) {
    return '<LineString><coordinates>' + line.map(function (point) {
      return point[0] + ',' + point[1] + (ele && point.length > 2 ? ',' + point[2] : '');
    }).join(' ') + '</coordinates></LineString>';
  }

  function toKml(collection, names, title) {
    var out = ['<?xml version="1.0" encoding="UTF-8"?>\n<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>' + escapeXml(title || '') + '</name>'];
    collection.features.forEach(function (feature) {
      var parts = lines(feature.geometry);
      var ele = hasElevation(parts);
      var geometry = parts.map(function (line) { return kmlCoordinates(line, ele); }).join('');
      out.push('<Placemark><name>' + escapeXml(nameOf(feature, names)) + '</name>' +
        (parts.length > 1 ? '<MultiGeometry>' + geometry + '</MultiGeometry>' : geometry) + '</Placemark>');
    });
    out.push('</Document></kml>\n');
    return out.join('\n');
  }

  // Solo geometria, id, nome e codice: la risposta dell'API porta anche descrizioni e gallerie, che in un file di
  // tracce pesano senza servire
  function toGeoJson(collection, names) {
    return JSON.stringify({
      type: 'FeatureCollection',
      features: collection.features.map(function (feature) {
        var parts = lines(feature.geometry);
        var ele = hasElevation(parts);
        var strip = function (line) { return line.map(function (point) { return ele ? point : point.slice(0, 2); }); };
        var geometry = feature.geometry.type === 'LineString'
          ? { type: 'LineString', coordinates: strip(parts[0]) }
          : { type: 'MultiLineString', coordinates: parts.map(strip) };
        return {
          type: 'Feature',
          properties: { id: feature.properties.id, name: nameOf(feature, names), ref: feature.properties.ref || '' },
          geometry: geometry
        };
      })
    });
  }

  function convert(collection, format, names, title) {
    var spec = FORMATS[format];
    if (!spec) {
      throw new Error('wp-package: unknown format ' + format);
    }
    var content = format === 'gpx' ? toGpx(collection, names) : format === 'kml' ? toKml(collection, names, title) : toGeoJson(collection, names);
    return { content: content, mime: spec.mime, extension: spec.extension };
  }

  // Nome per un file: minuscolo, senza accenti, solo lettere e numeri separati da trattini, al massimo 80 caratteri
  function slug(text) {
    return String(text || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 80)
      .replace(/-+$/, '');
  }

  // Con un solo percorso il nome del percorso («andala-segura-…-c-104-a.gpx»), con più il nome di base e il numero
  function fileName(base, count, extension, name) {
    var single = count === 1 ? slug(name) : '';
    return (single || (base || 'tracks') + '-' + count) + '.' + extension;
  }

  return {
    FORMATS: FORMATS,
    merge: merge,
    toGpx: toGpx,
    toKml: toKml,
    toGeoJson: toGeoJson,
    convert: convert,
    fileName: fileName
  };
});
