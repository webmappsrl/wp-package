> Ticket: oc:8983

# Indirizzi stabili delle pagine di dettaglio — piano di implementazione

> **Per chi esegue:** sub-skill richiesta: superpowers:subagent-driven-development (consigliata) o
> superpowers:executing-plans, task per task. I passi usano le caselle (`- [x]`) per il tracciamento.
> **Nessun `git commit`, `git add` o `git push` in autonomia:** i passi «Commit» sono istruzioni per il dev, che li
> approva uno per uno.

**Obiettivo:** indirizzi `/<base>/<slug>/` dei dettagli stabili nel tempo: chi ha un percorso lo tiene, i doppioni
prendono `-2`, `-3`…, e ogni percorso mai assegnato porta per sempre (301) all'indirizzo attuale del suo elemento.

**Architettura:** uno storico dei percorsi in una tabella propria (`{prefix}wmwp_detail_paths`, una riga per lingua e
percorso) che `wmwp_detail_build_index()` riceve come dato e che `wmwp_detail_index()` aggiorna solo con un indice
accettato. L'assegnazione resta una funzione senza database (testabile con array), il database sta in
`includes/detail-history.php`. La risoluzione consulta lo storico solo quando l'indice nella lingua chiesta non ha il
percorso.

**Tecnologie:** plugin WordPress (PHP 7.4+, WordPress 6.3+), `$wpdb` e `dbDelta()`, WP-CLI, test con `wp eval-file`.

**Spec:** [overview.md](overview.md) (e, per la parte del sito, `wp-forestas/docs/features/8983-…/overview.md`).

## Vincoli globali

- `Requires at least: 6.3`, `Requires PHP: 7.4` (`index.php`): niente sintassi oltre PHP 7.4 (no `match`, no enum,
  no proprietà promosse nel costruttore).
- Identificatori in inglese; commenti, docblock, documentazione e messaggi di commit in italiano.
- `wmwp_detail_source()` e il filtro `wmwp_detail_source` non cambiano (interfaccia pubblica, README).
- Versione del plugin: **0.3.0** (`Version:` nell'intestazione di `index.php` e `WMWP_VERSION`).
- Nessun commit né push automatico. Convenzione: `feat(oc:8983): …`, `fix(oc:8983): …`, `docs(oc:8983): …`,
  `test(oc:8983): …`.
- Test PHP: `docker exec -w /var/www/html wordpress-forestas wp eval-file wp-content/plugins/wp-package/tests/php/<file>.php --allow-root`
  (in locale il plugin è un link al clone di sviluppo; il daemon Docker va acceso e `wp-forestas` avviato).
- I test non toccano la tabella vera dello storico: ogni `tests/php/detail-*.php` include `history-sandbox.php`
  (Task 3) come prima riga dopo il controllo di `ABSPATH`.

## Punti da guardare in review

> ⚠️ Il punto 5 è cambiato durante l'implementazione: [notes.md](notes.md#task-3-tabella-dello-storico)

Casi che la spec implica ma che nessun requisito nomina; ciascuno ha il suo test nel task indicato.

1. **Nome senza lettere** (solo punteggiatura): lo slug è l'id (`wmwp_detail_slug()`), quindi su dev/UAT cambia a ogni
   reset. Atteso: il percorso nuovo si assegna, quello vecchio porta con un 301 al nuovo finché l'elemento esiste
   (Task 2, Task 4).
2. **Percorso più lungo di 191 caratteri** (nomi lunghi): la riga si scrive e si ritrova per impronta (Task 3).
3. **Stesso id per una traccia e un POI** senza chiave della fonte (`i:5` per tutti e due): uno non può tenere il
   percorso dell'altro (Task 2).
4. **Stesso percorso in due lingue** (base e slug uguali in `it` e `en`): due righe distinte, una per lingua
   (Task 3).
5. **Tabella che non risponde a metà richiesta** (tabella cancellata, permessi): nessun errore fatale, il plugin
   continua senza storico (Task 3).

---

### Task 1: Chiave dell'elemento e ordine fra omonimi

**File:**
- Crea: `includes/detail-history.php` (solo le due funzioni pure di questo task; il resto arriva nel Task 3)
- Modifica: `index.php` (`require_once` di `includes/detail-history.php` prima di `detail-index.php`)
- Test: `tests/php/detail-index.php`

**Interfacce:**
- Produce: `wmwp_detail_item_key( ?string $source, int $id ): string` — `'s:' . $source` se `$source` non è vuoto,
  altrimenti `'i:' . $id`. È la «chiave» dell'overview: tutte le parti che devono riconoscere un elemento passano da
  qui.
- Produce: `wmwp_detail_key_compare( string $a, string $b ): int` — ordine totale: chiavi `s:` numeriche
  (`ctype_digit`) in ordine numerico crescente, poi chiavi `s:` non numeriche con `strcmp`, poi chiavi `i:` in ordine
  numerico crescente.

- [x] **Passo 1: test che falliscono** in `tests/php/detail-index.php`, nuovo punto «Chiave e ordine»:

```php
$check( 's:4076' === wmwp_detail_item_key( '4076', 12 ), 'chiave della fonte' );
$check( 'i:12' === wmwp_detail_item_key( null, 12 ) && 'i:12' === wmwp_detail_item_key( '', 12 ), 'senza fonte: id' );
$keys = [ 'i:3', 's:N 12/B', 's:4076', 's:99', 'i:1' ];
usort( $keys, 'wmwp_detail_key_compare' );
$check( [ 's:99', 's:4076', 's:N 12/B', 'i:1', 'i:3' ] === $keys, 'ordine: nid numerici, chiavi testuali, id' );
```

- [x] **Passo 2:** lanciare `detail-index.php` → FAIL (`wmwp_detail_item_key` non esiste).
- [x] **Passo 3:** scrivere le due funzioni in `includes/detail-history.php` (intestazione del file con il perché,
  come gli altri `includes/detail-*.php`) e il `require_once` in `index.php`.
- [x] **Passo 4:** `detail-index.php` → i controlli nuovi passano (i vecchi sui suffissi cambiano nel Task 2).

---

### Task 2: Assegnazione dei percorsi con lo storico

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-assegnazione-dei-percorsi)

