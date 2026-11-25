# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/); le versioni seguono
[Semantic Versioning](https://semver.org/lang/it/).

## 0.7.0 — 2026-11-25

### Aggiunto
- Copia delle tracce (`includes/tracks-copy.php`): option `wmwp_tracks_copy` scritta dal rebuild dell'indice dei
  dettagli accanto alla copia dei POI, quindi solo da un rebuild accettato (ultima copia buona); solo i campi di card e
  faccette, ordinata per nome italiano senza spazi ai lati, letta una volta per richiesta; `wmwp_tracks_copy()` e
  `wmwp_tracks_layer_for_term()` (il layer con più tracce di un termine) (oc:9003).
- Comando WP-CLI `wp wmwp tracks-copy load <file>` (copia da una risposta dell'API Elastic salvata, per le prove) e
  `refresh` (oc:9003).
- Rotta `GET wmwp/v1/tracks` (`grid`, `lang`, `pg`, `q` e i parametri delle faccette): filtra, conta e pagina sulla
  copia con le regole di `search-logic.js` rifatte in PHP (`includes/grid.php`); 503 senza copia o se lo shard non
  risponde al testo, 404 per una griglia che il tema non configura (oc:9003).
- Shortcode `[wmwp_tracks_grid]` e `[wmwp_pois_grid]`, configurati dal filter `wmwp_grid_config` (`type`, `layer`,
  `facets`, `per_page`, `nouns`, `search_label`, `placeholder`): la ricerca in modalità griglia (una source, niente
  tab), con la prima pagina (card, numero, link alle pagine) già nell'HTML; una griglia per pagina (oc:9003).
- Source `grid` (`assets/js/source-grid-tracks.js`) sulla rotta delle tracce, con il risultato iniziale del server; la
  source `pois` accetta lo stesso risultato iniziale (oc:9003).
- Testo cercato nella griglia dei percorsi: gli id li dà l'API dello shard, come nella ricerca unica, con la risposta
  nell'object cache (mai nei transient: la rotta è pubblica) e l'ordine di pertinenza di `rankByText()` (oc:9003).
- Faccetta `slider` da un filtro `type: slider` di `MAP.filters` (`wmwp_app_slider_facet()`), anche nella ricerca
  unica: due `input type="range"` con etichetta e `aria-valuetext`, estremi aperti (un cursore al suo estremo non
  pone limiti), valore applicato dopo 600 ms; `L.lastStep()`, `L.sliderBounds()`, `L.sliderValue()` (oc:9003).

### Cambiato
- `wmwp_enqueue_search_assets()` accetta un terzo argomento con le chiavi in più per il JS (modalità griglia,
  risultato iniziale) (oc:9003).
- Dopo il cambio di pagina il focus va ai risultati quando sono disegnati, non prima: VoiceOver leggeva la pagina di
  prima, «occupata» (oc:9003).
- La disattivazione e la disinstallazione cancellano anche la copia delle tracce (oc:9003).

## 0.6.0 — 2026-11-24

### Aggiunto
- `config.json` dell'app scaricato da WP-Cron ogni ora, con richiesta condizionale (`ETag`, `Last-Modified`), in
  un'option con chiave nuova (`wmwp_app_config_v2`): `wmwp_app_config()` (mai una richiesta nella pagina) e
  `wmwp_app_config_refresh()`; un JSON senza `MAP` non sostituisce la copia buona; dopo un download fallito, senza
  copia buona, il giro si riprova dopo 5 minuti (`wmwp_app_config_failed_at`); mappa e ricerca leggono la stessa copia
  (oc:9018).
- Filtri della ricerca dal `config.json`, come le app: `from_config` (`activities`, `layers`, `poi_types` di
  `MAP.filters`: opzioni pubblicate, ordine, nomi e lingua del nome; tipi dei POI senza il prefisso `poi_type_`),
  `from_families` (un filtro per famiglia di `LABELS.taxonomies` con un suffisso, es. `-difficulty`) e
  `from_overlays` (un filtro per overlay con aree, oc:9018).
- Aree degli overlay (`includes/app-overlays.php`): geojson scaricati dal cron con richiesta condizionale, solo le aree
  con `layer_id` presente in `MAP.layers` (un file senza `layer_id` non si decodifica), `wmwp_point_in_geometry()`,
  appartenenza dei POI `wmwp_poi_layers()` rifatta quando cambiano le aree o la copia dei POI; i filtri leggono gli id
  delle aree da un'option piccola (`wmwp_app_overlay_layers_v1`), le geometrie solo il cron; la copia dei POI ha il
  punto (`point`), e una copia senza si riscrive subito; la rotta `wmwp/v1/pois` accetta `layer[]` (oc:9018).
- Parametro condiviso delle faccette (`param`, `ids`): tutte le aree in `layer` (fisso, `WMWP_APP_AREA_PARAM`), come le
  app; `parseState()` e `stateToSearch()` (terzo argomento) lo leggono e lo scrivono (oc:9018).
- Indirizzi vecchi degli elementi nello storico prima che servano: filtro `wmwp_detail_legacy_paths` (lingua del tema
  abbinata al codice del sito per prefisso), POI da `pois.geojson` a ogni giro, percorsi dal dettaglio letto una volta
  e solo nel cron o da WP-CLI (filtro `wmwp_detail_legacy_can_read`; al più 100 letture e 60 s per giro), segnati come
  passati in `wmwp_detail_legacy_done`; un dettaglio che non si legge si riprova dopo 6 ore (`wmwp_detail_legacy_failed`),
  uno letto senza indirizzo vecchio al più 3 volte; le chiavi di elementi che non sono più nell'indice si tolgono; righe
  non attuali che danno 301 alla pagina attuale. Un 404 sotto una base che c'è solo nello storico non forza la ricostruzione
  dell'indice (oc:9018).
- `wmwp_detail_print_url( $type, $id, $lang )`: indirizzo della scheda PDF dello shard (`<shard>/print/ec-track/<id>` o
  `ec-poi`), vuoto se `OPTIONS.ec_pdf_enabled` del `config.json` non è acceso; `lang` solo se l'app ha quella lingua.
  Per il pulsante «Stampa» dei temi, da marcare `rel="nofollow"` (oc:9018, stampa di oc:8982).

### Cambiato
- Registro degli stati senza i nomi della percorribilità, che arrivano con i termini dello shard; resta il nome dello
  stato di assenza «Sconosciuta / Unknown». Un termine senza nome si mostra con il suo identifier (oc:9018).
- `groupValues()`: un valore che non sta in nessun gruppo si tiene dopo i gruppi, con il suo nome, invece di sparire.
- Colore della linea: lo `strokeColor` della traccia se non è vuoto, poi il primo layer colorato. Anello della mappa
  del dettaglio da `roundtrip` della traccia; la regola dei 50 m resta solo senza quel campo (oc:9018).
- `wmwp_detail_normalize_path()` codifica i caratteri non ASCII come il browser: un indirizzo con `’` e lo stesso
  codificato sono lo stesso percorso (oc:9018).
- Faccetta dei tipi dei POI: con `include`, `order`, `labels` della faccetta (es. da `from_config`) solo quei tipi, in
  quell'ordine e con quei nomi; nome del filtro dalla faccetta (oc:9018).
- Un'opzione scelta che con gli altri filtri scende a zero risultati tiene il nome (e la lingua) che ha nei risultati
  che la hanno, invece dell'identifier, anche nei filtri di termini senza `labels` (oc:9018).
- La disattivazione toglie anche il cron del `config.json` e le aree dei POI; la disinstallazione cancella anche le
  option della 0.6.0 (oc:9018).
- `wmwp_poi_search_schedule_rebuild()` diventa `wmwp_detail_schedule_rebuild()` (in `detail-index.php`): pianifica la
  ricostruzione dell'indice, non solo della copia dei POI (oc:9018).

### Tolto
- La transient del `config.json` per carta e colori (`wmwp_map_config_*`) e la sua costante
  `WMWP_MAP_CONFIG_CACHE_PREFIX`: non si legge più (oc:9018).

## 0.5.0 — 2026-11-18

### Aggiunto
- Letture del dettaglio dei dati che nascono in wm-package, pronte per il tema (`includes/detail-data.php`):
  `wmwp_text()` (testo nella lingua con ripiego e la lingua da cui viene), `wmwp_detail_blocks()` (Blocchi Dettaglio),
  `wmwp_detail_gallery()`, `wmwp_detail_feature_image()`, `wmwp_detail_related_pois()` (con la posizione per la mappa),
  `wmwp_detail_stats()`, `wmwp_detail_places()` (terzo parametro `$source`: `taxonomies` o `taxonomy_where`),
  `wmwp_detail_poi_types()` (oc:9000).
- Registro degli stati delle famiglie (`includes/status.php`): `wmwp_status_families()` con la percorribilità di
  wm-package, filtri `wmwp_status_families` e `wmwp_walkability_taxonomy`, `wmwp_status_level()` (livello e totale
  per gli indicatori a tacche: tante tacche quanti sono gli stati della famiglia, ogni stato fino alla sua posizione,
  lo stato di assenza nessuna) e `wmwp_status_name()` (oc:9000).
- Percorribilità e avviso del dettaglio della traccia (`includes/detail-status.php`): `wmwp_walkability()` (la data
  solo se lo stato l'ha messo una persona, `walkability.user` non null) e `wmwp_track_notice()` (acceso con
  `FILTER_VALIDATE_BOOLEAN`, come `EcTrack::isNotAccessibleOn()` dello shard); il template di base li mostra (oc:9000).
- Ricerca unica: sorgente «Punti di interesse» da `pois.geojson` (copia `wmwp_poi_search_copy` scritta dallo stesso
  download dell'indice dei dettagli, solo quando l'indice accetta il nuovo elenco; un'app senza POI ha una copia vuota),
  rotta `GET wmwp/v1/pois` con testo nella lingua della pagina (ripiego sull'italiano), pagine e faccetta dei tipi (un
  tipo scelto resta con conteggio 0 e il suo nome); senza copia, o con una copia nel formato di prima (testo cercabile
  in una stringa sola), risponde 503 e programma la ricostruzione dell'indice.
  `assets/js/source-pois.js`; nomi della faccetta e `type_label` della card presi dalla source (oc:9000).
- Ricerca dei POI: faccette dei luoghi che il tema aggiunge alla source (`type` `list`, `field` `taxonomyWheres`, con
  `include`, `include_pattern`, `exclude`, `exclude_pattern` come per i percorsi), luoghi nella copia (`wheres`),
  parametro `filter[<chiave>][]` della rotta; ogni faccetta, tipi compresi, conta senza il proprio filtro e con gli
  altri (oc:9000).
- Dettaglio della traccia da un file con la versione (oc:8997 di wm-package): filtro `wmwp_track_json_version`
  (`wmwp_track_json_version()`), `tracks/<versione>/<id>.json` con ripiego sul file senza versione e poi sull'API; la
  richiesta condizionale vale solo per il file da cui viene la copia (oc:9000).
- Filtri della ricerca che si aprono e chiudono dal titolo, accesi dal tema con il filtro
  `wmwp_search_collapsible_facets` (pulsante con `aria-expanded` nel `legend`, aperto all'inizio solo il filtro con una
  voce scelta); «Show more» in italiano diventa «Mostra di più» (oc:9000).
- Card della ricerca: `titleLang` e `metaLang`, la lingua del testo quando ripiega su un'altra; `lang` anche sui valori
  delle faccette; la Card li marca con l'attributo `lang` (WCAG 3.1.2, `L.langFor()`) (oc:9000).
- Faccette: proprietà `missing`, il valore per i risultati senza il campo, valido per il filtro e per i conteggi
  (oc:9000).
- Source dei percorsi: `badge_exclude`, i valori del campo dell'etichetta della riga che non la mostrano (oc:9000).

### Cambiato
- **Il plugin non legge più `route-type`**, famiglia creata da uno shard e non da wm-package: la famiglia che dà la
  base degli indirizzi e il tipo delle tracce la indica il tema con il filtro `wmwp_track_kind_taxonomy` (e
  `kind_field` nella source dei percorsi). Senza il filtro le tracce non hanno tipo e la base è `track`: un tema che
  usava `route-type` deve aggiungere il filtro per tenere gli stessi indirizzi (oc:9000).
- Percorsi senza testo cercato: ordine alfabetico sul nome italiano senza spazi ai lati, con il confronto dello shard;
  prima restava l'ordine ricevuto, e i nomi con una tabulazione o uno spazio iniziale andavano in testa (oc:9000).
- La disattivazione toglie anche la copia dei POI; `uninstall.php` la cancella (oc:9000).
- `wmwp_detail_text()` è un involucro di `wmwp_text()`: un solo ripiego di lingua per indirizzi, titoli e testi
  (oc:9000).
- Testo dell'impostazione della base degli indirizzi: per le tracce il tipo è quello scelto dal tema (oc:9000).

## 0.4.0 — 2026-11-16

### Aggiunto
- Pagine di dettaglio di tracce e POI: se lo shard non risponde (qualunque codice diverso da 200, 304 e 404: errore di rete, timeout, 401/403, 5xx, risposta che non è un
  dettaglio) e c'è già una copia in cache, la pagina esce dalla copia con 200 invece del 503; la copia dura almeno
  un'ora e al massimo 6 ore dall'ultimo controllo riuscito. La risposta porta `X-WMWP-Stale: 1` e `nocache_headers()`; lo shard si richiama al
  massimo una volta ogni 60 s; senza copia resta il 503 (oc:8990).
- Filtro `wmwp_detail_stale_if_error` (predefinito `true`) per spegnere il ripiego, e `wmwp_detail_served_stale(
  string $type = '', int $id = 0 )` per sapere se un dettaglio è uscito dalla copia (oc:8990).
- Righe nel log di PHP: «dalla copia», «503, nessuna copia», «503, copia non usata» (oc:8990).
- `wmwp_http_json()` restituisce anche `error`, il messaggio dell'errore di rete (oc:8990).

### Cambiato
- Con il 304 la copia si riscrive solo quando le resta meno di un'ora: dopo l'ultimo controllo riuscito dura almeno
  un'ora e al massimo 6 (oc:8990).
- Il 404 dell'API `api/ec/<tipo>/<id>` (non quello del solo file su AWS) cancella la copia dell'elemento: non esce più al guasto successivo. `$fresh = true` e
  `wmwp_detail_url_by_id()` non usano la copia, come prima (oc:8990).

## 0.3.0 — 2026-11-16

### Aggiunto
- Storico dei percorsi delle pagine di dettaglio, nella tabella `{prefix}wmwp_detail_paths` (una riga per lingua e
  percorso, con l'elemento a cui è stato dato): un percorso resta del suo elemento finché l'elemento esiste, e dopo
  una rinomina, una traduzione del nome o un cambio di base porta con un 301 all'indirizzo attuale anche dopo la
  ricostruzione dell'indice. Tabella creata e aggiornata da sola con la versione dello schema, cancellata da
  `uninstall.php` (oc:8983).
- Comando `wp wmwp detail-paths list|forget` per vedere a chi appartiene un percorso e liberarlo (oc:8983).
- Protezioni dello storico: un indice in cui la chiave della fonte si perde (fra gli elementi presenti anche prima,
  meno della metà la tiene: API cambiate) si scarta sempre; quando lo shard viene svuotato e ricaricato (per un tipo,
  più della metà degli elementi con la chiave della fonte cambia id) le righe registrate con l'id di quel tipo si
  dimenticano; se lo storico non si legge resta l'indice di prima; i controlli usano l'ultimo indice buono anche dopo
  un cambio di configurazione delle basi; lo storico si scrive solo dopo che l'indice è stato accettato (oc:8983).
- Chiave della fonte ripetuta: resta all'id più basso, gli altri elementi contano come senza chiave (oc:8983).
- Suffisso dei doppioni nell'indice (`suffixes`): il controllo del nome accetta solo quello dato all'elemento (oc:8983).

### Cambiato
- Doppioni dello slug: chi ha già il percorso lo tiene, gli altri prendono `-2`, `-3`… in ordine di chiave (chiave
  della fonte numerica, poi testuale, poi solo id) invece di `-<chiave stabile>` o `-<id>`. Al primo giro i doppioni
  della 0.2.0 si ricalcolano e i loro percorsi vecchi portano con un 301 a quelli nuovi (oc:8983).
- Risoluzione di un percorso: lo storico viene prima dell'indice delle altre lingue, e il ripiego per slug vale solo
  per i percorsi che lo storico non conosce (oc:8983).
- Le regole di rewrite coprono anche le basi dello storico (oc:8983).

## 0.2.0 — 2026-11-13

### Aggiunto
- Pagine di dettaglio di tracce e POI senza post: indirizzi `/<base>/<slug>/` per lingua con base dal tipo o da una
  pagina scelta nelle impostazioni, indice dalle API dello shard, JSON della traccia dal file su AWS con richiesta condizionale (ETag/Last-Modified) e del POI dallo shard, cache breve di 60 s (`wmwp_detail_cache_ttl`), 301/404/503, template
  `track.php`, `poi.php`, `detail-unavailable.php` sostituibili dal tema, SEO con il solo core (adattatori per
  Impreza, Yoast, Rank Math), `hreflang` e selettore di lingua con WPML, sitemap del core (oc:8969).
- `wmwp_webapp_url()` e il campo «Webapp» nelle impostazioni: indirizzo della webapp dello shard (dominio proprio da
  `environment.ts` o `https://<app ID>.<shard>.webmapp.it/`), anche con il focus su una traccia o un POI (oc:8969).
- `wmwp_map()`: mappa del dettaglio per i temi, con tracce di stili diversi (la principale, le collegate tratteggiate) e
  punti numerati come la legenda; numeri sui punti aggiunti nella build della mappa (`map/src/index.js`) (oc:8969).
  Con `ends` sulla traccia, partenza (cerchio verde) e arrivo (bandiera a scacchi, `getAdditionalPointStyles` del
  componente); in un anello solo la partenza (oc:8969).
- `wmwp_icon_svg()`: SVG di un'icona del set IcoMoon dello shard (`<awsApi>/json/icons.json`) dal nome, con cache dei
  nomi già chiesti (oc:8969).
- Indirizzo per id `?wmwp_detail=&wmwp_id=` come `link_template` predefinito della ricerca (oc:8969).
- Indirizzo per id controllato con la chiave stabile e con un 302 (`wmwp_detail_url_by_id()`); elemento cancellato →
  404 invece di 503; rinomina → 301; indice rifatto ogni ora dal cron (`wmwp_detail_refresh_index`) e letto una volta
  per richiesta; regola di rewrite che lascia a WordPress feed, embed e pagine dei commenti della pagina base (oc:8969,
  review interna).
- Chiave stabile dei dettagli, `wmwp_detail_source()` e filtro `wmwp_detail_source` (di base `out_source_feature_id`):
  riconosce tracce e POI dopo un reset degli id dello shard, anche le tracce, e dà ai doppioni dello slug il suffisso
  `-<chiave>` invece di `-<id>`, stabile fra un reset e l'altro (oc:8969).
- Filtri `wmwp_api_urls` e `wmwp_server_origin` per uno shard fuori da `environment.ts` o raggiunto dal server da un
  altro indirizzo (oc:8969). Anche il config.json dell'app (carta Webmapp e colori dei layer) si legge all'indirizzo del
  server: con WordPress in Docker e lo shard sull'host la mappa restava su OpenStreetMap.

## 0.1.0 — non rilasciata

### Aggiunto
- Fork di wp-geohub (`osm2cai`, `8255db5`) ridotto alla base: scelta dello shard da wm-types, app id, URL
  dell'API Elasticsearch calcolato (oc:8952).
- Ricerca unica: shortcode `[wmwp_search]`, registro delle source (`wp_package_search_sources`,
  `wmWpSearch.registerSource()`), parametri dell'indirizzo (`wp_package_search_params`), template
  sostituibili dal tema, source dei percorsi da Elasticsearch con filtri e conteggi calcolati nel browser,
  traduzione italiana (oc:8952).
- Selezione e scarico di più percorsi: casella su ogni riga, «Seleziona tutti» della ricerca, barra con formato
  GPX, KML o GeoJSON; tracce da `api/ec/track/multiple` a gruppi di 10 e conversione nel browser
  (`assets/js/track-export.js`) (oc:8952).
- Mappa: il componente `FeatureCollectionMap` di wm-package compilato in `assets/dist/` (`map/`, build con Vite),
  `window.wmWpMap.mount()`, shortcode `[wmwp_map]` (tracce per id o GeoJSON), vista «Mappa» nella ricerca con il
  selettore «Elenco | Mappa» e la vista nell'indirizzo (`view`/`map` in `wp_package_search_params`), pulsante
  dello schermo intero, percorsi selezionati in primo piano e inquadrati, carta di base dal `config.json` dell'app
  (`wp_package_map_tiles`), percorsi nel colore del loro layer (`wp_package_map_layer_colors`), riquadro con la
  card al clic sulla mappa (`popup` in `mount()`, `map.card()` della source) e pulsante per la selezione nel riquadro
  (oc:8952).
- Il componente della mappa viene dal submodule `map/wm-package` (non più scaricato da GitHub dalla build) (oc:8952).
- `assets/js/track-api.js`: tracce da `api/ec/track/multiple` per scarico e mappa, con la cache per id; la mappa e
  lo scarico che chiedono le stesse tracce insieme fanno una richiesta sola (oc:8952).
- Ultima configurazione degli shard letta da wm-types tenuta nell'opzione `wmwp_shards_config_last`: se GitHub non
  risponde e la cache è scaduta vale quella, e wm-types si richiede una volta sola (oc:8952).
- `wmwp_asset_version()`: il `?ver=` degli script e degli stili è la versione del plugin più la data di modifica del
  file, così un file cambiato non resta nella cache dei browser (oc:8952).
- Nomi dei parametri dell'indirizzo e chiavi dei filtri che non possono essere query var di WordPress: tornano al
  predefinito o prendono il prefisso `f_`, con un avviso `_doing_it_wrong`; la pagina dei risultati è `pg`
  (oc:8952).
- `term_labels` della source `tracks`: nomi di tipo ed etichetta di stato nella lingua della pagina (oc:8952).
- Cambiando tab i filtri si tolgono: «Vedi tutti: N» apre N risultati (oc:8952).
- Variabile CSS `--wmwp-selection-height` su `<html>`: l'altezza della barra della selezione quando il tema la rende
  fissa (0px senza barra), per gli elementi fissi del tema che coprirebbe (oc:8952).
- Accessibilità (audit WCAG 2.1 AA): mappa da tastiera (frecce, + e −) e tooltip che si chiude con Esc e resta sotto
  il puntatore, `labels.keyboard` in `mount()`; spazio per la barra della selezione fissa nello scorrimento
  (`scroll-padding-bottom`), così il focus non le finisce sotto; numero dei filtri letto con il nome («Sentiero, 34
  percorsi»); form della ricerca con il nome della sua etichetta (oc:8952).
