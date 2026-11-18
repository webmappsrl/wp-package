/*
 * Source «pois» della ricerca (oc:9000): punti di interesse dalla rotta REST `wmwp/v1/pois` del plugin.
 *
 * Testo, pagine e conteggi dei tipi li calcola il server (la copia dei POI sta in un'opzione di WordPress): qui si
 * costruisce la richiesta e si restituisce la risposta, già nella forma attesa da search.js. Una risposta non ok
 * (es. 503 senza copia) rifiuta la Promise, così la pagina mostra l'errore e non «0 risultati».
 */
(function () {
  'use strict';

  var api = window.wmWpSearch;
  if (!api) {
    return;
  }
  var source = (api.config.sources || []).find(function (item) { return item.id === 'pois'; });
  if (!source) {
    return;
  }
  // Chiave della faccetta dei tipi nella risposta della rotta (WMWP_POI_SEARCH_FACET) e chiave del filtro dei tipi,
  // dalla prima faccetta della source (il tema la può cambiare, es. tipo-poi)
  var ROUTE_FACET = 'poi-type';
  var facetKey = ((source.facets || [])[0] || {}).key || ROUTE_FACET;

  api.registerSource('pois', {
    search: function (query, signal) {
      if (!source.endpoint) {
        return Promise.reject(new Error('wp-package: POI endpoint not configured'));
      }
      var url = new URL(source.endpoint);
      if (query.rawText) {
        url.searchParams.set('q', query.rawText);
      }
      url.searchParams.set('page', String(query.page || 1));
      url.searchParams.set('per_page', String(query.perPage || 12));
      url.searchParams.set('lang', query.lang || '');
      ((query.filters || {})[facetKey] || []).forEach(function (id) {
        url.searchParams.append('poi_type[]', id);
      });
      return fetch(url.toString(), { signal: signal, headers: { Accept: 'application/json' } }).then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      }).then(function (data) {
        // Nomi visibili dalla source, scritta nella lingua della pagina (il tema li può cambiare): la rotta REST non sa
        // in che lingua è la pagina che la chiama
        var labels = {};
        (source.facets || []).forEach(function (facet) {
          if (facet && facet.key && facet.label) {
            labels[facet.key] = facet.label;
          }
        });
        // La rotta usa la chiave del plugin: diventa quella della source, che il tema può cambiare
        (data.facets || []).forEach(function (facet) {
          if (ROUTE_FACET === facet.key) {
            facet.key = facetKey;
          }
          if (labels[facet.key]) {
            facet.label = labels[facet.key];
          }
        });
        if (source.type_label) {
          (data.items || []).forEach(function (item) {
            item.typeLabel = source.type_label;
          });
        }
        return data;
      });
    }
  });
})();
