// Test delle tracce da `api/ec/track/multiple` (oc:8952): node tests/js/track-api.test.js
// L'API vera tiene solo i primi 10 id (EcTrackController del wm-package, array_slice($ids, 0, 10)): il finto fetch fa lo
// stesso, così un gruppo più grande perderebbe tracce e il test se ne accorge.
const test = require('node:test');
const assert = require('node:assert/strict');
const A = require('../../assets/js/track-api.js');

const URL_API = 'https://shard.example/api/ec/track/multiple';
const line = (id) => ({ type: 'LineString', coordinates: [[9 + id / 1000, 40], [9 + id / 1000, 40.01]] });

// Finto fetch: risponde con le tracce chieste (al massimo 10), senza quelle in `withoutGeometry` e `unknown`; conta le
// richieste e quante sono in volo insieme
function fakeApi(options = {}) {
  const calls = [];
  let inFlight = 0;
  let maxInFlight = 0;
  const fetch = (url, init) => {
    const ids = new URL(url).searchParams.get('ids').split(',');
    calls.push(ids);
    inFlight++;
    maxInFlight = Math.max(maxInFlight, inFlight);
    return new Promise((resolve, reject) => {
      if (init && init.signal && init.signal.aborted) {
        inFlight--;
        reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        return;
      }
      setTimeout(() => {
        inFlight--;
        if (options.status) {
          resolve({ ok: false, status: options.status });
          return;
        }
        const features = ids.slice(0, 10)
          .filter((id) => !(options.unknown || []).includes(id))
          .map((id) => ({
            type: 'Feature',
            geometry: (options.withoutGeometry || []).includes(id) ? null : line(Number(id)),
            properties: { id: Number(id), ref: 'R' + id, layers: [6, 11], description: { it: 'lunga' }, images: [1, 2, 3] }
          }));
        resolve({ ok: true, json: () => Promise.resolve({ type: 'FeatureCollection', features }) });
      }, 5);
    });
  };
  return { fetch, calls, maxInFlight: () => maxInFlight };
}

const ids = (n, from = 1) => Array.from({ length: n }, (_, i) => String(from + i));

test('chiede le tracce a gruppi di 10, al massimo 4 richieste insieme, e le dà nell\'ordine degli id', async () => {
  const fake = fakeApi();
  const api = A.create(URL_API, { fetch: fake.fetch });
  const wanted = ids(45).reverse();
  const result = await api.features(wanted);
  assert.equal(fake.calls.length, 5);
  assert.ok(fake.calls.every((group) => group.length <= 10));
  assert.ok(fake.maxInFlight() <= 4);
  assert.deepEqual(result.features.map((f) => String(f.properties.id)), wanted);
  assert.deepEqual(result.missing, []);
});

test('tiene di ogni traccia solo geometria, id, codice e layer (per il colore)', async () => {
  const api = A.create(URL_API, { fetch: fakeApi().fetch });
  const { features } = await api.features(['7']);
  assert.deepEqual(features[0], { type: 'Feature', geometry: line(7), properties: { id: 7, ref: 'R7', layers: [6, 11] } });
});

test('le tracce senza geometria o che l\'API non restituisce sono in missing', async () => {
  const api = A.create(URL_API, { fetch: fakeApi({ withoutGeometry: ['2'], unknown: ['3'] }).fetch });
  const result = await api.features(['1', '2', '3', '4']);
  assert.deepEqual(result.features.map((f) => f.properties.id), [1, 4]);
  assert.deepEqual(result.missing, ['2', '3']);
});

test('una traccia già scaricata non si chiede di nuovo, nemmeno se era senza geometria', async () => {
  const fake = fakeApi({ withoutGeometry: ['2'] });
  const api = A.create(URL_API, { fetch: fake.fetch });
  await api.features(ids(12));
  const before = fake.calls.length;
  const result = await api.features(['2', '5', '13']);
  assert.deepEqual(fake.calls.slice(before), [['13']]);
  assert.deepEqual(result.features.map((f) => f.properties.id), [5, 13]);
  assert.deepEqual(result.missing, ['2']);
});

test('onProgress conta le tracce pronte, comprese quelle già in memoria', async () => {
  const api = A.create(URL_API, { fetch: fakeApi().fetch });
  await api.features(ids(5));
  const steps = [];
  await api.features(ids(25), null, (done, total) => steps.push([done, total]));
  assert.deepEqual(steps[0], [5, 25]);
  assert.deepEqual(steps[steps.length - 1], [25, 25]);
});

test('un errore dell\'API o un annullamento rifiutano la promessa', async () => {
  const api = A.create(URL_API, { fetch: fakeApi({ status: 500 }).fetch });
  await assert.rejects(api.features(['1']), /HTTP 500/);
  const controller = new AbortController();
  controller.abort();
  const other = A.create(URL_API, { fetch: fakeApi().fetch });
  await assert.rejects(other.features(['1'], controller.signal), { name: 'AbortError' });
});

test('cached dà subito, nell\'ordine degli id, le tracce già arrivate: la mappa le disegna mentre arrivano le altre', async () => {
  const api = A.create(URL_API, { fetch: fakeApi({ withoutGeometry: ['2'] }).fetch });
  assert.deepEqual(api.cached(['1', '2', '3']), []);
  await api.features(['3', '1', '2']);
  assert.deepEqual(api.cached(['9', '3', '2', '1']).map((f) => f.properties.id), [3, 1]);
});

test('onProgress arriva dopo ogni gruppo con le tracce di quel gruppo già in cached', async () => {
  const api = A.create(URL_API, { fetch: fakeApi().fetch });
  const wanted = ids(25);
  const seen = [];
  await api.features(wanted, null, (done) => seen.push([done, api.cached(wanted).length]));
  assert.ok(seen.every(([done, cached]) => done === cached));
});
