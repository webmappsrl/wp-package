> Ticket: oc:8990

# Notes — Enti sul sito WordPress: copia dei dettagli quando lo shard non risponde

## Divergenze dal piano, task per task

### Task 2: ripiego sulla copia in `wmwp_detail_fetch()`

Approvate in review:

1. `wmwp_detail_url_by_id()` non passa `$fresh = true`. Lo avrebbe fatto chiamare lo shard a ogni click della ricerca,
   saltando la cache breve. Invece `wmwp_detail_served_stale()` ha gli argomenti opzionali `$type` e `$id`, e la
   funzione ignora il JSON uscito dalla copia: con lo shard giù il 302 all'indirizzo dell'indice resta quello di prima
   (senza questo, una copia di prima di un reset avrebbe trasformato il redirect in un 404). Per questo è stato
   toccato anche `includes/detail-route.php`.
2. Dopo un ripiego il segno del controllo vale `'stale'` (`WMWP_DETAIL_STALE_MARK`), non `1`. Con `1`, le visite dei
   60 s seguenti avrebbero letto la copia senza segnale: l'header `X-WMWP-Stale` sarebbe sparito per il resto del
   minuto. Il successo scrive sempre `1`.
3. Terza riga di log: `503, copia non usata: <motivo>`, quando la copia c'è ma non si usa (`$fresh = true` o filtro
   spento). «Nessuna copia» lì sarebbe stato falso.

## Bug trovati

- In review: un 404 dello shard seguito da un errore serviva la copia di un elemento cancellato, perché il 404 non la
  toglieva dalla cache. Corretto: il ramo del 404 cancella la copia e il segno del controllo (anche con
  `$fresh = true`). Il caso 5f del test lo verifica.

## Decisioni

- La pagina servita dalla copia manda anche `nocache_headers()`, come il 503, così una cache di pagina o una CDN non
  la tiene oltre il guasto.
- Con il 304 la copia si riscrive solo quando le resta meno di un'ora, per non scrivere nel database a ogni controllo:
  dopo l'ultimo controllo riuscito dura quindi almeno un'ora e al massimo 6.
- Il 404 dell'API vince sul ripiego; il 404 del file AWS no, è solo uno dei motivi nel log.
- Versione del plugin 0.3.0 → 0.4.0.
- Verificato il 16/11 dalla review interna, con una richiesta HTTP vera (in CLI `headers_list()` è vuoto, quindi la
  riga `header()` non ha un test): POI 35, `/punti-di-interesse/torre-di-mezza-spiaggia/`, fonte forzata in errore.
  Con la copia: 200, `X-WMWP-Stale: 1` e header no-cache; seconda visita nello stesso minuto stessa risposta e una
  sola riga di log. Senza copia: 503 con `Retry-After`.

## Follow-up

- Con una object cache esterna la scadenza della copia non si legge, e la copia si riscrive a ogni 304 (in memoria).
- Non da fare ora: con una object cache persistente (Redis) `get_option( '_transient_timeout_…' )` vale sempre false e a ogni 304 la copia si riscrive: se un sito che monta il plugin usa una object cache persistente, salvare la scadenza dentro la copia.
