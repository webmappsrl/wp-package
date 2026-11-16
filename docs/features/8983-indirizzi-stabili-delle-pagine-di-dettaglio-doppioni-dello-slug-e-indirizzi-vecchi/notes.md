> Ticket: oc:8983

# Notes — Indirizzi stabili delle pagine di dettaglio: doppioni dello slug e indirizzi vecchi

## Divergenze dal piano, task per task

### Task 3: tabella dello storico

- Indice secondario `KEY item_key (item_key)` invece di `KEY item (item_type,item_key)`: con `utf8mb4` le due colonne
  insieme superano i 767 byte indicizzabili di MySQL vecchi, e `dbDelta()` fallirebbe. Il tipo si confronta in PHP.
- `uninstall.php` non ha un test automatico: cancella la tabella vera, il cui nome è scritto per esteso (all'eliminazione
  il plugin non è caricato), quindi un test la distruggerebbe sul sito su cui gira. Controllato con `php -l` e in review.
- La sandbox dei test (`tests/php/history-sandbox.php`) salva e rimette anche le option vere dello storico (versione,
  primo giro, fallimento): il test del primo giro cancellava la option vera `wmwp_detail_paths_seed_pending`, perché
  `delete_option()` non passa dai filtri `pre_option_`. Trovato nella verifica in locale del Task 8. Dalle review
  interne rimette anche lo stato vero dell'indice (transient, ultimo indice buono, basi, scarti).
