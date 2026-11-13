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

// Mappa del dettaglio (oc:8969): tracce con uno stile ciascuna (la principale, le collegate) e punti numerati
test('detail: ogni traccia con il suo stile, nell\'ordine chiesto; le tracce che l\'API non ha si saltano', () => {
  const features = [feature(5, 'C 104 A'), feature(6, 'C 104'), feature(7, 'C 105')];
  const out = M.detail(features, {
    tracks: [
      { id: 6, color: '#5B7A99', width: 2.5, dash: [7, 5], context: true, tooltip: 'C 104 · Monte', url: '/sentiero/monte/' },
      { id: 5, color: '#DE1B0D', width: 4, label: 'C 104 A' },
      { id: 99, color: '#000' }
    ]
  });
  assert.equal(out.type, 'FeatureCollection');
  assert.equal(out.features.length, 2);
  assert.deepEqual(out.features[0].properties, {
    id: 6,
    tooltip: 'C 104 · Monte',
    lineLabel: '',
    strokeColor: '#5B7A99',
    strokeWidth: 2.5,
    strokeDash: [7, 5],
    context: true,
    clickAction: 'link',
    link: '/sentiero/monte/'
  });
  assert.deepEqual(out.features[1].properties, { id: 5, tooltip: 'C 104 A', lineLabel: 'C 104 A', strokeColor: '#DE1B0D', strokeWidth: 4 });
  assert.deepEqual(out.features[1].geometry, feature(5).geometry);
});

test('detail: senza stile la traccia è rossa da 4 px, con il codice dell\'API nel tooltip o l\'id', () => {
  const out = M.detail([feature(8, null)], { tracks: [{ id: 8 }] });
  assert.deepEqual(out.features[0].properties, { id: 8, tooltip: '8', lineLabel: '', strokeColor: '#DE1B0D', strokeWidth: 4 });
});

test('detail: i punti dopo le tracce, con colore, numero, tooltip e link; senza coordinate valide si saltano', () => {
  const out = M.detail([], {
    points: [
      { coordinates: [9.52, 39.87], color: '#B7127F', number: 1, tooltip: 'Tavolo', url: '/punti-di-interesse/tavolo/' },
      { coordinates: [9.5, 39.8], color: '#1A1A1A' },
      { coordinates: ['x', 39] },
      {}
    ]
  });
  assert.equal(out.features.length, 2);
  assert.deepEqual(out.features[0], {
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [9.52, 39.87] },
    properties: {
      tooltip: 'Tavolo',
      pointFillColor: '#B7127F',
      pointStrokeColor: '#FFFFFF',
      pointStrokeWidth: 2,
      pointRadius: 9,
      number: '1',
      clickAction: 'link',
      link: '/punti-di-interesse/tavolo/'
    }
  });
  // senza numero il punto è più piccolo e senza testo
  assert.equal(out.features[1].properties.pointRadius, 7);
  assert.equal(out.features[1].properties.number, undefined);
  assert.equal(out.features[1].properties.pointFillColor, '#1A1A1A');
});

// Partenza e arrivo della traccia principale (oc:8969), come la mappa di GeoHub del sito attuale: cerchio verde
// all'inizio, bandiera a scacchi alla fine; in un anello (fine entro 50 m dall'inizio) solo la partenza
const linea = (id, coordinates) => ({ type: 'Feature', geometry: { type: 'LineString', coordinates }, properties: { id } });

test('detail: con ends, partenza e arrivo dopo le tracce e prima dei punti, con le etichette nel tooltip', () => {
  const out = M.detail([linea(5, [[9.5, 39.8], [9.51, 39.81], [9.53, 39.84]])], {
    tracks: [{ id: 5, ends: { start: 'Partenza', end: 'Arrivo' } }],
    points: [{ coordinates: [9.52, 39.83], number: 1 }]
  });
  assert.equal(out.features.length, 4);
  assert.deepEqual(out.features[1].geometry, { type: 'Point', coordinates: [9.5, 39.8] });
  assert.equal(out.features[1].properties.endpoint, 'start');
  assert.equal(out.features[1].properties.tooltip, 'Partenza');
  assert.deepEqual(out.features[2].geometry, { type: 'Point', coordinates: [9.53, 39.84] });
  assert.equal(out.features[2].properties.endpoint, 'end');
  assert.equal(out.features[2].properties.tooltip, 'Arrivo');
  assert.equal(out.features[3].properties.number, '1');
});

test('detail: partenza e arrivo di una MultiLineString dalla prima e dall\'ultima linea', () => {
  const multi = { type: 'Feature', geometry: { type: 'MultiLineString', coordinates: [[[9, 39], [9.1, 39.1]], [[9.2, 39.2], [9.3, 39.3]]] }, properties: { id: 6 } };
  const out = M.detail([multi], { tracks: [{ id: 6, ends: { start: 'S', end: 'E' } }] });
  assert.deepEqual(out.features.filter((f) => f.properties.endpoint).map((f) => f.geometry.coordinates), [[9, 39], [9.3, 39.3]]);
});

test('detail: anello (fine entro 50 m dall\'inizio) → solo la partenza; senza ends niente', () => {
  const anello = linea(7, [[9.5, 39.8], [9.52, 39.82], [9.5002, 39.8002]]);
  const out = M.detail([anello], { tracks: [{ id: 7, ends: { start: 'Partenza', end: 'Arrivo' } }] });
  assert.deepEqual(out.features.filter((f) => f.properties.endpoint).map((f) => f.properties.endpoint), ['start']);
  assert.equal(M.detail([anello], { tracks: [{ id: 7 }] }).features.filter((f) => f.properties.endpoint).length, 0);
});
