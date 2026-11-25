// Test della logica pura della ricerca (oc:8952): node --test tests/js/*.test.js
// La fixture sono 5 percorsi veri dell'API Elasticsearch del dev (09/11), ridotti ai campi usati; al 529
// è stato tolto `terms` per provare un percorso senza quel campo.
const test = require('node:test');
const assert = require('node:assert/strict');
const hits = require('./fixtures/hits.json');
const L = require('../../assets/js/search-logic.js');

const ACTIVITIES = { key: 'attivita', label: 'Attività', type: 'list', field: 'taxonomyActivities', exclude_prefix: 'sardegnasentieri:' };
const WHERE = { key: 'dove', label: 'Dove', type: 'list', field: 'taxonomyWheres' };
const DIFFICULTY = { key: 'difficolta', label: 'Difficoltà', type: 'terms', field: 'terms.hiking-difficulty' };
const PARAMS = { q: 'search_api_fulltext', tab: 'tipo', page: 'pagina', view: 'vista', map: 'mappa' };

const values = (facet) => Object.fromEntries(facet.values.map((v) => [v.id, v.count]));

test('cleanQuery toglie i caratteri che fanno rispondere 500 a Elasticsearch', () => {
  assert.equal(L.cleanQuery('(oliena'), 'oliena');
  assert.equal(L.cleanQuery('a/b'), 'a b');
  assert.equal(L.cleanQuery('"oliena:'), 'oliena');
  assert.equal(L.cleanQuery('  perda   liana '), 'perda liana');
  assert.equal(L.cleanQuery('/'), '');
});

test('cleanQuery tiene interi i codici con il trattino', () => {
  assert.equal(L.cleanQuery('B-401'), 'B\\-401');
});

test('cleanQuery scrive in minuscolo le parole operatore di Lucene, che danno 500', () => {
  assert.equal(L.cleanQuery('sentiero AND'), 'sentiero and');
  assert.equal(L.cleanQuery('a OR OR b'), 'a or or b');
  assert.equal(L.cleanQuery('NOT'), 'not');
  assert.equal(L.cleanQuery('Andalo'), 'Andalo');
});

test('cleanQuery toglie i trattini fra due parole, che azzerano i risultati', () => {
  assert.equal(L.cleanQuery('Jacca - Genna'), 'Jacca Genna');
  assert.equal(L.cleanQuery('B-401 - Oliena'), 'B\\-401 Oliena');
  assert.equal(L.cleanQuery('-'), '');
});

test('facetValues scarta i valori con il prefisso escluso e usa le etichette di taxonomyIcons', () => {
  const hit = hits.find((h) => h.id === 75);
  assert.deepEqual(
    L.facetValues(hit, ACTIVITIES, 'it').map((v) => v.id),
    ['mtb', 'hiking', 'horse']
  );
});

test('facetValues usa le etichette del tema prima di quelle dell\'API', () => {
  const hit = hits.find((h) => h.id === 75);
  const facet = { ...ACTIVITIES, labels: { hiking: 'Camminate giornaliere' } };
  assert.equal(L.facetValues(hit, facet, 'it').find((v) => v.id === 'hiking').label, 'Camminate giornaliere');
});

test('facetValues su un percorso senza il campo restituisce un elenco vuoto', () => {
  const hit = hits.find((h) => h.id === 529);
  assert.deepEqual(L.facetValues(hit, DIFFICULTY, 'it'), []);
});

test('facetValues in inglese ripiega sul nome italiano', () => {
  const hit = hits.find((h) => h.id === 80);
  // oc:9018: il nome che ripiega sull'italiano porta lang it
  assert.deepEqual(L.facetValues(hit, DIFFICULTY, 'en'), [{ id: 'sardegnasentieri:tid:4066', label: 'T', lang: 'it' }]);
});

test('filterHits: OR dentro un filtro, AND fra filtri', () => {
  const facets = [ACTIVITIES, WHERE];
  assert.deepEqual(L.filterHits(hits, facets, { attivita: ['mtb'] }, 'it').map((h) => h.id).sort(), [109, 75]);
  assert.deepEqual(L.filterHits(hits, facets, { attivita: ['mtb', 'trekking'] }, 'it').map((h) => h.id).sort(), [109, 529, 75]);
  assert.deepEqual(L.filterHits(hits, facets, { attivita: ['mtb'], dove: ['Nuoro'] }, 'it').map((h) => h.id), [109]);
});