- Firme diverse dal piano, dalle review: `wmwp_detail_history_load(): ?array` (null se la tabella c'è ma la lettura
  fallisce, `[]` solo se lo storico non c'è); `wmwp_detail_history_write(): bool` (con una riga nel log se fallisce);
  `wmwp_detail_history_forget_ids( ?string $type = null )` (per tipo). In più: `wmwp_detail_history_table_exists()`
  (`SHOW TABLES`, al posto del testo dell'errore), `wmwp_detail_history_base_types()`, `wmwp_detail_rule_bases()`,
  costanti `WMWP_DETAIL_PATHS_COLUMNS`, `WMWP_DETAIL_KEY_KEPT_RATIO`, `WMWP_DETAIL_RENUMBER_RATIO`, filtro
  `wpmu_drop_tables`.
- **Punto 5 dei «Punti da guardare in review» del piano**: una tabella che non risponde mentre la versione dice che
  c'è non fa più «continuare senza storico». La lettura dà null e l'indice resta quello di prima, perché un indice
  costruito senza storico riassegnerebbe i percorsi degli omonimi. Se la tabella è sparita, si toglie la versione e si
  ricrea al caricamento successivo.

### Task 2: assegnazione dei percorsi

- L'indice ha una chiave in più, `suffixes`: il suffisso dato a ogni elemento per lingua. C'è sempre, anche vuota, e
  la sua presenza dice che l'indice è della 0.3.0. Il piano diceva «stesso formato dell'indice». Serve a
  `wmwp_detail_slug_matches()` per accettare solo il suffisso dell'elemento (review interne, giro 1).
- Una chiave della fonte ripetuta resta all'id più basso; gli altri elementi contano come senza chiave (`source` null
  nell'indice, chiave `i:<id>`). La chiave è ripulita (`trim`) anche nell'indice.

### Task 4: storico nella ricostruzione

- La ricostruzione è in una funzione a sé, `wmwp_detail_index_rebuild()`, e il lock si rilascia in un punto solo
  (`try/finally`).
- `wmwp_detail_history_seed()` è diventata `wmwp_detail_history_seed_rows()`, una funzione che restituisce le righe
  senza scriverle. Primo giro e righe con l'id dimenticate agiscono in memoria prima dei controlli e si scrivono solo
  dopo l'accettazione dell'indice; l'option del primo giro si toglie solo se la scrittura riesce. Il piano li scriveva
  prima della costruzione.
- `wmwp_detail_shard_renumbered()` restituisce i tipi azzerati (un array, non un booleano) e serve la maggioranza
  degli elementi con la chiave, chiavi doppie escluse. Costo accettato: su dev e UAT un reset in cui cambia id meno
  della metà degli elementi con il nid non viene riconosciuto, e le righe `i:` degli elementi creati in Nova
  sopravvivono.
- `wmwp_detail_keys_collapsed()` guarda solo gli elementi presenti in tutti e due gli indici che avevano la chiave.
  Costo accettato: se le API tolgono la chiave proprio mentre lo shard rinumera gli id, il controllo non scatta.
- I controlli usano l'ultimo indice anche se nel frattempo è cambiata la configurazione delle basi (`$guard`), e il
  cambio di configurazione non cancella più `wmwp_detail_index_last`. Il piano usava `$last`, che con le basi cambiate
  è null.

### Task 5: risoluzione dei percorsi

- Il ripiego per slug, sotto una base che l'indice non ha più, prende i tipi dallo storico
  (`wmwp_detail_history_base_types()`), non da nessuna parte come prima.
- `wmwp_detail_slug_matches()` (`includes/detail-render.php`, non nel piano) accetta il suffisso dato dall'indice e,
  solo con un indice 0.2.0 in cache, le forme della 0.2.0. È la correzione critica della review indipendente.
- Il ripiego di `wmwp_detail_bases()` (option mai scritta) usa `wmwp_detail_rule_bases()`, quindi anche le basi dello
  storico.

### Task 6: comando WP-CLI

- `forget` rende non attuali le righe con la stessa base e lo stesso slug di partenza nella lingua di ogni riga
  cancellata (con `--lang`, solo in quella lingua). Svuota l'indice in cache e avvisa se la ricostruzione non riesce
  subito.
- `list` mostra anche la colonna `suffix`, accetta `--format` e scrive `current` come 1/0.
- Passo 4 (`list` sullo storico vero): fatto nel Task 8, quando lo storico locale era popolato
  (`wp wmwp detail-paths list --type=track --key=75`, vedi «Verifica in locale»), non nel Task 6.

### Task 8: verifica in locale

- Il primo giro è stato verificato su uno stato 0.2.0 ricreato: indice costruito con la funzione del commit `96f8d56`,
  messo in cache e come ultimo buono, storico vuoto, primo giro da fare. Serviva perché i test del plugin
  (`detail-cache.php`, già in 0.2.0) cancellano l'indice del sito su cui girano.
- Prima è servito reindicizzare le tracce nell'Elasticsearch locale (`php artisan scout:import "App\Models\EcTrack"`):
  l'indice locale non aveva `sardegnasentieri_id` (0 tracce su 769), quindi le tracce arrivavano senza chiave.

## Bug trovati

- Nella sandbox dei test, la option vera del primo giro cancellata dai test (sopra, Task 3).
- **Dalla review indipendente del branch (16/11)**, corretti con un test rosso e poi verde, suite 15/15:
  - `wmwp_detail_slug_matches()` (`includes/detail-render.php`, da oc:8969) riconosceva solo i suffissi della 0.2.0:
    una traccia doppia senza chiave della fonte su `-2` dava 404, una con la chiave faceva ricostruire l'indice al
    visitatore. Ora accetta `<slug>-<n>`. La verifica in locale non l'aveva visto perché i doppioni avevano tutti il nid.
  - Il controllo della chiave crollata contava gli elementi con la chiave in numero assoluto: una cancellazione vera di
    metà degli elementi avrebbe bloccato l'indice per sempre. Ora misura la quota sul totale del tipo.
  - Uno storico che c'è ma non si legge faceva accettare un indice costruito senza storico, che riassegna i percorsi
    degli omonimi. Ora l'indice resta quello di prima; se la tabella è sparita si toglie la versione e si ricrea.
  - Il reset dello shard si riconosceva da un solo elemento con l'id cambiato: in produzione un elemento reimportato,
    o un nid copiato duplicando un elemento in Nova, avrebbe cancellato le righe di tutti gli elementi creati in Nova.
    Ora serve la maggioranza, chiavi doppie escluse.
  - `forget` non svuotava l'indice in cache e dichiarava sempre «indice ricostruito».
- **Dalle review interne (sessioni `wp-package-52` e `wp-forestas-e1`, giro 1, 16/11):** nessun bloccante. Cleanup
  applicati, ognuno con un test rosso e poi verde; suite 15/15, stato vero dell'indice uguale prima e dopo la suite:
  - chiave crollata misurata solo sugli elementi presenti in tutti e due gli indici: la quota sul totale fermava l'indice
    per sempre con la cancellazione dei soli elementi con la chiave o con un import in blocco senza chiave;
  - chiave della fonte ripetuta: resta all'id più basso, gli altri elementi contano come senza chiave (prima i due si
    scambiavano i percorsi a ogni giro);
  - il controllo del nome accetta solo il suffisso dato all'elemento (`suffixes` nell'indice): con un `-<n>` qualunque
    un elemento rinominato da «Foo 3» a «Foo» restava servito su `foo-3`;
  - i controlli usano l'ultimo indice buono anche dopo un cambio di configurazione delle basi (prima l'ultimo indice si
    cancellava e i controlli non controllavano niente);
  - primo giro e righe con l'id dimenticate si scrivono solo dopo che l'indice è stato accettato; l'option del primo
    giro si toglie solo a scrittura riuscita; `wmwp_detail_history_write()` segnala i fallimenti nel log;
  - ripiego per slug sotto una base che conosce solo lo storico: i tipi della base vengono dallo storico;
  - tabella sparita riconosciuta con `SHOW TABLES`, non dal testo dell'errore (che dipende dalla lingua del database);
  - `wmwp_detail_index_id_for_key()` passa da `wmwp_detail_item_key()` (chiave ripulita), e la chiave è ripulita anche
    nell'indice;
  - azzeramento dello shard riconosciuto e applicato per tipo;
  - basi delle regole in un punto solo (`wmwp_detail_rule_bases()`), anche nel ripiego di `wmwp_detail_bases()`;
  - `wpmu_drop_tables`: la cancellazione di un sito in multisite porta via la sua tabella;
  - la sandbox rimette com'era anche lo stato vero dell'indice (prima i test cancellavano l'ultimo indice buono del
    sito: dopo un deploy, una suite lanciata prima della prima ricostruzione avrebbe lasciato i `-<nid>` senza 301);
  - test nuovi: 301 da capo a fondo dopo rinomina, cambio di base e traduzione; primo giro dall'ultimo indice buono;
    `list`; tabella che non si crea e nuovo tentativo dopo un'ora; uno SKIP stampato quando manca l'inglese;
  - giro 2: un comportamento solo con le basi appena cambiate (sotto, «Decisioni»); forme della 0.2.0 solo con un
    indice 0.2.0 in cache; `get_sites( [ 'number' => 0 ] )` in `uninstall.php`;
  - pulizie: costanti per le soglie (`WMWP_DETAIL_KEY_KEPT_RATIO`, `WMWP_DETAIL_RENUMBER_RATIO`), colonne della SELECT
    in una costante, lock rilasciato in un punto solo (`try/finally`, con la ricostruzione in
    `wmwp_detail_index_rebuild()`), docblock dei tipi e di `forget`.

