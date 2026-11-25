# Griglie dei percorsi e slider

> Lavoro d'origine: oc:9003. Artefatti in
> `docs/features/9003-sito-wordpress-pagine-sentieri-itinerari-cammini-punti-di-interesse-e-carte/`. L'uso (shortcode,
> filter `wmwp_grid_config`, rotta, comando WP-CLI) è nel `README.md`, «Griglie».

## Come funziona oggi

- **Griglia = ricerca in modalità griglia.** `[wmwp_tracks_grid]` e `[wmwp_pois_grid]` stampano il template della
  ricerca con `grid` acceso: tab e campo di testo nascosti, niente `role="tabpanel"`, una source sola (`grid` sulla rotta
  `wmwp/v1/tracks`, o `pois` sulla sua rotta). `search.js` in modalità griglia non scrive il tab nell'indirizzo e non
  disegna il riepilogo «Tutti». Una griglia per pagina (`wmwp_grid_rendered()`).
- **Copia delle tracce** (`includes/tracks-copy.php`): nasce nel rebuild dell'indice dei dettagli
  (`wmwp_detail_fetch_items()` → `wmwp_tracks_copy_pending()`, commit accanto a quello dei POI), quindi solo da un
  rebuild accettato; campi di card e faccette (`WMWP_TRACKS_COPY_FIELDS`), ordinata per nome italiano senza spazi ai
  lati, come `sortName()` della ricerca unica (l'API ordina per `name.keyword` grezzo: i nomi con un tab in testa
  andavano prima della A). Su Forestas 1,17 MB serializzati per 778 tracce, letti una volta per richiesta.
- **Regole delle faccette in PHP** (`includes/grid.php`): le stesse di `search-logic.js`. `tests/php/tracks-route.php`
  e `tests/js/search-logic.test.js` («stessi conteggi di wmwp_grid_compute_facets()») provano gli stessi casi.
- **Prima pagina dal server**: card riempite come `fill()` di `search.js` (`wmwp_grid_card_html()`, DOMDocument, link con
  `esc_url_raw()`), numero dei risultati e link alle pagine; il JS riceve il risultato in `config.initial`.
- **Slider**: faccetta `slider` da un filtro `type: slider` di `MAP.filters`; `L.lastStep()`, `L.sliderBounds()`
  (estremi aperti), `renderSlider()` in `search.js`. Il valore si applica dopo `SLIDER_SETTLE_MS` dal `change`; il testo
  dice «28 km+» e lo screen reader «nessun massimo» al cursore tutto a destra. Lo stile di binario e cursore lo dà il
  tema (Forestas: `style.css` del child, variabile `--wmwp-fill` scritta da `search.js`).
- **Focus dopo il cambio di pagina**: sui risultati quando sono disegnati e `aria-busy` è già `false` (due frame dopo),
  nella griglia e nella ricerca.

## Perché così

- **Copia sul server** (oc:9003, challenge del 25/11): l'indice nel browser costa 2,7 MB a ogni visita e lascia la pagina
  vuota con lo shard giù e per i crawler; la copia eredita lo scarto delle liste a metà dell'indice dei dettagli.
- **Modalità griglia invece di un'interfaccia nuova**: faccette, filtri attivi, annunci, focus e pannello dei filtri sul
  telefono sono quelli della ricerca, già provati per l'accessibilità (oc:9000, oc:9018).
- **Estremi aperti** (Rubens, 25/11): un cursore lasciato al suo estremo non toglie risultati.

## Come ci siamo arrivati

- **`grid.js` come interfaccia a sé** (piano di oc:9003): superato durante il lavoro, vedi sopra.
- **Focus sui risultati al clic** (ricerca, oc:8952): con una source sul server VoiceOver leggeva la pagina di prima,
  «occupata»; ora il focus aspetta il disegno.
- **`accent-color` per il colore dello slider**: in Safari il cursore restava bianco su bianco (sotto 3:1); ora binario e
  cursore li disegna il tema.