**File:**
- Modifica: `includes/detail-index.php` — `wmwp_detail_build_index()` (righe 97-160) e l'intestazione del file
- Test: `tests/php/detail-index.php` (punti 3 e «rinumerazione» riscritti, punti nuovi)

**Interfacce:**
- Consuma: `wmwp_detail_item_key()`, `wmwp_detail_key_compare()` (Task 1).
- Produce: `wmwp_detail_build_index( array $items, callable $base, ?array $langs = null, array $history = [], ?array &$assignments = null ): array`
  — stesso indice di oggi (`langs`, `paths`, `urls`, `items`, `counts`, `count`), stesso formato: l'indice 0.2.0 in
  cache resta valido.
  - `$history`: `[ lang => [ path => [ 'type' => string, 'key' => string, 'base' => string, 'root' => string, 'suffix' => int, 'current' => bool ] ] ]`
    (lo produce `wmwp_detail_history_load()`, Task 3).
  - `$assignments` (uscita): `list<array{lang: string, path: string, type: string, id: int, key: string, base: string, root: string, suffix: int}>`,
    un elemento per percorso assegnato; `suffix` 0 = senza suffisso. Serve al Task 4 per aggiornare lo storico.

Algoritmo, per ogni lingua (è la parte che firma e test non determinano da soli):

```text
per ogni elemento: base b = $base(type, kind, lang), root r = wmwp_detail_slug(nome nella lingua, id),
                   chiave k = wmwp_detail_item_key(source, id)
presenti = insieme di "type|k" degli elementi
ordina gli elementi con wmwp_detail_key_compare sulla chiave, poi type, poi id (ordine totale)
altrui(path, elemento) = la riga di storico di path esiste, il suo "type|key" è in presenti ed è diverso da quello
                         dell'elemento
1) storico:   per ogni elemento, la riga con current = true, stesso type e key, base = b e root = r → se il suo
              percorso non è già assegnato, assegnalo (suffix della riga)
2) senza suff.: per ogni elemento non assegnato, p = b/r: se p non è assegnato e non è altrui → assegna (suffix 0)
3) suffissi:  per ogni elemento non assegnato, n = 2, 3, …: p = b/r-n, il primo non assegnato e non altrui → assegna
```

