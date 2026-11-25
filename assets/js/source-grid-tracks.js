/*
 * Source delle griglie di percorsi (oc:9003): i percorsi di un layer dalla rotta REST `wmwp/v1/tracks` del plugin, che
 * filtra, conta e pagina sulla copia delle tracce del server. La griglia è la ricerca in modalità griglia (search.js con
 * `config.grid`): una source sola, senza tab.
 *
 * I filtri vanno alla rotta con gli stessi parametri dell'indirizzo della pagina (chiave della faccetta, valori separati
 * da virgola; le aree degli overlay nel parametro condiviso, es. `layer`), il testo in `q`. La prima ricerca usa il risultato che il
 * server ha già disegnato nella pagina (`config.initial`), così la pagina non chiama la rotta per ripetere lo stesso.
 */
(function () {
  'use strict';

  var api = window.wmWpSearch;
  if (!api) {
    return;
  }
  var source = (api.config.sources || []).find(function (item) { return item.id === 'grid'; });
  if (!source) {
    return;
  }
  var initial = api.config.initial || null;

  api.registerSource('grid', {
    search: function (query, signal) {
      if (initial) {
        var first = initial;
        initial = null;
        return Promise.resolve(first);
      }
      var url = new URL(source.endpoint);
      url.searchParams.set('grid', source.grid);
      url.searchParams.set('lang', query.lang || '');
      // Testo cercato: lo ripulisce e lo cerca il server con l'API dello shard, come la ricerca unica (oc:9003)
      if (query.rawText) {
        url.searchParams.set('q', query.rawText);
      }
      url.searchParams.set('pg', String(query.page || 1));
      var shared = {};
      (source.facets || []).forEach(function (facet) {
        var values = (query.filters || {})[facet.key] || [];
        if (!values.length) {
          return;
        }
        if (facet.param) {
          shared[facet.param] = (shared[facet.param] || []).concat(values);
        } else {
          url.searchParams.set(facet.key, values.join(','));
        }
      });
      Object.keys(shared).forEach(function (param) {
        url.searchParams.set(param, shared[param].join(','));
      });
      return fetch(url.toString(), { signal: signal, headers: { Accept: 'application/json' } }).then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      });
    }
  });
})();
