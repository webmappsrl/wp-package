> Ticket: oc:9000

# Sito WordPress: percorribilità e avvisi dei sentieri, dai dati dello shard — parte del plugin

Il resto del lavoro (percorribilità, avvisi, filtri di Forestas) sta nel child theme di `wp-forestas`: overview in
`wp-forestas/docs/features/9000-sito-wordpress-percorribilita-e-avvisi-dei-sentieri-dai-dati-dello-shard/`.

## Cosa cambia

Regola del lavoro (Rubens, 18/11): ciò che legge dati nati in `wm-package` sta nel plugin, ciò che legge dati di
uno shard sta nel suo tema. Percorribilità (dominio Catasto di `wm-package`), avviso del dettaglio (`not_accessible*`
di `EcTrack` del package) e `pois.geojson` nascono in `wm-package`, quindi stanno qui.

Il plugin legge dal dettaglio della traccia la percorribilità e l'avviso, e li mostra nel suo template di base; il
tema ne fa la grafica sovrascrivendo il template, come oggi. Un registro degli stati delle famiglie, con dentro la
percorribilità, dà a ogni stato la sua posizione per gli indicatori a tacche; il tema ci aggiunge le sue famiglie.

La ricerca unica del plugin ha una sorgente nuova, «Punti di interesse», accanto a «Percorsi»: i POI dello shard
si trovano nel loro gruppo del tab «Tutti» e in un tab proprio, con la faccetta per `poi-type`. La fonte è
`api/v2/app/<app>/pois.geojson`, che ogni shard `wm-package` espone e che il plugin già scarica per l'indice dei
dettagli.

Il motore dei filtri (`search-logic.js`) impara a trattare un risultato senza il campo di un filtro come se avesse
un valore scelto dal tema: serve a contare come «Sconosciuta» le tracce senza percorribilità e come «Non
verificato» quelle senza validazione.

## Perché

Il registro delle sorgenti nasce con oc:8952 per le sorgenti dello shard; i POI erano rimasti fuori perché lo
shard non li indicizza (`ElasticsearchController` interroga solo le tracce). La scelta di oc:9000 è usare
`pois.geojson` (Rubens, 18/11). È generico: vale per qualunque shard, non solo per Forestas.

## Requisiti

### Allineamento di ciò che è già fatto (primo passo del lavoro)

La regola vale anche per il lavoro già fatto (Rubens, 18/11: «vanno fatti ora e vanno fatti come prima cosa del
piano»). Si fa prima di tutto il resto, e si chiude con il controllo che nulla si sia rotto.

- [x] Passano nel plugin le letture del dettaglio che oggi il child di Forestas fa su dati nati in `wm-package`, come
      funzioni che restituiscono dati già pronti (nessun markup):
      - Blocchi Dettaglio (`config_detail`, `ConfigDetailResolver`);
      - galleria e foto principale con didascalia, autore e credits (`image_gallery`, `feature_image`,
        `MediaResource`);
      - POI correlati (`related_pois`);
      - dati tecnici: lunghezza, dislivelli, quote, tempo (`distance`, `ascent`, `descent`, `ele_min`, `ele_max`,
        `duration_forward`);
      - luoghi (`taxonomies.where`, `taxonomy_where` con il livello amministrativo);
      - tipo del POI (`poi-type`).
- [x] Il plugin non dipende più da `route-type`, famiglia creata da forestas e non da `wm-package`: la famiglia che
      dà la base degli indirizzi e il tipo delle tracce (`kind` dell'indice, badge della card) (`includes/detail-index.php:332`,
      `assets/js/source-tracks.js:97`, `includes/detail-base.php`) la indica il tema con un filtro; senza, la base
      resta `track`. Il child di Forestas indica `route-type`.
- [x] Nessun cambiamento visibile: gli indirizzi dei dettagli restano identici (indirizzi stabili di oc:8983) e le
      pagine di dettaglio di tracce e POI escono uguali.
- [x] Controllo che nulla si sia rotto: prima dello spostamento si salvano l'HTML di un campione di dettagli
      (sentieri, cammini, itinerari e POI, in italiano e in inglese) e gli indirizzi dell'indice dei dettagli;
      dopo, si confrontano con quelli nuovi e ogni differenza va spiegata. Passano i test di plugin e child.

### Registro degli stati

- [x] Registro delle famiglie a stati, con un filtro per aggiungerne: per ogni famiglia identifier, stati in ordine
      (identifier, nome per lingua) e stato di assenza di informazione, se c'è. Dentro c'è già la percorribilità
      di `wm-package`: famiglia dal nome configurabile come `wm-package.features.trail_registry.walkability_taxonomy`
      (default `walkability-status`), stati e nomi come `WalkabilityTaxonomyInstaller::TERMS` (Sconosciuta, Non
      percorribile, Parzialmente percorribile, Percorribile), «Sconosciuta» come stato di assenza.
