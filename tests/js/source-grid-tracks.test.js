// Test della source delle griglie di percorsi (oc:9003): node --test tests/js/*.test.js
// source-grid-tracks.js gira in una finestra finta (vm), come source-pois.js, con un fetch finto per `wmwp/v1/tracks`.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SCRIPT = path.join(__dirname, '..', '..', 'assets/js/source-grid-tracks.js');

function load(response, initial) {
  const calls = [];
  const fetch = (url) => {
    calls.push(url);
    return Promise.resolve(response);
  };
  const registered = {};
  const source = {
    id: 'grid',
    endpoint: 'https://sito.example/wp-json/wmwp/v1/tracks',
    grid: 'sentieri',
    facets: [{ key: 'activity' }, { key: 'track_distance', type: 'slider' }, { key: 'area-1', param: 'layer', ids: ['3', '50'] }]
  };
  const window = {
    wmWpSearch: { config: { sources: [source], initial: initial || null }, registerSource: (id, impl) => { registered[id] = impl; } }
  };
  vm.runInNewContext(fs.readFileSync(SCRIPT, 'utf8'), { window, fetch, URL, Promise, Error, Object, String, Array, JSON });
  return { impl: registered.grid, calls };
}

const query = (overrides = {}) => Object.assign({ rawText: '', filters: {}, lang: 'en', page: 2, perPage: 9 }, overrides);
const ok = (body) => ({ ok: true, status: 200, json: () => Promise.resolve(body) });

test('search() chiede alla rotta griglia, lingua, pagina e filtri con i parametri dell\'indirizzo', async () => {
  const body = { total: 1, items: [{ id: 1 }], facets: [] };
  const { impl, calls } = load(ok(body));
  const result = await impl.search(query({ filters: { activity: ['mtb', 'horse'], track_distance: ['-10'], 'area-1': ['50'] } }), null);
  assert.deepEqual(result, body);
  const url = new URL(calls[0]);
  assert.equal(url.searchParams.get('grid'), 'sentieri');
  assert.equal(url.searchParams.get('lang'), 'en');
  assert.equal(url.searchParams.get('pg'), '2');
  assert.equal(url.searchParams.get('activity'), 'mtb,horse');
  assert.equal(url.searchParams.get('track_distance'), '-10');
  assert.equal(url.searchParams.get('layer'), '50');
});

test('la prima ricerca usa il risultato già disegnato dal server, senza chiamare la rotta', async () => {
  const initial = { total: 27, items: [], facets: [] };
  const { impl, calls } = load(ok({ total: 0, items: [] }), initial);
  assert.deepEqual(await impl.search(query(), null), initial);
  assert.equal(calls.length, 0);
  await impl.search(query(), null);
  assert.equal(calls.length, 1);
});

test('una risposta non ok rifiuta la Promise (la pagina mostra l\'errore, non «0 risultati»)', async () => {
  const { impl } = load({ ok: false, status: 503, json: () => Promise.resolve({}) });
  await assert.rejects(impl.search(query(), null));
});

test('con un testo search() lo manda alla rotta come q, così com\'è scritto (lo ripulisce il server)', async () => {
  const { impl, calls } = load(ok({ total: 0, items: [], facets: [] }));
  await impl.search(query({ rawText: 'Monte Albo', text: 'Monte Albo' }), null);
  assert.equal(new URL(calls[0]).searchParams.get('q'), 'Monte Albo');
  await impl.search(query({ rawText: '', text: '' }), null);
  assert.equal(new URL(calls[1]).searchParams.get('q'), null);
});
