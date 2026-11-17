> Ticket: oc:8990

# Enti sul sito WordPress: elenco /enti, dettaglio /enti/<slug> e ricerca, dall'API di oc:8965

Questa è la parte di oc:8990 che tocca il plugin. Le pagine degli enti stanno nel child theme di
`wp-forestas` (vedi la `overview.md` di oc:8990 in quel repo): gli enti sono un dato solo di Forestas e il
plugin non li conosce.

## Cosa cambia

Il dettaglio di una traccia o di un POI non risponde più 503 quando lo shard non risponde e il plugin ha
già una copia del JSON: serve la copia, la segnala con un header e scrive un avviso nel log. Il 503 resta
solo per un elemento di cui il plugin non ha una copia.

Oggi `wmwp_detail_fetch()` (`includes/detail-render.php`) tiene il JSON fino a 6 ore
(`WMWP_DETAIL_JSON_TTL`) e ogni 60 secondi (`wmwp_detail_cache_ttl`) lo ricontrolla con ETag o
`Last-Modified`. Se quel controllo fallisce, sia sul file pubblicato su AWS sia sull'API
`api/ec/<tipo>/<id>`, la funzione restituisce `wmwp_detail_unavailable` anche quando la copia è in cache
(`$cached`), e la pagina risponde 503. Con il 304 la copia non si riscrive (righe 61-66), quindi scade 6
ore dopo l'ultimo download, non dopo l'ultimo controllo.

## Perché

Per gli enti oc:8990 tiene l'ultima copia buona del JSON quando lo shard non risponde (deploy,
riavvio, token sbagliato), e il dev ha chiesto lo stesso comportamento per i casi simili del sito.
L'indice dei dettagli (`wmwp_detail_index()`) lo fa già: si ricostruisce ogni ora con WP-Cron e, se la
ricostruzione fallisce, usa l'ultima copia (`WMWP_DETAIL_INDEX_LAST`). Il singolo dettaglio invece no: un
riavvio di pochi secondi dello shard manda in 503 tutte le pagine di tracce e POI il cui controllo scade
in quel momento, anche se il plugin ha il loro JSON.

## Requisiti

- [x] Se il controllo del JSON fallisce (errore di rete, timeout, codice diverso da 200, 304 e 404,
  risposta che non è una feature) e c'è la copia in cache, `wmwp_detail_fetch()` restituisce la copia.
- [x] Il ripiego vale solo per la lettura normale: con `$fresh = true` (la rilettura di `detail-render.php`,
  che vuole il verdetto della fonte) il comportamento resta quello di oggi, così un ripiego non trasforma un
  503 in un 404. `wmwp_detail_url_by_id()` non passa `$fresh = true`: ignora invece il JSON uscito dalla copia
  (`wmwp_detail_served_stale( $type, $id )`), per non chiamare lo shard a ogni click della ricerca.
- [x] Con il 304 la scadenza della copia si allunga, riscrivendola solo quando le resta meno di un'ora:
  dopo l'ultimo controllo riuscito la copia dura almeno un'ora e al massimo 6, senza una scrittura nel
  database a ogni minuto.
- [x] Il filtro `wmwp_detail_stale_if_error` (predefinito `true`) spegne il ripiego senza un rilascio del
  plugin: il plugin è usato da più shard e la modifica cambia due regole scritte nel README («gli errori
  non vanno in cache», «503 solo se la fonte non risponde»), da aggiornare.
- [x] Una pagina servita dalla copia ha l'header `X-WMWP-Stale: 1`.
- [x] `wmwp_http_json()` restituisce anche il messaggio dell'errore di rete, con una chiave in più che non
  cambia le altre; il log registra sia la copia usata sia il 503 senza copia (tipo, id e motivo), una
  volta per controllo: il segno del controllo si riscrive per la durata della cache breve, così la
  visita successiva non riprova subito.
- [x] Il 404 della fonte resta un 404: l'elemento non c'è più, la copia non si usa. Su UAT, durante
  l'import dopo il reset notturno, un POI non ancora ricreato dà 404 come oggi: dopo il reset quell'id
  può essere un altro POI.
- [x] Senza copia in cache il comportamento resta quello di oggi (503).
- [x] La durata della cache breve (60 secondi) e quella della copia (6 ore) non cambiano.
- [x] Test del plugin: controllo riuscito, 304 con scadenza allungata, errore con copia (anche traccia con
  file AWS mancante e API in errore), errore senza copia, 404 con copia (resta 404), `$fresh = true` con
  copia, filtro spento, header, un solo avviso per controllo.

## Rischi

- **Un elemento con un errore solo suo** (l'API risponde 500 per quella traccia): la pagina mostra la
  copia fino a 6 ore. Mitigazione: header e log dicono quale elemento usa la copia.
- **Guasto che dura più di 6 ore, o pagina non visitata da più di 6 ore**: non c'è copia, quindi 503 come
  oggi. Accettato: dopo 6 ore il dato potrebbe non essere più vero.
- **Il ripiego copre solo le pagine visitate di recente**: un'API dei POI rotta da ore si vede come «alcuni
  POI vanno, altri no». Mitigazione: header `X-WMWP-Stale` e log del 503, che oggi manca.
- **Tornare indietro**: il filtro spegne il ripiego dal child; togliere il codice richiede invece un
  rilascio del plugin con il nuovo `WP_PACKAGE_REF` e `scripts/wordpress-up.sh` di `forestas`.

## Out of scope

- Rinnovo in background dei singoli dettagli: con centinaia di elementi il controllo ogni 60 secondi con
  ETag costa già poco.
- Gli enti: stanno nel child di `wp-forestas`.

## Moduli toccati

- `includes/detail-render.php`: `wmwp_detail_fetch()`.
- `includes/detail-route.php`: `wmwp_detail_url_by_id()` ignora il JSON uscito dalla copia.
- `docs/knowledge/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress.md`.
- `includes/http.php`: `wmwp_http_json()`, messaggio dell'errore di rete.
- `tests/php/detail-render.php`: test del ripiego.
- `tests/php/http-json.php`: test del messaggio dell'errore di rete.
- `CHANGELOG.md`, `index.php`: versione 0.4.0.
- `README.md`: le due regole sulla cache dei dettagli.
- Documentazione: questa cartella.
- In `wp-forestas`, a lavoro finito: il nuovo hash in `WP_PACKAGE_REF` di `docker/scripts/init-wordpress.sh`.
