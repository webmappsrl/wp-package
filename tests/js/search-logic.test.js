// Test della logica pura della ricerca (oc:8952): node --test tests/js/
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
  assert.deepEqual(L.facetValues(hit, DIFFICULTY, 'en'), [{ id: 'sardegnasentieri:tid:4066', label: 'T' }]);
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
  assert.deepEqual(L.rankByText(list, '', 'it').map((h) => h.id), [1, 2, 3, 4, 5]);
});

test('measuredSum somma un campo solo se tutti i percorsi lo hanno, altrimenti null', () => {
  assert.equal(L.measuredSum([{ distance: 1.5 }, { distance: '2.5' }], 'distance'), 4);
  // con il DEM a metà la somma sembrerebbe il totale e non lo è
  assert.equal(L.measuredSum([{ distance: 1.5 }, { distance: 0 }], 'distance'), null);
  assert.equal(L.measuredSum([{ distance: 1.5 }, {}], 'distance'), null);
  assert.equal(L.measuredSum([], 'distance'), null);
});
