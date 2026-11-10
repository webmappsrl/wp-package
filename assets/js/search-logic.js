/*
 * Logica pura della ricerca (oc:8952): testo, filtri, conteggi, indirizzo, pagine e numeri. Niente DOM e
 * niente rete, così si prova con `node --test tests/js/`. Nel browser è `window.wmWpSearchLogic`, in Node
 * `require()`.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.wmWpSearchLogic = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  // Caratteri della sintassi di Lucene che, passati alla QueryStringQuery del controller, fanno rispondere
  // l'API con un errore 500 o cambiano il senso della ricerca (verificato sul dev il 09/11). Diventano spazi;
  // il trattino dentro una parola si scrive «\-», così un codice come «B-401» resta intero, mentre un trattino
  // fra due parole («Jacca - Genna») si toglie: sul dev azzerava i risultati. Le parole operatore AND, OR e
  // NOT in maiuscolo danno anch'esse 500 («sentiero AND»): diventano minuscole, che Lucene tratta come testo.
  var LUCENE_SPECIAL = /[+=&|><!(){}\[\]^"~*?:\\\/]/g;
  var LUCENE_OPERATORS = /\b(AND|OR|NOT)\b/g;

  function cleanQuery(text) {
    return String(text == null ? '' : text)
      .replace(LUCENE_SPECIAL, ' ')
      .replace(LUCENE_OPERATORS, function (word) { return word.toLowerCase(); })
      .split(/\s+/)
      .filter(function (word) { return word !== '' && !/^-+$/.test(word); })
      .join(' ')
      .replace(/-/g, '\\-');
  }

  function localized(names, lang) {
    if (!names || typeof names !== 'object') {
      return '';
    }
    return names[lang] || names.it || '';
  }

  // Valore di un campo con il percorso a punti («terms.hiking-difficulty»).
  function fieldValue(hit, path) {
    return String(path || '').split('.').reduce(function (value, key) {
      return value && typeof value === 'object' ? value[key] : undefined;
    }, hit);
  }

  // Valori di un filtro in un percorso: [{id, label}]. Etichetta dal tema (`labels`), poi dall'API.
  function facetValues(hit, facet, lang) {
    if (!facet || facet.disabled || !facet.field) {
      return [];
    }
    var raw = fieldValue(hit, facet.field);
    if (!Array.isArray(raw)) {
      return [];
    }
    var labels = facet.labels || {};
    var result = [];
    raw.forEach(function (item) {
      var id;
      var label;
      if (facet.type === 'terms') {
        if (!item || typeof item !== 'object' || !item.identifier) {
          return;
        }
        id = String(item.identifier);
        label = localized(item.name, lang);
      } else {
        if (item == null || item === '') {
          return;
        }
        id = String(item).trim();
        var icon = hit.taxonomyIcons && hit.taxonomyIcons[id];
        label = localized(icon && icon.label, lang);
      }
      if (facet.exclude_prefix && id.indexOf(facet.exclude_prefix) === 0) {
        return;
      }
      if (!keepValue(facet, id)) {
        return;
      }
      result.push({ id: id, label: String(labels[id] || label || id).trim() });
    });
    return facet.groups ? groupValues(result, facet.groups) : result;
  }

  // Fasce (`groups`): più valori sotto una voce sola, per esempio gli stati di validazione nelle fasce Base,
  // Intermedio e Avanzato della tavola 2. Un valore che non sta in nessuna fascia non conta.
  function groupValues(values, groups) {
    var out = [];
    groups.forEach(function (group) {
      var hit = values.some(function (value) { return (group.values || []).indexOf(value.id) !== -1; });
      if (hit) {
        out.push({ id: String(group.id), label: String(group.label || group.id) });
      }
    });
    return out;
  }

  // Primo termine di un campo come etichetta: { key: identifier, label: nome nella lingua, o in italiano }.
  function firstTerm(hit, path, lang) {
    if (!path) {
      return null;
    }
    var list = fieldValue(hit, path);
    var term = Array.isArray(list) ? list[0] : null;
    if (!term || !term.identifier) {
      return null;
    }
    return { key: String(term.identifier), label: String(localized(term.name, lang) || term.identifier).trim() };
  }

  // include / include_pattern tengono solo certi valori, exclude / exclude_pattern ne tolgono: servono, per
  // esempio, a dividere taxonomyWheres (regione, aree, settori CAI, province, comuni) in più filtri
  var patterns = {};
  function pattern(source) {
    if (!(source in patterns)) {
      patterns[source] = new RegExp(source);
    }
    return patterns[source];
  }

  function keepValue(facet, id) {
    if (facet.include && facet.include.indexOf(id) === -1) {
      return false;
    }
    if (facet.include_pattern && !pattern(facet.include_pattern).test(id)) {
      return false;
    }
    if (facet.exclude && facet.exclude.indexOf(id) !== -1) {
      return false;
    }
    if (facet.exclude_pattern && pattern(facet.exclude_pattern).test(id)) {
      return false;
    }
    return true;
  }

  // Testo per il confronto: minuscolo, senza accenti, solo lettere e numeri separati da uno spazio
  function normalize(text) {
    return String(text || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, ' ')
      .trim();
  }

  // Pertinenza (punto aperto 4 di oc:8952): prima i risultati che hanno il testo cercato nel nome, poi quelli che
  // ne hanno tutte le parole, poi gli altri; a parità resta l'ordine dell'API (per nome).
  function rankByText(hits, text, lang) {
    var phrase = normalize(text);
    if (!phrase) {
      return hits.slice();
    }
    var words = phrase.split(' ');
    return hits
      .map(function (hit, index) {
        var name = normalize(localized(hit.name, lang)) + ' ' + normalize(hit.name && hit.name.it);
        var score = name.indexOf(phrase) !== -1 ? 2 : words.every(function (word) { return name.indexOf(word) !== -1; }) ? 1 : 0;
        return { hit: hit, score: score, index: index };
      })
      .sort(function (a, b) { return b.score - a.score || a.index - b.index; })
      .map(function (item) { return item.hit; });
  }

  function matchesFacet(hit, facet, chosen, lang) {
    if (!chosen || !chosen.length) {
      return true;
    }
    return facetValues(hit, facet, lang).some(function (value) {
      return chosen.indexOf(value.id) !== -1;
    });
  }

  // OR dentro un filtro, AND fra filtri; `skipKey` lascia fuori un filtro (per i suoi conteggi).
  function filterHits(hits, facets, selected, lang, skipKey) {
    selected = selected || {};
    return hits.filter(function (hit) {
      return facets.every(function (facet) {
        return facet.key === skipKey || facet.disabled || matchesFacet(hit, facet, selected[facet.key], lang);
      });
    });
  }

  function computeFacets(hits, facets, selected, lang) {
    selected = selected || {};
    return facets.map(function (facet) {
      var chosen = selected[facet.key] || [];
      var view = { key: facet.key, label: facet.label, disabled: !!facet.disabled, values: [] };
      if (facet.disabled) {
        return view;
      }
      var counts = {};
      var labels = {};
      filterHits(hits, facets, selected, lang, facet.key).forEach(function (hit) {
        var seen = {};
        facetValues(hit, facet, lang).forEach(function (value) {
          if (seen[value.id]) {
            return;
          }
          seen[value.id] = true;
          counts[value.id] = (counts[value.id] || 0) + 1;
          labels[value.id] = value.label;
        });
      });
      chosen.forEach(function (id) {
        if (!(id in counts)) {
          counts[id] = 0;
          var group = (facet.groups || []).find(function (item) { return String(item.id) === id; });
          labels[id] = (facet.labels && facet.labels[id]) || (group && group.label) || id;
        }
      });
      var order = facet.order || [];
      view.values = Object.keys(counts)
        .map(function (id) {
          return { id: id, label: labels[id], count: counts[id], selected: chosen.indexOf(id) !== -1 };
        })
        .sort(function (a, b) {
          var ia = order.indexOf(a.id);
          var ib = order.indexOf(b.id);
          if (ia !== -1 || ib !== -1) {
            return (ia === -1 ? Infinity : ia) - (ib === -1 ? Infinity : ib);
          }
          return b.count - a.count || a.label.localeCompare(b.label);
        });
      return view;
    });
  }

  // Stato della ricerca dall'indirizzo. `params` dà i nomi dei parametri ({q, tab, page}).
  function parseState(search, params, facetKeys) {
    var query = new URLSearchParams(search || '');
    var page = parseInt(query.get(params.page) || '', 10);
    var filters = {};
    (facetKeys || []).forEach(function (key) {
      var raw = query.get(key);
      if (raw) {
        var list = raw.split(',').map(function (v) { return v.trim(); }).filter(Boolean);
        if (list.length) {
          filters[key] = list;
        }
      }
    });
    return {
      text: (query.get(params.q) || '').trim(),
      tab: query.get(params.tab) || '',
      page: page > 0 ? page : 1,
      filters: filters,
      // Vista mappa: un parametro con nome e valore del tema (es. vista=mappa); senza, la mappa non c'è
      map: !!(params.view && params.map) && query.get(params.view) === params.map
    };
  }

  function stateToSearch(state, params) {
    var query = new URLSearchParams();
    if (state.text) {
      query.set(params.q, state.text);
    }
    if (state.tab) {
      query.set(params.tab, state.tab);
    }
    if (state.page > 1) {
      query.set(params.page, String(state.page));
    }
    Object.keys(state.filters || {}).forEach(function (key) {
      var list = state.filters[key] || [];
      if (list.length) {
        query.set(key, list.join(','));
      }
    });
    if (state.map && params.view && params.map) {
      query.set(params.view, params.map);
    }
    var out = query.toString();
    return out ? '?' + out : '';
  }

  function paginate(items, page, perPage) {
    var pages = Math.max(1, Math.ceil(items.length / perPage));
    var current = Math.min(Math.max(1, page || 1), pages);
    return { items: items.slice((current - 1) * perPage, current * perPage), page: current, pages: pages };
  }

  function isEmptyNumber(value) {
    return value === null || value === undefined || value === '' || !isFinite(Number(value)) || Number(value) === 0;
  }

  function formatKm(km, lang) {
    if (isEmptyNumber(km)) {
      return '';
    }
    return new Intl.NumberFormat(lang, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(km)) + ' km';
  }

  function formatMeters(meters, lang) {
    if (isEmptyNumber(meters)) {
      return '';
    }
    return new Intl.NumberFormat(lang, { maximumFractionDigits: 0 }).format(Number(meters)) + ' m';
  }

  // Come wm_format_track_duration di wp-geohub (single_track.php): ore solo se ci sono, minuti sempre.
  function formatDuration(minutes, lang) {
    if (isEmptyNumber(minutes)) {
      return '';
    }
    var total = Math.round(Number(minutes));
    var hours = Math.floor(total / 60);
    var parts = [];
    if (hours > 0) {
      parts.push(hours + ' h');
    }
    parts.push((total % 60) + ' min');
    return parts.join(' ');
  }

  // Somma di un campo numerico (es. distance, ascent) solo se tutti i percorsi lo hanno: con il calcolo dal DEM a
  // metà (sul dev il 09/11 uno su 769) la somma sembrerebbe il totale e non lo è. Altrimenti null.
  function measuredSum(hits, field) {
    if (!hits.length || !hits.every(function (hit) { return !isEmptyNumber(hit[field]); })) {
      return null;
    }
    return hits.reduce(function (sum, hit) { return sum + Number(hit[field]); }, 0);
  }

  function countLabel(n, nouns, lang) {
    var form = new Intl.PluralRules(lang).select(n) === 'one' ? nouns.one : nouns.other;
    return String(form).replace('%s', new Intl.NumberFormat(lang).format(n));
  }

  return {
    cleanQuery: cleanQuery,
    facetValues: facetValues,
    firstTerm: firstTerm,
    rankByText: rankByText,
    filterHits: filterHits,
    computeFacets: computeFacets,
    parseState: parseState,
    stateToSearch: stateToSearch,
    paginate: paginate,
    formatKm: formatKm,
    formatMeters: formatMeters,
    formatDuration: formatDuration,
    measuredSum: measuredSum,
    countLabel: countLabel
  };
});