test('computeFacets conta un filtro senza il suo stesso filtro', () => {
  const [activities, where] = L.computeFacets(hits, [ACTIVITIES, WHERE], { attivita: ['mtb'] }, 'it');
  assert.deepEqual(values(activities), { mtb: 2, hiking: 3, horse: 3, trekking: 1 });
  assert.equal(values(where).Siniscola, 1);
  assert.equal(values(where).Sardegna, 2);
  assert.equal(values(where).Oristano, undefined);
  assert.equal(activities.values.find((v) => v.id === 'mtb').selected, true);
});

test('computeFacets tiene un valore selezionato anche a zero risultati', () => {
  const selected = { attivita: ['mtb'], dove: ['Oristano'] };
  assert.deepEqual(L.filterHits(hits, [ACTIVITIES, WHERE], selected, 'it'), []);
  const where = L.computeFacets(hits, [ACTIVITIES, WHERE], selected, 'it')[1];
  assert.deepEqual(where.values.find((v) => v.id === 'Oristano'), { id: 'Oristano', label: 'Oristano', count: 0, selected: true });
});

test('computeFacets non si ferma su un percorso senza il campo', () => {
  const difficulty = L.computeFacets(hits, [DIFFICULTY], {}, 'it')[0];
  assert.deepEqual(values(difficulty), { 'sardegnasentieri:tid:4066': 2, 'sardegnasentieri:tid:4067': 1 });
});

test('computeFacets ordina per conteggio, oppure con l\'ordine del tema', () => {
  const plain = L.computeFacets(hits, [ACTIVITIES], {}, 'it')[0];
  assert.deepEqual(plain.values.map((v) => v.id), ['hiking', 'horse', 'mtb', 'trekking']);
  const ordered = L.computeFacets(hits, [{ ...ACTIVITIES, order: ['mtb', 'trekking'] }], {}, 'it')[0];
  assert.deepEqual(ordered.values.map((v) => v.id), ['mtb', 'trekking', 'hiking', 'horse']);
});

test('computeFacets passa i filtri spenti senza valori', () => {
  const off = L.computeFacets(hits, [{ key: 'percorribilita', label: 'Percorribilità', disabled: true }], {}, 'it')[0];
  assert.equal(off.disabled, true);
  assert.deepEqual(off.values, []);
});

test('parseState legge testo, tab, pagina e filtri e ignora le chiavi sconosciute', () => {
  const state = L.parseState('?search_api_fulltext=perda+liana&tipo=percorsi&attivita=hiking,mtb&pagina=2&xyz=1', PARAMS, ['attivita']);
  assert.deepEqual(state, { text: 'perda liana', tab: 'percorsi', page: 2, filters: { attivita: ['hiking', 'mtb'] }, map: false });
});

test('parseState usa i valori predefiniti', () => {
  assert.deepEqual(L.parseState('', PARAMS, ['attivita']), { text: '', tab: '', page: 1, filters: {}, map: false });
  assert.equal(L.parseState('?pagina=abc', PARAMS, []).page, 1);
});

test('stateToSearch scrive solo quello che non è predefinito', () => {
  const state = { text: 'perda liana', tab: 'percorsi', page: 2, filters: { attivita: ['hiking', 'mtb'], dove: [] } };
  assert.equal(L.stateToSearch(state, PARAMS), '?search_api_fulltext=perda+liana&tipo=percorsi&pagina=2&attivita=hiking%2Cmtb');
  assert.equal(L.stateToSearch({ text: '', tab: '', page: 1, filters: {} }, PARAMS), '');
});

test('stateToSearch e parseState si rileggono', () => {
  const state = { text: 'a & b', tab: 'percorsi', page: 3, filters: { dove: ['Nughedu Santa Vittoria'] }, map: true };
  assert.deepEqual(L.parseState(L.stateToSearch(state, PARAMS), PARAMS, ['dove']), state);
});

