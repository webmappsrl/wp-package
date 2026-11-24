> Ticket: oc:9018

# Sito WordPress: filtri e dettagli hanno i valori dello shard scritti nel codice, invece di leggerli dal config.json e dalle API come l'app

Overview del plugin `wp-package`. Il primo consumer è il child di Forestas
(`wp-forestas/docs/features/<stesso slug>/`: overview, plan e `notes.md` con le decisioni e le prove).

## Cosa cambia

Il plugin legge dal `config.json` dell'app quello che oggi il tema gli deve passare a mano, come fanno
le app Webmapp (wm-core):

- **filtri dei percorsi e dei POI da `MAP.filters`**: opzioni con id, nome e ordine dell'array; nomi
  delle famiglie da `LABELS.taxonomies`. Il tema sceglie quali filtri mostrare, in che ordine e con che
  aspetto, ma non scrive più valori né nomi;
- **filtri di luogo dagli overlay** (`MAP.controls.overlays`): ogni overlay le cui aree hanno
  `layer_id` diventa un filtro, con il nome dell'overlay e un'opzione per area (nome del layer da
  `MAP.layers`). Nessuna configurazione: se lo shard aggiunge un overlay con `layer_id`, il sito ha un
  filtro in più; gli overlay senza `layer_id` restano solo sulla mappa;
- **valori sconosciuti visibili**: un valore che non sta nel registro degli stati o nei gruppi del tema
  compare con il nome dello shard, invece di essere scartato (`search-logic.js`, `groupValues()`);
- **linee dei percorsi sulla mappa** con lo `strokeColor` del percorso se non è vuoto, altrimenti con il
  colore del primo layer colorato nell'ordine di `MAP.layers`, come fa già `track-map.js` (non la
  regola delle app, vedi i requisiti).

## Perché

Vedi l'overview del child. In breve: lo shard è l'unica fonte dei dati (scrum del 19/11), il sito deve
funzionare come la webapp senza dati nuovi in Elastic (scrum del 23/11) e le FeatureCollection si
leggono dal `config.json` senza dichiararle (Giuseppe, 23/11 12:41: «non devi dichiarare le
featurecollection che usi ma le leggi dal file di configurazione, è tutto lì, come infatti fa già la
webapp»). Il plugin è condiviso: quello che serve a Forestas deve valere per ogni shard.

## Requisiti

Spuntati: fatti e verificati in locale (test PHP e JS, browser, dati del dev). Le correzioni della review interna del
24/11 sera sono in `notes.md`.

- [x] Il `config.json` si legge una volta e serve sia la mappa sia la ricerca. Si scarica fuori dalla
      richiesta del visitatore (WP-Cron, come l'indice dei dettagli), con una chiave di cache nuova:
      la cache di oggi (`includes/map.php:105-108`) accetta una copia con le sole `tiles` e
      `layer_colors`, e dopo il deploy il sito resterebbe fino a un giorno senza i filtri nuovi.
- [x] Filtri da `MAP.filters`: per ogni chiave di tipo `select` che il tema sceglie, le opzioni con
      `identifier` e nome, nell'ordine dell'array. Il nome nella lingua della pagina, poi in italiano, poi il
      primo non vuoto (come `wmtrans` di wm-core, che usa la lingua di default dell'app: l'italiano su Forestas;
      `notes.md`, C6); se non è nella lingua della pagina, l'opzione porta `lang`. Ogni chiave ha il suo campo dei risultati, come nelle app (`activities` →
      `taxonomyActivities`, `layers` → `layers`, `poi_types` → tipo del POI). I tipi dei POI si confrontano
      come fanno le app (wm-core `withPoiTypeIdentifiers`): il plugin toglie il prefisso `poi_type_` dalle
      opzioni di `MAP.filters`, che lo hanno, mentre i POI no (0 corrispondenze su 28 confrontando gli identifier così come
      sono, dev e UAT 24/11). La chiave `poi_type`, copia di `poi_types` per le app vecchie
      (`AppConfigService.php:758-764`), si ignora.