- [x] Una funzione dà, per un termine, livello e totale: il totale è il numero degli stati della famiglia, stato di
      assenza compreso; lo stato di assenza e il termine mancante valgono 0, gli altri stati la loro posizione
      (percorribilità: Non percorribile 2, Parzialmente 3, Percorribile 4 su 4; Rubens, 18/11).
- [x] Lo shard oggi non espone l'elenco degli stati: il registro lo scrive nel codice, e quando l'API ci sarà
      cambia solo la sua fonte.

### Percorribilità e avviso del dettaglio

- [x] Lettura della percorribilità dal dettaglio della traccia: termine di `walkability-status` con il nome nella
      lingua della pagina, livello e totale dal registro, nota (`properties.walkability.description`), data
      (`last_update`) solo se `properties.walkability.user` non è `null`: lo stato scritto dall'import ha la data
      dell'import, non quella di un controllo.
- [x] Lettura dell'avviso: `not_accessible` acceso letto come lo shard (`FILTER_VALIDATE_BOOLEAN`, come
      `EcTrack::isNotAccessibleOn()`), messaggio non
      vuoto nella lingua della pagina con ripiego sull'italiano (e la lingua da cui viene, per `lang`), data da
      `not_accessible_updated_at` quando c'è.
- [x] Il template di base del dettaglio (`templates/track.php`) mostra, in modo semplice, l'avviso quando c'è e la
      percorribilità quando non è di assenza.

### Ricerca dei POI

- [x] Sorgente `pois` nel registro (`wmwp_search_sources()`, filtro `wp_package_search_sources`): `label`,
      `nouns` al singolare e al plurale, `layout` a card, link al dettaglio con `wmwp_detail_link_template( 'poi' )`.
- [x] Una sola copia di `pois.geojson`: quella che l'indice dei dettagli già scarica (`wmwp_detail_fetch_items()`,
      `includes/detail-index.php:318`) tiene anche i campi della ricerca e della card. Un solo download, un solo
      calendario, lo stesso controllo che scarta un calo sotto la metà, e gli stessi id del dettaglio: su UAT, dopo
      il reset, ricerca e indirizzi dei dettagli cambiano insieme. Il file intero (5,88 MB e 976 POI sul dev,
      18/11) non arriva mai al browser.
- [x] La ricerca a testo libero usa `properties.searchable`, che lo shard calcola già ripulito e governa con «POI
      Search In» in Nova (oc:8677), nella lingua della pagina con ripiego sull'italiano. Prima i POI che hanno la
      parola nel nome, poi quelli che l'hanno solo nel testo.
- [x] Endpoint REST del plugin per cercare nella copia, con limiti sulla lunghezza del testo e sul numero di
      risultati per pagina: faccetta `poi-type` con i conteggi (un POI con più tipi conta in ognuno), pagine come le
      altre sorgenti. I nomi dei tipi nella lingua della pagina, con ripiego sull'italiano marcato `lang="it"`: sul
      dev 382 POI non hanno il nome del tipo in inglese.
- [x] Filtri dei luoghi dei POI (Rubens, 19/11): il tema aggiunge alla source faccette `list` su `taxonomyWheres` con
      le regole delle faccette dei percorsi (`include`, `include_pattern`, `exclude`, `exclude_pattern`), e la rotta
      le calcola sul server (`filter[<chiave>][]`). Ogni faccetta, tipi compresi, conta senza il proprio filtro e con
      gli altri. La copia tiene i luoghi (`wheres`); una copia senza luoghi risponde e si riscrive subito. Sul dev 974
      POI su 976 hanno i luoghi.
- [x] Filtri che si aprono e chiudono dal titolo (Rubens, 19/11), spenti di base e accesi dal tema con
      `wmwp_search_collapsible_facets`: pulsante nel `legend` con `aria-expanded` e `aria-controls`, aperto
      all'inizio solo il filtro con una voce scelta, focus che resta sul pulsante dopo il ridisegno. «Show more» in
      italiano diventa «Mostra di più», come nei filtri dei post del sito.
- [x] Card del POI con immagine, nome, inizio del testo e il tipo di contenuto, come il risultato di ricerca di
      Drupal, che non mostra la tipologia; link al dettaglio; il tema la può sostituire come quella dei percorsi.
- [x] [UX] Shard che non risponde e nessuna copia: il gruppo e il tab mostrano l'errore della sorgente, senza un
      falso «0 risultati», e le altre sorgenti funzionano (comportamento già di oc:8952).