test('la vista mappa sta nell\'indirizzo con il nome e il valore scelti dal tema', () => {
  assert.equal(L.parseState('?tipo=percorsi&vista=mappa', PARAMS, []).map, true);
  assert.equal(L.parseState('?tipo=percorsi&vista=elenco', PARAMS, []).map, false);
  assert.equal(L.stateToSearch({ text: '', tab: 'percorsi', page: 1, filters: {}, map: true }, PARAMS), '?tipo=percorsi&vista=mappa');
  assert.equal(L.stateToSearch({ text: '', tab: 'percorsi', page: 1, filters: {}, map: false }, PARAMS), '?tipo=percorsi');
  // senza i nomi della vista (configurazione di prima) la mappa non c'è
  const old = { q: 'q', tab: 'tab', page: 'page' };
  assert.equal(L.parseState('?view=map', old, []).map, false);
  assert.equal(L.stateToSearch({ text: '', tab: '', page: 1, filters: {}, map: true }, old), '');
});

test('paginate riporta la pagina fra 1 e l\'ultima', () => {
  const items = Array.from({ length: 30 }, (_, i) => i);
  assert.deepEqual(L.paginate(items, 99, 12), { items: items.slice(24), page: 3, pages: 3 });
  assert.equal(L.paginate(items, 0, 12).page, 1);
  assert.deepEqual(L.paginate([], 1, 12), { items: [], page: 1, pages: 1 });
});

test('formatKm e formatMeters seguono la lingua', () => {
  assert.equal(L.formatKm(179.5, 'it'), '179,5 km');
  assert.equal(L.formatKm(179.5, 'en'), '179.5 km');
  // 12.345 e non 1.234: in italiano le migliaia sotto i 10.000 si separano o no secondo il CLDR del runtime
  // (CLDR 44 «1.234 m», CLDR 47 e 48 «1234 m», verificato con Node 21.7 e 22.23)
  assert.equal(L.formatMeters(12345, 'it'), '12.345 m');
  assert.equal(L.formatMeters(12345, 'en'), '12,345 m');
  assert.equal(L.formatKm(0, 'it'), '');
  assert.equal(L.formatMeters(null, 'it'), '');
});

test('formatDuration scrive ore e minuti come il plugin di partenza', () => {
  assert.equal(L.formatDuration(125, 'it'), '2 h 5 min');
  assert.equal(L.formatDuration(45, 'it'), '45 min');
  assert.equal(L.formatDuration(0, 'it'), '');
  assert.equal(L.formatDuration('', 'it'), '');
});

test('countLabel sceglie singolare e plurale', () => {
  const nouns = { one: '%s percorso', other: '%s percorsi' };
  assert.equal(L.countLabel(1, nouns, 'it'), '1 percorso');
  assert.equal(L.countLabel(28, nouns, 'it'), '28 percorsi');
  assert.equal(L.countLabel(12345, nouns, 'it'), '12.345 percorsi');
});

// Stato di validazione (terms.validation-status sul dev dal 09/11): fasce della tavola 2 del wireframe
const VALIDATION = {
  key: 'validazione', label: 'Validazione catastale', type: 'terms', field: 'terms.validation-status',
  groups: [
    { id: 'avanzato', label: 'Avanzato · 5–6', values: ['validation-status-validated'] },
    { id: 'intermedio', label: 'Intermedio · 2–4', values: ['validation-status-under-review', 'validation-status-pre-registration', 'validation-status-walkable'] },
    { id: 'base', label: 'Base · 0–1', values: ['validation-status-unverified', 'validation-status-not-walkable'] },
  ],
  order: ['avanzato', 'intermedio', 'base'],
};
const withStatus = (id, identifier, name) => ({ id, terms: { 'validation-status': [{ identifier, name: { it: name } }] } });
const STATUS_HITS = [
  withStatus(1, 'validation-status-validated', 'Validato'),
  withStatus(2, 'validation-status-walkable', 'Percorribile'),
  withStatus(3, 'validation-status-under-review', 'In revisione-validazione'),
  withStatus(4, 'validation-status-not-walkable', 'Non percorribile'),
  { id: 5, terms: {} },
];

test('facetValues porta un valore nella sua fascia con groups', () => {
  assert.deepEqual(L.facetValues(STATUS_HITS[3], VALIDATION, 'it'), [{ id: 'base', label: 'Base · 0–1' }]);
  assert.deepEqual(L.facetValues(STATUS_HITS[4], VALIDATION, 'it'), []);
});

