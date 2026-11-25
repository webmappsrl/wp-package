> Ticket: oc:9003

# Griglia dei percorsi di un layer da una copia sul server, e slider dal config.json

Parte generica di oc:9003 (sito di Forestas). La parte di Forestas è in `wp-forestas`,
`docs/features/9003-sito-wordpress-pagine-sentieri-itinerari-cammini-punti-di-interesse-e-carte/overview.md`.

## Cosa cambia

Il plugin aggiunge tre cose che ogni shard può usare dal suo tema:

1. **Copia delle tracce sul server**: la lista delle tracce dell'API Elasticsearch dello shard, in un'option
   rinnovata dal cron, con la regola dell'ultima copia buona (una copia non plausibile non sostituisce la precedente),
   e una rotta REST che filtra, conta le faccette e pagina su quella copia, sul modello della rotta dei POI
   (`wmwp/v1/pois`, `includes/search-pois.php`).
2. **Griglia dei percorsi di un layer** e **griglia dei POI**: shortcode che ricevono un layer (i POI no) e le faccette
   scelte dal tema; la prima pagina di card arriva già nell'HTML, poi faccette, slider e pagine passano dalla rotta.
3. **Slider dal `config.json`**: le faccette `type: slider` di `MAP.filters` (per Forestas `track_distance` e
   `track_duration`) si disegnano con due cursori, minimo e massimo, nella griglia e nella ricerca unica, come fa
   `wm-core` nelle app (`filters.component.html:60`, `slider-filter.component.html`).

## Perché

Il sito deve funzionare come le app e leggere tutto dallo shard (regola del `config.json`, scrum del 24/11): una lista
di percorsi di un tipo, nella HOME delle app, è un box `layer`; i filtri a intervallo sono slider di `MAP.filters`.

La copia sul server (decisione del 25/11): l'API Elastic dello shard pesa 2,76 MB su UAT e 2,79 MB su dev, senza
compressione e con `Cache-Control: no-cache, private` (1,84 MB con `layer=` per i soli Sentieri). Scaricata nel browser
a ogni visita delle pagine d'elenco, le rende lente sul telefono, vuote per i crawler e senza JavaScript (le card sono
un `<template>` clonato dal JS), e vuote con lo shard giù. Il plugin ha già lo schema giusto: la copia dei POI e
l'ultima copia buona dell'indice dei dettagli (`detail-index.php`).

## Requisiti

- [x] Copia delle tracce in un'option, rinnovata dal cron; la copia si scrive solo quando il rebuild dell'indice dei
  dettagli la accetta, quindi con il suo scarto delle liste a metà (`WMWP_DETAIL_REJECT_RATIO`,
  `WMWP_DETAIL_MAX_REJECTS`), lo stesso che protegge la copia dei POI; senza copia la griglia risponde con un messaggio
  e la rotta con 503.
- [x] Rotta REST delle tracce: layer fisso, faccette del tema (stesse chiavi e tipi della ricerca: `from_config`,
  `from_families`, `from_overlays`, slider), conteggi per faccetta come `computeFacets()` di `search-logic.js`,
  pagina e card per pagina.
- [x] Shortcode della griglia dei percorsi con il layer passato dal tema (anche da un filter, così l'id non sta nel
  contenuto della pagina); il layer è un filtro fisso, invisibile e non rimovibile, e non usa il parametro `layer`
  dell'indirizzo, che resta quello della faccetta delle aree. Layer assente o vuoto: un messaggio, mai tutte le
  tracce.
- [x] Funzione per il tema che dice quale layer di una famiglia ha più tracce con un certo termine, calcolata sulla
  copia (serve a Forestas per passare da route-type a layer).
- [x] Shortcode della griglia dei POI con le faccette passate dal tema, sulla rotta `wmwp/v1/pois`, senza leggere le
  faccette dalla source della ricerca.
- [x] Prima pagina di card nell'HTML (template `card.php`, sovrascrivibile dal tema), poi aggiornamenti dalla rotta.
- [x] Ordine delle card quello dell'API (percorsi per `name.keyword`, POI per nome come `search-pois.php`).
- [x] Card per pagina e nomi del conteggio («%s sentieri») configurabili dal tema per ogni griglia.
- [x] Parametro di uno slider nell'indirizzo con la chiave del filtro in `MAP.filters` e i due valori, un lato vuoto
  se aperto (`?track_distance=-10`, `?track_duration=60-`); un valore che la griglia non conosce si ignora, senza
  errori. Gli indirizzi diventano pubblici: il formato non si cambia dopo il lancio.
- [x] Senza la copia dei POI (rotta 503) la griglia dei POI mostra un messaggio, come quella dei percorsi.
- [x] Testo cercato nella griglia (chiesto il 25/11, dopo le review): campo visibile; per i percorsi gli id li dà l'API
  dello shard come nella ricerca unica (`wmwp_grid_text_ids()`, risposta nell'object cache: 10 minuti per testo con un object cache esterno, altrimenti la
  sola richiesta; mai nei transient, perché la rotta è pubblica e ogni testo a caso scriverebbe righe in `wp_options`), poi layer e filtri sulla
  copia, in ordine di pertinenza come `rankByText()`; testo ripulito come `cleanQuery()`; shard che non risponde → 503;
  per i POI la rotta dei POI.