## Decisioni

- Tag Orchestrator: nessuno associato in pianificazione, né di ambiente né di contenuto, per scelta del dev (16/11).
  Il candidato era `wp-package` (id 721).
- `wmwp_detail_count_sources()` in più rispetto al piano: conta gli elementi con la chiave della fonte per tipo, per
  `wmwp_detail_keys_collapsed()` e per il messaggio di log quando l'indice si scarta.
- Il sottocomando `list` è il metodo `list_paths()` con `@subcommand list`: `list` come nome di metodo non è ammesso in
  PHP 7.4. Nella tabella di `list` la colonna `current` è 1/0, perché un `false` stampava una cella vuota.
- `wmwp_detail_index_id_for_key()` scorre gli elementi a ogni chiamata, senza una copia per richiesta: si chiama solo
  quando lo storico conosce un percorso che l'indice non ha.
- Dopo `forget` un percorso può dare ancora 301 per il ripiego per slug, se lo stesso slug esiste in un'altra lingua
  (il percorso inglese di un nome non tradotto): è il comportamento previsto per i percorsi che lo storico non conosce.

- Il test 0.2.0 «doppione `-<fonte>` di un altro elemento» (`tests/php/detail-render.php`) usa ora una chiave
  testuale: con i suffissi `-<n>` un numero non identifica più l'elemento (`-2` e `-4076` hanno la stessa forma), e
  percorso e chiave escono dallo stesso indice, quindi il caso non si presenta.