test('computeFacets conta le fasce e filterHits filtra per fascia', () => {
  const view = L.computeFacets(STATUS_HITS, [VALIDATION], {}, 'it')[0];
  assert.deepEqual(view.values.map((v) => v.id + '=' + v.count), ['avanzato=1', 'intermedio=2', 'base=1']);
  assert.deepEqual(L.filterHits(STATUS_HITS, [VALIDATION], { validazione: ['intermedio'] }, 'it').map((h) => h.id), [2, 3]);
});

test('firstTerm e termName usano il nome del tema (term_labels) prima di quello dell\'API, che è solo in italiano', () => {
  // L'API del dev dà terms.validation-status e terms.route-type con il nome solo in "it" (10/11)
  const labels = { 'validation-status-validated': 'Validated', 'route-type-trail': 'Trail' };
  assert.deepEqual(L.firstTerm(STATUS_HITS[0], 'terms.validation-status', 'en', labels), { key: 'validation-status-validated', label: 'Validated' });
  assert.equal(L.termName({ identifier: 'route-type-trail', name: { it: 'Sentiero' } }, 'en', labels), 'Trail');
  // senza un nome del tema resta quello dell'API, e senza nome l'identifier
  assert.equal(L.termName({ identifier: 'route-type-walk', name: { it: ' Cammino ' } }, 'en', labels), 'Cammino');
  assert.equal(L.termName({ identifier: 'route-type-x' }, 'en', {}), 'route-type-x');
  assert.equal(L.termName(null, 'en', labels), '');
});

test('firstTerm dà il primo termine di un campo come etichetta, o null', () => {
  assert.deepEqual(L.firstTerm(STATUS_HITS[0], 'terms.validation-status', 'en'), { key: 'validation-status-validated', label: 'Validato' });
  assert.equal(L.firstTerm(STATUS_HITS[4], 'terms.validation-status', 'it'), null);
  assert.equal(L.firstTerm(STATUS_HITS[0], '', 'it'), null);
});

// «Dove» diviso dal nome (taxonomyWheres mescola regione, macro aree, settori CAI, province e comuni): ripiego
// finché l'API non dà i luoghi per tipo (oc:8952, 09/11)
const PROVINCES = ['Cagliari', 'Medio Campidano', 'Nuoro', 'Ogliastra', 'Oristano', 'Sassari', 'Sulcis Iglesiente'];
const AREA = { key: 'area', type: 'list', field: 'taxonomyWheres', include_pattern: '^Area ' };
const PROVINCE = { key: 'provincia', type: 'list', field: 'taxonomyWheres', include: PROVINCES };
const MUNICIPALITY = { key: 'comune', type: 'list', field: 'taxonomyWheres', exclude: ['Sardegna'].concat(PROVINCES), exclude_pattern: '^(Area |Z[A-Z]{3}[0-9]+$)' };

test('facetValues tiene solo i valori di include o include_pattern', () => {
  const hit = hits.find((h) => h.id === 80);
  assert.deepEqual(L.facetValues(hit, AREA, 'it').map((v) => v.id), ['Area T (Centrale)']);
  assert.deepEqual(L.facetValues(hit, PROVINCE, 'it').map((v) => v.id), ['Nuoro', 'Ogliastra']);
});

test('facetValues toglie i valori di exclude ed exclude_pattern', () => {
  const hit = hits.find((h) => h.id === 80);
  assert.deepEqual(L.facetValues(hit, MUNICIPALITY, 'it').map((v) => v.id), ['Arzana', 'Desulo']);
});

test('rankByText mette prima i percorsi con il testo nel nome, poi con tutte le parole, e tiene l\'ordine dell\'API', () => {
  const named = (id, it) => ({ id, name: { it } });
  const list = [
    named(1, 'Sentiero Italia (Tappa Z23) Santa Barbara'),
    named(2, 'Liana di Perda alta'),
    named(3, 'CMSB variante tappa 2 (Perda Liana)'),
    named(4, 'Altro sentiero'),
    named(5, 'Perdà Liana, giro corto'),
  ];
  assert.deepEqual(L.rankByText(list, 'perda liana', 'it').map((h) => h.id), [3, 5, 2, 1, 4]);
  // Senza testo: alfabetico sul nome italiano, come lo shard (oc:9000; prima restituiva l'ordine ricevuto)
  assert.deepEqual(L.rankByText(list, '', 'it').map((h) => h.id), [4, 3, 2, 5, 1]);
});

