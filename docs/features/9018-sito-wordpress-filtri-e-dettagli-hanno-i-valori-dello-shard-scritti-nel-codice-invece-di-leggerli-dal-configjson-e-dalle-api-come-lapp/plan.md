> Ticket: oc:9018

# Piano del plugin: filtri e mappa dal `config.json` dell'app

> **Per chi esegue:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (consigliato) o
> superpowers:executing-plans. I passi usano le caselle (`- [ ]`). **Nessun commit automatico:** i commit
> sotto sono istruzioni per il dev, che li fa lui.

**Obiettivo:** il plugin legge dal `config.json` dell'app (e dagli overlay che vi sono elencati) filtri,
nomi, aree e colori, così il tema non scrive più valori dello shard.

**Architettura:** un modulo scarica con WP-Cron `config.json` e geojson degli overlay (richiesta
condizionale, cache con chiave nuova); un modulo trasforma `MAP.filters`, `LABELS.taxonomies` e gli
overlay in faccette nel formato che `search-logic.js` già usa (`key`, `label`, `type`, `field`, `labels`,
`order`); il tema le chiede per nome invece di scriverle. I POI ricevono i `layers` delle aree in cui
cadono, calcolati sul server.

**Stack:** PHP 8.3 (WordPress, WP-Cron, transient e option), JavaScript ES5 senza build (`assets/js`),
test PHP con `wp eval-file`, test JS con `node --test`.

**Spec:** `docs/features/9018-…/overview.md` (questo repo) e, per le decisioni con le prove,
`wp-forestas/docs/features/9018-…/notes.md` (sezioni «Decisioni» e «Challenge (24/11)»).

## Vincoli globali

- Nessun valore dello shard (identifier, nomi, ordini) nel plugin, salvo il nome dello stato di assenza
  della percorribilità «Sconosciuta / Unknown» (ripiego dichiarato, `includes/status.php`).
- Nomi con la regola di `wmtrans`: lingua della pagina, poi quella di default, poi il primo non vuoto;
  con `lang` quando non è la lingua della pagina (`wmwp_text()` dà già `{ text, lang }`).
- Nessun download nella richiesta del visitatore: `config.json` e geojson solo da WP-Cron.
- Parametri dell'indirizzo in inglese, uguali in tutte le lingue; pagina `pg`; filtri di luogo in un solo
  parametro `layer`.
- Comportamento di oggi invariato per un tema che non usa le nuove chiavi (`from_config`,
  `from_families`, `from_overlays`).
- Comandi dei test: PHP `docker exec wordpress-forestas wp eval-file wp-content/plugins/wp-package/tests/php/<file>.php --allow-root`
  (stampa `PASS` o `FAIL: …`); JS `node --test tests/js/*.test.js` dalla cartella del plugin.
- Commenti e documentazione in italiano, termini tecnici in inglese.

## Review Focus

1. **`config.json` scaricato a metà import** (su UAT il 24/11 mancava `mtb`): il plugin mostra quello
   che c'è, senza errori; test in Task 2 con una fixture senza `mtb`.
2. **Overlay con `layer_id` che `MAP.layers` non ha** (UAT, Aree geografiche di Geohub 206–244): le aree
   si saltano, nessuna opzione con un numero al posto del nome; test in Task 4.
3. **POI ricreati con id nuovi** (reset notturno di UAT): l'appartenenza si ricalcola quando cambia la
   copia dei POI; test in Task 4 con due copie con id diversi.
4. **Link con un `layer` che non esiste più** (`?layer=999`): ignorato senza errori e tolto
   dall'indirizzo alla prima interazione; test in Task 5.
5. **Cache vecchia dopo il deploy**: la copia nella transient di oggi (`tiles`, `layer_colors`) non vale
   come copia nuova; test in Task 1.

---

### Task 1: `config.json` condiviso, scaricato da WP-Cron con richiesta condizionale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-configjson-condiviso)

**File:**
- Crea: `includes/app-config.php`
- Modifica: `includes/map.php:97-136` (`wmwp_app_map_config()` legge da `wmwp_app_config()`),
  `index.php` (require del file nuovo)
- Test: `tests/php/app-config.php`

**Interfacce:**
- Produce: `wmwp_app_config(): ?array` (ultima copia buona, mai una richiesta HTTP);
  `wmwp_app_config_refresh(): bool` (agganciato a `WMWP_APP_CONFIG_CRON_HOOK`, orario);
  costante `WMWP_APP_CONFIG_OPTION = 'wmwp_app_config_v2'` (option con `config`, `etag`,
  `last_modified`, `fetched_at`).
