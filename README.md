# WP Package

Plugin WordPress di Webmapp per la **ricerca unica del sito**: i percorsi arrivano dall'API Elasticsearch dello
shard, le altre source le aggiunge il tema. Un solo plugin per tutti gli shard: quello che cambia da un sito
all'altro (nomi dei parametri, filtri, testi, aspetto) lo decide il tema con i filter hook e i template.

Nasce come fork di [wp-geohub](https://github.com/webmappsrl/wp-geohub), ramo `osm2cai` (`8255db5`), da cui
tiene solo la scelta dello shard e la logica generica della ricerca (oc:8952). Sincronizzazione, tipi di post
`track`/`poi`, shortcode di dettaglio (`single_track`, `single_poi`, `grid_poi`), la mappa di wp-geohub e gli stili
sono rimasti fuori: si recuperano da quel repo. La mappa di questo plugin è un'altra: il componente
`FeatureCollectionMap` di wm-package («Mappa», sotto).

> **Versione 0.x.** Fino alla 1.0 l'interfaccia pubblica può cambiare fra una versione e l'altra
> ([Semantic Versioning](https://semver.org/lang/it/), punto 4).

## Installazione

1. Il plugin sta in `wp-content/plugins/wp-package` (il nome della cartella non è scritto nel codice).
2. Si attiva dal pannello o con `wp plugin activate wp-package`.
3. In **Impostazioni → WP Package** si scelgono lo shard (elenco letto da `environment.ts` di
   [wm-types](https://github.com/webmappsrl/wm-types)) e l'app id. L'URL dell'API Elasticsearch si calcola da
   lì, per esempio `forestasdev` e app 1 danno `https://forestas.dev.maphub.it/api/v2/elasticsearch`.

Le opzioni sono `wmwp_shard` e `wmwp_app_id`: un'installazione automatica può scriverle con WP-CLI.

## Ricerca

Lo shortcode `[wmwp_search]` stampa lo scheletro della pagina (campo, tab, colonna dei filtri, `<noscript>` con
la ricerca di WordPress) e i template di card e righe; risultati e filtri li disegna `assets/js/search.js`.
Lo stato della ricerca sta nell'indirizzo (testo, tab, pagina, filtri), quindi ogni ricerca si può
condividere.

La source dei percorsi (`tracks`) chiede all'API Elasticsearch solo il testo: la risposta contiene tutti i
percorsi trovati, e filtri, conteggi e pagine si calcolano nel browser (`assets/js/search-logic.js`). Un clic su
un filtro non scarica niente. Funziona finché l'API restituisce tutti i risultati in una risposta: se un giorno
la pagina, i conteggi devono tornare all'API.

## Interfaccia pubblica

Fino alla 1.0 può cambiare (vedi sopra). Tutto ciò che non è in questo elenco è interno.

### Filter hook

**`wp_package_search_sources`** — array delle source, nell'ordine dei gruppi del tab «Tutti». Chiavi comuni:

| Chiave | Valore |
|---|---|
| `slug` | valore del tab nell'indirizzo |
| `label` | nome del tab e del gruppo |
| `nouns` | `['one' => '%s …', 'other' => '%s …']`, per «1 percorso», «28 percorsi» |
| `preview` | card nel tab «Tutti» (4) |
| `per_page` | risultati per pagina (12) |
| `script` | handle dello script che registra la source in JS |
| `layout` | `cards` o `rows` (righe come la tavola 2 del wireframe di oc:8952) |
| `search_label`, `placeholder` | testi del campo quando il tab è aperto |
| `facets` | filtri, vedi sotto |

Chiavi della source `tracks`: `endpoint` e `app` (calcolati dalle impostazioni), `link_template` (indirizzo del
dettaglio con `{id}` e `{ref}`; vuoto = card senza link), `badge_field` (campo dell'etichetta di stato nella riga, es.
`terms.validation-status`; vuoto = nessuna etichetta; classe `wmwp-badge--<identifier>`), `meta_facet` (`key` del filtro i cui
valori vanno all'inizio della riga, es. i comuni). Con un testo cercato i percorsi che lo hanno nel nome vengono
prima (l'API ordina per nome).

Selezione e scarico di più percorsi, sempre della source `tracks`: `download_url` (calcolato dalle impostazioni:
`<origin dello shard>/api/ec/track/multiple`; vuoto = niente caselle né barra), `download_name` (nome di base del
file, `<download_name>-<numero>.gpx`; con un solo percorso il file prende il nome del percorso, es.
`pedras-rujas-sa-pala-e-sa-trae-sentiero-di-san-francesco-g-602-c.gpx`), `selected` (`['one' => …, 'other' => …]`, «3 percorsi selezionati»),
`selection_label` (nome della barra per gli screen reader). Ogni riga ha una casella, sopra l'elenco c'è «Seleziona
tutti i N … della ricerca» (tutta la ricerca, non la pagina) e in fondo una barra con riepilogo, «Deseleziona tutto»,
formato (GPX, KML, GeoJSON) e pulsante di scarico. Le tracce si chiedono all'API a gruppi di 10, il limite di
`multiple`, quattro richieste alla volta, e si convertono nel browser (`assets/js/track-export.js`): le rotte GPX e
KML dell'API non si usano. La quota resta fuori dal file quando una traccia l'ha a 0 su tutti i punti (DEM non
calcolato); i percorsi senza tracciato restano fuori e la barra lo dice. La selezione resta cambiando testo, filtri,
tab e pagina, ma non sta nell'indirizzo.

Un filtro è un array con `key` (nome del parametro nell'indirizzo), `label`, `type` (`list` per un elenco di
stringhe, `terms` per un elenco di `{identifier, name}`), `field` (percorso nel risultato, es.
`terms.hiking-difficulty`), e facoltativi `exclude_prefix`, `labels` (id → nome), `order` (elenco di id),
`disabled` e `help` (filtro visibile ma spento), `groups` (fasce: `[{id, label, values: [id…]}]`, più valori sotto una
voce sola, che nell'indirizzo porta l'`id` della fascia). `include` / `include_pattern` tengono solo i valori dell'elenco o
dell'espressione regolare, `exclude` / `exclude_pattern` li tolgono: con più filtri sullo stesso campo si divide un
campo misto (es. `taxonomyWheres` in aree, province e comuni). OR dentro un filtro, AND fra filtri; i conteggi di un filtro si
calcolano senza il filtro stesso.

**`wp_package_search_params`** — nomi dei parametri dell'indirizzo: `['q' => 'q', 'tab' => 'tab',
'page' => 'page', 'view' => 'view', 'map' => 'map']`; `view` è il nome del parametro della vista e `map` il valore
della vista mappa («?view=map»; Forestas: «?vista=mappa»).

### JavaScript

`window.wmWpSearch.registerSource( id, { search( query, signal ) } )`, da chiamare prima di
`DOMContentLoaded` (script in fondo alla pagina, con dipendenza `wmwp-search`):

- `query` = `{ text, rawText, page, perPage, filters, lang }` (`text` ripulito per Elasticsearch, `rawText` com'è
  stato scritto, per le source che non passano da Elasticsearch);
- `signal` = `AbortSignal` della ricerca: una ricerca nuova annulla la precedente, e dopo 15 secondi senza risposta
  la source va in errore (nel tab «—»); ogni source parte da sola, senza aspettare le altre;
- restituisce `Promise<{ total, items, facets?, summary?, ids? }>`, dove ogni elemento di `items` è
  `{ id, title, url|null, typeKey, typeLabel, image: {src, alt}|null, code|null, meta: string[], badge: {key, label}|null }`,
  `facets` è `[{ key, label, disabled, values: [{ id, label, count, selected }] }]` e `ids` sono gli id di tutti i
  risultati della ricerca (per «Seleziona tutti»).

Una source permette la selezione e lo scarico di più risultati se registra anche `selection` («Seleziona tutti»
compare solo con un testo cercato o almeno un filtro: senza, i risultati sono l'intero catalogo; le caselle delle
righe restano sempre):
`summary( ids, lang )` → testo della barra, e `download( ids, format, signal, onProgress( done, total ) )` →
`Promise<{ content, mime, name, count, missing }>` (`format` è `gpx`, `kml` o `geojson`; `missing` gli id rimasti
fuori).

Una source mostra i risultati anche su una mappa (selettore «Elenco | Mappa» sopra i risultati, vista nell'indirizzo)
se registra `map`: `collection( ids, lang, signal, onProgress( done, total ), selected )` →
`Promise<{ collection, missing }>`, con `collection` la FeatureCollection da disegnare (proprietà lette dalla mappa:
`strokeColor`, `strokeWidth`, `lineLabel`, `tooltip`, `casing`, `context`, e `url`, aperto al clic) e `selected` gli
id scelti, e `card( id, lang )` → Card|null, la card che si apre in un riquadro al clic su un elemento della mappa
(senza `card` il clic non fa niente). La mappa prende il posto di elenco e pagine; «Seleziona tutti» e la barra della
selezione restano, e la mappa si ridisegna quando la selezione cambia. Il clic sulla mappa non apre il percorso: apre
il riquadro con la card, che porta alla scheda solo se il percorso ha un indirizzo (`link_template`), e, se la source
permette la selezione, il pulsante «Aggiungi alla selezione» / «Togli dalla selezione» (`aria-pressed`). Una scelta
fatta lì cambia solo i colori della mappa: la vista non si muove e il riquadro resta aperto (`update( …, { keepView,
keepPopup } )`). Nella source `tracks` i percorsi selezionati fra i risultati stanno in
primo piano, con un contorno bianco, e gli altri diventano sfondo tratteggiato blu ardesia (`#5B7A99`, come i
«sentieri collegati» del wireframe di Forestas) con `context: true`: per il componente
vuol dire «sotto le altre e fuori dall'inquadratura», quindi la mappa si inquadra sui selezionati. La source `tracks` la registra quando lo shard è
configurato: ogni percorso nel colore del suo layer nell'app (sotto, «Colori dei layer»), 4px, con il codice lungo
la linea; senza colore del layer rosso `#DE1B0D`. Colore di riserva e spessore si cambiano con la chiave `map_style`
della source (`['color' => …, 'width' => …]`).

Mentre le tracce arrivano la mappa disegna quelle già arrivate, senza cambiare inquadratura (`onProgress( done, total,
partial )` di `map.collection`), e alla fine inquadra tutto una volta: la ricerca di prima non resta mai sulla mappa
mentre il totale è già quello nuovo.

Le tracce per scarico e mappa vengono da `api/ec/track/multiple` con `assets/js/track-api.js`
(`window.wmWpTrackApi`): gruppi di 10 id, perché l'API ne tiene al massimo 10 per richiesta, quattro richieste alla
volta, e in memoria solo geometria, id e codice di ogni traccia già arrivata. Passare dall'elenco alla mappa, o
cambiare un filtro con la mappa aperta, non riscarica niente. Sul dev (10/11) la mappa di tutti i 769 percorsi è 77
richieste, 11,5 MB, circa 7 secondi; dopo, un filtro risponde in 0,3 secondi.

### Template

Si sostituiscono copiandoli in `wp-package/` nel tema: `search.php` (scheletro), `card.php`, `row.php`. Il JS
cerca gli elementi `data-wmwp`. Il `<noscript>` di `search.php` manda alla ricerca di WordPress con `nojs=1`: un tema che
reindirizza `?s=` alla pagina di ricerca non deve farlo quando c'è quel parametro. Una sola ricerca per pagina
(gli id dello scheletro sono fissi). Classi su cui il tema può contare: `wmwp-search`, `wmwp-card`, `wmwp-row`,
`wmwp-card--type-<typeKey>`, `wmwp-row--type-<typeKey>`, `wmwp-badge--<key>`, `wmwp-facet`, `wmwp-search__active` (riga dei
filtri attivi sopra i risultati), `wmwp-chip` (un filtro attivo, togliibile), `wmwp-search__clear-all`, `is-loading`,
`has-filters`, `filters-open`; per la selezione `wmwp-row__select`, `is-selectable` (elenco con le caselle),
`is-selected` (riga scelta), `wmwp-search__select-all`, `wmwp-selection` (barra) con `__top`, `__count`, `__clear`,
`__group`, `__group-label`, `__formats`, `__download`, `__status`, e `has-selection`, `is-downloading` sul
contenitore; per la mappa `wmwp-search__toolbar` (totale e selettore), `wmwp-search__view` (selettore, pulsanti con
`aria-pressed`), `wmwp-search__map` con `__map-canvas` e `__map-note` (avanzamento e inquadratura).

## Mappa

Un componente solo per tutto il sito (ricerca, dettaglio di track, POI, layer): il componente `FeatureCollectionMap`
di [wm-package](https://github.com/webmappsrl/wm-package) (`src/Nova/Fields/FeatureCollectionMap`, Vue 3 e
OpenLayers 9), lo stesso del campo Nova, compilato com'è in `assets/dist/wp-package-map.js` e `.css` (800 KB, 240 KB
compressi). Il file compilato è versionato: WordPress non ha bisogno di Node. Come si ricompila e come si porta a un
commit più recente di wm-package: [map/README.md](map/README.md).

Si usa in tre modi:

- **Ricerca:** la vista «Mappa» (sopra). Il file della mappa si carica solo al primo passaggio alla vista.
- **Shortcode `[wmwp_map]`:** `tracks="343,250"` (id delle tracce dello shard, lette da `api/ec/track/multiple`)
  oppure `geojson="https://…/api/ec/poi/12.geojson"` (un GeoJSON qualsiasi); `height` in pixel (420), `label` per
  gli screen reader («Map»). Senza dati, o con le tracce ma senza shard configurato, non stampa niente.
- **JavaScript:** si accoda `wmwp-map` (script e stile registrati da `includes/map.php`), poi
  `window.wmWpMap.mount( element, options )` → `{ update( geojson, { keepView, keepPopup } ), destroy() }`; `update()`
  inquadra i dati nuovi, con `keepView: true` lascia la vista dov'è (per disegnare i dati man mano che arrivano), con
  `keepPopup: true` lascia aperto il riquadro. `options`: `geojson`
  (FeatureCollection) o `url`, `height` (420), `padding` (40, margine dell'inquadratura), `slopeChart` (profilo
  altimetrico al clic su una linea, `false`), `onFeatureClick( properties )`, `onReady( { map, features } )` (`map`
  è la `Map` di OpenLayers), `fullScreen` (pulsante «schermo intero» sotto i +/−, `false`), `popup( properties )` →
  elemento da mostrare in un riquadro sul punto cliccato di una feature con `id` (si chiude con il pulsante, con Esc, con
  un clic fuori o a ogni `update()`), `labels: { zoomIn, zoomOut, fullScreen, exitFullScreen, closePopup }` (nomi dei
  pulsanti nella lingua della pagina), `tiles: { url, maxZoom,
  attributions, viewMaxZoom }` (carta di base al posto di OpenStreetMap). Ricerca e shortcode accendono lo schermo
  intero.

**Carta di base.** Ricerca e shortcode usano la prima carta di `MAP.tiles` del `config.json` dell'app configurata
(`<origin dello shard>/api/app/webmapp/<app id>/config.json`), la stessa che la webapp mostra per prima: per Forestas
la carta Webmapp, `https://api.webmapp.it/tiles/{z}/{x}/{y}.png`, con «© Webmapp © OpenStreetMap». Le tile Webmapp
arrivano allo zoom 16 (il 17 risponde 404): oltre si ingrandiscono, e la vista si ferma al `MAP.maxZoom` dell'app. Il
`config.json` si legge una volta al giorno (`wmwp_map_tiles()`, transient); se lo shard non risponde resta
OpenStreetMap e si riprova dopo un'ora. Il tema cambia la carta con il filter **`wp_package_map_tiles`**
(`['url' => …, 'maxZoom' => …, 'attributions' => …, 'viewMaxZoom' => …]`, o `[]` per OpenStreetMap); per una carta
diversa da quella Webmapp l'attribuzione la scrive il tema.

**Colori dei layer.** Dallo stesso `config.json` il plugin legge i colori dei layer dell'app (`MAP.layers[].style.color`,
nell'ordine dell'app), con cui la webapp disegna le tracce: per Forestas Sentieri `#de1b0d`, Itinerari `#608d0d`,
Cammini `#1a416f`, Sentieri Accessibili `#e100ff`. Ogni traccia prende il colore del primo layer colorato a cui
appartiene (`properties.layers`, che `api/ec/track/multiple` restituisce). Il tema li cambia, o ne cambia l'ordine, con
il filter **`wp_package_map_layer_colors`** (`[['id' => 11, 'color' => '#de1b0d'], …]`).

Lo stile viene dalle `properties` di ogni feature: linee `strokeColor`, `strokeWidth`, `strokeDash`, `lineLabel`
(etichetta lungo la linea), punti `pointFillColor`, `pointStrokeColor`, `pointRadius`, e `tooltip` (o `name`) al
passaggio del mouse; l'elenco completo è nel README del componente in wm-package. La mappa inquadra i dati a ogni
`update()`.

Limiti del componente usato com'è (modifiche possibili in wm-package, che è condiviso con Nova): base OpenStreetMap
fissa (qui sostituita da fuori, `tiles`), testo «Caricamento mappa...» solo in italiano (qui nascosto), popup
predefinito con le classi di Nova (qui non si usa: il clic lo decide `onFeatureClick`), etichette delle linee nel
colore della linea.

## Traduzioni

Testi di base in inglese, traduzione italiana in `languages/`. Per rigenerarle con WP-CLI:
`wp i18n make-pot . languages/wp-package.pot --domain=wp-package --exclude=tests,map,assets/dist`, poi il `.po`,
`wp i18n make-mo languages` e `wp i18n make-json languages --no-purge` (i `.json` sono le traduzioni del JS).

## Test

`node --test tests/js/*.test.js` prova la logica pura della ricerca, le tracce da `api/ec/track/multiple` (con un
fetch finto), le tracce per la mappa e la conversione in GPX, KML e GeoJSON (Node 18 o successivo; con Node 22
`node --test tests/js/` senza `*.test.js` non trova i file). `wp eval-file tests/php/shards-fallback.php` prova la
configurazione degli shard con wm-types che non risponde, `wp eval-file tests/php/map-tiles.php` la carta di base
letta dal `config.json` dell'app (risposte finte).
