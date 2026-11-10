/*
 * Tracce da `api/ec/track/multiple` dello shard (oc:8952), per lo scarico e per la mappa. Nel browser è
 * `window.wmWpTrackApi`, in Node `require()`; il fetch si può passare, così i test non vanno in rete.
 *
 * L'API restituisce al massimo 10 tracce per richiesta (EcTrackController del wm-package, `array_slice($ids, 0, 10)`),
 * senza errore per quelle in più: le tracce si chiedono a gruppi di 10, quattro richieste alla volta. Sul dev il 09/11
 * tutti i 769 percorsi erano 77 richieste, 20,5 MB, 12 secondi. Di ogni traccia si tengono in memoria solo geometria,
 * id, codice e layer (per il colore sulla mappa; la risposta porta anche descrizioni e gallerie), e una traccia già
 * arrivata non si chiede di nuovo:
 * passare dall'elenco alla mappa, o cambiare un filtro, non riscarica niente. Anche una traccia già chiesta e non ancora
 * arrivata non si chiede due volte: la mappa e lo scarico che partono insieme aspettano la stessa richiesta (se quella
 * viene annullata o fallisce, chi aspettava la rifà per conto suo).
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
    // id → promessa della richiesta in volo che la porta
    var pending = new Map();

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

    function request(group, signal) {
      var promise = fetchGroup(group, signal);
      var clear = function () {
        group.forEach(function (id) {
          if (pending.get(id) === promise) {
            pending.delete(id);
          }
        });
      };
      group.forEach(function (id) {
        pending.set(id, promise);
      });
      promise.then(clear, clear);
      return promise;
    }

    function aborted() {
      return Object.assign(new Error('aborted'), { name: 'AbortError' });
    }

    function features(ids, signal, onProgress) {
      var wanted = (ids || []).map(String);
      function progress() {
        if (onProgress) {
          onProgress(wanted.filter(function (id) { return cache.has(id); }).length, wanted.length);
        }
      }
      // Le tracce che mancano, a gruppi di GROUP e PARALLEL richieste alla volta; con shared, quelle già in volo per
      // un'altra chiamata non si chiedono
      function load(shared) {
        var todo = wanted.filter(function (id, index) {
          return !cache.has(id) && !(shared && pending.has(id)) && wanted.indexOf(id) === index;
        });
        var groups = chunk(todo, GROUP);
        var next = 0;
        function worker() {
          if (signal && signal.aborted) {
            return Promise.reject(aborted());
          }
          if (next >= groups.length) {
            return Promise.resolve();
          }
          var group = groups[next++];
          return request(group, signal).then(function () {
            progress();
            return worker();
          });
        }
        var workers = [];
        for (var i = 0; i < Math.min(PARALLEL, groups.length); i++) {
          workers.push(worker());
        }
        return Promise.all(workers);
      }
      // Le richieste in volo per altre chiamate: se una fallisce o viene annullata, le sue tracce restano da chiedere
      function wait() {
        var promises = [];
        wanted.forEach(function (id) {
          var promise = !cache.has(id) && pending.get(id);
          if (promise && promises.indexOf(promise) === -1) {
            promises.push(promise);
          }
        });
        return Promise.all(promises.map(function (promise) {
          return promise.then(progress, function () {});
        }));
      }
      if (signal && signal.aborted) {
        return Promise.reject(aborted());
      }
      progress();
      var waiting = wait();
      return Promise.all([load(true), waiting]).then(function () {
        return load(false);
      }).then(function () {
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