test('measuredSum somma un campo solo se tutti i percorsi lo hanno, altrimenti null', () => {
  assert.equal(L.measuredSum([{ distance: 1.5 }, { distance: '2.5' }], 'distance'), 4);
  // con il DEM a metà la somma sembrerebbe il totale e non lo è
  assert.equal(L.measuredSum([{ distance: 1.5 }, { distance: 0 }], 'distance'), null);
  assert.equal(L.measuredSum([{ distance: 1.5 }, {}], 'distance'), null);
  assert.equal(L.measuredSum([], 'distance'), null);
});

// oc:9000: valore per i risultati senza il campo (`missing`), per esempio le tracce senza percorribilità che contano
// come «Sconosciuta». Nella fixture nessun percorso ha `terms.walkability-status`, e il 529 non ha proprio `terms`.
const WALKABILITY = {
  key: 'percorribilita',
  label: 'Percorribilità',
  type: 'terms',
  field: 'terms.walkability-status',
  missing: 'walkability-status-unknown',
  labels: { 'walkability-status-unknown': 'Sconosciuta' }
};

test('missing: un risultato senza il campo vale come il valore dichiarato', () => {
  const senzaTerms = hits.find((hit) => hit.id === 529);
  assert.deepEqual(L.facetValues(senzaTerms, WALKABILITY, 'it'), [{ id: 'walkability-status-unknown', label: 'Sconosciuta' }]);
  const conTerms = Object.assign({}, senzaTerms, { terms: { 'walkability-status': [{ identifier: 'walkability-status-walkable', name: { it: 'Percorribile' } }] } });
  assert.deepEqual(L.facetValues(conTerms, WALKABILITY, 'it').map((value) => value.id), ['walkability-status-walkable']);
});

test('missing: vale per il filtro e per i conteggi; senza missing il comportamento resta quello di prima', () => {
  const selected = { percorribilita: ['walkability-status-unknown'] };
  assert.equal(L.filterHits(hits, [WALKABILITY], selected, 'it').length, hits.length);
  const view = L.computeFacets(hits, [WALKABILITY], {}, 'it')[0];
  assert.deepEqual(view.values.map((value) => [value.id, value.count]), [['walkability-status-unknown', hits.length]]);
  const senzaMissing = Object.assign({}, WALKABILITY, { missing: undefined });
  assert.deepEqual(L.computeFacets(hits, [senzaMissing], {}, 'it')[0].values, []);
});

test('missing con i gruppi: il valore dichiarato passa dal suo gruppo', () => {
  const grouped = Object.assign({}, WALKABILITY, {
    groups: [{ id: 'walkability-status-unknown', label: 'Sconosciuta', values: ['walkability-status-unknown'] }]
  });
  assert.deepEqual(L.facetValues(hits.find((hit) => hit.id === 529), grouped, 'it'), [{ id: 'walkability-status-unknown', label: 'Sconosciuta' }]);
});

// oc:9000 (review finale, I4): lingua da marcare con `lang` su un testo che ripiega su un'altra lingua (WCAG 3.1.2)
test('langFor: la lingua del testo solo se diversa da quella della pagina, confrontando le due lettere', () => {
  assert.equal(L.langFor('it', 'en-us'), 'it');
  assert.equal(L.langFor('en', 'en-us'), '');
  assert.equal(L.langFor('it', 'it'), '');
  assert.equal(L.langFor(null, 'en'), '');
  assert.equal(L.langFor('', 'en'), '');
});