- [x] **Passo 1: test che falliscono** in `tests/php/detail-index.php` (base finta di oggi, `[ 'it' ]`, storico come
  array). Nomi dei controlli e attese:
  - «senza storico: il primo per chiave senza suffisso»: id 1 e 2 «Monte Corrasi» senza fonte →
    `sentiero/monte-corrasi` all'1, `sentiero/monte-corrasi-2` al 2; con gli elementi in ordine inverso, stesso
    risultato.
  - «ordine per chiave», il vecchio test `$monte`: fonti `'4076'`, `'N 12/B'` e nessuna → `sentiero/monte`,
    `sentiero/monte-2`, `sentiero/monte-3`; dopo la rinumerazione (id 211-213) gli stessi percorsi.
  - «omonimo nuovo non sposta chi c'era»: storico con `sentiero/monte-x` attuale di `s:500`; elementi `s:500` e
    `s:100` (nid più basso, nuovo) → `s:500` tiene `sentiero/monte-x`, `s:100` prende `sentiero/monte-x-2`.
  - «il percorso resta di chi lo aveva (regola A)»: storico `sentiero/monte-x` di `s:100` (non più attuale) e
    `sentiero/monte-x-2` attuale di `s:200`; `s:100` ora si chiama «Monte Y» → `s:100` a `sentiero/monte-y`, `s:200`
    resta a `sentiero/monte-x-2`, nessuno prende `sentiero/monte-x`.
  - «percorso libero quando l'elemento non c'è più»: come sopra ma senza `s:100` fra gli elementi, più un nuovo
    `s:300` «Monte X» → `s:300` prende `sentiero/monte-x`, `s:200` resta a `-2`.
  - «rinomina che cambia lo slug di partenza»: storico `sentiero/cammino-x-tappa-2` attuale di `s:7` con root
    `cammino-x-tappa-2` e suffix 0; `s:7` ora «Cammino X Tappa», nessun altro → `sentiero/cammino-x-tappa`.
  - «stesso id fra traccia e POI» (punto 3 della review): traccia `i:5` e POI `i:5`
    con la stessa base `'track'` finta e lo stesso nome; storico `track/nuraghe` attuale della traccia → il POI
    prende `track/nuraghe-2`.
  - «nome senza lettere» (punto 1 della review): id 77 nome `'— !!'` → `sentiero/77`; storico con `sentiero/41` attuale di `s:9` root `41`,
    ora con id 77 → `sentiero/77` (root cambiato, percorso nuovo).
  - `$assignments` contiene `[ 'lang' => 'it', 'path' => 'sentiero/monte-x-2', 'type' => 'track', 'id' => …, 'key' => 's:100', 'base' => 'sentiero', 'root' => 'monte-x', 'suffix' => 2 ]`.
  - Le attese di oggi sui suffissi `-1`, `-2` per id e `-4076` / `-n-12-b` si tolgono.
- [x] **Passo 2:** `detail-index.php` → FAIL sui controlli nuovi.
- [x] **Passo 3:** riscrivere la parte per lingua di `wmwp_detail_build_index()` con le tre passate; aggiornare il
  docblock della funzione e l'intestazione del file (non più `-<fonte>` / `-<id>`).
- [x] **Passo 4:** `detail-index.php` → PASS; `detail-base.php` → PASS.
- [x] **Passo 5: Commit** (istruzione per il dev):

```bash
git add index.php includes/detail-history.php includes/detail-index.php tests/php/detail-index.php
git commit -m "feat(oc:8983): doppioni dello slug con -2 in ordine di chiave e percorsi tenuti dallo storico"
```

---

### Task 3: Tabella dello storico

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-tabella-dello-storico)

**File:**
- Modifica: `includes/detail-history.php`
- Crea: `uninstall.php`, `tests/php/history-sandbox.php`, `tests/php/detail-history.php`
- Modifica: `tests/php/detail-cache.php`, `detail-route.php`, `detail-render.php`, `detail-seo.php`,
  `detail-sitemap.php`, `detail-index.php`, `detail-base.php` (include della sandbox)

