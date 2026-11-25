/*
 * Source «pois» della ricerca (oc:9000): punti di interesse dalla rotta REST `wmwp/v1/pois` del plugin.
 *
 * Testo, pagine e conteggi dei tipi e dei luoghi li calcola il server (la copia dei POI sta in un'opzione di WordPress): qui si
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
  // La faccetta dei tipi è la prima che non è un luogo (oc:9018): senza config.json la faccetta dei tipi dal config può
  // mancare, e la prima sarebbe un'area. Stessa regola di wmwp_poi_search_is_place() della rotta
  var isPlace = function (facet) {
    return !!(facet && (facet.param || facet.field === 'taxonomyWheres' || facet.field === 'layers'));
  };
  var typeFacet = (source.facets || []).find(function (facet) { return !isPlace(facet); }) || null;
  var facetKey = (typeFacet && typeFacet.key) || ROUTE_FACET;

  // Griglia dei POI (oc:9003): la prima ricerca usa la pagina che il server ha già disegnato
  var initial = api.config.grid ? api.config.initial || null : null;

  api.registerSource('pois', {
    search: function (query, signal) {
      if (initial) {
        var first = initial;
        initial = null;
        return Promise.resolve(first);
      }
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
      // Le altre faccette della source (luoghi: area, provincia, comune…) vanno alla rotta come filter[chiave][]; le aree
      // degli overlay (parametro condiviso, oc:9018) come layer[]
      (source.facets || []).filter(function (facet) { return facet !== typeFacet; }).forEach(function (facet) {
        ((query.filters || {})[facet.key] || []).forEach(function (id) {
          url.searchParams.append(facet.param ? facet.param + '[]' : 'filter[' + facet.key + '][]', id);
        });
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