- Con un cambio di configurazione delle basi l'ultimo indice buono non si cancella più: serve ai controlli. Finché un
  indice con la configurazione nuova non è accettato, `wmwp_detail_index()` restituisce quello vecchio (`$last ??
  $guard`), sia quando la ricostruzione si scarta sia quando non è permessa (limite dei 5 minuti, lock): le pagine
  restano raggiungibili con le basi vecchie, ancora nelle regole di rewrite, invece di un 503. Le due review interne
  avevano indicazioni opposte (una preferiva il 503, l'altra questo): scelto un comportamento solo, questo (giro 2).
- Le forme dei doppioni della 0.2.0 (`-<id>`, `-<chiave>`) in `wmwp_detail_slug_matches()` valgono solo con un indice
  0.2.0 in cache, riconosciuto dall'assenza di `suffixes` (che nella 0.3.0 c'è sempre, anche vuoto).
- `id` resta nelle righe di `$assignments` anche se lo storico non lo usa: serve ai test e a capire un'assegnazione.
- Il ciclo della 0.2.0 in `wmwp_detail_slug_matches()` resta per gli indirizzi dell'indice 0.2.0 ancora in cache al
  primo giro.
- Il punto 5 dei «Punti da guardare in review» del piano è cambiato: una tabella che non risponde mentre la versione
  dice che c'è non fa più continuare senza storico, ma tiene l'indice di prima (sopra, «Bug trovati»).

## Verifica in locale (16/11)

- Primo giro: i 12 doppioni della 0.2.0 (4 tracce, 8 POI) danno 301 dai percorsi `-<nid>` a quelli nuovi; in ogni
  coppia il nid più basso prende l'indirizzo senza suffisso, l'altro `-2`; anche in inglese (`/en/trail/…`). Storico:
  3.490 righe attuali (1.745 elementi per due lingue) e 22 vecchie.
- Rinomina della traccia 478 («Carta 1. Baronie e Montalbo») in Nova locale: il percorso vecchio dà 301 al nuovo dopo
  la prima e dopo la seconda ricostruzione; `wp wmwp detail-paths list --type=track --key=75` mostra il percorso
  nuovo attuale e quello vecchio non attuale, tutti e due della chiave `s:75`. Nome rimesso com'era, righe di prova
  tolte con `forget`.
- Suite: `tests/php` del plugin 15/15 PASS, `tests/js` 5/5 PASS, test del child PASS.

## Informazioni per altri ticket

- L'Elasticsearch locale di forestas può non avere i campi aggiunti all'indice dopo la sua ultima costruzione
  (`sardegnasentieri_id` di oc:8959): `scout-init` lo crea solo se manca. Con
  `php artisan scout:import "App\Models\EcTrack"` si rifà.
- I test `tests/php/detail-cache.php` del plugin (da oc:8969) cancellano l'indice dei dettagli del sito su cui girano;
  si ricostruisce alla prima richiesta.

## Review formale (`wm-review-ticket`, 16/11)

Cinque finder sul working tree dei due repo: **approvato con riserve**, nessun bloccante. I casi della richiesta
(omonimo nuovo, rinomina, cambio di tipo, traduzione, reset degli id) sono stati verificati anche simulando il codice
vero. La documentazione è stata aggiornata nello stesso giro (piano, note, overview, README, howto e conoscenza di
wp-forestas); i cleanup di codice sono sotto, in «Follow-up».