**Interfacce:**
- Produce (tutte in `includes/detail-history.php`; costanti `WMWP_DETAIL_PATHS_DB_VERSION = 1`, option
  `wmwp_detail_paths_db_version`, `wmwp_detail_paths_seed_pending`, `wmwp_detail_paths_failed_at`, tutte con
  autoload `false`):
  - `wmwp_detail_paths_table(): string` — `$wpdb->prefix . 'wmwp_detail_paths'`, passato nel filtro interno
    `wmwp_detail_paths_table` (solo per i test, non nell'interfaccia pubblica del README).
  - `wmwp_detail_history_create_table( string $table ): bool` — `dbDelta()` dello schema qui sotto, poi controllo che
    la tabella esista.
  - `wmwp_detail_history_install(): void` — su `init` priorità 0: se la versione salvata è diversa da
    `WMWP_DETAIL_PATHS_DB_VERSION` e l'ultimo fallimento ha più di un'ora, crea o aggiorna; se la tabella prima non
    c'era imposta `wmwp_detail_paths_seed_pending`; se fallisce scrive `failed_at` e una riga di `error_log`.
  - `wmwp_detail_history_available(): bool` — versione salvata uguale a quella corrente.
  - `wmwp_detail_history_load(): array` — tutto lo storico nella forma del Task 2; `[]` se non disponibile o in errore.
  - `wmwp_detail_history_lookup( string $lang, string $path ): ?array` — la riga (`type`, `key`, `base`, `root`,
    `suffix`, `current`) per impronta; `null` se non c'è o se la tabella non risponde.
  - `wmwp_detail_history_changes( array $history, array $assignments ): array` (pura) — le sole righe da scrivere:
    ogni assegnazione nuova o diversa con `current = true`; ogni riga oggi `current` che non è più assegnata a quel
    percorso con `current = false` (stesso proprietario). Nessuna modifica → `[]`.
  - `wmwp_detail_history_write( array $rows ): void` — `INSERT … ON DUPLICATE KEY UPDATE` sull'impronta, a blocchi di
    200 righe.
  - `wmwp_detail_history_forget_ids(): int` — cancella le righe con chiave `i:%`, restituisce quante.
  - `wmwp_detail_history_bases(): array` — basi distinte dello storico.
  - Impronta: `md5( $lang . '|' . $path )`.
  - Righe scambiate fra le funzioni: `array{lang: string, path: string, type: string, key: string, base: string, root: string, suffix: int, current: bool}`;
    `key` è quella di `wmwp_detail_item_key()` (`s:…` / `i:…`) e va così com'è in `item_key`.

Schema (per `dbDelta()`, con i suoi vincoli di formato: due spazi dopo `PRIMARY KEY`, un campo per riga):

```sql
CREATE TABLE {table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(32) NOT NULL,
  lang varchar(20) NOT NULL,
  path varchar(1000) NOT NULL,
  item_type varchar(10) NOT NULL,
  item_key varchar(191) NOT NULL,
  base varchar(255) NOT NULL,
  root varchar(255) NOT NULL,
  suffix int(10) unsigned NOT NULL DEFAULT 0,
  is_current tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY item (item_type,item_key)
) {charset_collate};
```

`tests/php/history-sandbox.php`: crea `{prefix}wmwp_detail_paths_test` con `wmwp_detail_history_create_table()`, la
svuota, aggancia il filtro `wmwp_detail_paths_table` a quel nome, forza con `pre_option_` la versione corrente e
`seed_pending` a 0, e con `register_shutdown_function` cancella la tabella e toglie i filtri (gira anche dopo `exit`
e dopo un errore fatale, a differenza di `finally`).

`uninstall.php`: `WP_UNINSTALL_PLUGIN` obbligatorio; `DROP TABLE IF EXISTS {prefix}wmwp_detail_paths` e le tre
option; in multisite, per ogni sito (`get_sites()` + `switch_to_blog()`). Il nome della tabella è scritto per esteso:
all'uninstall il plugin non è caricato.

- [x] **Passo 1: test che falliscono** in `tests/php/detail-history.php` (con la sandbox):
  - «scrittura e lettura»: `wmwp_detail_history_write()` di due righe, `wmwp_detail_history_load()` le restituisce
    nella forma del Task 2.
  - «un percorso, una riga»: riscrivere lo stesso percorso con un'altra chiave → una riga sola, con la chiave nuova.
  - «stesso percorso in due lingue»: `it` e `en`, stesso `path` → due righe (punto 4 della review).
  - «percorso lungo»: percorso di 300 caratteri → `wmwp_detail_history_lookup()` lo trova (punto 2 della review).
  - «solo le righe che cambiano»: `wmwp_detail_history_changes( $storico, $assegnazioni_identiche )` → `[]`; con una
    rinomina → la riga nuova attuale e la vecchia con `current = false` e lo stesso proprietario.
  - «dimenticare gli id»: righe `s:1` e `i:2` → `wmwp_detail_history_forget_ids()` restituisce 1, resta `s:1`.
  - «basi»: righe con basi `sentiero` e `itinerario` → `wmwp_detail_history_bases()` le contiene tutte e due.
  - «senza tabella»: filtro su un nome di tabella che non esiste → `load()` `[]`, `lookup()` `null`, `write()` senza
    errori fatali (punto 5 della review; `$wpdb->suppress_errors()` attorno alle query).
- [x] **Passo 2:** `detail-history.php` → FAIL.
- [x] **Passo 3:** scrivere le funzioni, `uninstall.php`, la sandbox; aggiungere l'include della sandbox nei test
  elencati sopra.
- [x] **Passo 4:** tutti i `tests/php/detail-*.php` → PASS; dopo, la tabella `…_test` non esiste più
  (`wp db query "SHOW TABLES LIKE '%wmwp_detail_paths_test'"` vuoto).
- [x] **Passo 5: Commit** (istruzione per il dev):

```bash
git add includes/detail-history.php uninstall.php tests/php/
git commit -m "feat(oc:8983): tabella dello storico dei percorsi, con versione dello schema e uninstall"
```

---

### Task 4: Storico nella ricostruzione dell'indice

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-storico-nella-ricostruzione)

