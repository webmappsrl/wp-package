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
- `assets/js/track-api.js`: tracce da `api/ec/track/multiple` per scarico e mappa, con la cache per id (oc:8952).

### Cambiato
- Nome del plugin, della cartella e del text domain: da `wm-wp-package` a `wp-package`, come il repository
  (oc:8952).
