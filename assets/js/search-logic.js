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
    var labels = facet.labels || {};
    // Un risultato senza il campo vale come `missing`, se il tema lo dichiara (oc:9000): per esempio le tracce senza
    // percorribilità contano come «Sconosciuta», nel filtro e nei conteggi
    if (facet.missing && !hasValue(raw, facet.type)) {
      var missingId = String(facet.missing);
      var missing = [{ id: missingId, label: String(labels[missingId] || missingId).trim() }];
      return facet.groups ? groupValues(missing, facet.groups) : missing;
    }
    if (!Array.isArray(raw)) {
      return [];
    }
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
        // Nome che ripiega sull'italiano: va marcato con lang (oc:9018)
        var termLang = item.name && typeof item.name === 'object' && !item.name[lang] && item.name.it ? 'it' : '';
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
      var value = { id: id, label: String(labels[id] || label || id).trim() };
      // Lingua del nome dato dal config.json (`lang_labels`, oc:9018), per marcarlo con `lang` se non è quella della pagina
      if (labels[id] && facet.lang_labels && facet.lang_labels[id]) {
        value.lang = String(facet.lang_labels[id]);
      } else if (!labels[id] && langFor(termLang, lang)) {
        value.lang = langFor(termLang, lang);
      }
      result.push(value);
    });
    return facet.groups ? groupValues(result, facet.groups) : result;
  }

  // Il campo ha almeno un valore utile: per i termini un oggetto con l'identifier, per le liste un valore non vuoto
  function hasValue(raw, type) {
    if (!Array.isArray(raw)) {
      return false;
    }
    return raw.some(function (item) {
      return type === 'terms' ? !!(item && typeof item === 'object' && item.identifier) : item != null && item !== '';
    });
  }

  // Lingua da dare a un testo con `lang` (WCAG 3.1.2): quella del testo, se è diversa da quella della pagina, confrontando
  // le due lettere (i codici di WPML possono avere la regione, en-us); '' se è la stessa o non si sa (oc:9000)
  function langFor(textLang, pageLang) {
    var text = String(textLang || '').toLowerCase().slice(0, 2);
    var page = String(pageLang || '').toLowerCase().slice(0, 2);
    return text && text !== page ? text : '';
  }

  // Gruppi (`groups`): più valori sotto una voce sola (il tema può anche dare un gruppo per valore, per avere l'ordine
  // e i nomi suoi, come i filtri degli stati di Forestas, oc:9000). Un valore che non sta in nessun gruppo (uno stato
  // nuovo creato nello shard) si tiene dopo i gruppi, con il suo nome (oc:9018).
  function groupValues(values, groups) {
    var out = [];
    var grouped = {};
    groups.forEach(function (group) {
      var hit = values.some(function (value) { return (group.values || []).indexOf(value.id) !== -1; });
      (group.values || []).forEach(function (id) { grouped[id] = true; });
      if (hit) {
        out.push({ id: String(group.id), label: String(group.label || group.id) });
      }
    });
    values.forEach(function (value) {
      if (!grouped[value.id]) {
        out.push(value);
      }
    });
    return out;
  }

  // Primo termine di un campo come etichetta: { key: identifier, label: nome nella lingua, o in italiano }.
  // Nome di un termine dello shard: quello del tema per l'identifier (labels, es. `term_labels` della source), poi
  // quello dell'API nella lingua, poi l'identifier. L'API del dev dà i nomi di terms.route-type e
  // terms.validation-status solo in italiano (10/11): senza il tema, sulla pagina inglese restano in italiano
  function termName(term, lang, labels) {
    if (!term) {
      return '';
    }
    var id = term.identifier ? String(term.identifier) : '';
    if (id && labels && labels[id]) {
      return String(labels[id]).trim();
    }
    return String(localized(term.name, lang) || id).trim();
  }

  function firstTerm(hit, path, lang, labels) {
    if (!path) {
      return null;
    }
    var list = fieldValue(hit, path);
    var term = Array.isArray(list) ? list[0] : null;
    if (!term || !term.identifier) {
      return null;
    }
    return { key: String(term.identifier), label: termName(term, lang, labels) };
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

  // Nome con cui lo shard ordina (name.keyword: il nome italiano), senza spazi e tabulazioni ai lati
  function sortName(hit) {
    var name = hit && hit.name;
    return String((name && typeof name === 'object' ? name.it : name) || '').trim();
  }

  // Pertinenza (punto aperto 4 di oc:8952): prima i risultati che hanno il testo cercato nel nome, poi quelli che
  // ne hanno tutte le parole, poi gli altri; a parità resta l'ordine dell'API (per nome).
  function rankByText(hits, text, lang) {
    var phrase = normalize(text);
    if (!phrase) {
      // Senza testo l'ordine è quello dello shard, alfabetico sul nome italiano; alcuni nomi dello shard cominciano
      // con una tabulazione o uno spazio e finirebbero in testa: si riordina sul nome ripulito, con lo stesso
      // confronto per codice (con i nomi puliti l'ordine resta quello dell'API; a parità, l'ordine dell'API) (oc:9000)
      return hits
        .map(function (hit, index) { return { hit: hit, name: sortName(hit), index: index }; })
        .sort(function (a, b) { return a.name < b.name ? -1 : a.name > b.name ? 1 : a.index - b.index; })
        .map(function (item) { return item.hit; });
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

  // --- Faccette slider (oc:9003): `{type: 'slider', key, field, min, max, step, units, label}` da MAP.filters ----------

  // Ultimo valore che un input range con quel passo raggiunge: con min 1, max 30 e passo 3 è 28, non 30.
  function lastStep(facet) {
    var min = Number(facet.min);
    var max = Number(facet.max);
    var step = Number(facet.step);
    if (!(step > 0) || !(max > min)) {
      return max;
    }
    return Math.round((min + Math.floor((max - min) / step + 1e-9) * step) * 1e6) / 1e6;
  }

  // Limiti di uno slider dal valore nell'indirizzo («<min>-<max>»). Estremi aperti: un lato vuoto, al minimo o
  // all'ultimo passo non pone limiti da quel lato; un valore malformato non pone limiti.
  function sliderBounds(facet, value) {
    var open = { min: null, max: null };
    var match = /^\s*(\d+(?:[.,]\d+)?)?\s*-\s*(\d+(?:[.,]\d+)?)?\s*$/.exec(String(value || ''));
    if (!match) {
      return open;
    }
    var number = function (raw) { return raw === undefined ? null : Number(String(raw).replace(',', '.')); };
    var low = number(match[1]);
    var high = number(match[2]);
    // Un intervallo rovesciato scritto a mano («10-4») vale in ordine
    if (low !== null && high !== null && low > high) {
      var swap = low;
      low = high;
      high = swap;
    }
    return {
      min: low !== null && low > Number(facet.min) ? low : null,
      max: high !== null && high < lastStep(facet) ? high : null
    };
  }

  // Valore di uno slider dai suoi limiti: «<min>-<max>» con un lato vuoto se aperto, '' senza limiti (un valore malformato
  // non è un filtro attivo). Come wmwp_grid_slider_value() di includes/grid.php
  function sliderValue(facet, value) {
    var bounds = sliderBounds(facet, value);
    if (bounds.min === null && bounds.max === null) {
      return '';
    }
    return (bounds.min === null ? '' : String(bounds.min)) + '-' + (bounds.max === null ? '' : String(bounds.max));
  }

  // Un percorso senza valore (0 nell'indice) non passa un filtro attivo; senza limiti passa sempre.
  function matchesSlider(hit, facet, chosen) {
    var bounds = sliderBounds(facet, chosen && chosen.length ? chosen[0] : '');
    if (bounds.min === null && bounds.max === null) {
      return true;
    }
    var value = Number(fieldValue(hit, facet.field));
    if (!(value > 0)) {
      return false;
    }
    return (bounds.min === null || value >= bounds.min) && (bounds.max === null || value <= bounds.max);
  }

  function hasSliderValue(hit, facet) {
    return Number(fieldValue(hit, facet.field)) > 0;
  }

  function matchesFacet(hit, facet, chosen, lang) {
    if (facet.type === 'slider') {
      return matchesSlider(hit, facet, chosen);
    }
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
      if (facet.type === 'slider') {
        // Uno slider si mostra solo se qualche percorso, con gli altri filtri, ha il valore (oc:9003)
        return {
          key: facet.key,
          label: facet.label,
          type: 'slider',
          disabled: !!facet.disabled,
          min: facet.min,
          max: facet.max,
          step: facet.step,
          units: facet.units,
          value: chosen.length ? sliderValue(facet, chosen[0]) : '',
          available: !facet.disabled && filterHits(hits, facets, selected, lang, facet.key).some(function (hit) {
            return hasSliderValue(hit, facet);
          })
        };
      }
      var view = { key: facet.key, label: facet.label, disabled: !!facet.disabled, values: [] };
      if (facet.disabled) {
        return view;
      }
      var counts = {};
      var labels = {};
      var langs = {};
      filterHits(hits, facets, selected, lang, facet.key).forEach(function (hit) {
        var seen = {};
        facetValues(hit, facet, lang).forEach(function (value) {
          if (seen[value.id]) {
            return;
          }
          seen[value.id] = true;
          counts[value.id] = (counts[value.id] || 0) + 1;
          labels[value.id] = value.label;
          if (value.lang) {
            langs[value.id] = value.lang;
          }
        });
      });
      // Un valore scelto che con gli altri filtri non ha risultati resta, con 0: nome e lingua da un percorso qualsiasi
      // che lo ha (anche le faccette di termini senza `labels` né `groups`), poi dal tema, poi l'identifier
      var zero = chosen.filter(function (id) { return !(id in counts); });
      if (zero.length) {
        var known = {};
        hits.some(function (hit) {
          facetValues(hit, facet, lang).forEach(function (value) {
            if (zero.indexOf(value.id) !== -1 && !known[value.id]) {
              known[value.id] = value;
            }
          });
          return zero.every(function (id) { return known[id]; });
        });
        zero.forEach(function (id) {
          counts[id] = 0;
          var group = (facet.groups || []).find(function (item) { return String(item.id) === id; });
          labels[id] = (known[id] && known[id].label) || (facet.labels && facet.labels[id]) || (group && group.label) || id;
          if (known[id] && known[id].lang) {
            langs[id] = known[id].lang;
          } else if (!known[id] && facet.labels && facet.labels[id] && facet.lang_labels && facet.lang_labels[id]) {
            langs[id] = String(facet.lang_labels[id]);
          }
        });
      }
      var order = facet.order || [];
      view.values = Object.keys(counts)
        .map(function (id) {
          var value = { id: id, label: labels[id], count: counts[id], selected: chosen.indexOf(id) !== -1 };
          if (langs[id]) {
            value.lang = langs[id];
          }
          return value;
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

  // Faccetta con un parametro condiviso (oc:9018: le aree degli overlay stanno tutte in `layer`, come nelle app):
  // { key, param, ids }. Le altre si danno con la sola chiave.
  function sharedParam(item) {
    return item && typeof item === 'object' && item.param ? item : null;
  }

  // Stato della ricerca dall'indirizzo. `params` dà i nomi dei parametri ({q, tab, page}); `facetKeys` le chiavi dei
  // filtri, o le faccette con un parametro condiviso, che ne prendono solo i loro `ids`.
  function parseState(search, params, facetKeys) {
    var query = new URLSearchParams(search || '');
    var page = parseInt(query.get(params.page) || '', 10);
    var filters = {};
    var split = function (raw) {
      return String(raw || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean);
    };
    (facetKeys || []).forEach(function (item) {
      var shared = sharedParam(item);
      var key = shared ? shared.key : item;
      var list = split(query.get(shared ? shared.param : key));
      if (shared) {
        var ids = (shared.ids || []).map(String);
        list = list.filter(function (id) { return ids.indexOf(id) !== -1; });
      }
      if (list.length) {
        filters[key] = list;
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

  function stateToSearch(state, params, facetKeys) {
    var query = new URLSearchParams();
    var shared = {};
    (facetKeys || []).forEach(function (item) {
      if (sharedParam(item)) {
        shared[item.key] = item.param;
      }
    });
    var merged = {};
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
      if (!list.length) {
        return;
      }
      if (shared[key]) {
        merged[shared[key]] = (merged[shared[key]] || []).concat(list.map(String));
        return;
      }
      query.set(key, list.join(','));
    });
    // Un parametro condiviso: un valore solo, senza doppioni, in ordine numerico (lo stesso stato, lo stesso indirizzo)
    Object.keys(merged).forEach(function (param) {
      var list = merged[param].filter(function (id, i, all) { return all.indexOf(id) === i; });
      list.sort(function (a, b) { return (Number(a) - Number(b)) || (a < b ? -1 : a > b ? 1 : 0); });
      query.set(param, list.join(','));
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
    termName: termName,
    localized: localized,
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
    countLabel: countLabel,
    langFor: langFor,
    fieldValue: fieldValue,
    lastStep: lastStep,
    sliderBounds: sliderBounds,
    sliderValue: sliderValue
  };
});
