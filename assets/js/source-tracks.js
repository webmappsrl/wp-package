/*
 * Source «tracks» della ricerca (oc:8952): percorsi dall'API Elasticsearch dello shard.
 *
 * All'API si chiede solo il testo (`app` e `query`): la risposta contiene tutti i percorsi che lo trovano
 * (fino a 10.000), e filtri, conteggi e pagine si calcolano qui con wmWpSearchLogic. Così un clic su un
 * filtro non scarica niente, e i conteggi di un filtro si calcolano senza il filtro stesso (l'API oggi li dà
 * solo per i 10 valori più frequenti e già ristretti dal filtro scelto). Le risposte restano in memoria per
 * testo. Se un giorno l'API mette una paginazione, i conteggi devono tornare all'API.
 */
(function () {
  'use strict';

  var api = window.wmWpSearch;
  var L = window.wmWpSearchLogic;
  var X = window.wmWpTrackExport;
  var M = window.wmWpTrackMap;
  if (!api || !L) {
    return;
  }
  var source = (api.config.sources || []).find(function (item) { return item.id === 'tracks'; });
  if (!source) {
    return;
  }
  var i18n = (window.wp && window.wp.i18n) || {};
  var __ = i18n.__ || function (text) { return text; };
  // wp.i18n c'è sempre (dipendenza di wmwp-search); il ripiego tratta %s e %1$s come l'unico valore
  var sprintf = function (format, value) { return i18n.sprintf ? i18n.sprintf(format, value) : format.replace(/%(1\$)?s/, value); };

  // Risposte per testo, al massimo RESPONSE_LIMIT (una risposta con tutti i percorsi pesa circa 2,5 MB): con un testo
  // nuovo si toglie quello usato meno di recente
  var responses = new Map();
  var RESPONSE_LIMIT = 6;
  // Ogni percorso visto in una risposta, per id: nome, lunghezza e dislivello dei percorsi scelti anche quando la
  // ricerca è cambiata
  var known = new Map();

  // Tutti i percorsi per un testo, una richiesta per testo. Una richiesta annullata non resta in memoria.
  function hitsFor(text, signal) {
    var cached = responses.get(text);
    if (cached && (cached.done || !cached.signal.aborted)) {
      responses.delete(text);
      responses.set(text, cached);
      return cached.promise;
    }
    if (!source.endpoint || !source.app) {
      return Promise.reject(new Error('wp-package: shard or app id not configured'));
    }
    var url = new URL(source.endpoint);
    url.searchParams.set('app', source.app);
    if (text) {
      url.searchParams.set('query', text);
    }
    var promise = fetch(url.toString(), { signal: signal, headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        var hits = Array.isArray(data && data.hits) ? data.hits : [];
        hits.forEach(function (hit) { known.set(String(hit.id), hit); });
        return hits;
      });
    var entry = { promise: promise, signal: signal, done: false };
    responses.set(text, entry);
    while (responses.size > RESPONSE_LIMIT) {
      responses.delete(responses.keys().next().value);
    }
    promise.then(
      function () { entry.done = true; },
      function () {
        if (responses.get(text) === entry) {
          responses.delete(text);
        }
      }
    );
    return promise;
  }

  // Nome nella lingua della pagina, con l'italiano di ripiego (search-logic)
  var localized = L.localized;

  function trackUrl(hit) {
    if (!source.link_template) {
      return null;
    }
    return source.link_template
      .replace('{id}', encodeURIComponent(hit.id))
      .replace('{ref}', encodeURIComponent(hit.ref ? String(hit.ref).trim() : ''));
  }

  // Filtro i cui valori vanno all'inizio della riga (es. i comuni), indicato dal tema con meta_facet
  var metaFacet = (source.facets || []).find(function (facet) { return facet.key === source.meta_facet; }) || null;

  function toCard(hit, lang) {
    var routeType = hit.terms && Array.isArray(hit.terms['route-type']) ? hit.terms['route-type'][0] : null;
    var ascent = L.formatMeters(hit.ascent, lang);
    return {
      id: hit.id,
      title: String(localized(hit.name, lang)).trim(),
      url: trackUrl(hit),
      typeKey: routeType ? String(routeType.identifier) : '',
      // Nomi di tipo e stato dal tema (term_labels) quando ci sono: l'API li dà solo in italiano
      typeLabel: L.termName(routeType, lang, source.term_labels),
      image: hit.feature_image ? { src: hit.feature_image, alt: '' } : null,
      code: hit.ref ? String(hit.ref).trim() : null,
      meta: [
        metaFacet ? L.facetValues(hit, metaFacet, lang).map(function (value) { return value.label; }).join(', ') : '',
        L.formatKm(hit.distance, lang),
        ascent ? '+' + ascent : '',
        L.formatDuration(hit.duration_forward, lang)
      ],
      // Stato del percorso (es. terms.validation-status), se il tema indica il campo con badge_field
      badge: L.firstTerm(hit, source.badge_field, lang, source.term_labels)
    };
  }

  // --- selezione, scarico e mappa ---------------------------------------------------------------------
  // Le tracce vengono da `api/ec/track/multiple` (wmWpTrackApi: gruppi di 10, quattro richieste alla volta, e una
  // traccia già arrivata resta in memoria), sia per lo scarico sia per la mappa: passare dall'una all'altro, o cambiare
  // un filtro nella mappa, non riscarica le tracce già viste.
  var tracksApi = source.download_url && window.wmWpTrackApi ? window.wmWpTrackApi.create(source.download_url) : null;

  function namesFor(ids, lang) {
    var names = {};
    ids.forEach(function (id) {
      var hit = known.get(String(id));
      if (hit) {
        names[hit.id] = String(localized(hit.name, lang)).trim();
      }
    });
    return names;
  }

  var selection = {
    // «3 percorsi selezionati · 12,4 km · +560 m»: km e dislivello solo se tutti i percorsi scelti li hanno
    summary: function (ids, lang) {
      var hits = ids.map(function (id) { return known.get(String(id)); }).filter(Boolean);
      var parts = [L.countLabel(ids.length, source.selected || source.nouns, lang)];
      var km = hits.length === ids.length ? L.measuredSum(hits, 'distance') : null;
      var ascent = hits.length === ids.length ? L.measuredSum(hits, 'ascent') : null;
      if (km !== null) {
        parts.push(L.formatKm(km, lang));
      }
      if (ascent !== null) {
        parts.push('+' + L.formatMeters(ascent, lang));
      }
      return parts.join(' · ');
    },
    // Un file con tutte le tracce scelte: { content, mime, extension, name, count, missing }
    download: function (ids, format, signal, onProgress) {
      return tracksApi.features(ids, signal, onProgress).then(function (result) {
        var merged = X.merge([{ features: result.features }], ids);
        var names = namesFor(ids, api.config.lang);
        var features = merged.collection.features;
        // Con un solo percorso file e documento KML prendono il suo nome (o il codice)
        var single = features.length === 1 ? names[features[0].properties.id] || features[0].properties.ref || '' : '';
        var file = X.convert(merged.collection, format, names, single || source.label);
        file.count = features.length;
        file.missing = merged.missing;
        file.name = X.fileName(source.download_name, file.count, file.extension, single);
        return file;
      });
    }
  };

  // Nome, codice e indirizzo dei percorsi per la mappa, dai risultati della ricerca
  function mapInfo(ids, lang) {
    var info = {};
    ids.forEach(function (id) {
      var hit = known.get(String(id));
      if (hit) {
        info[hit.id] = { name: String(localized(hit.name, lang)).trim(), code: hit.ref ? String(hit.ref).trim() : '', url: trackUrl(hit) };
      }
    });
    return info;
  }

  // Colore, spessore e colori dei layer dell'app (config.json, includes/map.php): ogni percorso nel colore del suo layer
  function mapStyle() {
    var style = {};
    Object.keys(source.map_style || {}).forEach(function (key) { style[key] = source.map_style[key]; });
    style.layerColors = (api.config.map && api.config.map.layer_colors) || [];
    return style;
  }

  var map = {
    // Le tracce dei risultati per la mappa: { collection: FeatureCollection, missing: id senza tracciato }. I percorsi
    // selezionati fra i risultati stanno in primo piano e la mappa si inquadra su di loro (wmWpTrackMap.collection)
    // onProgress(done, total, partial): partial() è la FeatureCollection delle tracce già arrivate, per disegnarle
    // mentre arrivano le altre (si costruisce solo se chi la usa la chiede)
    collection: function (ids, lang, signal, onProgress, selected) {
      var info = mapInfo(ids, lang);
      var style = mapStyle();
      var progress = onProgress ? function (done, total) {
        onProgress(done, total, function () { return M.collection(tracksApi.cached(ids), info, style, selected); });
      } : null;
      return tracksApi.features(ids, signal, progress).then(function (result) {
        return { collection: M.collection(result.features, info, style, selected), missing: result.missing };
      });
    },
    // Card di un percorso per il riquadro al clic sulla mappa: gli stessi dati della ricerca, già in memoria
    card: function (id, lang) {
      var hit = known.get(String(id));
      return hit ? toCard(hit, lang) : null;
    }
  };

  api.registerSource('tracks', {
    search: function (query, signal) {
      return hitsFor(query.text, signal).then(function (hits) {
        var facets = source.facets || [];
        // Con un testo cercato, prima i percorsi che lo hanno nel nome: l'API ordina sempre per nome
        var filtered = L.rankByText(L.filterHits(hits, facets, query.filters, query.lang), query.rawText, query.lang);
        var page = L.paginate(filtered, query.page, query.perPage);
        // I km complessivi solo se tutti i percorsi hanno la lunghezza (measuredSum)
        var km = L.measuredSum(filtered, 'distance');
        var summary = L.countLabel(filtered.length, source.nouns, query.lang);
        if (km) {
          /* translators: %s: total length, e.g. 179.5 km */
          summary += ' · ' + sprintf(__('%s in total', 'wp-package'), L.formatKm(km, query.lang));
        }
        return {
          total: filtered.length,
          items: page.items.map(function (hit) { return toCard(hit, query.lang); }),
          // Tutti i percorsi della ricerca, per «Seleziona tutti»
          ids: filtered.map(function (hit) { return String(hit.id); }),
          facets: L.computeFacets(hits, facets, query.filters, query.lang),
          summary: filtered.length ? summary : ''
        };
      });
    },
    selection: tracksApi && X ? selection : null,
    map: tracksApi && M ? map : null
  });
})();
