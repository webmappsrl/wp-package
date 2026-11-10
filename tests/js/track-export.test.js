// Test dello scarico di più percorsi (oc:8952): node --test tests/js/
// Le geometrie imitano quelle di `api/ec/track/multiple` sul dev (09/11): MultiLineString con la quota a 0 dove il
// DEM non è calcolato (343), con la quota vera (250), una LineString e un percorso senza geometria.
const test = require('node:test');
const assert = require('node:assert/strict');
const E = require('../../assets/js/track-export.js');

const feature = (id, geometry, ref) => ({ type: 'Feature', geometry, properties: { id, ref, description: { it: 'lungo' } } });
const FLAT = feature(343, { type: 'MultiLineString', coordinates: [[[9.5211, 39.8764, 0], [9.5212, 39.8763, 0]]] }, 'C 104 A');
const HIGH = feature(250, { type: 'MultiLineString', coordinates: [[[9.5118, 39.3671, 40.5], [9.5110, 39.3683, 44]], [[9.51, 39.37, 46.5], [9.50, 39.38, 0]]] }, 'Z-SU-C-421');
const LINE = feature(473, { type: 'LineString', coordinates: [[8.2211, 40.9002], [8.2212, 40.9003]] }, 'Z-SS-E-102A');
const EMPTY = feature(9, null, '');
const NAMES = { 343: 'Àndala Segura - Sentiero accessibile di Seleni (C 104 A)', 250: 'Fassoni & Su Presoni <C 421>', 473: 'Coscia di Donna' };

test('chunk divide gli id in gruppi della dimensione data, nell\'ordine', () => {
  assert.deepEqual(E.chunk(['1', '2', '3', '4', '5'], 2), [['1', '2'], ['3', '4'], ['5']]);
  assert.deepEqual(E.chunk([], 10), []);
});

test('merge unisce le risposte nell\'ordine degli id, senza doppioni, e conta i percorsi senza tracciato', () => {
  const merged = E.merge(
    [{ type: 'FeatureCollection', features: [HIGH, FLAT] }, { type: 'FeatureCollection', features: [LINE, FLAT, EMPTY] }],
    ['343', '473', '250', '9', '777']
  );
  assert.deepEqual(merged.collection.features.map((f) => f.properties.id), [343, 473, 250]);
  assert.deepEqual(merged.missing, ['9', '777']);
});

test('toGpx scrive una traccia per percorso con nome, segmenti e quota solo se c\'è', () => {
  const gpx = E.toGpx({ type: 'FeatureCollection', features: [FLAT, HIGH] }, NAMES);
  assert.match(gpx, /^<\?xml version="1.0" encoding="UTF-8"\?>\n<gpx version="1.1" creator="wp-package" xmlns="http:\/\/www.topografix.com\/GPX\/1\/1">/);
  assert.equal((gpx.match(/<trk>/g) || []).length, 2);
  assert.equal((gpx.match(/<trkseg>/g) || []).length, 3);
  assert.match(gpx, /<name>Àndala Segura - Sentiero accessibile di Seleni \(C 104 A\)<\/name>/);
  assert.match(gpx, /<name>Fassoni &amp; Su Presoni &lt;C 421&gt;<\/name>/);
  // 343 ha la quota a 0 su tutti i punti: niente <ele>, che direbbe «a livello del mare»
  assert.match(gpx, /<trkpt lat="39.8764" lon="9.5211"\/>/);
  assert.match(gpx, /<trkpt lat="39.3671" lon="9.5118"><ele>40.5<\/ele><\/trkpt>/);
  assert.match(gpx, /<\/gpx>\n$/);
});

test('toGpx usa il codice o l\'id quando il nome manca', () => {
  const gpx = E.toGpx({ type: 'FeatureCollection', features: [LINE, feature(5, LINE.geometry, '')] }, {});
  assert.match(gpx, /<name>Z-SS-E-102A<\/name>/);
  assert.match(gpx, /<name>5<\/name>/);
});

test('toKml scrive un Placemark per percorso: LineString se la linea è una, MultiGeometry se sono più', () => {
  const kml = E.toKml({ type: 'FeatureCollection', features: [FLAT, HIGH] }, NAMES, 'Sardegna Sentieri');
  assert.match(kml, /^<\?xml version="1.0" encoding="UTF-8"\?>\n<kml xmlns="http:\/\/www.opengis.net\/kml\/2.2"><Document><name>Sardegna Sentieri<\/name>/);
  assert.equal((kml.match(/<Placemark>/g) || []).length, 2);
  assert.match(kml, /<Placemark><name>Àndala[^<]*<\/name><LineString><coordinates>9.5211,39.8764 9.5212,39.8763<\/coordinates><\/LineString><\/Placemark>/);
  assert.match(kml, /<MultiGeometry><LineString><coordinates>9.5118,39.3671,40.5 9.511,39.3683,44<\/coordinates><\/LineString><LineString>/);
});

test('toGeoJson tiene geometria, id, nome e codice, e toglie la quota a 0', () => {
  const data = JSON.parse(E.toGeoJson({ type: 'FeatureCollection', features: [FLAT, LINE] }, NAMES));
  assert.equal(data.type, 'FeatureCollection');
  assert.deepEqual(data.features[0].properties, { id: 343, name: NAMES[343], ref: 'C 104 A' });
  assert.deepEqual(data.features[0].geometry, { type: 'MultiLineString', coordinates: [[[9.5211, 39.8764], [9.5212, 39.8763]]] });
  assert.deepEqual(data.features[1].geometry, LINE.geometry);
});

test('convert dà contenuto, tipo MIME ed estensione del formato scelto', () => {
  const collection = { type: 'FeatureCollection', features: [LINE] };
  assert.equal(E.convert(collection, 'gpx', NAMES, 'x').mime, 'application/gpx+xml');
  assert.equal(E.convert(collection, 'kml', NAMES, 'x').extension, 'kml');
  assert.equal(E.convert(collection, 'geojson', NAMES, 'x').mime, 'application/geo+json');
  assert.throws(() => E.convert(collection, 'shp', NAMES, 'x'));
});

test('fileName mette insieme nome di base, numero di percorsi ed estensione', () => {
  assert.equal(E.fileName('sardegna-sentieri-percorsi', 3, 'gpx'), 'sardegna-sentieri-percorsi-3.gpx');
  assert.equal(E.fileName('', 1, 'kml'), 'tracks-1.kml');
});

test('fileName con un solo percorso usa il suo nome, senza accenti né simboli', () => {
  assert.equal(E.fileName('sardegna-sentieri-percorsi', 1, 'gpx', 'Àndala Segura- Sentiero accessibile di Seleni (C 104 A)'), 'andala-segura-sentiero-accessibile-di-seleni-c-104-a.gpx');
  assert.equal(E.fileName('sardegna-sentieri-percorsi', 1, 'kml', "'Untanas de Su Tassu - Su Tassu (G 111B)"), 'untanas-de-su-tassu-su-tassu-g-111b.kml');
  // con più percorsi, o senza un nome utilizzabile, resta il nome di base con il numero
  assert.equal(E.fileName('sardegna-sentieri-percorsi', 3, 'gpx', 'Qualunque'), 'sardegna-sentieri-percorsi-3.gpx');
  assert.equal(E.fileName('sardegna-sentieri-percorsi', 1, 'gpx', '«»'), 'sardegna-sentieri-percorsi-1.gpx');
  assert.equal(E.fileName('x', 1, 'gpx', 'a'.repeat(200)).length, 80 + '.gpx'.length);
});
