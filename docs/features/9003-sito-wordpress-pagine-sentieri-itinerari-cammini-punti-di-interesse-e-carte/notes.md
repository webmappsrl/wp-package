> Ticket: oc:9003

# Notes — Griglia dei percorsi da una copia, slider dal config.json (wp-package)

## Divergenze dal piano, task per task

### Task 5 griglia dentro la ricerca

Il piano prevedeva un `grid.js` nuovo con la sua interfaccia (faccette, cronologia, annunci, focus, zero risultati).
Leggendo `search.js` è emerso che la ricerca sa già lavorare con una source che calcola tutto sul server (la source
`pois` sulla rotta `wmwp/v1/pois`): la griglia è quindi la ricerca in **modalità griglia** (`config.grid`), con una
source sola, senza tab né campo di testo.

- `assets/js/source-grid-tracks.js` (nuovo) interroga `wmwp/v1/tracks` con i parametri dell'indirizzo e, alla prima
  ricerca, usa il risultato già disegnato dal server (`config.initial`).
- `search.js`: in modalità griglia il tab non va nell'indirizzo, non si disegnano i tab né il riepilogo «Tutti».
- `templates/search.php`: tab e campo di testo nascosti, niente `role="tabpanel"`, prima pagina (card, numero dei
  risultati, link alle pagine) dal server.
- La griglia dei POI usa la source `pois` della ricerca così com'è, con le sue faccette (tipi e luoghi): per Forestas è
  la decisione presa («come il tab dei POI»). `wmwp_poi_search_place_facets( ?array $facets )` non serve: si farà
  quando un tema vorrà faccette dei POI diverse da quelle della ricerca.
- Con un filtro nell'indirizzo la prima pagina dei POI la disegna il JS (la rotta dei POI riceve i filtri come la
  ricerca); senza filtri la disegna il server.

Perché: faccette, filtri attivi, pagine, annunci, focus e pannello dei filtri sul telefono sono quelli della ricerca,
già provati per l'accessibilità (oc:9000, oc:9018), invece di una seconda copia da tenere allineata.

## Prove di accessibilità (25/11)

Su `/sentieri/` locale con la copia delle tracce di UAT caricata (`wp wmwp tracks-copy load`), poi copia rimessa da dev.

| Prova | Chromium | Firefox | WebKit / Safari |
|---|---|---|---|
| Albero di accessibilità (Playwright `ariaSnapshot`): gruppo «Lunghezza del sentiero», slider «Minimo» e «Massimo», stato «340 sentieri» | ✅ | ✅ | ✅ |
| Solo tastiera: Tab fino ai cursori, frecce, Home/End; una voce di cronologia per tre frecce; focus resta sul cursore | ✅ | ✅ | ✅ |
| Focus visibile: contorno 3 px `#4D4D4D` (8,45:1); bersaglio alto 24 px | ✅ | ✅ | ✅ |
| 375 px: pannello dei filtri con gli slider, Tab fino ai cursori | ✅ | ✅ | ✅ |
| Inglese: «Path length», «No minimum», «1 hour», filtro attivo «from 1 h to 3 h» | ✅ | ✅ | ✅ |
| Contrasto: binario `#767676` 4,54:1, cursore e parte scelta `#254877` 9,24:1, valori `#4A4A4A` 8,86:1 | ✅ | ✅ | ✅ |

**VoiceOver in Safari** (comandato da AppleScript, `content of last phrase`): sui cursori «Nessun minimo, Minimo,
cursore» e «10 km, Massimo, cursore» (il valore scritto accanto è `aria-hidden` e non si legge due volte); togliendo il
filtro attivo («Togli il filtro Lunghezza del sentiero: fino a 10 km, pulsante») annuncia «617 sentieri»; cambiando
pagina legge la prima card della pagina nuova.

Difetti trovati e corretti in queste prove:

- **Cursore invisibile in Safari**: con `accent-color` Safari disegnava un cursore bianco su fondo bianco e un binario
  grigio chiarissimo (1,15:1, sotto il 3:1 di WCAG 1.4.11), Firefox un cursore grigio. Ora binario e cursore sono
  disegnati dal tema con colori espliciti, uguali nei tre motori (`style.css` del child, `--wmwp-fill` aggiornato da
  `search.js`).
- **Focus sui risultati vecchi dopo il cambio di pagina**: il focus andava ai risultati al clic, prima che arrivassero
  quelli nuovi dalla rotta, e VoiceOver leggeva la pagina di prima «occupata». Ora il focus arriva quando la pagina è
  disegnata e `aria-busy` è già `false` (due frame dopo). Vale anche per la ricerca unica, provato nei tre motori.

Il requisito diceva «il focus va al titolo dei risultati»: va al contenitore dei risultati, come già nella ricerca
unica (oc:8952), e lo screen reader legge la prima card della pagina nuova.

## Review interna