// oc:9000: senza testo l'ordine è quello dello shard (alfabetico sul nome italiano), ma alcuni nomi dello shard
// cominciano con una tabulazione o uno spazio e finivano in testa all'elenco: si ordina sul nome senza spazi ai lati
test('rankByText senza testo: alfabetico sul nome italiano senza spazi iniziali, come lo shard con i nomi puliti', () => {
  const dirty = [
    { id: 1, name: { it: '\tCMSB - Tappa 04' } },
    { id: 2, name: " 'Untanas de Su Tassu" },
    { id: 3, name: { it: 'Abriddedu - Peales', en: 'Abriddedu' } },
    { id: 4, name: { it: 'Zuddas' } },
    { id: 5, name: { it: 'Acu e Brusaerru' } }
  ];
  assert.deepEqual(L.rankByText(dirty, '', 'en').map((hit) => hit.id), [2, 3, 5, 1, 4]);
  // A parità di nome resta l'ordine dell'API (9 prima di 8)
  const tie = [{ id: 9, name: { it: 'Bau' } }, { id: 7, name: { it: 'Abba' } }, { id: 8, name: { it: 'Bau' } }];
  assert.deepEqual(L.rankByText(tie, '', 'it').map((hit) => hit.id), [7, 9, 8]);
});

// oc:9018: faccette dal config.json (from_config). Solo le opzioni pubblicate (`include`), con il nome e la lingua del
// nome di MAP.filters; le opzioni a zero non si mostrano, quelle scelte sì
test('computeFacets con una faccetta dal config.json: solo le opzioni pubblicate, nome e lingua dal config', () => {
  const facet = {
    key: 'activity', type: 'list', field: 'taxonomyActivities',
    include: ['hiking', 'horse'], order: ['horse', 'hiking'],
    labels: { hiking: 'Alte vie', horse: 'Turismo equestre' }, lang_labels: { hiking: 'it' }
  };
  const view = L.computeFacets(hits, [facet], {}, 'en')[0];
  assert.deepEqual(view.values.map((v) => v.id), ['horse', 'hiking']);
  assert.equal(view.values.find((v) => v.id === 'hiking').lang, 'it');
  assert.equal(view.values.find((v) => v.id === 'horse').lang, undefined);
  assert.ok(!view.values.some((v) => v.id === 'mtb'), 'mtb non è fra le opzioni pubblicate');
  assert.ok(view.values.every((v) => v.count > 0));
});

test('computeFacets: un\'opzione scelta resta anche a zero, le altre a zero no', () => {
  const facet = { key: 'activity', type: 'list', field: 'taxonomyActivities', include: ['hiking', 'nordic-walking'], labels: { 'nordic-walking': 'Nordic' } };
  const view = L.computeFacets(hits, [facet], { activity: ['nordic-walking'] }, 'it')[0];
  const chosen = view.values.find((v) => v.id === 'nordic-walking');
  assert.ok(chosen && chosen.selected && chosen.count === 0);
  assert.equal(chosen.label, 'Nordic');
});

test('computeFacets: una famiglia senza percorsi ha values vuoti (la ricerca non la disegna)', () => {
  const facet = { key: 'ebike-difficulty', type: 'terms', field: 'terms.ebike-difficulty' };
  assert.deepEqual(L.computeFacets(hits, [facet], {}, 'it')[0].values, []);
});

// oc:9018: un valore che non sta in nessun gruppo del tema (uno stato nuovo creato in Nova) si mostra con il nome dello
// shard, dopo i gruppi, invece di sparire
test('groupValues tiene i valori fuori gruppo, dopo i gruppi', () => {
  const facet = {
    key: 'walkability', type: 'terms', field: 'terms.walkability-status',
    groups: [{ id: 'walkability-status-walkable', label: 'Percorribile', values: ['walkability-status-walkable'] }]
  };
  const hit = { terms: { 'walkability-status': [{ identifier: 'walkability-status-closed', name: { it: 'Chiuso' } }] } };
  assert.deepEqual(L.facetValues(hit, facet, 'it'), [{ id: 'walkability-status-closed', label: 'Chiuso' }]);
});

// oc:9018: filtri di luogo dagli overlay in un solo parametro `layer`, come le app (?layer=<id>). Ogni faccetta prende
// dal parametro gli id delle sue aree; un id che non è di nessuna si ignora
const AREAS = [
  { key: 'area-1', param: 'layer', ids: ['3', '4'], type: 'list', field: 'layers' },
  { key: 'area-2', param: 'layer', ids: ['50'], type: 'list', field: 'layers' }
];

test('parseState divide il parametro condiviso fra le faccette, ignorando gli id sconosciuti', () => {
  const state = L.parseState('?layer=3,50,999&activity=hiking', PARAMS, AREAS.concat(['activity']));
  assert.deepEqual(state.filters, { 'area-1': ['3'], 'area-2': ['50'], activity: ['hiking'] });
});

