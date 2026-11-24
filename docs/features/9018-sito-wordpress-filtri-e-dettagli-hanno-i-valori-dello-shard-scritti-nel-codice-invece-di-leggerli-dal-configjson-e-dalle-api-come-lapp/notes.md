> Ticket: oc:9018

# Note del plugin

Le decisioni del dialogo e della challenge, con le prove, sono nel `notes.md` del child
(`wp-forestas/docs/features/<stesso slug>/notes.md`). Qui solo ciò che è successo nell'implementazione del plugin.

## Divergenze dal piano, task per task

### Task 1: config.json condiviso

`tests/php/map-tiles.php` (parte 2) riscritto: si aspettava il download del `config.json` nella pagina, che il piano
toglie (download solo da WP-Cron).

### Task 3: registro degli stati

`wmwp_status_name()` resta vuoto per uno stato senza nome nel registro (il piano diceva «identifier»): l'identifier è
l'ultimo ripiego in `wmwp_walkability()`, dopo il nome del termine in qualsiasi lingua; altrimenti un nome solo
italiano su una pagina inglese diventerebbe l'identifier.

### Task 4: aree degli overlay

Un geojson senza la stringa `"layer_id"` non si decodifica: sul dev il picco di memoria del download scende da 162 a
136 MB (WordPress da solo ne usa 100). La rotta dei POI usa `ids`, `labels`, `lang_labels` e `order` delle faccette sul
campo `layers`, e la faccetta dei tipi è la prima che non è un luogo (non la prima in assoluto: senza `config.json` la
faccetta dei tipi dal config può mancare).

### Task 5: parametro layer

`stateToSearch()` riceve le chiavi delle faccette come terzo argomento (opzionale), con gli oggetti `{ key, param, ids }`
per le faccette con un parametro condiviso.

### Task 7: indirizzi vecchi

- `wmwp_detail_normalize_path()` non decodifica (il piano diceva `rawurldecode`): codifica i soli caratteri non ASCII
  come il browser e `sanitize_title()`, così i percorsi dell'indice, già codificati in minuscolo, restano uguali.
