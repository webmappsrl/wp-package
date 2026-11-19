# Stati, letture del dettaglio e ricerca dei POI

> Lavoro d'origine: oc:9000. Artefatti: [overview](../features/9000-sito-wordpress-percorribilita-e-avvisi-dei-sentieri-dai-dati-dello-shard/overview.md),
> [note](../features/9000-sito-wordpress-percorribilita-e-avvisi-dei-sentieri-dai-dati-dello-shard/notes.md).

## Come funziona oggi

Funzioni, filtri, rotte e parametri sono nel README («Interfaccia pubblica», «Ricerca»). In breve:

- **Letture del dettaglio** (`includes/detail-data.php`, `includes/detail-status.php`): i dati che nascono in
  wm-package (Blocchi Dettaglio, galleria, POI correlati, dati tecnici, luoghi, tipo del POI, percorribilità, avviso)
  pronti per il tema, con il testo nella lingua della pagina e la lingua da cui viene.
- **Registro degli stati** (`includes/status.php`): famiglie «a stati» con l'ordine e lo stato di assenza; la
  percorribilità di wm-package c'è di suo, il tema aggiunge le sue con il filtro `wmwp_status_families`. Livello e
  totale per gli indicatori a tacche.
- **Ricerca dei POI** (`includes/search-pois.php`, `assets/js/source-pois.js`): copia ridotta di `pois.geojson`
  scritta dalla ricostruzione dell'indice dei dettagli, rotta `wmwp/v1/pois` con testo, pagine e faccette calcolate sul
  server (tipi e, se il tema le aggiunge, i luoghi di `taxonomyWheres`).
- **Filtri della ricerca** che si aprono e chiudono dal titolo, accesi dal tema (`wmwp_search_collapsible_facets`), e
  valore per i risultati senza il campo (`missing`).

## Perché così

- **Letture nel plugin, disegno nel tema** (oc:9000): il child di Forestas leggeva da solo il JSON di wm-package; ogni
  shard avrebbe dovuto rifare le stesse letture. Il tema controlla la versione del plugin e, con una più vecchia,
  degrada.
- **Elenco degli stati scritto nel plugin** (oc:9000): lo shard non espone l'elenco degli stati di una famiglia;
  quando lo farà il registro leggerà l'API, e i temi non cambiano.
- **Una sola copia di `pois.geojson`** (oc:9000): quella che l'indice dei dettagli scarica già. Un solo download, lo
  stesso controllo sul calo sotto la metà e gli stessi id del dettaglio; il file (6 MB) non arriva al browser.
- **Faccette dei luoghi dichiarate dal tema** (oc:9000): il plugin sa solo dividere `taxonomyWheres` con le regole di
  `keepValue()` di `search-logic.js`, rifatte in PHP; quali luoghi mostrare lo decide il tema, come per i percorsi.
  Ogni faccetta conta senza il proprio filtro e con gli altri.
- **Filtri apribili spenti di base** (oc:9000): cambiano l'aspetto per ogni sito che monta il plugin, e il plugin non
  ha CSS: freccia e aspetto li dà il tema.

## Come ci siamo arrivati

- **Copia dei POI senza luoghi** (prima versione di oc:9000): una copia scritta prima risponde comunque, senza i
  filtri dei luoghi, e programma subito la ricostruzione; una copia nel formato ancora precedente (testo cercabile in
  una stringa sola) vale invece come assente (503), perché darebbe 0 risultati a ogni testo.
