/*
 * Tracce da `api/ec/track/multiple` dello shard (oc:8952), per lo scarico e per la mappa. Nel browser è
 * `window.wmWpTrackApi`, in Node `require()`; il fetch si può passare, così i test non vanno in rete.
 *
 * L'API restituisce al massimo 10 tracce per richiesta (EcTrackController del wm-package, `array_slice($ids, 0, 10)`),
 * senza errore per quelle in più: le tracce si chiedono a gruppi di 10, quattro richieste alla volta. Sul dev il 09/11
 * tutti i 769 percorsi erano 77 richieste, 20,5 MB, 12 secondi. Di ogni traccia si tengono in memoria solo geometria,
 * id, codice e layer (per il colore sulla mappa; la risposta porta anche descrizioni e gallerie), e una traccia già
 * arrivata non si chiede di nuovo:
 * passare dall'elenco alla mappa, o cambiare un filtro, non riscarica niente.
 *
 *   var api = wmWpTrackApi.create(url)
 *   api.features(ids, signal, onProgress(done, total)) → Promise<{ features: Feature[], missing: string[] }>
 *   features nell'ordine degli id; missing: gli id che l'API non restituisce, o restituisce senza geometria
 *   api.cached(ids) → Feature[]: subito, le tracce di quegli id già arrivate (la mappa le disegna mentre arrivano le
 *   altre; onProgress arriva dopo che un gruppo è entrato qui)
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.wmWpTrackApi = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var GROUP = 10;
  var PARALLEL = 4;

  function chunk(list, size) {
    var out = [];
    for (var i = 0; i < list.length; i += size) {
      out.push(list.slice(i, i + size));
    }
    return out;
  }

  function hasGeometry(feature) {
    return !!(feature && feature.geometry && Array.isArray(feature.geometry.coordinates) && feature.geometry.coordinates.length);
  }

  function create(url, options) {
    var fetchImpl = (options && options.fetch) || (typeof fetch === 'function' ? fetch.bind(typeof self !== 'undefined' ? self : this) : null);
    // id → Feature leggera, o null per una traccia che l'API non dà o dà senza geometria
    var cache = new Map();

    function fetchGroup(ids, signal) {
      var request = new URL(url);
      request.searchParams.set('ids', ids.join(','));
      return fetchImpl(request.toString(), { signal: signal, headers: { Accept: 'application/json' } }).then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      }).then(function (data) {
        var byId = {};
        ((data && data.features) || []).forEach(function (feature) {
          var id = feature && feature.properties ? String(feature.properties.id) : '';
          if (id && hasGeometry(feature)) {
            byId[id] = {
              type: 'Feature',
              geometry: feature.geometry,
              properties: {
                id: feature.properties.id,
                ref: feature.properties.ref,
                layers: Array.isArray(feature.properties.layers) ? feature.properties.layers : []
              }
            };
          }
        });
        ids.forEach(function (id) {
          cache.set(id, byId[id] || null);
        });
      });
    }

    function features(ids, signal, onProgress) {
      var wanted = (ids || []).map(String);
      var todo = wanted.filter(function (id, index) { return !cache.has(id) && wanted.indexOf(id) === index; });
      var groups = chunk(todo, GROUP);
      var done = wanted.length - todo.length;
      var next = 0;
      if (onProgress) {
        onProgress(done, wanted.length);
      }
      function worker() {
        if (signal && signal.aborted) {
          return Promise.reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        }
        if (next >= groups.length) {
          return Promise.resolve();
        }
        var group = groups[next++];
        return fetchGroup(group, signal).then(function () {
          done += group.length;
          if (onProgress) {
            onProgress(done, wanted.length);
          }
          return worker();
        });
      }
      var workers = [];
      for (var i = 0; i < Math.min(PARALLEL, groups.length); i++) {
        workers.push(worker());
      }
      if (!workers.length && signal && signal.aborted) {
        workers.push(worker());
      }
      return Promise.all(workers).then(function () {
        var out = [];
        var missing = [];
        wanted.forEach(function (id) {
          var feature = cache.get(id);
          if (feature) {
            out.push(feature);
          } else {
            missing.push(id);
          }
        });
        return { features: out, missing: missing };
      });
    }

    function cached(ids) {
      var out = [];
      (ids || []).forEach(function (id) {
        var feature = cache.get(String(id));
        if (feature) {
          out.push(feature);
        }
      });
      return out;
    }

    return { features: features, cached: cached };
  }

  return { create: create, GROUP: GROUP, PARALLEL: PARALLEL };
});