- La lingua del tema (`en`) si abbina al codice del sito per prefisso: in locale WPML ha `en-us`.
- Senza un callback su `wmwp_detail_legacy_paths` non si legge nessun dettaglio. Un giro legge al più 100 dettagli e
  60 secondi; un dettaglio che non si legge si salta per 6 ore (`wmwp_detail_legacy_failed`), altrimenti i falliti in
  testa consumavano ogni giro (in locale l'avanzamento si era fermato a 1633 elementi su 1756).
- Test in un file nuovo, `tests/php/detail-legacy.php`, che svuota anche la cache dei dettagli di prova.

### Aggiunte chieste dal piano del child

`meta_last_field` della source `tracks` (ultimo luogo all'inizio della riga), `lang` sui nomi dei termini che ripiegano
sull'italiano e sul titolo della faccetta (`label_lang`), test `search-pois.php` isolato dalle faccette del tema attivo.

### Aggiunta del 24/11 sera: indirizzo della scheda PDF

Con forestas `develop` 1123b46 (wm-package a588928) la stampa del wm-package ha l'opzione dell'App
(`OPTIONS.ec_pdf_enabled` del `config.json`) e il link non sta più nel JSON degli elementi: lo costruisce il sito
(`Print.md`, scrum del 24/11). Il dev ha chiesto di farlo in questo ticket. `wmwp_detail_print_url( $type, $id, $lang )`
in `includes/detail-render.php`, test `tests/php/detail-print.php` (visto fallire, poi PASS).

- Solo `ec_pdf_enabled === true`: la chiave deprecata `print_track_enable` non conta (Giuseppe: un solo flag, quello
  dell'app).
- `lang` solo se è fra `LANGUAGES.available`: con una lingua che l'app non ha la rotta risponde 404 (`Print.md`,
  «Flusso»); il codice di WPML con la variante (`en-us`) vale `en`.
- Origin del browser (`wmwp_configured_api_urls()['origin']`), non quello del server: il link lo apre il visitatore.
- I `track.php` e `poi.php` di base del plugin restano senza pulsante: non hanno una riga di azioni, e un pulsante
  nuovo cambierebbe la pagina degli altri siti senza che lo chiedano. Il pulsante lo mette il tema.
- Provato sul dev (24/11): `ec_pdf_enabled` è `true` nel `config.json`, `print/ec-track/263/status` e
  `print/ec-poi/177/status` rispondono 200.

## Bug trovati

- `tests/php/detail-render.php` falliva già prima del ticket, con il child attivo: si aspetta nel log «file AWS: HTTP
  404» e il codice scrive i due file provati (`v1/<id>.json`, `tracks/<id>.json`), perché il child chiede i file `v1`.

## Review interna del 24/11 sera (sessione di review di wp-package)

Un bloccante e 18 cleanup sul working tree. Test dopo le correzioni: PHP 25 su 26 (fallisce solo `detail-render`, come
prima del ticket), JS 95 su 95.

- **B1, opzione scelta a zero con l'identifier** (`computeFacets()`, `search-logic.js`): scelta «Facile» e poi un'area
  dove nessun percorso è facile, l'opzione restava con `hiking-difficulty-easy`; lo stesso per la percorribilità, che
  dalla 0.6.0 non ha più nomi nei gruppi. Ora nome e lingua vengono da un percorso qualsiasi che ha il valore, poi dal
  tema, poi l'identifier. Test in `tests/js/search-logic.test.js` (visto fallire, poi passa).
- **C1:** senza una copia buona del `config.json` il giro singolo si ripianifica non prima di 5 minuti dall'ultimo
  fallimento (`wmwp_app_config_failed_at`), invece che a ogni richiesta. Test in `app-config.php`, scritto dopo il
  codice.
- **C2:** un percorso letto senza errori ma senza indirizzo vecchio si riprova al più 3 volte, poi è passato (un
  percorso creato in Nova non ha indirizzo Drupal); un errore di lettura, o la copia servita con la fonte giù, non
  conta. Le chiavi di elementi che non sono più nell'indice si tolgono da `wmwp_detail_legacy_done` e `_failed`. Il
  valore in `_failed` diventa `{ at, empty }` (l'intero di prima vale ancora come ora).
- **C3:** una copia dei POI della 0.5.0, senza `point`, fa ripartire subito la ricostruzione, come una senza `wheres`.
- **C4:** la disattivazione toglie anche il cron del `config.json` e le aree dei POI; la disinstallazione cancella
  anche le option nuove della 0.6.0.
- **C5:** la copia degli overlay tiene solo le aree dei layer di `MAP.layers` (se `MAP.layers` cambia i file si
  riscaricano per intero), e i filtri leggono un'option piccola con gli id (`wmwp_app_overlay_layers_v1`), non le
  geometrie: prima ogni pagina della ricerca deserializzava i MB delle geometrie.
- **C6, lasciato:** la lingua di ripiego resta l'italiano, non `LANGUAGES.default` del `config.json`. È la regola di
  `wmwp_text()` dalla 0.5.0 (oc:9000) per tutti i testi dei dettagli; cambiarla tocca tutto il plugin, non solo i
  filtri. Su Forestas la lingua di default dell'app è l'italiano, quindi oggi non cambia nulla; da rivedere se il
  plugin va su uno shard con un'altra lingua di default.
- **C7:** ordine e identifier degli stati della percorribilità restano in `includes/status.php` (il Task 3 li teneva:
  nessuna API pubblica l'elenco degli stati di una famiglia, ticket sullo shard nel child); docblock corretto.
- **C8:** gli elementi passati stanno in `wmwp_detail_legacy_done`, non nello storico come diceva il piano: lo storico
  non distingue un percorso senza righe vecchie da uno non ancora letto. Nel CHANGELOG.
- **C9:** il parametro delle aree è fisso, `layer` (`WMWP_APP_AREA_PARAM`): lo legge anche la rotta dei POI, e un
  `param` scelto dal tema avrebbe rotto i filtri dei POI.
- **C10:** una regola sola per «faccetta di luogo», `wmwp_poi_search_is_place()`, uguale a `isPlace()` di
  `source-pois.js`.
- **C11:** un ordinamento solo delle voci delle faccette nella rotta dei POI.
- **C12:** tolta la costante della cache di prima; `wmwp_app_config_url()` sta in `app-config.php`.
- **C13:** tolta l'azione `wmwp_app_config_updated`, che nessuno ascoltava (overlay e aree dei POI girano sul cron, a
  priorità 20).
- **C14:** un nome del tema per una faccetta `from_config` porta via anche la lingua del nome del config.json. Test con
  una pagina tedesca (tutti i nomi delle fixture hanno l'inglese), visto fallire senza la correzione.
- **C15:** `langFor()` anche per i nomi dei termini.
- **C16, C17:** commento dei timeout di nuovo sulle sue costanti; la funzione che pianifica la ricostruzione dell'indice
  si chiama `wmwp_detail_schedule_rebuild()` e sta in `detail-index.php`.
- **C18:** README con `meta_last_field` (vale se non c'è `meta_facet`) e il parametro fisso delle aree.
- **Test più robusti** (trovato durante le correzioni): un test interrotto da un errore lasciava le option vere con i
  dati di prova (in locale la fixture di UAT al posto del `config.json` del dev, e con `detail-legacy.php` l'indice dei
  dettagli finto), perché il gestore degli errori fatali di WordPress chiude il processo prima delle funzioni di
  shutdown. `history-sandbox.php`, `app-overlays.php` e `app-filters.php` ripristinano anche dal gestore delle
  eccezioni. Lo stato locale è stato rimesso a posto (indice ricostruito dal dev, 1756 elementi).

### Secondo giro: nessun bloccante, quattro cleanup ipotetici

- **Fatto:** `layer` (`WMWP_APP_AREA_PARAM`) fra le chiavi occupate di `wmwp_safe_facet_keys()`: una faccetta del tema
  con chiave `layer` prende `f_layer`, come una query var. Test in `search-params.php`, visto fallire.
- **Lasciato, titolo della faccetta dei tipi nella rotta dei POI:** è il `label` del tema invece di `Type`. I nomi del
  tema sono nella lingua della pagina, come per tutte le altre faccette (il child li dà con `forestas_testo()`);
  Forestas usa `from_config`, con il nome del `config.json` per lingua.
- **Lasciato, ricostruzione su un 404:** parte solo sotto una base dell'indice. Il primo elemento di un tipo che
  nell'indice non ha elementi, sotto una base che c'è solo nello storico, resta 404 fino al giro orario (o a
  `wmwp_detail_index( true )` da WP-CLI). È il prezzo della scelta del Task 7: senza, ogni bot sui vecchi link di Drupal
  (`/node/…`, `/sentieri/…`) farebbe ricostruire l'indice ogni 5 minuti, e lo storico non distingue una base di Drupal
  da quella di un tipo rimasto senza elementi.
- **Lasciato, due overlay con lo stesso `layer_id`:** scegliere l'area in uno la sceglie anche nell'altro. È lo stesso
  layer, e il parametro unico `layer` è quello delle app; su Forestas gli overlay non condividono aree.
- **Trovato rilanciando la suite:** `map-tiles.php` lasciava l'ora del fallimento del `config.json` (C1) nelle option
  vere; ora la rimette com'era.

