> Ticket: oc:9000

# Notes — Sito WordPress: percorribilità e avvisi dei sentieri, dai dati dello shard — parte del plugin

Le note complete, con le decisioni del dialogo e le divergenze task per task, sono in
`wp-forestas/docs/features/9000-sito-wordpress-percorribilita-e-avvisi-dei-sentieri-dai-dati-dello-shard/notes.md`.
Qui quello che riguarda chi lavora sul plugin.

## Bug trovati

- **La sandbox dei test non rimetteva la copia dei POI.** `tests/php/history-sandbox.php` salva e rimette le opzioni
  dell'indice dei dettagli; con oc:9000 la ricostruzione dell'indice scrive anche `wmwp_poi_search_copy`, e
  `detail-cache.php` la lasciava con un POI finto (la ricerca reale trovava 1 risultato). Ora la sandbox la salva e la
  rimette.
- **Test in parallelo sullo stesso WordPress.** I test PHP del plugin e del child scrivono sullo stesso database:
  lanciati insieme da due processi hanno lasciato regole di rewrite con le basi finte di un test (`/en/…` e i POI in
  404). Si ripara cancellando `WMWP_DETAIL_RULES_SIGNATURE` e caricando una pagina. Da soli, nessun test lascia le
  regole sbagliate.

## Decisioni

- `route-type` non è più scritto nel plugin: la famiglia del tipo delle tracce la indica il tema con
  `wmwp_track_kind_taxonomy`. Un tema che usava `route-type` deve aggiungere il filtro, altrimenti la base degli
  indirizzi diventa `track` (CHANGELOG 0.5.0, «Cambiato»).
- `wmwp_detail_text()` è diventata un involucro di `wmwp_text()` (review interna, 18/11): un solo ripiego di lingua.
  Le differenze (liste unite, scalari) non toccano i nomi, che sono stringhe; gli indirizzi sono gli stessi, verificato
  con il confronto prima/dopo degli indirizzi dell'indice.
- Avviso acceso con `FILTER_VALIDATE_BOOLEAN`, come `EcTrack::isNotAccessibleOn()` dello shard (anche `"yes"` e
  `"on"`): prima il plugin accettava solo `true`, `1`, `"1"`, `"true"`, e il commento diceva il contrario del codice.
- Ricerca dei POI nella lingua della pagina: `searchable` dello shard è una stringa con tutte le lingue e i marcatori
  (`{it:…,en:…}`), quindi `q=it` trovava tutti i 976 POI. La copia tiene il testo per lingua, senza marcatori, con le
  entità HTML decodificate come fa lo shard; la ricerca usa la lingua della pagina con ripiego sull'italiano.
- Un'app senza POI ha una copia vuota (0 risultati), distinta dal download fallito (nessuna copia, 503).
- Un tipo scelto resta nella faccetta dei POI con conteggio 0 e il suo nome, come i valori scelti dei percorsi.
- Nomi della faccetta e tipo della card dei POI dalla source (`source-pois.js`), perché la rotta REST non conosce la
  lingua della pagina.

## Review interna, giro 1 (18/11)

Bloccanti della review di `wp-package` corretti: README (interfaccia pubblica, base degli indirizzi senza il
filtro, test), ricerca dei POI nella lingua della pagina, tipo scelto a 0 nella faccetta. Cleanup applicati: parametri
non scalari della rotta (niente più «Array to string conversion» nel log), `wp_next_scheduled()` invece di
`_get_cron_array()` nella rotta, titolo e lingua da una sola `wmwp_text()` con chiavi nominate, costante in
`index.php`, docblock di `uninstall.php`, `detail-base.php` e dei test, doppia chiamata in `search.php`, niente
traduzioni per `source-pois.js`, via `typeLang`, commenti superati di `search-logic.js` e `source-tracks.js`, tipo dei
POI correlati da `wmwp_detail_poi_types()`, `lang` sul nome della percorribilità nel template, `L.fieldValue`
esportata e usata da `source-tracks.js`, chiave della faccetta dei POI cambiata per nome, test più forti (pareggio
dell'ordine, «q» lungo con risultati, chiave per nome, precedenza dei tipi, filtro della famiglia in
`detail-cache`/`detail-route`), CHANGELOG, testo dell'impostazione, `badge_exclude` normalizzato.

Scartati, con il motivo:
- helper PHP per «lingua diversa da quella della pagina»: i tre punti stanno in PHP e in JS e fanno cose diverse
  (attributo, scelta del nome, valore per `lang`); un helper ne unirebbe due su tre;
- il test dei POI conta ancora gli eventi con `_get_cron_array()`: `wp_next_scheduled()` dice se un evento c'è, non
  quanti sono, e il test deve provare che più richieste ne programmano uno solo;
- la data del template di base del plugin resta in UTC (`gmdate( 'c' )`): su Forestas il template lo sostituisce il
  child, che usa la stessa data nel dettaglio e in `/avvisi`.

Giro 2: approvato senza bloccanti. Cleanup applicato: la rotta tratta come assente una copia nel formato di prima
(`searchable` stringa, scritta in locale durante il lavoro), così si ricostruisce da sola invece di dare 0 risultati
fino al rinnovo orario; copia locale ricostruita (976 POI; «cascata» 19 su `/it/`, «waterfall» 0 su `/it/` e 18 su
`/en/`, `q=it` 630).

Dalla review di `wp-forestas`, giro 2 (nessun bloccante), tre cleanup facoltativi applicati, ognuno con un test che
falliva prima:
- `wmwp_detail_text()` salta una lingua che è una lista, come prima di oc:9000: il nome fa lo slug e l'indirizzo
  stabile, e `wmwp_text()` la unirebbe con un a capo;
- testo cercabile dei POI: un campo finisce con la graffa seguita dal campo successivo o dalla fine, e dentro un campo
  separano le lingue solo `,<lingua>:` con le lingue del sito: graffe e «,da:» nel testo restano testo;
- senza copia, durante l'attesa fra due ricostruzioni (300 secondi) la rotta programma un solo evento, alla fine
  dell'attesa, invece di uno a ogni richiesta.
Verificato sul locale: 976 POI con le sole lingue `it` e `en`, stessi risultati di prima (nuraghe 66/65, cascata 19,
waterfall 0/18, `q=it` 630), indirizzi dell'indice identici a «prima».

## Follow-up

- Quando lo shard espone l'elenco degli stati di una famiglia, `wmwp_status_families()` lo legge da lì.
