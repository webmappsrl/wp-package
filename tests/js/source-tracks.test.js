// Test della source «tracks» (oc:8952): node --test tests/js/*.test.js
// source-tracks.js è uno script del browser: gira in una finestra finta (vm) con search-logic, track-api, track-export e
// track-map veri, un registerSource che tiene l'implementazione e un fetch finto per l'API Elasticsearch e per
// `api/ec/track/multiple`. I percorsi imitano la risposta dell'API sul dev (nomi con il tab in coda, termini solo in
// italiano).
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const SCRIPT = fs.readFileSync(path.join(ROOT, 'assets/js/source-tracks.js'), 'utf8');
const TrackApi = require(path.join(ROOT, 'assets/js/track-api.js'));

const HITS = [
  {
    id: 473,
    name: { it: 'Coscia di Donna\t', en: '' },
    ref: ' Z-SS-E-102A ',
    distance: 3.2,
    ascent: 120,
    terms: {
      'route-type': [{ identifier: 'route-type-trail', name: { it: 'Sentiero' } }],
      'validation-status': [{ identifier: 'validated', name: { it: 'Validato' } }]
    }
  },
  { id: 250, name: { it: 'Fassoni & Su Presoni' }, ref: 'Z-SU-C-421', distance: 5.1, ascent: 300, terms: {} }
];

function load(options = {}) {
  const calls = { search: [], tracks: [] };
  const fetch = (url) => {
    const parsed = new URL(url);
    if (parsed.pathname.endsWith('/multiple')) {
      const ids = parsed.searchParams.get('ids').split(',');
      calls.tracks.push(ids);
      const features = ids.map((id) => ({
        type: 'Feature',
        geometry: { type: 'LineString', coordinates: [[9, 40], [9.01, 40.01]] },
        properties: { id: Number(id), ref: 'R' + id, layers: [11] }
      }));
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ type: 'FeatureCollection', features }) });
    }
    calls.search.push(parsed.searchParams.get('query'));
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ hits: HITS }) });
  };
  const source = Object.assign({
    id: 'tracks',
    endpoint: 'https://shard.example/api/v2/elasticsearch',
    app: 1,
    facets: [],
    nouns: { one: '%s trail', other: '%s trails' },
    selected: { one: '%s trail selected', other: '%s trails selected' },
    link_template: 'https://sito.example/percorso/{ref}/?id={id}',
    badge_field: 'terms.validation-status',
    download_url: 'https://shard.example/api/ec/track/multiple',
    download_name: 'percorsi',
    label: 'Percorsi'
  }, options.source || {});
  const registered = {};
  const window = {
    wmWpSearch: {
      config: { lang: options.lang || 'it', sources: [source], map: { layer_colors: [{ id: 11, color: '#de1b0d' }] } },
      registerSource: (id, impl) => { registered[id] = impl; }
    },
    wmWpSearchLogic: require(path.join(ROOT, 'assets/js/search-logic.js')),
    // Come nel browser, create(url) senza fetch: qui prende il finto
    wmWpTrackApi: { create: (url) => TrackApi.create(url, { fetch }) },
    wmWpTrackExport: require(path.join(ROOT, 'assets/js/track-export.js')),
    wmWpTrackMap: require(path.join(ROOT, 'assets/js/track-map.js')),
    wp: { i18n: { __: (text) => text, sprintf: (format, value) => format.replace('%s', value) } }
  };
  vm.runInNewContext(SCRIPT, { window, fetch, URL, Promise, Map, Intl, Object, String, Array, encodeURIComponent });
  return { impl: registered.tracks, calls };
}

const query = (overrides = {}) => Object.assign({ text: '', rawText: '', filters: {}, lang: 'it', page: 1, perPage: 10 }, overrides);

test('la card toglie spazi e tab da nome e codice e costruisce l\'indirizzo da link_template', async () => {
  const { impl } = load();
  const result = await impl.search(query());
  const card = result.items.find((item) => item.id === 473);
  assert.equal(card.title, 'Coscia di Donna');
  assert.equal(card.code, 'Z-SS-E-102A');
  assert.equal(card.url, 'https://sito.example/percorso/Z-SS-E-102A/?id=473');
  assert.deepEqual(result.ids, ['473', '250']);
});

test('tipo e stato prendono il nome dal tema (term_labels), se no il nome italiano dell\'API', async () => {
  const labels = { 'route-type-trail': 'Trail', validated: 'Validated' };
  const en = await load({ lang: 'en', source: { term_labels: labels } }).impl.search(query({ lang: 'en' }));
  const card = en.items.find((item) => item.id === 473);
  assert.equal(card.typeLabel, 'Trail');
  assert.deepEqual(card.badge, { key: 'validated', label: 'Validated' });
  const it = await load().impl.search(query());
  assert.equal(it.items.find((item) => item.id === 473).typeLabel, 'Sentiero');
});

test('l\'indirizzo per id del dettaglio (link_template predefinito, oc:8969) porta l\'id nella query', async () => {
  const { impl } = load({ source: { link_template: 'http://x/?wmwp_detail=track&wmwp_id={id}' } });
  const result = await impl.search(query());
  assert.equal(result.items.find((item) => item.id === 473).url, 'http://x/?wmwp_detail=track&wmwp_id=473');
});

test('senza link_template la card non ha indirizzo', async () => {
  const { impl } = load({ source: { link_template: '' } });
  const result = await impl.search(query());
  assert.equal(result.items[0].url, null);
});

test('lo scarico di un percorso prende il nome dai risultati, senza il tab in coda', async () => {
  const { impl } = load();
  await impl.search(query());
  const file = await impl.selection.download(['473'], 'gpx', null, null);
  assert.equal(file.name, 'coscia-di-donna.gpx');
  assert.match(file.content, /<name>Coscia di Donna<\/name>/);
  const many = await impl.selection.download(['473', '250'], 'geojson', null, null);
  assert.equal(many.name, 'percorsi-2.geojson');
});

test('la stessa ricerca non si chiede due volte; oltre sei testi si toglie il meno recente', async () => {
  const { impl, calls } = load();
  await impl.search(query({ text: 'a' }));
  await impl.search(query({ text: 'a', page: 2 }));
  assert.deepEqual(calls.search, ['a']);
  for (const text of ['b', 'c', 'd', 'e', 'f']) {
    await impl.search(query({ text }));
  }
  await impl.search(query({ text: 'a' }));
  assert.equal(calls.search.length, 6, 'con sei testi «a» è ancora in memoria');
  await impl.search(query({ text: 'g' }));
  await impl.search(query({ text: 'b' }));
  assert.equal(calls.search.length, 8, '«b» era il meno recente ed è uscito');
});