**File:**
- Modifica: `includes/detail-index.php` — `wmwp_detail_index()` (righe 302-351)
- Modifica: `includes/detail-history.php` (due funzioni pure)
- Test: `tests/php/detail-index.php` (funzioni pure), `tests/php/detail-cache.php` (giro completo con lo shard finto)

**Interfacce:**
- Consuma: Task 2 e Task 3.
- Produce:
  - `wmwp_detail_keys_collapsed( ?array $previous, array $items ): bool` (pura) — per tipo: elementi con `source` non
    vuoto nell'indice precedente (`$previous['items']`) e fra i nuovi `$items`; `true` se prima erano più di 0 e ora
    sono meno di `WMWP_DETAIL_REJECT_RATIO` (0,5) di prima.
  - `wmwp_detail_shard_renumbered( ?array $previous, array $items ): bool` (pura) — `true` se un elemento con
    `source` ha, nello stesso tipo, un id diverso da quello che aveva nell'indice precedente.
  - `wmwp_detail_history_seed( array $index ): void` — scrive come `current = false` tutti i percorsi di un indice
    esistente (`paths`), con la chiave da `items` (`wmwp_detail_item_key()`), `root` = slug del percorso,
    `suffix` 0.

Ordine nuovo in `wmwp_detail_index()` dopo `wmwp_detail_fetch_items()`:

```text
1. items null → return $last (come oggi)
2. wmwp_detail_keys_collapsed($last, $items) → error_log("[wp-package] indice dei dettagli scartato: chiave della fonte …"),
   return $last — sempre, senza contatore (a differenza di WMWP_DETAIL_MAX_REJECTS)
3. seed_pending e $last → wmwp_detail_history_seed($last); delete_option(seed_pending)
4. wmwp_detail_shard_renumbered($last, $items) → wmwp_detail_history_forget_ids()
5. $history = wmwp_detail_history_load(); build_index($items, 'wmwp_detail_base', null, $history, $assignments)
6. controlli di oggi (indice vuoto, tipo sotto la metà) → return $last senza toccare lo storico
7. salvataggio di oggi; poi wmwp_detail_history_write(wmwp_detail_history_changes($history, $assignments));
   WMWP_DETAIL_BASES_OPTION = basi dell'indice ∪ wmwp_detail_history_bases(), ordinate e senza doppioni
```