- [x] Stato nell'indirizzo con i parametri del plugin (faccette, slider, `pg`), letto al caricamento; una griglia per
  pagina, non insieme alla ricerca unica (configurazione e parametri sono unici per pagina).
- [x] Faccette `type: slider` di `MAP.filters`: limiti, passo e unità dal `config.json`; filtro sui campi della copia
  (`distance`, `duration_forward`); disponibili anche nella ricerca unica.
- [x] Estremi aperti (decisione del 25/11): un cursore al suo estremo non pone limiti da quel lato (cursore basso al
  minimo = nessun minimo, cursore alto tutto a destra = nessun massimo, anche quando il passo non arriva al `max`
  del config: con `min 1`, `max 30`, `steps 3` l'ultimo valore è 28); un percorso senza valore (0 nell'indice) non
  passa un filtro attivo. Le app invece chiudono l'intervallo appena si tocca lo slider: differenza segnata in
  `notes.md`.
- [x] Uno slider compare solo se almeno un percorso della griglia (o del tab della ricerca) ha quel valore diverso da
  0: senza dati non c'è, e compare da solo quando lo shard li aggiunge (decisione del 25/11).
- [x] Comando WP-CLI per le prove: `wp wmwp tracks-copy load <file.json>` riempie la copia da una risposta salvata
  dell'API (per esempio quella di UAT), `wp wmwp tracks-copy refresh` la ricarica dallo shard collegato; il cron la
  rinnova comunque al giro successivo.
- [x] Muovere uno slider da tastiera non aggiunge una voce di cronologia per ogni passo (`replaceState` durante il
  movimento) e non annuncia il numero dei risultati a ogni passo, ma solo quando il valore si ferma.
- [x] [UX] Slider con `input type="range"` nativi, `<label>` visibili, valori scritti e `aria-valuetext` con l'unità;
  usabili da tastiera e senza trascinare (WCAG 2.1.1, 2.5.7).
- [x] [UX] Slider provati da chi sviluppa, con tastiera e con VoiceOver, in Chrome, Firefox e Safari su macOS e a
  larghezza da telefono; ciò che non va si corregge in questo lavoro. Esito di ogni prova in `notes.md`.
- [x] [UX] Numero dei risultati annunciato come messaggio di stato completo (4.1.3); zero risultati con un messaggio e,
  se c'è un filtro attivo, il pulsante per toglierlo; dopo il cambio di pagina il focus va al titolo dei risultati.
- [x] Testi del plugin in inglese con le traduzioni italiane (`languages/`), come il resto del plugin.
- [x] README aggiornato, versione 0.7.0.

## Rischi

[UX] Lo slider è un controllo nuovo per il plugin, e i browser leggono in modo diverso due `input type="range"`
affiancati. Mitigazione: input nativi, nessun cursore disegnato a mano, e la prova del requisito sopra.

La logica delle faccette oggi vive nel browser (`search-logic.js`) e per i POI sul server (`search-pois.php`): la
rotta delle tracce ne fa una terza copia in PHP. Mitigazione: stessa regola (OR dentro un filtro, AND fra filtri,
ogni faccetta contata senza il proprio filtro) e test che confrontano i conteggi con quelli della ricerca.

## Out of scope

- La ricerca unica resta sul modello attuale (indice nel browser): passarla alla copia è un altro lavoro.
- Le pagine e i loro contenuti: li decide il tema.
- Il dislivello: nessuno slider finché lo shard non lo mette in `MAP.filters`.
- Una mappa dentro la griglia; la mappa delle aree.

## Moduli toccati

Come sono stati toccati (il piano prevedeva anche `assets/js/grid.js` e `templates/grid.php`: la griglia è invece la
ricerca in modalità griglia, vedi `notes.md`, «Task 5 griglia dentro la ricerca»).

- `includes/tracks-copy.php` (nuovo): copia delle tracce, ordine per nome, lettura unica per richiesta, layer per termine.
- `includes/class-wmwp-tracks-copy-command.php` (nuovo): comando WP-CLI `wp wmwp tracks-copy load|refresh`.
- `includes/grid.php` (nuovo): regole delle faccette in PHP, rotta `wmwp/v1/tracks`, card e pagine dal server, shortcode.
- `assets/js/source-grid-tracks.js` (nuovo): source della griglia dei percorsi.
- `assets/js/search.js`: modalità griglia, slider, focus dopo il disegno e dopo i filtri tolti.
- `assets/js/search-logic.js`: faccette slider (estremi aperti, intervallo rovesciato, valore malformato).
- `assets/js/source-pois.js`: prima pagina della griglia dei POI dal server.
- `includes/app-filters.php`: slider da `MAP.filters`; `includes/search.php`: configurazione in più per il JS e script
  della griglia; `includes/detail-index.php`: aggancio della copia al rebuild.
- `templates/search.php`: modalità griglia (tab e campo nascosti, prima pagina dal server).
- `index.php` (versione 0.7.0, disattivazione), `uninstall.php`, `languages/`, `README.md`, `docs/knowledge/`.
- Test: `tests/php/tracks-copy.php`, `tracks-route.php`, `grid-render.php` (nuovi), `app-filters.php`,
  `history-sandbox.php`; `tests/js/source-grid-tracks.test.js` (nuovo), `search-logic.test.js`, `source-tracks.test.js`,
  `source-pois.test.js`; `tests/fixtures/grid-parity.json` (nuova, casi condivisi PHP/JS).
