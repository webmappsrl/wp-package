// Test delle tracce per la mappa dei risultati (oc:8952): node --test tests/js/*.test.js
const test = require('node:test');
const assert = require('node:assert/strict');
const M = require('../../assets/js/track-map.js');

const feature = (id, ref, layers) => ({ type: 'Feature', geometry: { type: 'LineString', coordinates: [[9, 40], [9.1, 40.1]] }, properties: { id, ref, layers } });

test('ogni traccia porta nome, codice come etichetta, tooltip, colore e link della ricerca', () => {
  const out = M.collection([feature(102, 'Z-NU-B-405C')], { 102: { name: 'Monte Corrasi', code: 'B 405 C', url: '/percorso/102' } }, { color: '#DE1B0D', width: 4 });
  assert.equal(out.type, 'FeatureCollection');
  assert.deepEqual(out.features[0].properties, {
    id: 102,
    name: 'Monte Corrasi',
    tooltip: 'B 405 C · Monte Corrasi',
    lineLabel: 'B 405 C',
    strokeColor: '#DE1B0D',
    strokeWidth: 4,
    url: '/percorso/102'
  });
  assert.deepEqual(out.features[0].geometry, feature(102).geometry);
});

test('senza i dati della ricerca usa il codice dell\'API, e senza codice l\'id', () => {
  const out = M.collection([feature(7, 'G 602 C'), feature(8, null)], {}, {});
  assert.equal(out.features[0].properties.tooltip, 'G 602 C');
  assert.equal(out.features[0].properties.lineLabel, 'G 602 C');
  assert.equal(out.features[1].properties.tooltip, '8');
  assert.equal(out.features[1].properties.lineLabel, '');
  assert.equal(out.features[1].properties.url, null);
  // colore e spessore predefiniti: quelli della tavola 2 del wireframe
  assert.equal(out.features[1].properties.strokeColor, '#DE1B0D');
  assert.equal(out.features[1].properties.strokeWidth, 4);
});

test('con percorsi selezionati fra i risultati, quelli sono in primo piano e gli altri fanno da sfondo', () => {
  const features = [feature(1, 'A 1'), feature(2, 'A 2'), feature(3, 'A 3')];
  const out = M.collection(features, {}, {}, ['2', '99']);
  const [one, two, three] = out.features.map((f) => f.properties);
  // il selezionato: rosso, con il contorno bianco, l'etichetta, e dentro l'inquadratura (niente context)
  assert.equal(two.strokeColor, '#DE1B0D');
  assert.deepEqual(two.casing, { color: '#FFFFFF', width: 8 });
  assert.equal(two.lineLabel, 'A 2');
  assert.equal(two.context, undefined);
  // gli altri: tratteggiati blu ardesia da 2,5px come i «sentieri collegati» della tavola (`.lg-near`), senza
  // etichetta, sotto e fuori dall'inquadratura (context del componente)
  for (const other of [one, three]) {
    assert.equal(other.context, true);
    assert.equal(other.strokeColor, '#5B7A99');
    assert.equal(other.strokeWidth, 2.5);
    assert.deepEqual(other.strokeDash, [7, 5]);
    assert.equal(other.lineLabel, '');
    assert.equal(other.casing, undefined);
  }
  // tooltip e link restano su tutti
  assert.equal(one.tooltip, 'A 1');
});

test('senza selezionati fra i risultati la mappa resta quella di sempre', () => {
  const features = [feature(1, 'A 1'), feature(2, 'A 2')];
  for (const selected of [undefined, [], ['99']]) {
    const props = M.collection(features, {}, {}, selected).features.map((f) => f.properties);
    assert.ok(props.every((p) => p.context === undefined && p.casing === undefined && p.strokeColor === '#DE1B0D'));
  }
});

// Colori dei layer come nel config.json dell'app di Forestas (dev, 10/11), nell'ordine dell'app
const LAYER_COLORS = [{ id: 8, color: '#1a416f' }, { id: 9, color: '#e100ff' }, { id: 10, color: '#608d0d' }, { id: 11, color: '#de1b0d' }];

test('ogni traccia prende il colore del primo layer colorato a cui appartiene, nell\'ordine dell\'app', () => {
  const out = M.collection([feature(1, 'S', [6, 11]), feature(2, 'I', [6, 10]), feature(3, 'C', [8]), feature(4, 'A', [9, 11]), feature(5, 'X', [6]), feature(6, 'Y')], {}, { layerColors: LAYER_COLORS });
  assert.deepEqual(out.features.map((f) => f.properties.strokeColor), ['#de1b0d', '#608d0d', '#1a416f', '#e100ff', '#DE1B0D', '#DE1B0D']);
});

test('con i selezionati, quelli tengono il colore del loro layer e gli altri restano tratteggiati', () => {
  const out = M.collection([feature(1, 'S', [11]), feature(2, 'I', [10])], {}, { layerColors: LAYER_COLORS }, ['2']);
  const [sentiero, itinerario] = out.features.map((f) => f.properties);
  assert.equal(itinerario.strokeColor, '#608d0d');
  assert.ok(itinerario.casing);
  assert.equal(sentiero.context, true);
  assert.equal(sentiero.strokeColor, '#5B7A99');
});
