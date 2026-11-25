> Ticket: oc:9003

# Griglia dei percorsi da una copia, slider dal config.json — piano di implementazione (wp-package)

> **Per chi esegue:** usare superpowers:subagent-driven-development o superpowers:executing-plans, task per task. I
> passi sono caselle (`- [ ]`). **Nessun commit automatico:** i passi «Commit» sono istruzioni per il dev.

**Goal:** il plugin sa mostrare la griglia dei percorsi di un layer (e dei POI) costruita sul server da una copia delle
tracce, con faccette e slider letti dal `config.json`, e mostra gli slider anche nella ricerca unica.

**Architecture:** la copia delle tracce nasce dove nasce già la copia dei POI: nel rebuild dell'indice dei dettagli
(`wmwp_detail_fetch_items()` scarica già l'API Elastic, e il rebuild scarta le liste a metà), quindi eredita cron
orario e ultima copia buona. Una rotta REST `wmwp/v1/tracks` filtra, conta e pagina sulla copia con le stesse regole
di `search-logic.js`; gli shortcode `[wmwp_tracks_grid]` e `[wmwp_pois_grid]` disegnano la prima pagina in PHP e
`grid.js` aggiorna dalla rotta. Gli slider sono un tipo di faccetta (`slider`) letto da `MAP.filters`.

**Tech Stack:** PHP 8.3 / WordPress, JavaScript senza build (IIFE come `search.js`), `node --test`, test PHP con
`wp eval-file`.

**Spec:** `docs/features/9003-sito-wordpress-pagine-sentieri-itinerari-cammini-punti-di-interesse-e-carte/overview.md`
(questo repo) e l'overview gemella in `wp-forestas`.

## Global Constraints

- Identificatori in inglese; commenti, docs e commit in italiano; scope `oc:9003`.
- Nessun valore dello shard nel codice: limiti, passo, unità e nomi degli slider da `MAP.filters`; layer e termini
  dai dati.
- Testi di base in inglese con traduzione italiana in `languages/` (README, «Traduzioni»); in locale il plugin è
  montato in sola lettura: `wp i18n` su una copia.
- Slider: estremi aperti; percorso con valore 0 escluso da un filtro attivo; slider visibile solo se almeno un percorso
  ha il valore diverso da 0.
- Parametro di uno slider: `?<chiave MAP.filters>=<min>-<max>` con un lato vuoto se aperto (`?track_distance=-10`).
- Una griglia per pagina, non nella pagina della ricerca unica.
- Versione 0.7.0 (`index.php`, README).

## Review Focus

- **Copia assente** (WordPress nuovo, prima del rebuild): griglia con messaggio, rotta 503 — test nel Task 4 e 5.
- **Layer assente o senza percorsi** (route-type senza layer, layer tolto da `MAP.filters`): messaggio, mai tutte le
  tracce — test nel Task 4.
- **Valori dell'indirizzo sconosciuti o malformati** (`?track_distance=abc`, `?activity=inesistente`, `pg=999`):
  ignorati o riportati all'ultima pagina, senza errori — test nel Task 4.
- **Passo che non arriva al `max`** (`min 1`, `max 30`, `steps 3` → 28): cursore tutto a destra = nessun massimo —
  test nel Task 1.
- **Lista a metà durante il reset notturno**: la copia non si sostituisce — test nel Task 3.

---

### Task 1: faccetta slider nella logica condivisa (JS)

**Files:**
- Modify: `assets/js/search-logic.js`
- Test: `tests/js/search-logic.test.js`, fixture `tests/js/fixtures/hits.json` (aggiungere `distance` e
  `duration_forward` ai percorsi, uno con 0)

**Interfaces:**
- Produces: `L.sliderBounds(facet, value) → {min: number|null, max: number|null}`: `value` è `"<a>-<b>"`; un lato vuoto
  o all'estremo (≤ `facet.min`, ≥ ultimo valore raggiungibile `facet.min + floor((facet.max - facet.min) / facet.step)
  * facet.step`) diventa `null`.
- Produces: `L.lastStep(facet) → number`.
- Produces: `filterHits(hits, facets, filters, lang)` e `computeFacets(...)` accettano faccette `{type: 'slider', key,
  field, min, max, step, units, label}`; per una slider `computeFacets` restituisce `{key, type: 'slider', available:
  boolean}` (`available` = almeno un hit con `field` > 0, contato senza il filtro della faccetta stessa).
- Produces: `parseState`/`stateToSearch` leggono e scrivono il parametro di una slider come stringa `"<a>-<b>"`.

- [x] **Step 1: test che falliscono** — in `search-logic.test.js`:
  - `sliderBounds({min:1,max:30,step:3}, '-10')` → `{min:null, max:10}`; `'1-28'` → `{min:null, max:null}`;
    `'4-'` → `{min:4, max:null}`; `'abc'` → `{min:null, max:null}`;
  - `lastStep({min:1,max:30,step:3})` → `28`; `lastStep({min:30,max:900,step:30})` → `900`;
  - `filterHits` con `track_distance='-10'`: tiene un percorso da 0,6 km, toglie quello da 0 e quello da 12;
  - `computeFacets` su hit tutti a 0 → `available: false`;
  - `stateToSearch` con `track_distance: '-10'` → `?track_distance=-10`, e ritorno con `parseState`.
- [x] **Step 2:** `node --test tests/js/*.test.js` → FAIL sui test nuovi.
- [x] **Step 3:** implementare in `search-logic.js` (stile del file: funzioni pure, esportate nell'oggetto `L`).
- [x] **Step 4:** `node --test tests/js/*.test.js` → tutti PASS.
- [x] **Step 5: Commit** (istruzione per il dev): `feat(oc:9003): faccetta slider con gli estremi aperti nella logica della ricerca`

### Task 2: slider da `MAP.filters`

**Files:**
- Modify: `includes/app-filters.php`
- Modify: `tests/fixtures/config-dev.json`, `tests/fixtures/config-uat.json` (aggiungere `track_distance` e
  `track_duration` come nei `config.json` del 25/11: `type slider`, `identifier distance|duration_forward`, `units
  km|min`, `steps 3|30`, `min 1|30`, `max 30|900`)
- Test: `tests/php/app-filters.php`

**Interfaces:**
- Produces: `wmwp_app_filter_facet( array $facet, string $lang ): ?array` restituisce, per `from_config` di un filtro
  `type: slider`, `['key' => <chiave del filtro, es. track_distance>, 'type' => 'slider', 'field' => <identifier>,
  'min' => float, 'max' => float, 'step' => float, 'units' => string, 'label' => <nome nella lingua>]`; null se
  mancano `min`, `max` o `steps` numerici.

- [x] **Step 1: test che fallisce** — `from_config => 'track_distance'` con la fixture UAT dà `type slider`, `field
  distance`, `min 1`, `max 30`, `step 3`, `units km`, `label` «Lunghezza del sentiero» (it) / «Path length» (en); un
  filtro slider con `min` vuoto dà null.
- [x] **Step 2:** `wp eval-file <plugin>/tests/php/app-filters.php` → FAIL.
- [x] **Step 3:** implementare il ramo slider in `wmwp_app_filter_facet()`.
- [x] **Step 4:** stesso comando → PASS; `wp eval-file <plugin>/tests/php/search-params.php` → PASS (le chiavi
  `track_distance`, `track_duration` non sono query var di WordPress).
- [x] **Step 5: Commit**: `feat(oc:9003): faccette slider lette da MAP.filters`

### Task 3: copia delle tracce e comando WP-CLI

**Files:**
- Create: `includes/tracks-copy.php`, `includes/class-wmwp-tracks-copy-command.php`
- Modify: `includes/detail-index.php` (in `wmwp_detail_fetch_items()` accanto a `wmwp_poi_search_pending()`, e nel
  commit accanto a `wmwp_poi_search_commit()`), `index.php` (require e comando WP-CLI come quello dei percorsi)
- Test: `tests/php/tracks-copy.php`

**Interfaces:**
- Produces: `const WMWP_TRACKS_COPY_OPTION = 'wmwp_tracks_copy';`
- Produces: `wmwp_tracks_copy_build( array $hits ): array` — lista nell'ordine dell'API, ogni voce con i soli campi
  usati da card, faccette e slider: `id`, `name` (tutte le lingue: `name` + `SEARCH_NAME_TRANSLATIONS_FIELD` dell'hit),
  `ref`, `feature_image`, `from`, `to`, `distance`, `duration_forward`, `ascent`, `layers`, `terms`,
  `taxonomyActivities`, `taxonomyWheres`, `taxonomyIcons`.
- Produces: `wmwp_tracks_copy_pending( ?array $hits = null, bool $reset = false ): ?array` e
  `wmwp_tracks_copy_commit(): void` — stesso schema dei POI: la copia si scrive solo se il rebuild dell'indice la
  accetta (lo scarto sotto la metà vale anche qui).
- Produces: `wmwp_tracks_copy(): ?array` — copia salvata, null se non c'è.
- Produces: `wmwp_tracks_layer_for_term( string $family, string $term, array $allowed ): ?int` — fra i layer di
  `$allowed`, quello con più tracce della copia con `terms[$family]` uguale a `$term`; null se nessuno.
- Produces: `wp wmwp tracks-copy load <file>` (legge una risposta salvata dell'API: hits come `hits.hits[]._source`
  o lista) e `wp wmwp tracks-copy refresh` (`wmwp_detail_index( true )`).

- [x] **Step 1: test che falliscono** (`tests/php/tracks-copy.php`, rimette le option com'erano anche su errore,
  come `tests/php/app-filters.php`):
  - `wmwp_tracks_copy_build()` su 3 hit finti tiene l'ordine e solo i campi elencati;
  - `wmwp_tracks_copy_pending()` + `wmwp_tracks_copy_commit()` scrive l'option; senza pending non scrive;
  - `wmwp_tracks_layer_for_term('route-type','route-type-trail',[11,10,8])` su una copia con layer 11 (2 sentieri) e
    layer 9 (3 sentieri) → `11`; con `$allowed` vuoto → null.
- [x] **Step 2:** `wp eval-file <plugin>/tests/php/tracks-copy.php` → FAIL.
- [x] **Step 3:** implementare; aggancio nel rebuild dell'indice; comando WP-CLI.
- [x] **Step 4:** test → PASS; `wp eval-file <plugin>/tests/php/detail-index.php` → PASS (nessuna regressione).
- [x] **Step 5: verifica in locale:** `wp wmwp tracks-copy refresh` → l'option `wmwp_tracks_copy` ha le tracce di dev
  (778 al 25/11); `wp wmwp tracks-copy load /Volumes/WebmappDev/tmp-9003/es-uat.json` → 778 tracce, 404 sentieri con
  `distance` > 0.
- [x] **Step 6: Commit**: `feat(oc:9003): copia delle tracce sul server e comando tracks-copy`

### Task 4: rotta REST delle tracce

**Files:**
- Create: `includes/grid.php` (parte rotta)
- Test: `tests/php/tracks-route.php`

**Interfaces:**
- Consumes: `wmwp_tracks_copy()`, `wmwp_app_expand_facets()`, faccette slider del Task 2.
- Produces: `wmwp_tracks_query( array $copy, int $layer, array $facets, array $filters, string $lang, int $page, int
  $per_page ): array{items: list<array>, total: int, page: int, pages: int, facets: list<array>}` — layer fisso; OR
  dentro un filtro, AND fra filtri, ogni faccetta contata senza il proprio filtro (come `computeFacets()`); slider con
  le regole del Task 1 (`available`); ordine della copia; pagina oltre l'ultima → ultima.
- Produces: rotta `GET wmwp/v1/tracks` con `layer` (int), `lang`, `pg`, `per` (max 48), `grid` (id della
  configurazione di griglia registrata dal tema, Task 5) e i parametri delle faccette; 503 senza copia; risposta
  `{items: Card[], total, page, pages, facets}` con le card nella forma di `source-tracks.js`.

- [x] **Step 1: test che falliscono** (`tests/php/tracks-route.php`, copia finta di 6 tracce):
  - layer 11 → solo le tracce del layer; layer 0 o assente → `total 0`, nessuna traccia;
  - `activity=mtb,horse` OR, più `walkability=…` AND; conteggio di `activity` calcolato senza il proprio filtro;
  - `track_distance=-10` tiene 0,6 km, toglie 0 e 12 km; slider con tutte le tracce a 0 → `available false`;
  - `track_distance=abc` e `activity=inesistente` ignorati senza errori; `pg=999` → ultima pagina;
  - senza copia → `WP_Error` con status 503.
- [x] **Step 2:** `wp eval-file <plugin>/tests/php/tracks-route.php` → FAIL.
- [x] **Step 3:** implementare `wmwp_tracks_query()` e la rotta.
- [x] **Step 4:** test → PASS; confronto dei conteggi con `search-logic.js` sulla stessa fixture (`hits.json`): stessi
  numeri per `activity` e per lo slider (aggiungere il caso in `tests/js/search-logic.test.js` con i numeri attesi
  dal test PHP).
- [x] **Step 5: Commit**: `feat(oc:9003): rotta delle tracce sulla copia, con faccette e slider`

### Task 5: shortcode delle griglie e interfaccia

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-griglia-dentro-la-ricerca)

**Files:**
- Modify: `includes/grid.php` (shortcode e asset), `includes/search-pois.php` (faccette dei luoghi da un contesto)
- Create: `templates/grid.php`, `assets/js/grid.js`
- Test: `tests/php/grid-render.php`, `tests/js/grid.test.js` (finestra finta come `source-pois.test.js`)

**Interfaces:**
- Consumes: Task 3 e 4; `templates/card.php`; `wmwp_poi_search_rest()`.
- Produces: shortcode `[wmwp_tracks_grid id="<id>"]` e `[wmwp_pois_grid id="<id>"]`; la configurazione arriva dal
  filter `wmwp_grid_config` (`apply_filters( 'wmwp_grid_config', null, string $id ): ?array`) con `layer` (int, solo
  tracce), `facets` (formato della ricerca), `per_page`, `nouns` (`one`/`other` con `%s`), `title` (titolo dei
  risultati per il focus); config null o layer null → messaggio «Trails are not available at the moment» (tradotto),
  nessuna chiamata alla rotta.
- Produces: `wmwp_poi_search_place_facets( ?array $facets = null ): array` — con `$facets` usa quelle della griglia,
  senza usa la source `pois` della ricerca (comportamento di oggi).
- Produces: `grid.js` — legge lo stato dall'indirizzo, chiama la rotta, sostituisce card e faccette; `pushState` su
  faccette e pagine, `replaceState` mentre uno slider si muove e annuncio del conteggio solo a valore fermo (`change`,
  non `input`); dopo il cambio di pagina focus sul titolo dei risultati (`tabindex="-1"`); numero dei risultati in un
  solo `role="status"` con `aria-atomic="true"` e testo completo («27 sentieri»); zero risultati: messaggio e, se c'è
  un filtro attivo, pulsante per toglierli; slider con due `<input type="range">` nativi, `<label>` visibili, valori
  scritti accanto, `aria-valuetext` con unità (minuti in ore e minuti); slider con `available false` non disegnato.

- [x] **Step 1: test che falliscono**:
  - PHP: lo shortcode con una config di test disegna la prima pagina con 9 card (`card.php`), il conteggio, le
    faccette e lo stato iniziale in un `<script type="application/json">`; layer null → messaggio e nessun `<script>`
    della griglia; due shortcode nella stessa pagina → il secondo non si disegna (avviso in `WP_DEBUG`);
  - JS: cambio di faccetta → chiamata con i parametri giusti e `pushState`; movimento dello slider → `replaceState`
    senza annuncio, `change` → annuncio; zero risultati con filtro → pulsante; senza filtro → nessun pulsante.
- [x] **Step 2:** `wp eval-file <plugin>/tests/php/grid-render.php` e `node --test tests/js/*.test.js` → FAIL.
- [x] **Step 3:** implementare; griglia dei POI sulla rotta `wmwp/v1/pois` con le faccette passate (parametro
  `grid=<id>` letto dalla rotta per prendere le faccette dal filter); 503 dei POI → messaggio.
- [x] **Step 4:** test → PASS; `wp eval-file <plugin>/tests/php/search-pois.php` (se c'è) e `node --test` → PASS.
- [x] **Step 5: Commit**: `feat(oc:9003): griglie di percorsi e POI disegnate sul server`

### Task 6: slider nella ricerca unica

**Files:**
- Modify: `assets/js/search.js`, `assets/js/source-tracks.js`, `includes/search.php`
- Test: `tests/js/source-tracks.test.js`

**Interfaces:**
- Consumes: Task 1 e 2.
- Produces: la ricerca unica disegna le faccette `slider` della source `tracks` con gli stessi controlli di `grid.js`
  (funzione di disegno condivisa, esportata da `grid.js` o da un file comune: una sola implementazione).

- [x] **Step 1: test che fallisce** — `source-tracks.test.js`: con una faccetta slider e `track_distance=-10` la
  source filtra come `filterHits` del Task 1 e restituisce `available`.
- [x] **Step 2:** `node --test tests/js/*.test.js` → FAIL.
- [x] **Step 3:** implementare; `replaceState` durante il movimento come nella griglia.
- [x] **Step 4:** `node --test tests/js/*.test.js` → PASS.
- [x] **Step 5: Commit**: `feat(oc:9003): slider di distanza e tempo nella ricerca unica`

### Task 7: traduzioni, README, versione

**Files:**
- Modify: `languages/*`, `README.md` (shortcode, filter `wmwp_grid_config`, rotta, copia, comando, slider,
  «Test»), `index.php` (Version 0.7.0), `docs/features/9003-…/notes.md` (crearlo), `docs/knowledge/` (pagina del plugin
  sulle griglie, o aggiornamento della pagina della ricerca se c'è)

- [x] **Step 1:** rigenerare le traduzioni su una copia del plugin (README, «Traduzioni»); controllare che ogni testo
  nuovo abbia l'italiano.
- [x] **Step 2:** README e versione; `node --test tests/js/*.test.js` e tutti i test PHP del README → PASS.
- [x] **Step 3: Commit**: `docs(oc:9003): griglie, copia delle tracce e slider nel README, versione 0.7.0`

### Task 8: prova di accessibilità degli slider e delle griglie

- [x] **Step 1:** con le pagine del child attive (piano di `wp-forestas`, Task 1 e 2) e la copia di UAT caricata (`wp wmwp tracks-copy
  load`), Playwright in Chromium, Firefox e WebKit: solo tastiera (Tab, frecce, Home/End sui cursori, Invio sulle
  faccette e sulle pagine), `ariaSnapshot` della griglia e degli slider in IT e EN, a 1440px e a 375px.
- [x] **Step 2:** VoiceOver in Safari, comandato da AppleScript (`tell application "VoiceOver"`, lettura di
  `content of last phrase`; prerequisito: in Utility VoiceOver «Consenti il controllo di VoiceOver con AppleScript»):
  annuncio di `<label>` e valore dei cursori, del conteggio a valore fermo, del titolo dei risultati dopo il cambio
  di pagina.
- [x] **Step 3:** contrasto calcolato dei colori nuovi (≥ 4,5:1 il testo, ≥ 3:1 i controlli), bersagli ≥ 24px.
- [x] **Step 4:** correggere ciò che non va; esito di ogni prova in `notes.md`.
- [x] **Step 5: Commit** delle correzioni: `fix(oc:9003): accessibilità di griglie e slider`