- [x] **Passo 1: test che falliscono.** In `detail-index.php`: «chiave crollata» (prima 3 tracce con fonte, ora 1 →
  `true`; ora 2 → `false`; prima 0 → `false`), «shard rinumerato» (fonte `4076` con id 12 prima e 15 ora → `true`;
  stesso id → `false`; solo elementi senza fonte → `false`). In `detail-cache.php`, con lo shard finto che usa già:
  - «indice scartato per la chiave crollata»: indice buono con le fonti, poi lo shard finto le toglie →
    `wmwp_detail_index( true )` restituisce l'indice di prima, lo storico non cambia; alla terza volta ancora scartato.
  - «primo giro»: `seed_pending` a 1 e un indice 0.2.0 con `sentiero/monte-4076` e `sentiero/monte-n-12-b` →
    dopo la ricostruzione i percorsi sono `sentiero/monte` e `sentiero/monte-2`, e lo storico ha ancora
    `sentiero/monte-4076` del nid 4076 con `current = false`.
  - «righe con l'id dopo un azzeramento»: riga `i:13`, poi lo shard finto rinumera gli elementi con fonte → la riga
    `i:13` non c'è più.
  - «nome senza lettere dopo un reset» (punto 1 della review): elemento con fonte e nome `'— !!'`, id 41 poi 77 →
    lo storico ha `…/41` non attuale e `…/77` attuale dello stesso proprietario.
  - «basi dello storico»: dopo una ricostruzione in cui una base sparisce, `get_option( 'wmwp_detail_bases' )` la
    contiene ancora.
- [x] **Passo 2:** i due file → FAIL.
- [x] **Passo 3:** scrivere le tre funzioni e il nuovo ordine in `wmwp_detail_index()`; aggiornare il suo docblock.
- [x] **Passo 4:** tutti i `tests/php/detail-*.php` → PASS.
- [x] **Passo 5: Commit** (istruzione per il dev):

```bash
git add includes/detail-index.php includes/detail-history.php tests/php/detail-index.php tests/php/detail-cache.php
git commit -m "feat(oc:8983): storico aggiornato a ogni indice accettato, con le protezioni sulla chiave e sugli id"
```

---

### Task 5: Risoluzione dei percorsi con lo storico

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-risoluzione-dei-percorsi)

**File:**
- Modifica: `includes/detail-route.php` — `wmwp_detail_find()` (righe 132-187) e il suo docblock
- Modifica: `includes/detail-history.php` — una funzione
- Test: `tests/php/detail-route.php`

**Interfacce:**
- Consuma: `wmwp_detail_history_lookup()`, `wmwp_detail_item_key()`.
- Produce: `wmwp_detail_index_id_for_key( array $index, string $type, string $key ): ?int` — l'id che ha oggi
  nell'indice l'elemento con quella chiave (scorre `$index['items'][ $type ]`, con una copia per richiesta).
- `wmwp_detail_find()` mantiene firma e forma del risultato. Ordine nuovo: (1) indice nella lingua chiesta → 200;
  (2) `wmwp_detail_history_lookup( $lang, $path )` → se c'è: id del proprietario nell'indice → 301 a quell'elemento,
  altrimenti 404 senza ripiego; (3) indice in un'altra lingua → 301; (4) ripiego per slug di oggi; (5) 404.

- [x] **Passo 1: test che falliscono** in `tests/php/detail-route.php` (indice finto di oggi, righe nella tabella
  della sandbox):
  - «301 dallo storico dopo la ricostruzione»: riga `sentiero/vecchio` (it) di `i:5`, non nell'indice →
    `wmwp_detail_resolve( 'it', 'sentiero/vecchio' )` 301 con `location` uguale a `wmwp_detail_url( 'track', 5, 'it' )`.
  - «elemento che non c'è più»: riga di `s:999`, nessun elemento con quella chiave → 404 (e non il ripiego per slug,
    anche se un altro elemento ha lo stesso slug).
  - «storico prima dell'altra lingua»: `sentiero/foo` nell'indice `en` di un elemento, e in `it` lo storico lo dà a un
    altro → 301 all'elemento dello storico.
  - «ripiego solo per i percorsi sconosciuti»: il caso di oggi «base cambiata» senza righe di storico → 301 come oggi.
- [x] **Passo 2:** `detail-route.php` → FAIL.
- [x] **Passo 3:** scrivere `wmwp_detail_index_id_for_key()` e il nuovo ordine in `wmwp_detail_find()`.
- [x] **Passo 4:** tutti i `tests/php/detail-*.php` → PASS.
- [x] **Passo 5: Commit** (istruzione per il dev):

```bash
git add includes/detail-route.php includes/detail-history.php tests/php/detail-route.php
git commit -m "feat(oc:8983): i percorsi vecchi portano con un 301 all'indirizzo attuale anche dopo la ricostruzione"
```

---

### Task 6: Comando WP-CLI per le correzioni

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-comando-wp-cli)