- [x] Un'opzione scelta resta visibile anche quando i risultati scendono a zero, così si può togliere, con il
      nome che ha nei risultati (review interna: prima mostrava l'identifier nei filtri di termini).
- [x] Il tema può aggiungere famiglie che `MAP.filters` non ha (lette dai risultati della ricerca) e
      dare per quelle un ordine di ripiego; un valore che non vi compare si mostra in fondo.
- [x] Un'opzione con zero risultati non si mostra, come nelle app.
- [x] Un valore di una famiglia del registro degli stati (`includes/status.php`) che il registro non
      conosce compare con il nome dello shard; non viene scartato. Il registro della percorribilità
      (4 stati di osm2cai) resta solo per ordine e stato di assenza, finché lo shard non pubblica
      l'elenco degli stati di una famiglia (lacuna per oc:8916). Nomi e icone vengono solo dai termini
      dello shard, che li dà già in italiano e inglese (dev, 24/11): i nomi scritti nel registro si
      tolgono, e un termine senza nome compare con il suo identifier, come un valore fuori registro.
      Resta solo il nome dello stato di assenza, «Sconosciuta / Unknown», come ripiego dichiarato in un
      punto: molti percorsi non hanno uno stato (24/11: 98 su 170 su UAT, 509 su 778 sul dev) e il termine non arriva con
      nessun dato. Si toglie quando lo shard pubblica l'elenco degli stati o assegna lo stato di
      assenza ai percorsi senza stato (deciso dal dev, 24/11).
- [x] Anello nella mappa del dettaglio (`track-map.js`): dal campo `roundtrip` del percorso quando c'è;
      la regola dei 50 m fra inizio e fine resta solo per uno shard che non lo dà.
- [x] Faccette dei luoghi per nome su `taxonomyWheres` (`includes/search-pois.php`, chiavi `include`,
      `include_pattern`, `exclude`, `exclude_pattern`): restano nel plugin come possibilità generica per
      altri shard; Forestas non le usa più.
- [x] Filtri di luogo: il plugin legge `MAP.controls.overlays`, scarica il geojson di ogni overlay
      (fuori dalla richiesta del visitatore, con richiesta condizionale su `ETag`/`Last-Modified`, perché
      l'url non cambia quando il file si rigenera) e costruisce un filtro per ogni overlay con almeno
      un'area il cui `layer_id` sta in `MAP.layers`. Un'area con un `layer_id` che `MAP.layers` non ha si
      salta: su UAT il 24/11 le 39 Aree geografiche hanno gli id di Geohub (206–244) e `MAP.layers` ha 12
      layer, finché oc:9001 non gira lì. Il nome del filtro è quello dell'overlay; le opzioni sono le
      aree, con il nome del layer da `MAP.layers`.
- [x] Un percorso sta in un'area se il suo `layers` (risultati della ricerca) contiene il `layer_id`
      dell'area. Un POI sta in un'area se il suo punto cade nel poligono dell'area (scrum del 23/11:
      «le geometrie per fare il filtro le devi avere a priori»); il calcolo si fa sul server, non a ogni
      ricerca, e si rifà quando cambia il geojson **o la copia dei POI** (ricostruita ogni ora con
      l'indice dei dettagli): gli id dei POI cambiano (su UAT il reset notturno fa `RESTART IDENTITY`) e
      un POI nuovo creato in Nova deve entrare nella sua area.
- [x] Difficoltà, un filtro per scala: il tema può dichiarare un gruppo di filtri per suffisso delle
      famiglie (`-difficulty`); il plugin crea un filtro per ogni famiglia che ha risultati, con il nome
      della famiglia da `LABELS.taxonomies` e per valore l'identifier intero (`horse-difficulty-easy`),
      senza unire scale diverse. Una scala nuova compare da sola. Decisione della challenge (24/11): unire
      per valore metteva insieme `easy` di 4 scale e dava a `hard` due nomi («Difficile» e
      «Impegnativo»); 101 percorsi su 778 del dev hanno 2 o 3 scale.
- [x] Parametri dell'indirizzo: i nomi del plugin sono già in inglese (`q`, `tab`, `pg`, `view`, `map`;
      la pagina si chiama `pg` e non `page`, query var di WordPress che fa tornare la ricerca a pagina 1
      con il redirect canonico, `wmwp_search_params()`); le chiavi dei filtri costruiti dal plugin sono
      in inglese e uguali in tutte le lingue.
- [x] Filtri di luogo nell'indirizzo: un solo parametro, `layer` (fisso, lo legge anche la rotta dei POI), con gli
      id delle aree scelte
      (`?layer=3,50`), come le app (`?layer=<id>`, wm-core `geobox-map.component.ts:554`,
      `url-handler.service.ts:252`). Gli overlay non hanno né id né slug nel `config.json`, e il numero
      del file cambia fra ambienti (Catastali `1.geojson` sul dev, `2.geojson` su UAT): il plugin
      rimette ogni id sotto il filtro del suo overlay. Aree dello stesso filtro in alternativa fra loro,
      filtri diversi insieme (Area D e Supramontes). Un link vale sull'ambiente dove nasce (decisione
      della challenge, 24/11).
