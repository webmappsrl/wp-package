// Test della source «pois» (oc:9000): node --test tests/js/*.test.js
// source-pois.js è uno script del browser: gira in una finestra finta (vm) con un registerSource che tiene
// l'implementazione e un fetch finto per la rotta `wmwp/v1/pois`.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const SCRIPT = path.join(ROOT, 'assets/js/source-pois.js');

function load(response, sourceOverrides = {}) {
  const calls = [];
  const fetch = (url) => {
    calls.push(url);
    return Promise.resolve(response);
  };
  const registered = {};
  const source = Object.assign({ id: 'pois', endpoint: 'https://sito.example/wp-json/wmwp/v1/pois', facets: [{ key: 'tipo-poi' }] }, sourceOverrides);
  const window = {
    wmWpSearch: { config: { sources: [source] }, registerSource: (id, impl) => { registered[id] = impl; } }
  };
  vm.runInNewContext(fs.readFileSync(SCRIPT, 'utf8'), { window, fetch, URL, Promise, Error, Object, String, Array });
  return { impl: registered.pois, calls };
}

const query = (overrides = {}) => Object.assign({ text: 'nur', rawText: 'Nuraghe losa', filters: {}, lang: 'it', page: 2, perPage: 12 }, overrides);

test('search() chiede alla rotta testo, pagina, lingua e tipi, e restituisce la risposta', async () => {
  const body = { total: 1, items: [{ id: 1, title: 'Nuraghe' }], facets: [] };
  const { impl, calls } = load({ ok: true, status: 200, json: () => Promise.resolve(body) });
  const result = await impl.search(query({ filters: { 'tipo-poi': ['siti', 'fonti'] } }), null);
  assert.deepEqual(result, body);
  const url = new URL(calls[0]);
  assert.equal(url.searchParams.get('q'), 'Nuraghe losa');
  assert.equal(url.searchParams.get('page'), '2');
  assert.equal(url.searchParams.get('per_page'), '12');
  assert.equal(url.searchParams.get('lang'), 'it');
  assert.deepEqual(url.searchParams.getAll('poi_type[]'), ['siti', 'fonti']);
});

test('senza testo né filtri non manda q né poi_type', async () => {
  const { impl, calls } = load({ ok: true, status: 200, json: () => Promise.resolve({ total: 0, items: [] }) });
  await impl.search(query({ rawText: '', filters: {} }), null);
  const url = new URL(calls[0]);
  assert.equal(url.searchParams.has('q'), false);
  assert.equal(url.searchParams.has('poi_type[]'), false);
});

test('una risposta non ok (503) rifiuta la Promise', async () => {
  const { impl } = load({ ok: false, status: 503, json: () => Promise.resolve({ code: 'wmwp_poi_search_unavailable' }) });
  await assert.rejects(impl.search(query(), null), /503/);
});

test('nome della faccetta e tipo della card dalla source, che è nella lingua della pagina', async () => {
  const body = () => ({
    total: 1,
    items: [{ id: 1, title: 'Nuraghe', typeKey: 'poi', typeLabel: 'Point of interest' }],
    facets: [{ key: 'tipo-poi', label: 'Type', values: [] }]
  });
  const sourceOverrides = { facets: [{ key: 'tipo-poi', label: 'Tipo di punto di interesse' }], type_label: 'Punto di interesse' };
  const { impl } = load({ ok: true, status: 200, json: () => Promise.resolve(body()) }, sourceOverrides);
  const result = await impl.search(query(), null);
  assert.equal(result.facets[0].label, 'Tipo di punto di interesse');
  assert.equal(result.items[0].typeLabel, 'Punto di interesse');
  const plain = await load({ ok: true, status: 200, json: () => Promise.resolve(body()) }).impl.search(query(), null);
  assert.equal(plain.facets[0].label, 'Type');
  assert.equal(plain.items[0].typeLabel, 'Point of interest');
});

test('la chiave della faccetta della risposta diventa quella della source (il tema la può cambiare)', async () => {
  const body = { total: 0, items: [], facets: [{ key: 'poi-type', label: 'Type', values: [] }] };
  const { impl } = load({ ok: true, status: 200, json: () => Promise.resolve(body) }, { facets: [{ key: 'tipo-poi', label: 'Tipo' }] });
  const result = await impl.search(query(), null);
  assert.equal(result.facets[0].key, 'tipo-poi');
  assert.equal(result.facets[0].label, 'Tipo');
});

test('la chiave si cambia per nome, non per posizione: un\'altra faccetta della rotta resta com\'è', async () => {
  const body = { total: 0, items: [], facets: [{ key: 'altro', values: [] }, { key: 'poi-type', values: [] }] };
  const { impl } = load({ ok: true, status: 200, json: () => Promise.resolve(body) }, { facets: [{ key: 'tipo-poi' }] });
  const result = await impl.search(query(), null);
  assert.deepEqual(result.facets.map((facet) => facet.key), ['altro', 'tipo-poi']);
});
