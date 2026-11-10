# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/); le versioni seguono
[Semantic Versioning](https://semver.org/lang/it/).

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