- **Review finale dell'esecuzione (modello forte, 25/11)**: nessun Critical; tre Important e sette Minor, tutti risolti
  con un test che li riproduceva prima:
  - focus che tornava sul cursore mosso invece che su quello raggiunto con Tab (Firefox e WebKit): ora torna su chi ha
    il focus al momento del ridisegno;
  - focus sul `body` togliendo i filtri in una griglia (il campo di testo è nascosto): ora va ai risultati;
  - ordine delle card diverso dalla ricerca unica, con i nomi che iniziano con un tab in testa: la copia è ordinata per
    nome italiano senza spazi ai lati, come `sortName()`;
  - focus dopo un cambio di pagina interrotto da un altro filtro: legato alla run che lo ha chiesto;
  - lettura della copia a ogni chiamata (circa +90 ms nei dettagli dei POI): una lettura per richiesta, rinnovata quando
    l'option cambia;
  - shortcode senza copia: controlla la copia prima del layer e programma subito il rebuild; `tracks-copy refresh` non
    aspetta i 5 minuti dall'ultimo rebuild e chiude con un errore se la copia non c'è;
  - griglia dei POI: `q` nell'indirizzo ignorato, pagina oltre l'ultima → l'ultima, prima pagina senza doppia chiamata;
  - link alle pagine dal server con la stessa finestra del JS (7 link invece di 69);
  - slider che si aprono e chiudono come gli altri filtri (`collapsible_facets`), aperti se hanno un valore.
- **Primo giro del revisore di wp-package (25/11)**: nessun bloccante; cleanup risolti insieme ai precedenti, più:
  valore dello slider malformato (`abc`) non più filtro attivo, intervallo rovesciato (`10-4`) in ordine, fixture
  condivisa `tests/fixtures/grid-parity.json` letta dai test PHP e JS, overview allineata.
- **Attesa invece di `replaceState`** (scelta diversa dal piano): durante il movimento dello slider non si scrive nulla;
  il filtro si applica 600 ms dopo l'ultimo `change` (`SLIDER_SETTLE_MS`) con un solo `pushState`. Il risultato è lo
  stesso: una voce di cronologia e un annuncio per ogni valore fermo.

## Decisioni

- **Un solo commit** (25/11): i passi «Commit» del piano sono entrati in un commit unico, in `develop` con lo squash
  della PR del ticket e in `main` con un merge commit.
- **Testo cercato nella griglia** (Rubens, 25/11, dopo le review): la ricerca in modalità griglia mostra il campo; la
  rotta delle tracce chiede gli id all'API dello shard (`query`, come `source-tracks.js`), poi applica layer, filtri e
  pertinenza sulla copia. Scartata la ricerca nella copia: `searchable` pesa 1,46 MB su UAT e Elasticsearch analizza le
  parole, una ricerca in PHP no, quindi i risultati sarebbero diversi dalla ricerca unica. La risposta dello shard sta
  nell'object cache (gruppo `wmwp_grid_text`), non nei transient (review interna): la rotta è pubblica e, senza object
  cache esterno come su Forestas, ogni testo a caso scriveva due righe in `wp_options`, tolte solo dalla pulizia
  giornaliera. La ricerca unica fa la stessa chiamata dal browser senza cache, quindi non si perde niente.

- Campi della copia: quelli usati da card e faccette (`taxonomyIcons` compreso, che dà il nome di un valore di lista
  senza `labels`); tolti `from` e `to`, che nessuno usa.
- La rotta delle tracce restituisce card già costruite (`wmwp_grid_track_card()`, come `toCard()` di
  `source-tracks.js`, con le chiavi della source `tracks` della ricerca): prima pagina dal server e aggiornamenti usano
  lo stesso dato.
- Rotta delle tracce: 503 senza copia, 404 per una griglia che il tema non configura.
- Copia locale: 1,17 MB serializzati per 778 tracce (dev, 25/11), option non caricata in automatico.

## Bug trovati

- **I test del plugin cambiavano la copia delle tracce del sito** (25/11): `detail-cache`, `detail-legacy`,
  `detail-route` e `search-pois` ricostruiscono l'indice con uno shard finto, e il rebuild ora scrive anche la copia
  delle tracce. `tests/php/history-sandbox.php` la salva e la rimette insieme a quella dei POI; verificato rilanciando
  i quattro test con la copia di UAT caricata (resta di 778 tracce).
- **Link delle card disegnate dal server con due escape** (25/11, prova nel browser): `esc_url()` scrive `&` come
  `&#038;` e DOMDocument rifà l'escape dell'attributo, quindi `?wmwp_detail=poi&amp;#038;wmwp_id=453`. Corretto con
  `esc_url_raw()` in `wmwp_grid_card_html()`; test in `tests/php/grid-render.php`.
- **Slider nella colonna dei filtri**: con nome e valore sulla stessa riga del cursore il binario restava di 60 px;
  ora nome e valore stanno sopra e il cursore occupa la larghezza della colonna. Il cursore al suo estremo mostra
  «28 km+» / «15 h+» e lo screen reader legge «nessun massimo» / «nessun minimo».

## Follow-up

- **`tests/php/detail-render.php` fallisce già su `develop`** (25/11, verificato togliendo le modifiche di 9003): il
  caso «ripiego: traccia, motivo del file e dell'API» si aspetta un messaggio diverso da quello di oggi (il dettaglio
  prova anche `tracks/<id>.json` dopo `v1/<id>.json`). Non riguarda le griglie; da sistemare in un lavoro sui dettagli.
- `tests/php/history-sandbox.php` non è un test: è la sandbox dello storico che gli altri test includono.