- Consuma: `wmwp_app_config_url()` (`includes/map.php:23`).

- [x] **Step 1: test che fallisce.** Con `pre_http_request` finto:
  - prima risposta 200 con `ETag: "a"` → `wmwp_app_config()['MAP']['filters']` uguale alla fixture;
  - seconda chiamata a `wmwp_app_config_refresh()`: la richiesta porta `If-None-Match: "a"`; risposta 304 →
    copia invariata, `fetched_at` aggiornato;
  - risposta 500 → la copia resta, una riga di log, `wmwp_app_config_refresh()` restituisce `false`;
  - una transient di oggi con le sole `tiles` e `layer_colors` → `wmwp_app_config()` restituisce `null`
    finché il cron non ha scaricato;
  - nessuna richiesta HTTP durante `wmwp_app_config()`.
- [x] **Step 2:** lanciare `tests/php/app-config.php`; atteso `FAIL` (funzione non definita).
- [x] **Step 3:** scrivere `includes/app-config.php`. Al primo uso senza copia si pianifica un giro
  singolo (`wp_schedule_single_event`), come `wmwp_poi_search_schedule_rebuild()`. `wmwp_app_map_config()`
  ricava `tiles` e `layer_colors` da `wmwp_app_config()`.
- [x] **Step 4:** lanciare `tests/php/app-config.php`, `tests/php/map-tiles.php`, `tests/php/map-detail.php`;
  atteso `PASS` su tutti e tre.
- [x] **Step 5 (commit del dev):** `feat(oc:9018): config.json dell'app scaricato da WP-Cron con richiesta condizionale` — fatto nel commit unico `226a9a1` (24/11), su richiesta del dev

### Task 2: faccette da `MAP.filters` e `LABELS.taxonomies`

**File:**
- Crea: `includes/app-filters.php`
- Modifica: `includes/search.php:99` (`wmwp_search_sources()` espande le faccette del tema),
  `assets/js/search-logic.js:236-280` (`computeFacets()`)
- Test: `tests/php/app-filters.php`, `tests/js/search-logic.test.js`, fixture
  `tests/fixtures/config-dev.json` e `config-uat.json` (copie ridotte dei `config.json` del 24/11)

**Interfacce:**
- Produce: `wmwp_app_filter_facet( array $facet, string $lang ): ?array`: per una faccetta del tema
  `{ key, from_config: 'activities'|'layers'|'poi_types', order? }` restituisce `{ key, label, type: 'list',
  field, labels, order, lang_labels }`, o `null` se la chiave manca in `MAP.filters`.
  Campi: `activities` → `taxonomyActivities`; `layers` → `layers`; `poi_types` → `poi_type` (per la rotta
  dei POI), con il prefisso `poi_type_` tolto dagli id come fa `withPoiTypeIdentifiers` di wm-core.
  La chiave `poi_type` non si usa mai.
- Produce: `wmwp_app_family_facets( array $facet, string $lang ): array`: per `{ key_prefix,
  from_families: '-difficulty', exclude: ['inclusivity-difficulty'], order: [ family => [ids] ] }`
  restituisce una faccetta `type: 'terms'`, `field: 'terms.<famiglia>'` per ogni famiglia di
  `LABELS.taxonomies` con quel suffisso, con `label` dal nome della famiglia e `key` = `<key_prefix><famiglia>`.
- Produce: `wmwp_app_label( $names, string $lang ): array{ text: string, lang: ?string }` (wrapper di
  `wmwp_text()` per i nomi del `config.json`).

- [x] **Step 1: test PHP che fallisce** (`tests/php/app-filters.php`), con le fixture:
  - `activities` su UAT → `order` = `['trekking','trail','horse','hiking','nordic-walking']`, nessun `mtb`;
  - `layers` → `order` = `['11','10','8']`, `labels['11']` = `Sentieri`, `label` = `Tipo percorso`
    (it) e `Path type` (en);
  - `poi_types` → 28 valori, nessuno con il prefisso `poi_type_`;
  - pagina inglese con un nome solo italiano → `lang_labels[id]` = `it`;
  - `from_families: '-difficulty'` sul dev → 6 faccette (esclusa `inclusivity-difficulty`), la prima
    con `label` `Difficoltà escursionistica` e `field` `terms.hiking-difficulty`;
  - una chiave di `MAP.filters` assente → `null`, nessun warning.
