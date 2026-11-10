/*
 * Controller della pagina di ricerca (oc:8952). Legge lo stato dall'indirizzo, chiede i risultati alle
 * source registrate e disegna tab, gruppi del tab «Tutti», righe o card, filtri e pagine dentro lo scheletro
 * stampato da templates/search.php.
 *
 * Interfaccia pubblica: window.wmWpSearch.registerSource(id, { search(query, signal) }), dove
 *   query  = { text, rawText, page, perPage, filters, lang } (text ripulito per Elasticsearch con cleanQuery,
 *            rawText com'è stato scritto)
 *   search → Promise<{ total, items: Card[], facets?: FacetView[], summary?: string, ids?: string[] }>
 *   Card   = { id, title, url|null, typeKey, typeLabel, image: {src, alt}|null, code|null, meta: string[],
 *              badge: {key, label}|null }
 * Una source può permettere la selezione e lo scarico di più risultati (righe con la casella, «Seleziona tutti»,
 * barra della selezione) con `selection`:
 *   selection.summary(ids, lang) → string (es. «3 percorsi selezionati · 12,4 km»)
 *   selection.download(ids, format, signal, onProgress(done, total))
 *     → Promise<{ content, mime, name, count, missing: string[] }>
 * e `ids` nella risposta di search: tutti i risultati della ricerca, per «Seleziona tutti».
 * Una source può mostrare i risultati su una mappa (selettore Elenco | Mappa, vista nell'indirizzo) con `map`:
 *   map.collection(ids, lang, signal, onProgress(done, total, partial), selected)
 *     → Promise<{ collection: FeatureCollection, missing: string[] }>
 *   (selected: gli id scelti; la source li mette in primo piano, e la mappa si aggiorna quando la selezione cambia;
 *   partial(), se c'è, dà la FeatureCollection di quello che è già arrivato: la mappa la disegna mentre aspetta)
 *   map.card(id, lang) → Card|null: la card del riquadro che si apre al clic su un percorso della mappa
 * e `ids` nella risposta di search. La mappa è window.wmWpMap (includes/map.php, assets/dist/): pesa 800 KB e si carica
 * al primo passaggio alla vista mappa, dagli indirizzi in `config.map`.
 * Le source si registrano prima di DOMContentLoaded (script in fondo alla pagina, dopo questo).
 */