- [x] Mappa: le linee dei percorsi con lo `strokeColor` del percorso se non è vuoto (oggi è `''` su
      tutti i percorsi di dev e UAT), altrimenti con il colore del primo layer colorato nell'ordine di
      `MAP.layers` (`track-map.js:44-50`, la regola di oggi); il colore di ripiego vale solo per un
      percorso senza layer colorati. Non è la regola delle app: map-core (`styleFn`,
      `styles.ts:674-760`, `origin/develop`) usa `layers[0]` e, se non ha colore, il rosso di default
      (`constants.ts:3`); su UAT il 24/11 `layers[0]` è un'area catastale senza colore in 166 percorsi
      su 170, quindi un itinerario o un cammino verrebbe rosso (deciso dal dev nella challenge, 24/11).
      Un percorso anche in «Sentieri Accessibili» prende quel colore, che in `MAP.layers` viene prima
      di «Sentieri» (2 percorsi sul dev, `#e100ff`; su UAT `#ffff00`, segnalato per lo shard).
- [x] Se il `config.json` o un geojson non si scarica, la ricerca funziona senza quei filtri e senza
      errori in pagina; il log lo dice.
- [x] [UX] I filtri di luogo usano la faccetta che c'è già (`fieldset`, `legend`, prime 5 opzioni e
      «Show more», conteggio letto dallo screen reader); i nomi lunghi vanno a capo, non si troncano.
- [x] Test: `tests/php/` e `tests/js/` per lettura di `MAP.filters` e degli overlay, appartenenza di
      percorsi e POI, valori sconosciuti, un filtro per scala di difficoltà.
- [x] Indirizzo della scheda PDF per il pulsante «Stampa» dei temi (deciso dal dev, 24/11): il plugin lo costruisce
      dal `config.json`, come i filtri, solo se `OPTIONS.ec_pdf_enabled` è acceso; `<shard>/print/ec-track/<id>` o
      `ec-poi`, con la lingua della pagina se l'app ce l'ha (`Print.md` del wm-package, scrum del 24/11: il JSON degli
      elementi non porta più il link).
- [x] Versione del plugin alzata, `CHANGELOG` e documentazione dei nuovi filtri.

## Rischi

- **Geometrie pesanti.** Per sapere quali overlay hanno aree con `layer_id` bisogna scaricarli tutti:
  sul dev il 24/11 sono circa 8,8 MB, e «Aree no caccia» da sola pesa 5,3 MB (463 poligoni, nessun
  `layer_id`). Le aree con `layer_id` sono 46 (7 + 39). Mitigazione: download e calcolo fuori dalla
  richiesta del visitatore (WP-Cron), con richiesta condizionale, quindi un file che non cambia non si
  riscarica; in cache si salvano le aree utili e l'appartenenza dei POI, non i file interi. Un overlay
  senza `layer_id` si ricorda come tale finché il suo file non cambia.
- **Il sito segue gli interruttori dell'app.** Un filtro di `MAP.filters` esiste solo se l'app lo ha
  acceso in Nova (`filter_activity`, `filter_layer`, `filter_poi_type`, `AppConfigService.php:703-766`):
  spegnerlo per l'app lo toglie anche dal sito. È il comportamento voluto (come le app).
- **Plugin condiviso.** Uno shard con `MAP.filters` diverso vedrà filtri diversi senza toccare il tema.
  È il comportamento voluto (come le app), ma un tema che oggi passa filtri a mano va controllato.
- **`config.json` non raggiungibile.** Si resta senza i filtri che ne dipendono, non senza ricerca.

## Out of scope

- Modifiche allo shard: le lacune (nomi in inglese, ordine dei termini pubblicato, filtri del sito in
  `MAP.filters`) vanno nel ticket per lo shard descritto in `notes.md` del child.
- Filtri per gli overlay senza `layer_id`.

## Moduli toccati

- `includes/map.php`: lettura del `config.json` condivisa con la ricerca; colori delle linee.
- `includes/search.php`: filtri da `MAP.filters` e dagli overlay, ordine di ripiego, chiavi in inglese.
- `includes/search-pois.php`: appartenenza dei POI alle aree con le geometrie.
- `includes/status.php`: valori fuori registro visibili.
- `assets/js/search-logic.js`: `groupValues()` senza scarto, filtro su più famiglie, filtro per `layers`.
- `assets/js/source-tracks.js`, `assets/js/source-pois.js`: filtri di luogo.
- `assets/js/track-map.js`: colore delle linee dal layer.
- `includes/detail-render.php`: indirizzo della scheda PDF (`wmwp_detail_print_url()`).
- `tests/php/`, `tests/js/`.
- `README.md` / documentazione del plugin, `CHANGELOG`.