- [x] **Step 2: test JS che falliscono** in `tests/js/search-logic.test.js`:
  - `computeFacets` non restituisce i valori con conteggio 0, tranne quelli scelti (scelto `mtb` con 0
    risultati → resta, con `selected: true`);
  - una faccetta senza valori (scala di difficoltà senza percorsi) ha `values: []` e il template non la
    disegna (asserzione su `view.values.length === 0`).
- [x] **Step 3:** lanciare i due test; atteso `FAIL`.
- [x] **Step 4:** implementare. In `computeFacets()` i valori a zero non scelti si tolgono dopo il
  conteggio; l'ordine resta `facet.order`, poi conteggio. `wmwp_search_sources()` passa ogni faccetta
  del tema da `wmwp_app_filter_facet()` o `wmwp_app_family_facets()` quando ha `from_config` o
  `from_families`, e la lascia com'è altrimenti.
- [x] **Step 5:** lanciare `tests/php/app-filters.php`, `tests/php/search-params.php`,
  `node --test tests/js/*.test.js`; atteso `PASS`.
- [x] **Step 6 (commit del dev):** `feat(oc:9018): faccette dai filtri e dalle famiglie del config.json` — fatto nel commit unico `226a9a1` (24/11), su richiesta del dev

### Task 3: registro degli stati senza nomi, valori fuori registro visibili

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-registro-degli-stati)

**File:**
- Modifica: `includes/status.php:34-119`, `assets/js/search-logic.js:113-126` (`groupValues()`),
  `includes/detail-status.php:28-45`
- Test: `tests/php/status.php`, `tests/php/detail-status.php`, `tests/js/search-logic.test.js`

**Interfacce:**
- Produce: `wmwp_status_families()` con `states[].identifier` senza `name`, salvo lo stato di assenza della
  percorribilità che tiene `name` `{ it: 'Sconosciuta', en: 'Unknown' }`.
- Produce: `wmwp_status_name( $family, $identifier, $lang )` → nome del registro se c'è, altrimenti
  `{ text: $identifier, lang: null }`; il nome del termine, quando arriva con il dato, vince sempre
  (già così in `wmwp_walkability()`).

- [x] **Step 1: test che falliscono:**
  - `wmwp_status_name('walkability-status', 'walkability-status-walkable', 'it')['text']` = identifier
    (il nome ora arriva dal termine);
  - `wmwp_status_name('walkability-status', 'walkability-status-unknown', 'en')['text']` = `Unknown`;
  - `wmwp_walkability()` con un termine `{ identifier: 'walkability-status-walkable', name: { it:
    'Percorribile', en: 'Walkable' } }` → `Walkable` in inglese;
  - JS: `groupValues([{id:'x-nuovo',label:'Nuovo'}], groups)` restituisce anche `x-nuovo`, in fondo.
- [x] **Step 2:** lanciare; atteso `FAIL`.
- [x] **Step 3:** implementare; `groupValues()` mette i valori fuori gruppo dopo i gruppi, con il loro
  nome.
- [x] **Step 4:** lanciare `tests/php/status.php`, `tests/php/detail-status.php`, `node --test tests/js/*.test.js`;
  atteso `PASS`.
- [x] **Step 5 (commit del dev):** `feat(oc:9018): stati con i nomi dello shard e valori fuori registro visibili` — fatto nel commit unico `226a9a1` (24/11), su richiesta del dev

### Task 4: aree degli overlay e appartenenza dei POI

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-aree-degli-overlay)

