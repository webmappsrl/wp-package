> Ticket: oc:8990

# Ripiego sulla copia nei dettagli di tracce e POI — piano di implementazione

> **Per chi esegue:** sub-skill richiesta: superpowers:subagent-driven-development (consigliata) o
> superpowers:executing-plans, task per task. I passi usano le caselle (`- [ ]`). **Nessun commit**: i comandi
> `git commit` sono istruzioni per il dev, che li esegue dopo la review.

**Obiettivo:** il dettaglio di una traccia o di un POI serve la copia del JSON quando lo shard non risponde,
invece del 503.

**Architettura:** tutto dentro `wmwp_detail_fetch()` (`includes/detail-render.php`): quando il controllo della
fonte fallisce e la copia c'è, si restituisce la copia, si segna il controllo come fatto per la cache breve, si
scrive un avviso nel log e si accende un segnale che la pagina trasforma in header. `wmwp_http_json()` aggiunge
il messaggio dell'errore di rete.

**Stack:** WordPress, PHP 8.3, test `wp eval-file` con lo shard finto (`pre_http_request`).

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Identificatori in inglese; commenti, docblock e documentazione in italiano.
- La durata della cache breve (60 s, `wmwp_detail_cache_ttl`) e della copia (`WMWP_DETAIL_JSON_TTL`, 6 h) non
  cambiano.
- Il 404 della fonte resta 404; con `$fresh = true` il comportamento è quello di oggi.
- In locale il plugin è un link simbolico al clone montato in sola lettura: i test si lanciano dal container
  (`docker exec wordpress-forestas wp --allow-root eval-file wp-content/plugins/wp-package/tests/php/<file>.php`),
  e si modifica il clone sull'host.

## Punti da guardare in review

1. Traccia con il file AWS che dà 404 e l'API in errore: deve servire la copia, non il 404 (il 404 del file
   non vuol dire «non c'è», `detail-render.php:17-19`).
2. Più visite nello stesso minuto con lo shard giù: un solo avviso nel log, una sola richiesta allo shard.
3. 304 con la copia vicina alla scadenza: la scadenza si allunga, con la copia lontana dalla scadenza nessuna
   scrittura.
4. `$fresh = true` con la copia e lo shard giù: `WP_Error`, come oggi (altrimenti `wmwp_detail_load_again()`
   trasformerebbe un 503 in un 404).
5. Filtro `wmwp_detail_stale_if_error` a `false`: comportamento identico a oggi, header compreso.

---

### Task 1: messaggio dell'errore di rete in `wmwp_http_json()`

**File:**
- Modifica: `includes/http.php:20-45`
- Test: `tests/php/http-json.php` (nuovo)

**Interfacce:**
- Produce: `wmwp_http_json( string $url, int $timeout, array $headers = [] ): array` con la chiave in più
  `'error' => string` (messaggio del `WP_Error`, `''` se la richiesta ha avuto risposta). Le chiavi `code`,
  `data`, `response` non cambiano.

- [x] **Passo 1: test che fallisce** — `tests/php/http-json.php`, shard finto con `pre_http_request`:
  - `WP_Error( 'http_request_failed', 'cURL error 28: timeout' )` → `code === 0`, `error === 'cURL error 28: timeout'`;
  - risposta 200 con `{"a":1}` → `error === ''`, `data === ['a' => 1]`;
  - risposta 500 → `code === 500`, `error === ''`.
- [x] **Passo 2:** `docker exec wordpress-forestas wp --allow-root eval-file wp-content/plugins/wp-package/tests/php/http-json.php` → `FAIL` (manca `error`).
- [x] **Passo 3:** aggiungere `'error'` nei due rami di ritorno di `wmwp_http_json()`.
- [x] **Passo 4:** stesso comando → `PASS`; rilanciare anche `tests/php/shards-fallback.php` e
  `tests/php/detail-render.php` → `PASS`.
- [x] **Passo 5 (per il dev, 17/11, unito al commit del task 2):** `git commit -m "feat(oc:8990): messaggio dell'errore di rete in wmwp_http_json"`

### Task 2: ripiego sulla copia in `wmwp_detail_fetch()`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-ripiego-sulla-copia-in-wmwp_detail_fetch)

**File:**
- Modifica: `includes/detail-render.php:23-70` (`wmwp_detail_fetch()`), `includes/detail-render.php:90-130`
  (`wmwp_detail_fetch_file()`: restituire il motivo del fallimento)
- Modifica: `includes/detail-route.php` (`wmwp_detail_url_by_id()`, vedi notes.md)
- Modifica: `includes/detail-render.php:335-345`, dove la pagina del dettaglio manda gli header (oggi il 503 con
  `Retry-After`)
- Test: `tests/php/detail-render.php` (casi nuovi)