(function () {
  'use strict';

  var config = window.wmWpSearchConfig || { params: { q: 'q', tab: 'tab', page: 'pg', view: 'view', map: 'map' }, sources: [], lang: 'en' };
  var L = window.wmWpSearchLogic;
  var i18n = (window.wp && window.wp.i18n) || {};
  var __ = i18n.__ || function (text) { return text; };
  var sprintf = function (format) {
    var args = Array.prototype.slice.call(arguments, 1);
    return i18n.sprintf ? i18n.sprintf.apply(null, [format].concat(args)) : format.replace('%s', args[0]);
  };
  var DEBOUNCE_MS = 400;
  var SOFT_LIMIT = 5;
  var OVERVIEW_LIMIT = 40;
  // Tempo massimo di una source, poi va in errore: un'API lenta non tiene la pagina in attesa per sempre
  var TIMEOUT_MS = config.timeout_ms || 15000;

  var impls = {};
  var sources = config.sources || [];
  var facetKeys = [];
  sources.forEach(function (source) {
    (source.facets || []).forEach(function (facet) {
      if (facetKeys.indexOf(facet.key) === -1) {
        facetKeys.push(facet.key);
      }
    });
  });

  window.wmWpSearch = {
    config: config,
    registerSource: function (id, impl) {
      if (impl && typeof impl.search === 'function') {
        impls[id] = impl;
      }
    }
  };

  var dom = {};
  var state = { text: '', tab: '', page: 1, filters: {}, map: false };
  var controller = null;
  var overviews = new Map();
  var typingTimer = null;
  // Vero mentre si scrive una ricerca che ha già il suo passo nella cronologia
  var typingEntry = false;
  // Vero se dopo il prossimo disegno dei tab il focus va sul tab attivo
  var focusTab = false;
  var expandedFacets = {};
  var lastStatus = '';
  var statusTail = null;
  var defaultLabel = '';
  var filtersLabel = '';
  // Posizione dell'etichetta appena tolta: dopo il ridisegno il focus va sull'etichetta che ha preso il suo posto
  var focusChip = null;
  // Casella appena cambiata: i filtri si ridisegnano, e chi usa la tastiera deve restare su quella casella
  var focusFacet = null;
  // Selezione per source: id scelti, nell'ordine della scelta. Resta cambiando testo, filtri e tab, finché non si
  // toglie («Deseleziona tutto»); non sta nell'indirizzo, che resta quello della ricerca.
  var selected = {};
  // Source del tab aperto, se permette la selezione; tutti i risultati della ricerca aperta; scarico in corso
  var selectionSource = null;
  var searchIds = [];
  var downloading = null;
  var FORMAT_LABELS = { gpx: 'GPX', kml: 'KML', geojson: 'GeoJSON' };
  // Mappa dei risultati: caricamento del file della mappa, mappa montata (una sola, aggiornata a ogni ricerca), Map di
  // OpenLayers per ridimensionarla quando torna visibile, richiesta delle tracce in corso
  var mapLoader = null;
  var mapInstance = null;
  var olMap = null;
  var mapRun = null;
  // Source e risultati della mappa aperta: quando cambia la selezione la mappa si ridisegna con gli stessi risultati
  var mapSource = null;
  var mapIds = [];
  var MAP_HEIGHT = 420;

  function q(name) {
    return dom.root.querySelector('[data-wmwp="' + name + '"]');
  }

  function activeSources() {
    return sources.filter(function (source) { return impls[source.id]; });
  }

  function sourceBySlug(slug) {
    return activeSources().find(function (source) { return source.slug === slug; }) || null;
  }

  function filtersFor(source) {
    var picked = {};
    (source.facets || []).forEach(function (facet) {
      if (state.filters[facet.key] && state.filters[facet.key].length) {
        picked[facet.key] = state.filters[facet.key].slice();
      }
    });
    return picked;
  }

  function hasFilters(source) {
    return Object.keys(filtersFor(source)).length > 0;
  }

  // Una ricerca vera: un testo (non fatto di soli caratteri speciali) o almeno un filtro. Senza, i risultati sono tutto
  // il catalogo e «Seleziona tutti» non si mostra (Rubens, 10/11); con un filtro sì, anche senza testo: è il caso
  // d'uso del resoconto del 12/08, § 4.3, «selezionare un comune e scaricare in un'unica operazione tutti i sentieri»
  function hasQuery(source) {
    return !!L.cleanQuery(state.text) || hasFilters(source);
  }

  function formatNumber(n) {
    return new Intl.NumberFormat(config.lang).format(n);
  }

  function countText(source, n) {
    return L.countLabel(n, source.nouns || { one: '%s', other: '%s' }, config.lang);
  }

  function isAbort(error) {
    return error && error.name === 'AbortError';
  }

  function selectable(source) {
    var impl = source && impls[source.id];
    return !!(impl && impl.selection && typeof impl.selection.download === 'function' && typeof impl.selection.summary === 'function');
  }

  function selectedFor(source) {
    return (source && selected[source.id]) || [];
  }

  // --- indirizzo -------------------------------------------------------------------------------------

  function writeUrl(push) {
    var url = window.location.pathname + L.stateToSearch(state, config.params) + window.location.hash;
    if (push) {
      window.history.pushState(null, '', url);
    } else {
      window.history.replaceState(null, '', url);
    }
  }

  function readUrl() {
    state = L.parseState(window.location.search, config.params, facetKeys);
    if (state.tab && !sourceBySlug(state.tab)) {
      state.tab = '';
    }
    dom.input.value = state.text;
  }

  // --- dati --------------------------------------------------------------------------------------------

  // Testo da passare alle source: ripulito per Elasticsearch, e com'è stato scritto per le altre. Un testo
  // fatto solo di caratteri speciali vale come nessun testo per tutte le source.
  function queryFor(source, text, page, perPage, filters) {
    var clean = L.cleanQuery(text);
    return { text: clean, rawText: clean ? text : '', page: page, perPage: perPage, filters: filters, lang: config.lang };
  }

  // Una ricerca di una source con il suo tempo massimo: scaduto, la richiesta si annulla e la source va in
  // errore («—»), così una source lenta non tiene la pagina in attesa per sempre. L'annullamento della
  // ricerca intera (testo cambiato) passa invece come AbortError e non disegna niente.
  function searchSource(source, query, signal) {
    var local = new AbortController();
    var onAbort = function () { local.abort(); };
    signal.addEventListener('abort', onAbort);
    var timer = setTimeout(function () { local.abort(); }, TIMEOUT_MS);
    var done = function () {
      clearTimeout(timer);
      signal.removeEventListener('abort', onAbort);
    };
    return impls[source.id].search(query, local.signal).then(
      function (result) {
        done();
        return result;
      },
      function (error) {
        done();
        if (signal.aborted) {
          throw error;
        }
        throw isAbort(error) ? new Error('wp-package: timeout of source ' + source.id) : error;
      }
    );
  }

  // Totale e prime card di una source per un testo: servono al tab «Tutti» e ai numeri dei tab, che
  // dipendono solo dal testo. In memoria solo le risposte riuscite: dopo un errore, la ricerca successiva
  // con lo stesso testo riprova.
  function overviewFor(source, text, signal) {
    var key = source.id + '\n' + text;
    var cached = overviews.get(key);
    if (cached && (cached.done || !cached.signal.aborted)) {
      return cached.promise;
    }
    var promise = searchSource(source, queryFor(source, text, 1, source.preview || 4, {}), signal).then(
      function (result) { return { source: source, total: result.total, items: result.items || [], error: null }; },
      function (error) {
        if (isAbort(error) && signal.aborted) {
          throw error;
        }
        return { source: source, total: null, items: [], error: error };
      }
    );
    var entry = { promise: promise, signal: signal, done: false };
    overviews.set(key, entry);
    // Al massimo OVERVIEW_LIMIT ricerche in memoria: si tolgono le più vecchie
    while (overviews.size > OVERVIEW_LIMIT) {
      overviews.delete(overviews.keys().next().value);
    }
    promise.then(
      function (group) {
        if (group.error) {
          if (overviews.get(key) === entry) {
            overviews.delete(key);
          }
        } else {
          entry.done = true;
        }
      },
      function () {
        if (overviews.get(key) === entry) {
          overviews.delete(key);
        }
      }
    );
    return promise;
  }

  function setBusy(busy) {
    dom.results.setAttribute('aria-busy', busy ? 'true' : 'false');
    dom.root.classList.toggle('is-loading', busy);
  }

  // Ogni source parte subito e per conto suo: il tab aperto non aspetta i numeri degli altri tab, e il tab
  // «Tutti» disegna ogni gruppo quando arriva la sua risposta.
  function run() {
    if (controller) {
      controller.abort();
    }
    controller = new AbortController();
    var signal = controller.signal;
    var text = state.text;
    var active = sourceBySlug(state.tab);
    var list = activeSources();
    var groups = {};
    var remaining = list.length;
    var sections = null;
    setBusy(true);
    renderTabs(groups);
    renderChrome(active);

    list.forEach(function (source) {
      overviewFor(source, text, signal).then(function (group) {
        if (signal.aborted) {
          return;
        }
        groups[source.id] = group;
        remaining--;
        renderTabs(groups);
        if (!active) {
          sections = renderGroup(sections, groups, group, remaining === 0);
          if (remaining === 0) {
            setBusy(false);
          }
        }
      }, function () {});
    });

    if (!active) {
      if (!list.length) {
        sections = renderGroup(sections, groups, null, true);
        setBusy(false);
      }
      return;
    }
    var query = queryFor(active, text, state.page, active.per_page || 12, filtersFor(active));
    searchSource(active, query, signal).then(
      function (result) {
        if (!signal.aborted) {
          renderSource(active, result, null);
          setBusy(false);
        }
      },
      function (error) {
        if (!signal.aborted && !isAbort(error)) {
          renderSource(active, null, error);
          setBusy(false);
        }
      }
    );
  }

  // --- disegno -----------------------------------------------------------------------------------------

  // Il numero iniziale in grassetto, come il totale della tavola 2 («<b>28</b> sentieri · 179,5 km complessivi»); il
  // testo letto dagli screen reader resta la frase intera
  // Con la stessa frase di prima (un filtro che non cambia il totale) uno screen reader non riannuncia niente: si
  // aggiunge o si toglie un carattere invisibile in fondo, così la zona role="status" cambia e la frase si rilegge
  function setStatus(text) {
    if (text === lastStatus) {
      // Un nodo solo in coda, che alterna vuoto e carattere invisibile: il testo cambia e lo screen reader lo rilegge
      if (text) {
        if (!statusTail || statusTail.parentNode !== dom.status) {
          statusTail = dom.status.appendChild(document.createTextNode(''));
        }
        statusTail.nodeValue = statusTail.nodeValue ? '' : '\u200b';
      }
      return;
    }
    lastStatus = text;
    dom.status.textContent = '';
    var match = /^(\d[\d.,\u00a0\u202f]*)(.*)$/.exec(text);
    if (match) {
      var number = document.createElement('b');
      number.textContent = match[1];
      dom.status.appendChild(number);
      dom.status.appendChild(document.createTextNode(match[2]));
    } else {
      dom.status.textContent = text;
    }
  }

  // I tab si creano una volta e poi si aggiornano: chi li usa da tastiera non perde il focus mentre
  // arrivano i numeri. «…» = in attesa, «—» = errore.
  function renderTabs(groups) {
    var entries = [{ slug: '', label: __('All', 'wp-package'), group: null }].concat(activeSources().map(function (source) {
      return { slug: source.slug, label: source.label, group: groups[source.id], counted: true };
    }));
    if (dom.tabs.children.length !== entries.length) {
      dom.tabs.textContent = '';
      entries.forEach(function (entry) {
        var tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'wmwp-search__tab';
        tab.id = 'wmwp-tab-' + (entry.slug || 'all');
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', 'wmwp-panel');
        tab.dataset.slug = entry.slug;
        tab.appendChild(document.createTextNode(entry.label));
        if (entry.counted) {
          var count = document.createElement('span');
          count.className = 'wmwp-search__tab-count';
          tab.appendChild(count);
        }
        dom.tabs.appendChild(tab);
      });
    }
    entries.forEach(function (entry, index) {
      var tab = dom.tabs.children[index];
      var selected = entry.slug === state.tab;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
      tab.tabIndex = selected ? 0 : -1;
      var count = tab.querySelector('.wmwp-search__tab-count');
      if (count) {
        var group = entry.group;
        count.textContent = ' (' + (!group ? '…' : group.error ? '—' : formatNumber(group.total)) + ')';
      }
    });
    dom.panel.setAttribute('aria-labelledby', 'wmwp-tab-' + (state.tab || 'all'));
    if (focusTab) {
      focusTab = false;
      document.getElementById('wmwp-tab-' + (state.tab || 'all')).focus();
    }
  }

  function fill(template, item, layout, source) {
    var node = document.importNode(template.content, true).firstElementChild;
    var part = function (name) { return node.querySelector('[data-wmwp="' + name + '"]'); };
    if (item.typeKey) {
      node.classList.add((layout === 'rows' ? 'wmwp-row--type-' : 'wmwp-card--type-') + item.typeKey);
    }
    var select = part('select');
    if (select) {
      if (selectable(source)) {
        var id = String(item.id);
        select.value = id;
        select.checked = selectedFor(source).indexOf(id) !== -1;
        node.classList.toggle('is-selected', select.checked);
        /* translators: %s: code and name of the result (e.g. C 104 A Andala Segura) */
        select.setAttribute('aria-label', sprintf(__('Select %s', 'wp-package'), [item.code, item.title].filter(Boolean).join(' ')));
        select.addEventListener('change', function () {
          setSelected(source, [id], select.checked);
        });
      } else {
        (select.closest('.wmwp-row__select') || select).remove();
      }
    }
    var link = part('link');
    if (link) {
      if (item.url) {
        link.setAttribute('href', item.url);
      } else {
        link.removeAttribute('href');
      }
    }
    var image = part('image');
    if (image) {
      if (item.image && item.image.src) {
        image.src = item.image.src;
        image.alt = item.image.alt || '';
      } else {
        (image.closest('.wmwp-card__media') || image).remove();
      }
    }
    var setText = function (name, value) {
      var element = part(name);
      if (!element) {
        return;
      }
      if (value) {
        element.textContent = value;
      } else {
        element.remove();
      }
    };
    setText('type', item.typeLabel);
    setText('code', item.code);
    setText('title', item.title);
    setText('meta', (item.meta || []).filter(Boolean).join(' · '));
    var badge = part('badge');
    if (badge) {
      if (item.badge && item.badge.label) {
        badge.textContent = item.badge.label;
        badge.classList.add('wmwp-badge--' + item.badge.key);
      } else {
        badge.remove();
      }
    }
    return node;
  }

  function list(items, layout, source) {
    var ul = document.createElement('ul');
    ul.className = 'wmwp-search__list wmwp-search__list--' + layout + (selectable(source) ? ' is-selectable' : '');
    var template = document.getElementById(layout === 'rows' ? 'wmwp-row' : 'wmwp-card');
    items.forEach(function (item) { ul.appendChild(fill(template, item, layout, source)); });
    return ul;
  }

  // --- selezione ---------------------------------------------------------------------------------------

  // Sceglie o toglie degli id e aggiorna caselle, «Seleziona tutti» e barra senza ridisegnare l'elenco: chi usa
  // la tastiera resta sulla casella
  // quiet: la selezione arriva dalla mappa (pulsante del riquadro): la mappa cambia solo i colori, senza reinquadrare e
  // senza chiudere il riquadro
  function setSelected(source, ids, on, quiet) {
    var list = selectedFor(source).filter(function (id) { return ids.indexOf(id) === -1; });
    if (on) {
      list = list.concat(ids);
    }
    selected[source.id] = list;
    syncSelection();
    refreshMap(quiet);
  }

  function syncSelection() {
    var chosen = selectedFor(selectionSource);
    Array.prototype.forEach.call(dom.results.querySelectorAll('input[data-wmwp="select"]'), function (input) {
      input.checked = chosen.indexOf(input.value) !== -1;
      var row = input.closest('.wmwp-row');
      if (row) {
        row.classList.toggle('is-selected', input.checked);
      }
    });
    var all = dom.results.querySelector('[data-wmwp="select-all"]');
    if (all) {
      var count = searchIds.filter(function (id) { return chosen.indexOf(id) !== -1; }).length;
      all.checked = count > 0 && count === searchIds.length;
      all.indeterminate = count > 0 && count < searchIds.length;
    }
    renderSelection();
  }

  // «Seleziona tutti i N percorsi della ricerca»: tutta la ricerca, non solo la pagina (wireframe, tavola 2)
  function selectAllControl(source, ids) {
    var label = document.createElement('label');
    label.className = 'wmwp-search__select-all';
    var input = document.createElement('input');
    input.type = 'checkbox';
    input.dataset.wmwp = 'select-all';
    input.addEventListener('change', function () { setSelected(source, ids, input.checked); });
    label.appendChild(input);
    var text = document.createElement('span');
    if (ids.length === 1) {
      /* translators: %s: number and type of results (e.g. 1 trail) */
      text.textContent = sprintf(__('Select %s in this search', 'wp-package'), countText(source, 1));
    } else {
      /* translators: %s: number and type of results (e.g. 42 trails) */
      text.textContent = sprintf(__('Select all %s in this search', 'wp-package'), countText(source, ids.length));
    }
    label.appendChild(text);
    return label;
  }

  function currentFormat() {
    var radio = dom.selection.querySelector('input[name="wmwp-format"]:checked');
    return radio ? radio.value : 'gpx';
  }

  // Barra in fondo: riepilogo, «Deseleziona tutto», formato e «Scarica N percorsi in GPX». Solo nel tab di una source
  // che permette la selezione e con almeno un risultato scelto.
  // Con la barra della selezione fissa in fondo (dal tema), lo scorrimento tiene libero il suo spazio: il campo che
  // prende il focus con Tab non finisce sotto la barra (WCAG 2.4.11, tecnica C43: scroll-padding). L'altezza della
  // barra resta nella variabile CSS --wmwp-selection-height di <html> (0px senza barra), per gli elementi fissi del
  // tema che la barra coprirebbe (es. il pulsante «torna su»)
  function syncSelectionSpace() {
    var root = document.documentElement;
    var fixed = !dom.selection.hidden && /fixed|sticky/.test(window.getComputedStyle(dom.selection).position);
    var height = fixed ? Math.ceil(dom.selection.getBoundingClientRect().height) : 0;
    // 12px: il `bottom` della barra nel tema; 16px: il contorno del focus (3px, 2px fuori dall'elemento) e un po' d'aria
    root.style.scrollPaddingBottom = fixed ? (height + 12 + 16) + 'px' : '';
    root.style.setProperty('--wmwp-selection-height', height + 'px');
  }

  function renderSelection() {
    var source = selectionSource;
    var ids = selectedFor(source);
    dom.selection.hidden = !ids.length;
    dom.root.classList.toggle('has-selection', ids.length > 0);
    if (!ids.length) {
      syncSelectionSpace();
      return;
    }
    if (source.selection_label) {
      dom.selection.setAttribute('aria-label', source.selection_label);
    }
    // «<b>3 percorsi selezionati</b> · 22,6 km · +1.744 m», come nella tavola 2
    var parts = impls[source.id].selection.summary(ids, config.lang).split(' · ');
    dom.selectionCount.textContent = '';
    var count = document.createElement('b');
    count.textContent = parts[0];
    dom.selectionCount.appendChild(count);
    if (parts.length > 1) {
      dom.selectionCount.appendChild(document.createTextNode(' · ' + parts.slice(1).join(' · ')));
    }
    /* translators: 1: number and type of results (e.g. 3 trails), 2: file format (e.g. GPX) */
    dom.selectionDownload.textContent = sprintf(__('Download %1$s as %2$s', 'wp-package'), countText(source, ids.length), FORMAT_LABELS[currentFormat()]);
    syncSelectionSpace();
  }

  function setSelectionStatus(text) {
    dom.selectionStatus.textContent = text;
  }

  function saveFile(file) {
    var url = URL.createObjectURL(new Blob([file.content], { type: file.mime }));
    var link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
  }

  // Un file con tutti i risultati scelti, nel formato scelto. Mentre si prepara il pulsante non riparte e la barra
  // dice a che punto è; alla fine dice quanti percorsi ci sono e quali sono rimasti fuori perché senza tracciato.
  function startDownload() {
    var source = selectionSource;
    var ids = selectedFor(source).slice();
    if (!source || !ids.length || downloading) {
      return;
    }
    var format = currentFormat();
    var progress = function (done, total) {
      /* translators: 1: results ready, 2: number and type of results (e.g. 769 trails) */
      setSelectionStatus(sprintf(__('Preparing the file: %1$s of %2$s', 'wp-package'), formatNumber(Math.min(done, total)), countText(source, total)));
    };
    downloading = new AbortController();
    dom.selectionDownload.setAttribute('aria-disabled', 'true');
    dom.root.classList.add('is-downloading');
    progress(0, ids.length);
    impls[source.id].selection.download(ids, format, downloading.signal, progress).then(
      function (file) {
        if (!file.count) {
          setSelectionStatus(__('None of the selected results has a route.', 'wp-package'));
          return;
        }
        saveFile(file);
        /* translators: 1: number and type of results (e.g. 3 trails), 2: file format (e.g. GPX) */
        var text = sprintf(__('File ready: %1$s as %2$s.', 'wp-package'), countText(source, file.count), FORMAT_LABELS[format]);
        if (file.missing && file.missing.length) {
          /* translators: %s: number and type of results (e.g. 2 trails) */
          text += ' ' + sprintf(__('Not included because they have no route: %s.', 'wp-package'), countText(source, file.missing.length));
        }
        setSelectionStatus(text);
      },
      function (error) {
        if (!isAbort(error)) {
          setSelectionStatus(__('The download did not work. Please try again.', 'wp-package'));
        }
      }
    ).then(function () {
      downloading = null;
      dom.selectionDownload.removeAttribute('aria-disabled');
      dom.root.classList.remove('is-downloading');
    });
  }

  function clearSelection() {
    if (!selectionSource) {
      return;
    }
    if (downloading) {
      downloading.abort();
    }
    selected[selectionSource.id] = [];
    setSelectionStatus('');
    syncSelection();
    refreshMap();
    var all = dom.results.querySelector('[data-wmwp="select-all"]');
    (all || dom.input).focus();
  }

  function message(text, withClear) {
    var box = document.createElement('div');
    box.className = 'wmwp-search__message';
    var p = document.createElement('p');
    p.textContent = text;
    box.appendChild(p);
    if (withClear) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'wmwp-search__clear';
      button.textContent = __('Remove all filters', 'wp-package');
      button.addEventListener('click', clearFilters);
      box.appendChild(button);
    }
    return box;
  }

  function noResultsText() {
    if (!state.text) {
      return __('No results.', 'wp-package');
    }
    /* translators: %s: the searched text */
    return sprintf(__('No results for “%s”. Check the spelling or try a shorter text.', 'wp-package'), state.text);
  }

  function fillGroup(section, group) {
    var source = group.source;
    section.textContent = '';
    section.hidden = !group.error && !group.total;
    var title = document.createElement('h2');
    title.className = 'wmwp-search__group-title';
    /* translators: 1: result type (e.g. Trails), 2: number of results */
    title.textContent = group.error ? source.label : sprintf(__('%1$s: %2$s', 'wp-package'), source.label, formatNumber(group.total));
    section.appendChild(title);
    if (group.error) {
      section.appendChild(message(__('These results are not available right now. Please try again later.', 'wp-package'), false));
      return;
    }
    section.appendChild(list(group.items.slice(0, source.preview || 4), 'cards', null));
    if (group.total > (source.preview || 4)) {
      var more = document.createElement('button');
      more.type = 'button';
      more.className = 'wmwp-search__more';
      /* translators: %s: number and type of results (e.g. 42 trails) */
      more.textContent = sprintf(__('See all: %s', 'wp-package'), countText(source, group.total));
      more.addEventListener('click', function () { goToTab(source.slug); });
      section.appendChild(more);
    }
  }

  // Tab «Tutti»: alla prima risposta i risultati vecchi lasciano il posto a una sezione per source; ogni
  // sezione si riempie quando arriva la sua. Lo stato si scrive una volta, quando sono arrivate tutte.
  function renderGroup(sections, groups, group, complete) {
    if (!sections) {
      sections = {};
      dom.results.textContent = '';
      dom.pager.textContent = '';
      dom.pager.hidden = true;
      activeSources().forEach(function (source) {
        var section = document.createElement('section');
        section.className = 'wmwp-search__group wmwp-search__group--' + source.slug;
        var wait = document.createElement('p');
        wait.className = 'wmwp-search__group-wait';
        wait.textContent = source.label + ' …';
        section.appendChild(wait);
        dom.results.appendChild(section);
        sections[source.id] = section;
      });
    }
    if (group) {
      fillGroup(sections[group.source.id], group);
    }
    if (!complete) {
      return sections;
    }
    var parts = [];
    var shown = 0;
    activeSources().forEach(function (source) {
      var item = groups[source.id];
      if (item && (item.error || item.total > 0)) {
        shown++;
      }
      if (item && !item.error && item.total > 0) {
        parts.push(countText(source, item.total));
      }
    });
    if (!shown) {
      dom.results.appendChild(message(noResultsText(), false));
      setStatus(noResultsText());
    } else if (!parts.length) {
      // Solo source in errore e le altre senza risultati: il gruppo in errore lo dice, la riga di stato pure
      setStatus(__('These results are not available right now. Please try again later.', 'wp-package'));
    } else {
      setStatus(parts.join(' · '));
    }
    return sections;
  }

  // --- mappa dei risultati ---------------------------------------------------------------------------

  function mappable(source) {
    return !!(source && impls[source.id] && impls[source.id].map && config.map && config.map.script);
  }

  // Il file della mappa (script e stile) si carica una volta sola, al primo bisogno
  function loadMap() {
    if (window.wmWpMap) {
      return Promise.resolve(window.wmWpMap);
    }
    if (!mapLoader) {
      mapLoader = new Promise(function (resolve, reject) {
        if (config.map.style) {
          var link = document.createElement('link');
          link.rel = 'stylesheet';
          link.href = config.map.style;
          document.head.appendChild(link);
        }
        var script = document.createElement('script');
        script.src = config.map.script;
        script.async = true;
        script.onload = function () {
          if (window.wmWpMap) {
            resolve(window.wmWpMap);
          } else {
            mapLoader = null;
            reject(new Error('wp-package: map not loaded'));
          }
        };
        script.onerror = function () {
          mapLoader = null;
          script.remove();
          reject(new Error('wp-package: map not loaded'));
        };
        document.head.appendChild(script);
      });
    }
    return mapLoader;
  }

  function setMapNote(text) {
    dom.mapNote.textContent = text;
    dom.mapNote.hidden = !text;
  }

  // Riquadro al clic su un percorso: la card del risultato (template wmwp-card), con i dati già in memoria. Il clic sulla
  // mappa non apre il percorso: prima le informazioni essenziali, poi la scheda (resoconto del 12/08, § 4.2), che si
  // raggiungerà dalla card quando il percorso avrà un indirizzo (`link_template`, oggi vuoto)
  function mapPopup(properties) {
    var impl = mapSource && impls[mapSource.id];
    var item = impl && impl.map && typeof impl.map.card === 'function' ? impl.map.card(properties.id, config.lang) : null;
    var template = document.getElementById('wmwp-card');
    if (!item || !template) {
      return null;
    }
    var box = document.createElement('div');
    var list = document.createElement('ul');
    list.className = 'wmwp-map-popup__cards';
    list.appendChild(fill(template, item, 'cards', mapSource));
    box.appendChild(list);
    // Pulsante per scegliere il percorso per lo scarico senza tornare all'elenco: la scelta è esplicita, il clic sulla
    // mappa da solo non seleziona (Rubens, 10/11)
    if (selectable(mapSource)) {
      var source = mapSource;
      var id = String(item.id);
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'wmwp-map-popup__select';
      var show = function () {
        var on = selectedFor(source).indexOf(id) !== -1;
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.textContent = on ? __('Remove from selection', 'wp-package') : __('Add to selection', 'wp-package');
      };
      show();
      button.addEventListener('click', function () {
        setSelected(source, [id], selectedFor(source).indexOf(id) === -1, true);
        show();
      });
      box.appendChild(button);
    }
    return box;
  }

  // Le tracce di tutti i risultati della ricerca sulla mappa, inquadrata sul riquadro che li contiene. Mentre arrivano
  // la mappa disegna quelle già arrivate senza cambiare inquadratura: la prima volta subito, così la ricerca di prima
  // non resta sulla mappa mentre il totale è già quello nuovo, poi al massimo ogni 600 ms; alla fine inquadra tutto una
  // volta sola. Una ricerca nuova annulla le richieste di quella prima.
  function showMap(source, ids, quiet) {
    if (mapRun) {
      mapRun.abort();
    }
    mapRun = new AbortController();
    var signal = mapRun.signal;
    mapSource = source;
    mapIds = ids;
    dom.mapView.hidden = false;
    dom.mapView.setAttribute('aria-busy', 'true');
    if (olMap) {
      olMap.updateSize();
    }
    var partial = null;
    var lastDraw = 0;
    var draw = function (now) {
      if (signal.aborted || !mapInstance || !partial) {
        return;
      }
      if (!now && lastDraw && Date.now() - lastDraw < 600) {
        return;
      }
      lastDraw = Date.now();
      mapInstance.update(partial(), { keepView: true, keepPopup: !!quiet });
      partial = null;
    };
    var progress = function (done, total, current) {
      if (signal.aborted) {
        return;
      }
      /* translators: 1: routes loaded so far, 2: routes to load */
      setMapNote(sprintf(__('Loading the routes: %1$s of %2$s', 'wp-package'), formatNumber(done), formatNumber(total)));
      if (current) {
        partial = current;
        draw(false);
      }
    };
    progress(0, ids.length);
    var ready = loadMap().then(function (lib) {
      if (!signal.aborted && !mapInstance) {
        mapInstance = lib.mount(dom.map, {
          geojson: null,
          height: MAP_HEIGHT,
          popup: mapPopup,
          onReady: function (event) { olMap = event.map; },
          tiles: config.map.tiles && config.map.tiles.url ? config.map.tiles : null,
          fullScreen: true,
          labels: {
            zoomIn: __('Zoom in on the map', 'wp-package'),
            zoomOut: __('Zoom out of the map', 'wp-package'),
            fullScreen: __('Show the map full screen', 'wp-package'),
            exitFullScreen: __('Exit full screen', 'wp-package'),
            closePopup: __('Close', 'wp-package'),
            keyboard: __('Map: arrow keys to move it, + and − to zoom in and out', 'wp-package')
          }
        });
      }
      draw(true);
      return lib;
    });
    var chosen = selectedFor(source);
    Promise.all([ready, impls[source.id].map.collection(ids, config.lang, signal, progress, chosen)]).then(function (out) {
      if (signal.aborted) {
        return;
      }
      var data = out[1];
      mapInstance.update(data.collection, quiet ? { keepView: true, keepPopup: true } : {});
      dom.mapView.setAttribute('aria-busy', 'false');
      var features = data.collection.features;
      var inFocus = features.filter(function (feature) { return !feature.properties.context; }).length;
      var note = __('Framed on the area that contains all the results', 'wp-package');
      if (quiet) {
        // Selezione dalla mappa: la vista non è cambiata, quindi la nota non parla di inquadratura
        /* translators: %s: selected results, e.g. 3 trails selected */
        note = inFocus && inFocus < features.length ? sprintf(__('%s. The other results are dashed.', 'wp-package'), L.countLabel(inFocus, source.selected || source.nouns, config.lang)) : '';
      } else if (inFocus && inFocus < features.length) {
        /* translators: %s: selected results, e.g. 3 trails selected */
        note = sprintf(__('Framed on the selection: %s. The other results are dashed.', 'wp-package'), L.countLabel(inFocus, source.selected || source.nouns, config.lang));
      }
      if (data.missing.length) {
        /* translators: %s: number of results, e.g. 3 trails */
        note = [note, sprintf(__('Not on the map because they have no route: %s.', 'wp-package'), countText(source, data.missing.length))].filter(Boolean).join(' · ');
      }
      setMapNote(note);
    }, function (error) {
      if (signal.aborted || isAbort(error)) {
        return;
      }
      dom.mapView.setAttribute('aria-busy', 'false');
      setMapNote(__('The map is not available right now. Please try again later.', 'wp-package'));
    });
  }

  function hideMap() {
    if (mapRun) {
      mapRun.abort();
      mapRun = null;
    }
    mapSource = null;
    mapIds = [];
    dom.mapView.hidden = true;
  }

  // Selezione cambiata con la mappa aperta («Seleziona tutti», «Deseleziona tutto»): le tracce sono già in memoria
  function refreshMap(quiet) {
    if (mapSource && !dom.mapView.hidden) {
      showMap(mapSource, mapIds, quiet);
    }
  }

  // Selettore Elenco | Mappa: visibile nei tab la cui source ha la mappa
  function renderView(active) {
    var withMap = mappable(active);
    dom.view.hidden = !withMap;
    Array.prototype.forEach.call(dom.view.querySelectorAll('button[data-view]'), function (button) {
      var on = (button.dataset.view === 'map') === (withMap && state.map);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
      button.classList.toggle('is-active', on);
    });
    if (!withMap || !state.map) {
      hideMap();
    }
  }

  function setView(map) {
    if (state.map === map) {
      return;
    }
    typingEntry = false;
    state.map = map;
    writeUrl(true);
    run();
  }

  function renderSource(source, result, error) {
    dom.results.textContent = '';
    dom.pager.textContent = '';
    dom.pager.hidden = true;
    searchIds = [];
    if (error) {
      hideMap();
      dom.results.appendChild(message(__('These results are not available right now. Please try again later.', 'wp-package'), false));
      dom.facets.textContent = '';
      // senza filtri da mostrare, niente chip né «Filtri (2)» davanti a una colonna vuota
      renderActive(null, []);
      setStatus(__('These results are not available right now. Please try again later.', 'wp-package'));
      return;
    }
    renderFacets(source, result.facets || []);
    renderActive(source, result.facets || []);
    var pages = Math.max(1, Math.ceil(result.total / (source.per_page || 12)));
    if (state.page > pages) {
      state.page = pages;
      writeUrl(false);
    }
    var onMap = state.map && mappable(source) && Array.isArray(result.ids);
    if (!result.total) {
      hideMap();
      dom.results.appendChild(message(noResultsText(), hasFilters(source)));
    } else {
      if (selectable(source) && Array.isArray(result.ids) && result.ids.length) {
        searchIds = result.ids.map(String);
        if (hasQuery(source)) {
          dom.results.appendChild(selectAllControl(source, searchIds));
        }
      }
      if (onMap) {
        // Vista mappa: al posto di elenco e pagine, come nella tavola 2; «Seleziona tutti» e la barra restano
        showMap(source, result.ids.map(String));
      } else {
        hideMap();
        dom.results.appendChild(list(result.items || [], source.layout === 'rows' ? 'rows' : 'cards', source));
        renderPager(pages);
      }
    }
    setStatus(result.summary || (result.total ? countText(source, result.total) : noResultsText()));
    syncSelection();
  }

  function renderFacets(source, views) {
    dom.facets.textContent = '';
    var configs = {};
    (source.facets || []).forEach(function (facet) { configs[facet.key] = facet; });
    views.forEach(function (view) {
      var facet = configs[view.key] || {};
      var fieldset = document.createElement('fieldset');
      fieldset.className = 'wmwp-facet' + (view.disabled ? ' is-disabled' : '');
      var legend = document.createElement('legend');
      legend.className = 'wmwp-facet__title';
      legend.textContent = view.label || facet.label || view.key;
      fieldset.appendChild(legend);
      if (view.disabled) {
        fieldset.disabled = true;
        if (facet.help) {
          var help = document.createElement('p');
          help.className = 'wmwp-facet__help';
          help.textContent = facet.help;
          fieldset.appendChild(help);
        }
        dom.facets.appendChild(fieldset);
        return;
      }
      if (!view.values.length) {
        return;
      }
      var expanded = !!expandedFacets[view.key];
      var ul = document.createElement('ul');
      ul.className = 'wmwp-facet__values';
      var hiddenCount = 0;
      view.values.forEach(function (value, index) {
        var li = document.createElement('li');
        li.className = 'wmwp-facet__value';
        if (index >= SOFT_LIMIT && !value.selected && !expanded) {
          li.hidden = true;
          hiddenCount++;
        }
        var label = document.createElement('label');
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.name = view.key;
        input.value = value.id;
        input.checked = value.selected;
        input.addEventListener('change', function () {
          focusFacet = { key: view.key, id: value.id };
          toggleFilter(view.key, value.id, input.checked);
        });
        var name = document.createElement('span');
        name.className = 'wmwp-facet__label';
        name.textContent = value.label;
        // Il numero si vede da solo; lo screen reader legge «Sentiero, 34 percorsi» invece di «Sentiero34»
        var count = document.createElement('span');
        count.className = 'wmwp-facet__count';
        count.setAttribute('aria-hidden', 'true');
        count.textContent = formatNumber(value.count);
        var spoken = document.createElement('span');
        spoken.className = 'screen-reader-text';
        spoken.textContent = ', ' + countText(source, value.count);
        label.appendChild(input);
        label.appendChild(name);
        label.appendChild(count);
        label.appendChild(spoken);
        li.appendChild(label);
        ul.appendChild(li);
      });
      fieldset.appendChild(ul);
      if (hiddenCount || (expanded && view.values.length > SOFT_LIMIT)) {
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'wmwp-facet__more';
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.textContent = expanded ? __('Show less', 'wp-package') : __('Show more', 'wp-package');
        toggle.addEventListener('click', function () {
          expandedFacets[view.key] = !expanded;
          renderFacets(source, views);
          var again = dom.facets.querySelector('fieldset .wmwp-facet__more[data-key="' + view.key + '"]');
          if (again) {
            again.focus();
          }
        });
        toggle.dataset.key = view.key;
        fieldset.appendChild(toggle);
      }
      dom.facets.appendChild(fieldset);
    });
    if (focusFacet) {
      var target = Array.prototype.find.call(dom.facets.querySelectorAll('input'), function (input) {
        return input.name === focusFacet.key && input.value === focusFacet.id;
      });
      focusFacet = null;
      if (target) {
        target.focus();
      }
    }
  }

  function renderPager(pages) {
    if (pages <= 1) {
      return;
    }
    dom.pager.hidden = false;
    var add = function (label, page, current, aria) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'wmwp-search__page' + (current ? ' is-current' : '');
      button.textContent = label;
      if (aria) {
        button.setAttribute('aria-label', aria);
      }
      if (current) {
        button.setAttribute('aria-current', 'page');
      }
      button.addEventListener('click', function () { goToPage(page); });
      dom.pager.appendChild(button);
    };
    if (state.page > 1) {
      add('‹', state.page - 1, false, __('Previous page', 'wp-package'));
    }
    var first = Math.max(1, Math.min(state.page - 2, pages - 4));
    var last = Math.min(pages, first + 4);
    for (var page = first; page <= last; page++) {
      /* translators: %s: page number */
      add(formatNumber(page), page, page === state.page, sprintf(__('Page %s', 'wp-package'), formatNumber(page)));
    }
    if (state.page < pages) {
      add('›', state.page + 1, false, __('Next page', 'wp-package'));
    }
  }

  // Filtri attivi sopra i risultati: un'etichetta per ogni valore scelto, che con un clic lo toglie, e «Togli tutti i
  // filtri». Si vedono anche con il pannello dei filtri chiuso (sotto 1024px), dove il pulsante dice quanti sono.
  function renderActive(source, views) {
    dom.active.textContent = '';
    var chosen = [];
    (source ? source.facets || [] : []).forEach(function (facet) {
      var view = views.find(function (item) { return item.key === facet.key; });
      (state.filters[facet.key] || []).forEach(function (id) {
        var value = view && view.values.find(function (item) { return item.id === id; });
        chosen.push({ key: facet.key, id: id, facet: (view && view.label) || facet.label || facet.key, label: value ? value.label : id });
      });
    });
    dom.active.hidden = !chosen.length;
    dom.openFilters.textContent = chosen.length ? filtersLabel + ' (' + formatNumber(chosen.length) + ')' : filtersLabel;
    if (!chosen.length) {
      if (focusChip !== null) {
        focusChip = null;
        dom.input.focus();
      }
      return;
    }
    var title = document.createElement('span');
    title.className = 'wmwp-search__active-title';
    title.textContent = __('Active filters:', 'wp-package');
    dom.active.appendChild(title);
    var ul = document.createElement('ul');
    ul.className = 'wmwp-search__chips';
    chosen.forEach(function (item, index) {
      var li = document.createElement('li');
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'wmwp-chip';
      /* translators: 1: filter name (e.g. Activity), 2: filter value (e.g. Mountain bike) */
      chip.setAttribute('aria-label', sprintf(__('Remove filter %1$s: %2$s', 'wp-package'), item.facet, item.label));
      chip.appendChild(document.createTextNode(item.label));
      var x = document.createElement('span');
      x.className = 'wmwp-chip__x';
      x.setAttribute('aria-hidden', 'true');
      x.textContent = '✕';
      chip.appendChild(x);
      chip.addEventListener('click', function () {
        focusChip = index;
        toggleFilter(item.key, item.id, false);
      });
      li.appendChild(chip);
      ul.appendChild(li);
    });
    dom.active.appendChild(ul);
    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'wmwp-search__clear-all';
    clear.textContent = __('Remove all filters', 'wp-package');
    clear.addEventListener('click', clearFilters);
    dom.active.appendChild(clear);
    if (focusChip !== null) {
      var chips = ul.querySelectorAll('.wmwp-chip');
      chips[Math.min(focusChip, chips.length - 1)].focus();
      focusChip = null;
    }
  }

  // Parti che dipendono dal tab: colonna dei filtri e testi del campo.
  function renderChrome(active) {
    selectionSource = selectable(active) ? active : null;
    renderSelection();
    renderView(active);
    var withFacets = !!(active && active.facets && active.facets.length);
    dom.filters.hidden = !withFacets;
    dom.openFilters.hidden = !withFacets;
    if (!withFacets) {
      closeFilters(false);
      dom.facets.textContent = '';
      renderActive(null, []);
    }
    dom.root.classList.toggle('has-filters', withFacets);
    dom.label.textContent = (active && active.search_label) || defaultLabel;
    if (active && active.placeholder) {
      dom.input.setAttribute('placeholder', active.placeholder);
    } else {
      dom.input.removeAttribute('placeholder');
    }
  }

  // --- azioni ------------------------------------------------------------------------------------------

  function focusResults() {
    dom.results.setAttribute('tabindex', '-1');
    dom.results.focus({ preventScroll: true });
    dom.results.scrollIntoView({ block: 'start' });
  }

  // Cambiando tab i filtri si tolgono: valgono per la source del tab in cui si sono scelti («Validazione» c'è solo per
  // i percorsi), e così «Vedi tutti: 37 percorsi» apre davvero 37 percorsi. «Indietro» ritrova la ricerca filtrata
  // (Rubens, 10/11, review interna di wp-package, punto 13a)
  function goToTab(slug) {
    typingEntry = false;
    state.tab = slug;
    state.page = 1;
    state.filters = {};
    writeUrl(true);
    focusTab = true;
    run();
  }

  function goToPage(page) {
    typingEntry = false;
    state.page = page;
    writeUrl(true);
    run();
    focusResults();
  }

  function toggleFilter(key, id, on) {
    typingEntry = false;
    var values = (state.filters[key] || []).filter(function (value) { return value !== id; });
    if (on) {
      values.push(id);
    }
    if (values.length) {
      state.filters[key] = values;
    } else {
      delete state.filters[key];
    }
    state.page = 1;
    writeUrl(true);
    run();
  }

  function clearFilters() {
    typingEntry = false;
    var active = sourceBySlug(state.tab);
    (active ? active.facets || [] : []).forEach(function (facet) { delete state.filters[facet.key]; });
    state.page = 1;
    writeUrl(true);
    run();
    dom.input.focus();
  }

  // Testo cercato. Quello che si scrive di seguito è un passo della cronologia: la prima pausa crea il passo
  // (pushState), le pause successive e l'Invio lo aggiornano (replaceState); dopo l'Invio, o un cambio di filtro, tab,
  // pagina o vista, il testo successivo ne crea un altro. Così «Indietro» torna alla ricerca precedente, non a ogni
  // parola scritta a metà.
  function setText(text, done) {
    if (text !== state.text) {
      state.text = text;
      state.page = 1;
      writeUrl(!typingEntry);
      typingEntry = true;
      run();
    }
    if (done) {
      typingEntry = false;
    }
  }

  // Sotto la soglia del tema i filtri sono un pannello a schermo intero: un dialog, con il focus che resta dentro
  // finché non si chiude (pulsante, Esc)
  function openFilters() {
    dom.root.classList.add('filters-open');
    dom.openFilters.setAttribute('aria-expanded', 'true');
    dom.filters.setAttribute('role', 'dialog');
    dom.filters.setAttribute('aria-modal', 'true');
    dom.filters.setAttribute('aria-labelledby', 'wmwp-filters-title');
    var first = dom.filters.querySelector('input:not([disabled]), button');
    if (first) {
      first.focus();
    }
  }

  function closeFilters(returnFocus) {
    if (!dom.root.classList.contains('filters-open')) {
      return;
    }
    dom.root.classList.remove('filters-open');
    dom.openFilters.setAttribute('aria-expanded', 'false');
    dom.filters.removeAttribute('role');
    dom.filters.removeAttribute('aria-modal');
    dom.filters.removeAttribute('aria-labelledby');
    if (returnFocus) {
      dom.openFilters.focus();
    }
  }

  function onTabsKey(event) {
    var tabs = Array.prototype.slice.call(dom.tabs.querySelectorAll('[role="tab"]'));
    var index = tabs.indexOf(document.activeElement);
    if (index === -1) {
      return;
    }
    var next = null;
    if (event.key === 'ArrowRight') {
      next = tabs[(index + 1) % tabs.length];
    } else if (event.key === 'ArrowLeft') {
      next = tabs[(index - 1 + tabs.length) % tabs.length];
    } else if (event.key === 'Home') {
      next = tabs[0];
    } else if (event.key === 'End') {
      next = tabs[tabs.length - 1];
    }
    if (next) {
      event.preventDefault();
      goToTab(next.dataset.slug);
    }
  }

  function init() {
    dom.root = document.querySelector('[data-wmwp="search"]');
    if (!dom.root || !L) {
      return;
    }
    ['tabs', 'panel', 'filters', 'facets', 'form', 'input', 'status', 'results', 'pager'].forEach(function (name) {
      dom[name] = q(name);
    });
    dom.label = q('search-label');
    dom.active = q('active');
    dom.openFilters = q('filters-open');
    dom.closeFilters = q('filters-close');
    dom.selection = q('selection');
    dom.selectionCount = q('selection-count');
    dom.selectionStatus = q('selection-status');
    dom.selectionDownload = q('selection-download');
    dom.view = q('view');
    dom.mapView = q('map-view');
    dom.map = q('map');
    dom.mapNote = q('map-note');
    defaultLabel = dom.label.textContent;
    filtersLabel = dom.openFilters.textContent;

    q('selection-clear').addEventListener('click', clearSelection);
    dom.view.addEventListener('click', function (event) {
      var button = event.target.closest('button[data-view]');
      if (button) {
        setView(button.dataset.view === 'map');
      }
    });
    dom.selectionDownload.addEventListener('click', startDownload);
    dom.selection.addEventListener('change', function (event) {
      if (event.target.name === 'wmwp-format') {
        renderSelection();
      }
    });

    dom.form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearTimeout(typingTimer);
      setText(dom.input.value.trim(), true);
    });
    dom.input.addEventListener('input', function () {
      clearTimeout(typingTimer);
      typingTimer = setTimeout(function () { setText(dom.input.value.trim(), false); }, DEBOUNCE_MS);
    });
    dom.tabs.addEventListener('click', function (event) {
      var tab = event.target.closest('[role="tab"]');
      if (tab && tab.dataset.slug !== state.tab) {
        goToTab(tab.dataset.slug);
      }
    });
    dom.tabs.addEventListener('keydown', onTabsKey);
    dom.openFilters.addEventListener('click', openFilters);
    dom.closeFilters.addEventListener('click', function () { closeFilters(true); });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeFilters(true);
      } else if (event.key === 'Tab' && dom.root.classList.contains('filters-open')) {
        var focusable = Array.prototype.slice.call(dom.filters.querySelectorAll('button, input:not([disabled])'))
          .filter(function (element) { return element.offsetParent !== null; });
        if (!focusable.length) {
          return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });
    // L'altezza della barra cambia con la larghezza (su un telefono va su più righe) e con l'avanzamento dello scarico
    window.addEventListener('resize', syncSelectionSpace);
    if (window.ResizeObserver) {
      new window.ResizeObserver(syncSelectionSpace).observe(dom.selection);
    }
    window.addEventListener('popstate', function () {
      // La voce ripristinata non è quella che si sta scrivendo: il prossimo testo apre una voce nuova
      clearTimeout(typingTimer);
      typingEntry = false;
      readUrl();
      run();
    });

    readUrl();
    run();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    setTimeout(init, 0);
  }
})();