**File:**
- Crea: `includes/app-overlays.php`
- Modifica: `includes/search-pois.php:107-185` (la copia dei POI riceve `layers`; `wmwp_poi_search_commit()`
  ricalcola l'appartenenza), `includes/app-config.php` (il giro del cron scarica anche gli overlay)
- Test: `tests/php/app-overlays.php`, `tests/php/search-pois.php`, fixture `tests/fixtures/overlay-areas.geojson`
  (tre aree: una con `layer_id` in `MAP.layers`, una con `layer_id` 206 assente, una con un buco) e
  `tests/fixtures/overlay-plain.geojson` (senza `layer_id`)

**Interfacce:**
- Produce: `wmwp_app_overlays( string $lang ): array` → `[ { id: '<indice nel config>', label, label_lang,
  areas: [ { layer_id: int, label, label_lang } ] } ]`, solo overlay con almeno un'area valida.
- Produce: `wmwp_point_in_geometry( float $lon, float $lat, array $geometry ): bool` (Polygon e
  MultiPolygon, con i buchi).
- Produce: `wmwp_poi_layers(): array<int, list<int>>` (id del POI → `layer_id` delle aree in cui cade).
- Produce: option `wmwp_app_overlays_v1` = `[ url => { etag, last_modified, areas|null } ]`: `null` ricorda
  un overlay senza `layer_id` finché il file non cambia; nelle aree si salvano solo i poligoni utili.

- [x] **Step 1: test che falliscono:**
  - fixture con tre aree → `wmwp_app_overlays()` ha un overlay con 2 aree (quella con 206 saltata);
  - `overlay-plain.geojson` → nessun overlay; al giro dopo, risposta 304 → nessun nuovo download, l'overlay
    resta escluso;
  - `wmwp_point_in_geometry()`: punto dentro → `true`; nel buco → `false`; fuori → `false`; su
    MultiPolygon nella seconda parte → `true`;
  - copia dei POI con gli id 1 e 2, poi una copia con gli stessi punti e id 7 e 8 →
    `wmwp_poi_layers()` ha le chiavi 7 e 8, non 1 e 2;
  - la rotta `wmwp/v1/pois` con `layer[]=<id>` restituisce solo i POI di quell'area.
- [x] **Step 2:** lanciare; atteso `FAIL`.
- [x] **Step 3:** implementare. Il giro del cron, dopo il `config.json`, scarica ogni url di
  `MAP.controls.overlays` con tipo diverso da `title`, con `If-None-Match`/`If-Modified-Since`;
  l'appartenenza si ricalcola quando cambia un'area o quando `wmwp_poi_search_commit()` scrive una copia
  nuova. Il file grande non resta in memoria: si legge, si tengono le aree utili, si libera.
- [x] **Step 4:** lanciare `tests/php/app-overlays.php` e `tests/php/search-pois.php`; atteso `PASS`.
  Poi sul dev: `wp eval 'wmwp_app_config_refresh(); echo count( wmwp_app_overlays("it") );'` → `2`, e
  memoria di picco sotto i 256M (`memory_get_peak_usage`).
- [x] **Step 5 (commit del dev):** `feat(oc:9018): aree degli overlay e appartenenza dei POI calcolata sul server` — fatto nel commit unico `226a9a1` (24/11), su richiesta del dev

### Task 5: filtri di luogo nella ricerca, parametro `layer`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-parametro-layer)

**File:**
- Modifica: `includes/app-filters.php` (faccette `from_overlays`), `includes/search.php`,
  `assets/js/search-logic.js:282-330` (`parseState()`, `stateToSearch()`), `assets/js/source-pois.js:20-60`
- Test: `tests/js/search-logic.test.js`, `tests/js/source-pois.test.js`, `tests/php/app-filters.php`

**Interfacce:**
- Produce: una faccetta del tema `{ from_overlays: true }` diventa una faccetta per overlay
  `{ key: 'area-<id overlay>', param: 'layer', type: 'list', field: 'layers', labels, order, ids }`.
- Produce: in `search-logic.js` una faccetta con `param` legge e scrive il parametro condiviso:
  `parseState()` dà a ogni faccetta gli id di `layer` che stanno nei suoi `ids`; `stateToSearch()` unisce
  le scelte di tutte le faccette con lo stesso `param` in un solo valore, in ordine numerico.

- [x] **Step 1: test che falliscono:**
  - `parseState('?layer=3,50,999', …)` con due faccette (aree catastali con 3, geografiche con 50) →
    `filters['area-0']` = `['3']`, `filters['area-1']` = `['50']`, il 999 non c'è;
  - `stateToSearch()` dello stesso stato → `layer=3,50`;
  - `filterHits()`: un percorso con `layers [3, 11]` passa con `area-0: ['3','4']` (OR dentro la
    faccetta) e non passa se c'è anche `area-1: ['50']` (AND fra faccette);
  - `source-pois`: la richiesta alla rotta porta `layer[]=3&layer[]=50`.
- [x] **Step 2:** lanciare; atteso `FAIL`.
- [x] **Step 3:** implementare.
- [x] **Step 4:** `node --test tests/js/*.test.js` e `tests/php/app-filters.php`; atteso `PASS`.
- [x] **Step 5 (commit del dev):** `feat(oc:9018): filtri di luogo dagli overlay in un solo parametro layer` — fatto nel commit unico `226a9a1` (24/11), su richiesta del dev

### Task 6: mappa, colore delle linee e anello

**File:**
- Modifica: `assets/js/track-map.js:44-50` (`layerColor()`), `:160-190` (anello)
- Test: `tests/js/track-map.test.js`

- [x] **Step 1: test che falliscono:**
  - percorso con `strokeColor: ''` e `layers [3, 11]`, colori `[ {id: 9, color: '#e100ff'}, {id: 11,
    color: '#de1b0d'} ]` → `#de1b0d`;
  - lo stesso con `layers [3, 9, 11]` → `#e100ff` (ordine di `MAP.layers`);
  - `strokeColor: '#123456'` → `#123456`;
  - `roundtrip: true` con inizio e fine a 300 m → anello; `roundtrip: false` con inizio e fine a 10 m →
    niente anello; senza `roundtrip` → regola dei 50 m di oggi.
- [x] **Step 2:** lanciare; atteso `FAIL` sui casi di `strokeColor` e `roundtrip`.
- [x] **Step 3:** implementare.
- [x] **Step 4:** `node --test tests/js/track-map.test.js`; atteso `PASS`.
- [x] **Step 5 (commit del dev):** `feat(oc:9018): colore della linea da strokeColor o dal primo layer colorato, anello dallo shard` — fatto nel commit unico `226a9a1` (24/11), su richiesta del dev

### Task 7: indirizzi vecchi nello storico prima che servano

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-indirizzi-vecchi)