- [x] Percorsi senza testo cercato: in ordine alfabetico sul nome italiano senza spazi ai lati, con lo stesso confronto
      dello shard (`name.keyword`): alcuni nomi dello shard cominciano con una tabulazione o uno spazio e finivano in
      testa all'elenco (Rubens, 18/11). Con i nomi puliti l'ordine è quello dell'API.
- [x] Source dei percorsi: `badge_exclude`, i valori del campo dell'etichetta che non la mostrano (per esempio lo
      stato «non lo sappiamo»).
- [x] `search-logic.js`: un filtro può dichiarare il valore da usare per i risultati senza il campo (per esempio
      `walkability-status-unknown`), valido per il filtro e per i conteggi.
- [x] Disattivazione e `uninstall.php` tolgono quello che il lavoro aggiunge nel database.
- [x] Testi nuovi in inglese con traduzione italiana nei file di `languages/` (`.po`, `.mo` e JSON per gli script).
- [x] Versione del plugin e `CHANGELOG.md` aggiornati.

## Rischi

- **Allineamento su pagine già verificate.** Lo spostamento tocca dettagli già verificati su dev con oc:8969 e
  oc:8983, e la base degli indirizzi: un errore cambierebbe un indirizzo stabile o una pagina. Mitigazione: il
  confronto prima/dopo di HTML e indirizzi, come primo passo e prima di tutto il resto.
- **Peso di `pois.geojson`.** Il file cresce con ogni proprietà che lo shard aggiunge (5,3 MB il 17/11, 5,88 MB il
  18/11): resta nel rinnovo in background dell'indice, con il suo timeout.
- **Ricerca meno tollerante di Elasticsearch.** Chi scrive una parola con un errore non trova il POI, mentre fra i
  percorsi Elasticsearch lo troverebbe. Accettato per ora (Rubens, 18/11).
- **Aggiornamento dopo una modifica in Nova.** Un POI cambiato arriva nella ricerca dopo la rigenerazione di
  `pois.geojson` nello shard e il rinnovo dell'indice dei dettagli: fino a un'ora e mezza, mentre il dettaglio si
  aggiorna in un minuto.
- **Plugin condiviso.** Ogni sito che monta `wp-package` vede comparire il tab dei POI: la sorgente si spegne dal
  filtro `wp_package_search_sources` per chi non la vuole.
- **Ordine di rilascio.** Il plugin va rilasciato con il child di Forestas che lo usa (`WP_PACKAGE_REF`): con il
  child nuovo e il plugin vecchio «Sconosciuta» non conta le tracce senza termine.

## Out of scope

- Indice dei POI nello shard.
- Filtri dei POI oltre il tipo e i luoghi (per esempio i percorsi vicini o gli enti, che `pois.geojson` non ha).
- Mappa dei POI nella ricerca.

## Moduli toccati

- `includes/detail-data.php` (nuovo): letture del dettaglio spostate dal child (Blocchi Dettaglio, galleria e foto,
  POI correlati, dati tecnici, luoghi, tipo del POI).
- `includes/detail-index.php`, `includes/detail-base.php`, `assets/js/source-tracks.js`: famiglia del tipo delle
  tracce indicata dal tema invece di `route-type` scritto nel codice.
- `includes/status.php` (nuovo): registro degli stati, livello e totale di un termine.
- `includes/detail-status.php` (nuovo): lettura di percorribilità e avviso dal dettaglio della traccia.
- `templates/track.php`: avviso e percorribilità nel template di base.
- `includes/search.php`: sorgente `pois` nel registro e registrazione dello script.
- `includes/detail-index.php`: la copia di `pois.geojson` tiene i campi della ricerca.
- `includes/search-pois.php` (nuovo): endpoint REST di ricerca nella copia, con le faccette dei luoghi.
- `assets/js/source-pois.js` (nuovo): sorgente JS che interroga l'endpoint, anche con i filtri dei luoghi.
- `assets/js/search-logic.js`: valore per i risultati senza il campo di un filtro.
- `assets/js/search.js`: filtri che si aprono e chiudono (`wmwp_search_collapsible_facets` in `includes/search.php`);
  attributo `lang` sui testi della card e sui valori delle faccette che ripiegano su un'altra
  lingua (`titleLang`, `metaLang`, `lang`).
- `includes/settings.php`: testo dell'impostazione della base degli indirizzi (il tipo delle tracce lo sceglie il
  tema). `templates/card.php` non è servito.
- `index.php` (Version, disattivazione), `uninstall.php`, `languages/`, `CHANGELOG.md`, `README.md` (interfaccia
  pubblica).
- `tests/php`, `tests/js`: test della copia, della ricerca e del valore per i risultati senza campo; filtro della
  famiglia del tipo nei test `detail-cache.php` e `detail-route.php`.