**Interfacce:**
- Consuma: `wmwp_http_json()` con `error` (Task 1).
- Produce:
  - filtro `wmwp_detail_stale_if_error` (`bool`, predefinito `true`, argomenti `$type`, `$id`);
  - `wmwp_detail_served_stale( string $type = '', int $id = 0 ): bool` — vero se in questa richiesta almeno un dettaglio (o quello indicato) è uscito dalla copia;
  - header `X-WMWP-Stale: 1` sulla pagina del dettaglio quando `wmwp_detail_served_stale()` è vero.

- [x] **Passo 1: test che falliscono** in `tests/php/detail-render.php`, con lo shard finto:
  - `stale_on_error`: copia in transient, segno scaduto, API 500 → `wmwp_detail_fetch()` restituisce la feature
    della copia e `wmwp_detail_served_stale() === true`;
  - `stale_on_error_track_aws_404`: traccia, file AWS 404 e API 500 → la copia;
  - `no_copy_still_503`: nessuna copia, API 500 → `WP_Error` `wmwp_detail_unavailable`;
  - `not_found_with_copy`: copia presente, API 404 → `WP_Error` `wmwp_detail_not_found`;
  - `fresh_ignores_copy`: copia presente, API 500, `$fresh = true` → `WP_Error` `wmwp_detail_unavailable`;
  - `filter_off`: `add_filter( 'wmwp_detail_stale_if_error', '__return_false' )`, copia, API 500 → `WP_Error`;
  - `one_check_per_ttl`: due chiamate di fila con API 500 → una sola richiesta allo shard (contatore nel finto)
    e una sola riga di log (catturata con un `error_log` reindirizzato su file temporaneo, `ini_set( 'error_log', … )`);
  - `not_modified_extends_copy`: copia con scadenza fra 30 minuti (`_transient_timeout_wmwp_detail_json_*`),
    risposta 304 → la scadenza torna a circa 6 h; copia con scadenza fra 5 h, 304 → scadenza invariata.
- [x] **Passo 2:** `docker exec wordpress-forestas wp --allow-root eval-file wp-content/plugins/wp-package/tests/php/detail-render.php` → `FAIL` sui casi nuovi.
- [x] **Passo 3: implementazione** in `wmwp_detail_fetch()`:
  - quando `$entry` è null (oggi riga 58) e `! $fresh` e `null !== $cached` e il filtro è vero: `set_transient( $checked, 'stale', $ttl )` (non 1, vedi notes.md),
    avviso nel log (`[wp-package] dettaglio <tipo> <id> dalla copia, fonte non raggiungibile: <motivo>`), segnale
    acceso, `return $cached['feature']`;
  - senza copia: avviso nel log (`… 503, nessuna copia: <motivo>`) e il `WP_Error` di oggi;
  - con `not_modified`: se `get_option( '_transient_timeout_' . $key )` è a meno di `HOUR_IN_SECONDS` da ora,
    `set_transient( $key, $cached, WMWP_DETAIL_JSON_TTL )`;
  - il motivo viene da `error` di `wmwp_http_json()` o dal codice HTTP; `wmwp_detail_fetch_file()` restituisce il
    suo motivo in un parametro per riferimento, senza cambiare il tipo di ritorno.
- [x] **Passo 4:** header `X-WMWP-Stale: 1` dove la pagina del dettaglio manda i suoi header, solo se
  `wmwp_detail_served_stale()`.
- [x] **Passo 5:** stesso comando del passo 2 → `PASS`; rilanciare tutti i `tests/php/*.php` → `PASS`.
- [x] **Passo 6 (per il dev, 17/11):** `git commit -m "feat(oc:8990): copia del dettaglio quando lo shard non risponde"`

### Task 3: documentazione del plugin

**File:**
- Modifica: `README.md:288` e `README.md:335` (le due regole sulla cache dei dettagli), più la descrizione del
  filtro `wmwp_detail_stale_if_error` e dell'header `X-WMWP-Stale` fra i filtri documentati.
- Modifica: `CHANGELOG.md` e la versione del plugin, da 0.3.0 a 0.4.0 (`index.php:6` e `WMWP_VERSION` a
  `index.php:20`), come in oc:8983.
- Crea: `docs/features/<slug>/notes.md`.

- [x] **Passo 1:** riscrivere le due righe del README: gli errori non vanno in cache, ma una copia già in cache
  si serve quando la fonte non risponde (fino a 6 ore dall'ultimo controllo riuscito); 503 solo senza copia.
- [x] **Passo 2 (per il dev, 17/11):** `git commit -m "docs(oc:8990): copia dei dettagli quando lo shard non risponde"`

### Task 4: aggiornare il plugin in `wp-forestas`

Si fa dopo il merge di questo branch su `develop` e poi su `main` del plugin (regola del team), nel branch del
ticket di `wp-forestas`: è il task 9 di `wp-forestas/docs/features/<slug>/plan.md`.