## Follow-up

- Task 9 del piano (rilascio fino al dev e verifica dopo il reset notturno): dopo i commit, un passo alla volta con
  il sì del dev. Sul dev, aprire anche una pagina `-2` di una traccia e controllare che
  `wmwp_detail_index_rebuilt_at` non cambi.
- Minori della review rimandati, da decidere: il log «tabella non creata» esce senza il motivo (la `SHOW TABLES` dopo
  `dbDelta()` azzera `last_error`); la versione dello schema ha autoload `false` e si legge a ogni richiesta;
  `list --type --id` su un
  elemento con il nid non suggerisce `--key`; il test del percorso lungo scrive un `root` di 300 caratteri in un
  `varchar(255)`; con due elementi con lo stesso nid la prima passata può scambiarne i percorsi una volta; mancano un
  test della classe del comando oltre `forget` e `list`.
- Dalle review interne, rimandati: un minimo assoluto per riconoscere l'azzeramento dello shard (con un solo elemento
  in comune basta un id cambiato); `forget --base` per togliere dalle regole una base finita nello storico per
  sbaglio; una chiave della fonte che cambia valore invece di sparire non fa scattare i controlli (ipotetico).
- Dalle review interne, giro 2, ipotetico e lasciato su indicazione del revisore: se al primo giro la scrittura delle
  righe del seed fallisce, l'indice viene accettato lo stesso e il giro dopo semina dall'indice nuovo, quindi i
  `-<nid>` della 0.2.0 restano senza 301. Serve un errore di scrittura proprio in quel giro.
- Dalla review formale, cleanup di codice da fare:
  - **chiave che compare tutta insieme** (righe `i:<id>` di un primo giro senza nid, che non diventano `s:<nid>`
    quando il nid arriva): oggi c'è solo la precondizione documentata (README, conoscenza 8969 di wp-forestas, Task 9
    passo 0); una conversione delle righe quando lo stesso id riceve la chiave renderebbe il plugin sicuro anche senza;
  - **indice di ripiego tenuto in memoria per la richiesta**: con l'indice scartato a lungo (chiave crollata) e il
    transient scaduto, ogni chiamata rilegge e decodifica l'ultimo indice (circa 800 KB); la sitemap fa migliaia di
    chiamate;
  - **elemento assente per un giro**: al ritorno riparte da `-2` invece di riprendere il proprio percorso vecchio con la
    stessa base e lo stesso slug;
  - **rinomina di un elemento senza nid**: 404 sull'indirizzo vecchio nei 5 minuti dopo una ricostruzione (come nella
    0.2.0; pesa di più a Drupal spento);
  - identificatori in italiano nei test nuovi (`$con_fonte`, `$svuota`, `$ricostruisci`…), da portare in inglese;
    docblock che citano `wmwp_detail_index()` dove lavora `wmwp_detail_index_rebuild()`; `$suffix` riusato in
    `wmwp_detail_slug_matches()`; prefissi `s:`/`i:` e regola «ha la chiave» ripetuti, da portare in due funzioni;
    nomi di option per esteso nella sandbox; `wmwp_detail_history_forget()` che conta le righe trovate e non quelle
    cancellate;
  - architettura: dipendenza reciproca fra `detail-history.php` e `detail-index.php`; primo giro legato alla tabella
    appena creata (dopo un ritorno alla 0.2.0 non riparte); un indice sulla colonna `base`.
- Ipotetici dalla review formale, da ignorare finché non cambiano i dati: primo giro che coincide con il reset notturno
  (righe `i:` del primo giro reinserite; servono elementi senza nid, oggi 0 in locale); nid doppio al passaggio
  (il primo giro non applica la regola dell'id più basso; oggi 0); shard con meno della metà degli elementi con la
  chiave e reset degli id (falso «chiave crollata»); cambio di shard o di app nelle impostazioni (indice scartato fino
  a una pulizia a mano); nomi senza lettere che si incrociano dopo un rimescolamento degli id.