**File:**
- Modifica: `includes/detail-index.php:299-360` (`wmwp_detail_fetch_items()`), `:28-30`
  (`wmwp_detail_normalize_path()`), `includes/detail-history.php:484-510`
- Test: `tests/php/detail-history.php`, `tests/php/detail-index.php`, `tests/php/detail-route.php`

**Interfacce:**
- Produce: filtro `wmwp_detail_legacy_paths( array $paths, string $type, array $props ): array` →
  `[ [ 'lang' => 'it', 'path' => 'sentieri/acacias-gutturu-farris-d-325' ], … ]`, chiamato alla ricostruzione
  dell'indice con le proprietà dei POI (`pois.geojson`) e, per i percorsi, con il dettaglio della traccia
  letto solo se lo storico non ha ancora un indirizzo vecchio per quell'elemento.
- Produce: righe dello storico non correnti per ogni percorso restituito (mai cancellate dal plugin).
- `wmwp_detail_normalize_path()`: `rawurldecode` poi minuscolo multibyte, uguale in scrittura e lettura.

- [x] **Step 1: test che falliscono:**
  - un filtro che restituisce `sentieri/s%E2%80%99araighinos-t-511b` → la richiesta a
    `/sentieri/s’araighinos-t-511b/` e a quella codificata danno 301 alla pagina corrente;
  - `en` + `sentieri/acacias-gutturu-farris-d-325` → `/en/sentieri/acacias-gutturu-farris-d-325/` dà 301
    alla pagina inglese;
  - seconda ricostruzione dell'indice: nessuna lettura del dettaglio per i percorsi già nello storico
    (contatore delle richieste finte = 0);
  - le righe vecchie restano dopo `wmwp_detail_history_forget_ids()`.
- [x] **Step 2:** lanciare; atteso `FAIL`.
- [x] **Step 3:** implementare; le letture dei dettagli mancanti hanno un tetto per giro (100), il resto al
  giro dopo.
- [x] **Step 4:** lanciare i tre test e tutta la suite PHP del dettaglio (`tests/php/detail-*.php`); atteso
  `PASS`.
- [x] **Step 5 (commit del dev):** `feat(oc:9018): indirizzi vecchi degli elementi nello storico, anche codificati e inglesi` — fatto nel commit unico `226a9a1` (24/11), su richiesta del dev

### Task 8: documentazione, versione, verifica

**File:**
- Modifica: `README.md` (sezione «Ricerca»: `from_config`, `from_families`, `from_overlays`, `param`;
  sezione «Mappa»: regola del colore), `CHANGELOG.md`, `index.php` (versione `0.6.0` in `Version:` e
  `WMWP_VERSION`)

- [x] **Step 1:** scrivere la documentazione delle nuove chiavi con un esempio per ciascuna.
- [x] **Step 2:** alzare la versione a `0.6.0` e scrivere la voce del `CHANGELOG`.
- [x] **Step 3:** lanciare tutta la suite: ogni `tests/php/*.php` con `wp eval-file` e
  `node --test tests/js/*.test.js`; atteso `PASS` su tutti.
- [x] **Step 4 (commit del dev):** `docs(oc:9018): nuove chiavi delle faccette, colore delle linee, versione 0.6.0` — fatto nel commit unico `226a9a1` (24/11), su richiesta del dev
