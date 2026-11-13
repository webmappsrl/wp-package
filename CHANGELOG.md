# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/); le versioni seguono
[Semantic Versioning](https://semver.org/lang/it/).

## 0.2.0 — non rilasciata

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