**File:**
- Crea: `includes/class-wmwp-detail-paths-command.php`
- Modifica: `index.php` (con `defined( 'WP_CLI' ) && WP_CLI`: `require_once` e
  `WP_CLI::add_command( 'wmwp detail-paths', 'WMWP_Detail_Paths_Command' )`), `includes/detail-history.php`
- Test: `tests/php/detail-history.php`

**Interfacce:**
- Produce: `wmwp_detail_history_forget( string $path, ?string $lang = null ): int` — cancella le righe di quel
  percorso (in una lingua o in tutte) e mette `current = false` su tutte le righe con la stessa `base` e lo stesso
  `root`, così al giro dopo gli omonimi si riassegnano in ordine di chiave; restituisce le righe cancellate.
- Produce: `wmwp_detail_history_rows( array $where ): array` — righe per `path` e `lang`, oppure per `type` e `key`.
- Comando: `wp wmwp detail-paths list [<path>] [--lang=<lang>] [--type=<track|poi>] [--key=<chiave della fonte>] [--id=<id>]`
  (tabella `lang`, `path`, `type`, `key`, `current`, con `WP_CLI\Utils\format_items`) e
  `wp wmwp detail-paths forget <path> [--lang=<lang>]` (chiama `wmwp_detail_history_forget()`, poi
  `delete_option( WMWP_DETAIL_REBUILT_AT )` e `wmwp_detail_index( true )`, e stampa il percorso nuovo degli omonimi).
  Il percorso si accetta con o senza barre ai lati (`wmwp_detail_normalize_path()`).

- [x] **Passo 1: test che fallisce** in `tests/php/detail-history.php`, «forget rimette in gioco gli omonimi»:
  storico `sentiero/monte-x` di `s:100` non attuale e `sentiero/monte-x-2` attuale di `s:200` «Monte X» →
  `wmwp_detail_history_forget( 'sentiero/monte-x' )` restituisce 1; poi `wmwp_detail_build_index()` con lo storico
  ricaricato dà `sentiero/monte-x` a `s:200`, e la sua riga `sentiero/monte-x-2` resta sua (301).
- [x] **Passo 2:** `detail-history.php` → FAIL.
- [x] **Passo 3:** scrivere le due funzioni e la classe del comando (docblock dei sottocomandi in italiano: è l'aiuto
  di `wp help wmwp detail-paths`).
- [x] **Passo 4:** `detail-history.php` → PASS; in locale
  `docker exec -w /var/www/html wordpress-forestas wp wmwp detail-paths list sentiero/monte-cresia-t-513a --allow-root`
  stampa la riga con il suo proprietario (dopo il Task 4 lo storico vero è già popolato).
- [x] **Passo 5: Commit** (istruzione per il dev):

```bash
git add index.php includes/class-wmwp-detail-paths-command.php includes/detail-history.php tests/php/detail-history.php
git commit -m "feat(oc:8983): comando wp wmwp detail-paths per vedere e liberare un percorso"
```

---

### Task 7: Versione 0.3.0 e documentazione del plugin

**File:**
- Modifica: `index.php` (`Version: 0.3.0`, `WMWP_VERSION` `'0.3.0'`: cambia anche la firma delle regole di rewrite,
  che si rigenerano), `CHANGELOG.md` (sezione `## 0.3.0 — <data>`), `README.md` («Interfaccia pubblica»: il comando;
  «Pagine di dettaglio»: slug e doppioni, storico, risposte nell'ordine nuovo, protezioni, basi dello storico; «Test»:
  `detail-history.php` e la sandbox), `docs/knowledge/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress.md`
  (riscrittura di «Come funziona oggi» e «Perché così» con le scelte di oc:8983; in «Come ci siamo arrivati» il
  suffisso `-<id>` e poi `-<chiave stabile>` superati, con il motivo)

- [x] **Passo 1:** scrivere le modifiche.
- [x] **Passo 2:** tutti i test: `for f in tests/php/*.php; do …eval-file…; done` → ogni file stampa `PASS` (esclusa
  `history-sandbox.php`, che non è un test); `node --test tests/js/*.test.js` → PASS (non toccati, controllo di
  regressione).
- [x] **Passo 3: Commit** (istruzione per il dev):

```bash
git add index.php CHANGELOG.md README.md docs/
git commit -m "docs(oc:8983): versione 0.3.0, README, CHANGELOG e pagina di conoscenza degli indirizzi stabili"
```

---

### Task 8: Parte del sito (wp-forestas) e verifica in locale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-8-verifica-in-locale)