test('stateToSearch unisce le faccette con lo stesso parametro in un valore solo, in ordine numerico', () => {
  const search = L.stateToSearch({ text: '', tab: '', page: 1, filters: { 'area-2': ['50'], 'area-1': ['4', '3'] } }, PARAMS, AREAS);
  assert.equal(decodeURIComponent(search), '?layer=3,4,50');
});

test('filterHits sulle aree: OR dentro la faccetta, AND fra le faccette', () => {
  const hit = { layers: [3, 11] };
  assert.equal(L.filterHits([hit], AREAS, { 'area-1': ['3', '4'] }, 'it').length, 1);
  assert.equal(L.filterHits([hit], AREAS, { 'area-1': ['3'], 'area-2': ['50'] }, 'it').length, 0);
});

// oc:9018: un termine senza il nome nella lingua della pagina mostra quello italiano, marcato con lang (WCAG 3.1.2)
test('facetValues su un termine: nome italiano sulla pagina inglese con lang it', () => {
  const facet = { key: 'validation', type: 'terms', field: 'terms.validation-status' };
  const hit = { terms: { 'validation-status': [{ identifier: 'validation-status-validated', name: { it: 'Validato' } }] } };
  assert.deepEqual(L.facetValues(hit, facet, 'en'), [{ id: 'validation-status-validated', label: 'Validato', lang: 'it' }]);
  const both = { terms: { 'validation-status': [{ identifier: 'validation-status-validated', name: { it: 'Validato', en: 'Validated' } }] } };
  assert.deepEqual(L.facetValues(both, facet, 'en'), [{ id: 'validation-status-validated', label: 'Validated' }]);
});

// Review interna di oc:9018 (B1): un valore scelto che con gli altri filtri scende a 0 risultati resta con il suo nome,
// preso dai percorsi che lo hanno, anche in una faccetta di termini senza `labels` né `groups` (scale di difficoltà,
// percorribilità), e con la lingua del nome
test('computeFacets: valore scelto a 0 con il nome dei percorsi, non l\'identifier', () => {
  const tracce = [
    { id: 1, layers: [3], terms: { 'hiking-difficulty': [{ identifier: 'hiking-difficulty-easy', name: { it: 'Facile' } }], 'walkability-status': [{ identifier: 'walkability-status-walkable', name: { it: 'Percorribile', en: 'Walkable' } }] } },
    { id: 2, layers: [4], terms: { 'hiking-difficulty': [{ identifier: 'hiking-difficulty-hard', name: { it: 'Difficile' } }] } },
  ];
  const scala = { key: 'hiking-difficulty', label: 'Difficoltà', type: 'terms', field: 'terms.hiking-difficulty' };
  const stato = { key: 'walkability', label: 'Percorribilità', type: 'terms', field: 'terms.walkability-status', missing: 'walkability-status-unknown', labels: { 'walkability-status-unknown': 'Sconosciuta' } };
  const area = { key: 'area-3', label: 'Aree', type: 'list', field: 'layers' };
  const [difficolta, percorribilita] = L.computeFacets(tracce, [scala, stato, area], { 'hiking-difficulty': ['hiking-difficulty-easy'], walkability: ['walkability-status-walkable'], 'area-3': ['4'] }, 'en');
  const facile = difficolta.values.find((v) => v.id === 'hiking-difficulty-easy');
  assert.deepEqual(facile, { id: 'hiking-difficulty-easy', label: 'Facile', count: 0, selected: true, lang: 'it' });
  const percorribile = percorribilita.values.find((v) => v.id === 'walkability-status-walkable');
  assert.deepEqual(percorribile, { id: 'walkability-status-walkable', label: 'Walkable', count: 0, selected: true });
  // Un valore scelto che nessun percorso ha (indirizzo scritto a mano): resta l'identifier
  const [solo] = L.computeFacets(tracce, [scala], { 'hiking-difficulty': ['hiking-difficulty-xx'] }, 'it');
  assert.equal(solo.values.find((v) => v.id === 'hiking-difficulty-xx').label, 'hiking-difficulty-xx');
});