**File (repo `wp-forestas`, branch `feature/oc-8983-…` già creato):**
- Modifica: `themes/forestas-child/inc/dettaglio.php:233-237` (solo il docblock di `forestas_dettaglio_nid()`)
- Modifica: `docs/knowledge/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress.md`
  (chiave: nid finché c'è, id per gli elementi creati in Nova, anche con Drupal spento; il nid resta salvato nello
  shard, `properties->sardegnasentieri_id`)

- [x] **Passo 1:** scrivere le due modifiche; `themes/forestas-child/tests/dettaglio.php` → PASS (non cambia).
- [x] **Passo 2: verifica in locale con i dati veri** (plugin dal clone, shard locale):
  1. prima del primo giro, `wp option get wmwp_detail_paths_seed_pending` = 1 e la tabella vuota;
  2. `wp cron event run wmwp_detail_refresh_index` → `wp wmwp detail-paths list sentiero/monte-cresia-t-513a`: il nid
     più basso dei due; `curl -sI <sito>/sentiero/monte-cresia-t-513a-699/` → `301` verso `…-2/` o verso quello senza
     suffisso, secondo il nid;
  3. i 12 doppioni di oggi: nessun `-<nid>` nell'indice nuovo, ognuno con il 301 dal vecchio;
  4. rinomina di una traccia nel Nova locale, ricostruzione, `curl -sI` del percorso vecchio → `301` al nuovo; dopo
     un'altra ricostruzione ancora `301`;
  5. `wp wmwp detail-paths forget` su un percorso di prova e ritorno allo stato di prima.
- [x] **Passo 3: Commit** (istruzione per il dev, in `wp-forestas`):

```bash
git add themes/forestas-child/inc/dettaglio.php docs/
git commit -m "docs(oc:8983): chiave degli elementi nel child e nella pagina di conoscenza dei dettagli"
```

---

### Task 9: Rilascio e verifica sul dev

Ogni passo che scrive su un remoto (PR, merge, push, puntatore) si fa solo dopo il sì del dev a quel passo.

- [ ] **Passo 0 — prima del rilascio:** sul dev le tracce in Elasticsearch hanno `sardegnasentieri_id` (in locale
  mancava: l'indice non era stato rifatto dopo oc:8959). Senza, al primo giro lo storico si riempirebbe di righe `i:`
  e i percorsi delle tracce, quando la chiave arriva, darebbero 404. Se manca: `php artisan scout:import
  "App\Models\EcTrack"` nel container php di forestas sul dev.
- [ ] **Passo 1 — `wp-package`:** PR del branch verso `develop`, chiusa con squash and merge.
- [ ] **Passo 2 — `wp-package`:** `git log origin/develop..origin/main` vuoto (se no, avvisare prima); merge di
  `develop` in `main` con merge commit (`git merge --no-ff develop`), push.
- [ ] **Passo 3 — `wp-forestas`:** hash con `git ls-remote https://github.com/webmappsrl/wp-package refs/heads/main`,
  scritto in `WP_PACKAGE_REF` (`docker/scripts/init-wordpress.sh:16`), commit a sé
  (`chore(oc:8983): wp-package 0.3.0`), PR verso `develop` con il Task 8, squash and merge.
- [ ] **Passo 4 — `forestas`:** puntatore di `wp-forestas` all'hash di `develop` letto con `git rev-parse`, commit a sé
  in un branch del ticket, PR verso `develop`: il merge fa partire il deploy sul dev.
- [ ] **Passo 5 — dev:** `scripts/wordpress-up.sh` sull'host (ricostruisce l'immagine con l'init nuovo); nei log
  dell'init `scarico wp-package al commit <hash>`.
- [ ] **Passo 6 — verifica sul dev:** gli stessi controlli del Task 8, passo 2 (punti 2-4), sui dati del dev; il
  giorno dopo, dopo il reset notturno: un percorso vecchio di una traccia con nid porta ancora al 301 giusto, e
  `wp wmwp detail-paths list --type=track --id=<id di un elemento creato in Nova il giorno prima>` non restituisce
  righe. Aprire anche una pagina `-2` di una traccia e controllare che `wmwp_detail_index_rebuilt_at` non cambi.
- [ ] **Passo 7:** `notes.md` aggiornato con l'esito; pagine di conoscenza riviste se la verifica cambia qualcosa.
