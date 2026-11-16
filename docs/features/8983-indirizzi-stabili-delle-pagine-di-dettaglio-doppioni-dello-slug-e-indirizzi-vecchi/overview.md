> Ticket: oc:8983

# Indirizzi stabili delle pagine di dettaglio: doppioni dello slug e indirizzi vecchi

## Cosa cambia

Gli indirizzi `/<base>/<slug>/` delle pagine di dettaglio di tracce e POI diventano stabili nel tempo:

- **Doppioni dello slug.** Fra gli elementi con lo stesso percorso, quello che lo ha già lo tiene; gli altri prendono
  un numero progressivo, `-2`, `-3`…, nell'ordine della loro chiave. Il suffisso non è più `-<chiave stabile>` né
  `-<id>`.
- **Storico degli indirizzi.** Una tabella propria del plugin, `{prefix}wmwp_detail_paths`, ricorda ogni percorso mai
  assegnato, per lingua, con il tipo e la chiave dell'elemento a cui è stato dato. Si aggiorna a ogni indice
  accettato da `wmwp_detail_index()`.
- **Un percorso resta dell'elemento a cui è stato dato.** Finché quell'elemento esiste, il percorso non passa a un
  altro elemento: se l'elemento cambia nome, tipo (e quindi base) o riceve la traduzione del nome, il percorso vecchio
  porta con un 301 al suo indirizzo attuale, anche dopo che l'indice è stato ricostruito. Il percorso torna libero solo
  quando l'elemento non c'è più: da quel momento dà 404, finché non viene dato a un altro elemento con quel nome.
- **Chiave di un elemento: la chiave della fonte se c'è, altrimenti l'id dello shard.** La chiave della fonte è quella
  di `wmwp_detail_source()` (per Forestas il nid di Drupal, dal filtro del child). Gli elementi senza, come quelli
  creati in Nova, sono riconosciuti dall'id, che in produzione non si rinumera.
- **Protezioni.** Un indice in cui la chiave della fonte crolla (API cambiate) si scarta, invece di riscrivere lo
  storico; quando lo shard viene svuotato e ricaricato (reset di dev e UAT) lo storico dimentica le righe registrate
  con l'id.
- **Correzioni a mano** con un comando WP-CLI, `wp wmwp detail-paths`, che mostra a chi appartiene un percorso e lo
  libera.
- **Primo giro con la versione nuova.** I doppioni di oggi (`-<nid>`) si ricalcolano con le regole nuove; i loro
  percorsi vecchi entrano nello storico dall'indice esistente e portano con un 301 a quelli nuovi.

Il lavoro arriva fino al server dev: versione 0.3.0 del plugin su `main`, nuovo hash in `WP_PACKAGE_REF` di
`wp-forestas`, puntatore di `wp-forestas` in `forestas`, `scripts/wordpress-up.sh` sul dev.

## Perché

Nasce dalla consulenza SEO del 12/11 sulle pagine di oc:8969 (scrum delle 14:48): il report segnalava che gli slug
doppi prendevano l'id, che il reset notturno cambia; Giuseppe: «Non devi mettere l'ID della traccia sullo slag, non ne
hai neanche di bisogno» e «per la B creiamo un ticket a parte per gestire l'unicità degli slag». oc:8969 ha già
sostituito l'id con il nid, ma restano tre problemi:

- quando arriva un omonimo, oggi `wmwp_detail_build_index()` mette il suffisso a **tutti** gli elementi con quel
  percorso (`includes/detail-index.php:132-146`), quindi anche chi c'era cambia indirizzo e il suo indirizzo vecchio dà
  404;
- il 301 di un elemento rinominato funziona solo finché il percorso vecchio è nell'indice: dopo la ricostruzione
  successiva (al massimo un'ora, con il cron) `wmwp_detail_find()` non lo trova più (`includes/detail-route.php:134`) e
  dà 404;
- il suffisso espone un identificativo interno.

Il sito va chiuso entro dicembre (scrum del 29/10). Dev e UAT hanno `blog_public = 0`, quindi nessun indirizzo è
ancora indicizzato: cambiare adesso i doppioni di oggi non costa nulla, dopo il lancio costerebbe link e posizionamento.

## Requisiti

**Slug dei doppioni**

- [x] Ogni elemento tiene il percorso che lo storico segna come suo attuale, se la sua base e lo slug del suo nome sono
      ancora quelli da cui il percorso è nato (la riga ricorda base, slug di partenza e numero del suffisso).
      «Cammino X Tappa 2» rinominato «Cammino X Tappa» non tiene `cammino-x-tappa-2`: è un altro slug.
- [x] Un elemento senza percorso valido nello storico prende `<base>/<slug>` se è libero, altrimenti il primo
      `<base>/<slug>-<n>` libero con `n` da 2 in su. «Libero» vuol dire non assegnato in questo giro e non dato nello
      storico a un altro elemento che esiste ancora.
- [x] L'assegnazione va in tre passate fisse — percorsi tenuti dallo storico, slug senza suffisso, suffissi — e in ogni
      passata gli elementi vanno in ordine di chiave: chiavi della fonte numeriche in ordine crescente, poi quelle non
      numeriche in ordine alfabetico, poi gli elementi con il solo id in ordine crescente. Un elemento nuovo non toglie
      mai l'indirizzo a uno che c'era.
- [x] Senza storico (primo giro, tabella non disponibile, tabella svuotata) la regola dà sempre lo stesso risultato per
      lo stesso insieme di elementi: con l'ordine per chiave, il primo omonimo prende lo slug senza suffisso.
- [x] Una chiave della fonte ripetuta (due elementi con lo stesso valore) resta all'id più basso; gli altri elementi
      contano come senza chiave, così i due non si scambiano i percorsi a ogni giro (review interne).
- [x] L'indice ricorda il suffisso dato a ogni elemento per lingua (`suffixes`): il controllo del nome
      (`wmwp_detail_slug_matches()`) accetta quello e nessun altro `-<n>`, così un elemento rinominato da «Foo 3» a
      «Foo» non resta servito su `foo-3` (review interne).

**Chiave**

- [x] Una funzione sola restituisce la chiave di un elemento: la chiave della fonte (`wmwp_detail_source()`) se c'è,
      altrimenti l'id dello shard. Storico, ordine dei doppioni e risoluzione dei percorsi vecchi passano da lì.
- [x] `wmwp_detail_source()` e il filtro `wmwp_detail_source` restano come sono (interfaccia pubblica, README).
- [x] Il controllo «l'id porta ancora allo stesso elemento» (`wmwp_detail_matches()`, `wmwp_detail_url_by_id()`)
      resta com'è: chiave della fonte se c'è, altrimenti lo slug del nome. L'id non può controllare sé stesso.
- [x] Un indice nuovo in cui, fra gli elementi presenti anche nell'indice precedente (stesso tipo e id) che avevano la
      chiave della fonte, meno della metà ce l'ha ancora si scarta sempre, non solo per due volte come il calo degli
      elementi, con un errore nel log: il sito resta sull'ultimo indice buono e lo storico non si tocca finché la chiave
      non torna. Cancellazioni e aggiunte, con o senza chiave, non spostano il conto: le giudica il controllo sui
      conteggi. I controlli usano l'ultimo indice buono anche se nel frattempo è cambiata la configurazione delle basi.

**Storico**

- [x] Tabella `{prefix}wmwp_detail_paths`, una riga per lingua e percorso (vincolo unico su un'impronta di lingua e
      percorso, perché un percorso lungo supera la lunghezza indicizzabile di MySQL vecchi): tipo, chiave e se è una
      chiave della fonte o un id, base, slug di partenza, numero del suffisso, se il percorso è quello attuale
      dell'elemento. Un percorso dato a un altro elemento sovrascrive la sua riga.
- [x] La tabella si crea e si aggiorna con `dbDelta()` dopo un controllo della versione dello schema a ogni caricamento
      (l'init di `wp-forestas` aggiorna il plugin sostituendo i file, senza riattivarlo); in multisite ogni sito ha la
      sua.
- [x] Se la tabella non si può creare, il plugin funziona come senza storico: nessun errore fatale, una riga nel log, un
      nuovo tentativo al massimo una volta all'ora invece che a ogni caricamento.
- [x] Lo storico si aggiorna solo con un indice accettato (non vuoto, non scartato, non fallito), compresi il primo giro
      e le righe con l'id dimenticate, e si scrivono solo le righe che cambiano: percorsi nuovi, percorsi che smettono o tornano a essere attuali, percorsi dati a un altro
      elemento. Un giro in cui non cambia niente non scrive niente.
- [x] Quando, per un tipo, più della metà degli elementi con la chiave della fonte (presenti anche nell'indice
      precedente, chiavi doppie escluse) ha un id diverso, lo shard è stato svuotato e ricaricato (reset di dev e UAT):
      le righe registrate con l'id di quel tipo si cancellano. Un elemento solo non basta: in produzione può cambiare id
      se cancellato e reimportato. Uno storico che c'è ma non si legge tiene l'indice di prima; se la tabella è sparita
      (controllo con `SHOW TABLES`, non con il testo dell'errore) si toglie la versione e si ricrea.
- [x] La disattivazione lascia la tabella; l'eliminazione del plugin (`uninstall.php`) la cancella con la sua option
      di versione, e in multisite la cancellazione di un sito porta via la sua (`wpmu_drop_tables`).
- [x] Comando WP-CLI `wp wmwp detail-paths`: `list` (a chi appartiene un percorso, oppure i percorsi di un elemento)
      e `forget <percorso>` (libera il percorso, che al giro successivo va all'elemento che oggi porta quel nome, e
      svuota l'indice in cache).

**Risposte**

- [x] Ordine della risoluzione di un percorso: l'indice nella lingua chiesta (200); lo storico nella lingua chiesta
      (301 all'indirizzo attuale dell'elemento nella lingua della richiesta, oppure 404 se l'elemento non c'è più);
      l'indice in un'altra lingua (301, come oggi); il ripiego per slug di oggi solo per i percorsi che lo storico non
      conosce; poi 404. Lo storico viene prima dell'altra lingua perché, con traduzioni diverse fra omonimi, nell'altra
      lingua lo stesso percorso può essere di un altro elemento.
- [x] Un percorso dello storico porta all'elemento a cui è stato dato finché quell'elemento esiste, mai a un altro.
- [x] Il ripiego per slug sotto una base che l'indice non ha più prende i tipi della base dallo storico: l'indirizzo
      vecchio di una traccia non porta a un POI con lo stesso slug.
- [x] Le regole di rewrite coprono anche le basi che compaiono nello storico (in un punto solo,
      `wmwp_detail_rule_bases()`, anche per il ripiego di `wmwp_detail_bases()`): quando lo slug di una base cambia (pagina
      base rinominata, nome di un `route-type` cambiato) i percorsi vecchi arrivano ancora al plugin, invece di finire
      al 404 di WordPress o al suo redirect verso un post con uno slug simile.
- [x] Rinomina, traduzione del nome arrivata dopo e cambio di `route-type`/`poi-type` portano con un 301 al nuovo
      indirizzo anche dopo la ricostruzione dell'indice, e anche dopo un reset degli id per gli elementi con la chiave
      della fonte.
- [x] Primo giro della versione nuova, riconosciuto dalla tabella appena creata: prima di qualsiasi ricostruzione, i
      percorsi dell'indice esistente (il transient, altrimenti `wmwp_detail_index_last`) entrano nello storico come
      non attuali; poi l'indice si ricostruisce con le regole nuove, e i `-<nid>` dei doppioni di oggi portano con un
      301 ai nuovi.
- [x] Indirizzo per id (302), sitemap, canonical e `hreflang` restano come sono: usano già l'indirizzo attuale
      dall'indice.

**Test, documentazione, messa in opera**

- [x] I test non toccano il sito su cui girano (`wp eval-file` gira sul sito vero): usano una tabella a parte, creata e
      cancellata dal test anche se il test fallisce, e alla fine rimettono com'erano le option dello storico e lo stato
      dell'indice (transient, ultimo indice buono, basi).
- [x] Test in `tests/php/` per: omonimo nuovo che non sposta chi c'era; doppioni con `-2` nell'ordine della chiave;
      rinomina che cambia lo slug di partenza; percorso che resta all'elemento dopo la sua rinomina; percorso libero
      dopo che l'elemento non c'è più; 301 da storico dopo la ricostruzione (rinomina, traduzione, cambio di base);
      storico prima dell'altra lingua; base rinominata; indice scartato per la chiave crollata; righe con l'id
      cancellate dopo un azzeramento dello shard; primo giro con l'indice vecchio; plugin senza tabella; comando
      WP-CLI.
- [x] README («Pagine di dettaglio»), CHANGELOG (0.3.0), versione del plugin, pagina di conoscenza 8969 del plugin
      aggiornati.
- [ ] Verifica sul dev con i dati veri: doppioni di oggi ricalcolati con i 301 dai `-<nid>`, rinomina di una traccia in
      Nova con il 301 dopo la ricostruzione dell'indice, comportamento dopo il reset notturno.

## Rischi

Dalla challenge, verificati sul codice:

- **API che smettono di dare la chiave della fonte.** È già successo con `out_source_feature_id` nel dettaglio del POI
  (oc:8959, pagina di conoscenza 8969). Con lo storico l'errore resterebbe scritto: i percorsi si libererebbero e gli
  omonimi si scambierebbero l'indirizzo. Mitigazione: l'indice in cui la chiave crolla si scarta sempre, il sito resta
  sull'ultimo indice buono; le tracce nuove non hanno la pagina finché le API non tornano a posto.
- **Id riusati dopo il reset di dev e UAT.** `TRUNCATE ... RESTART IDENTITY` riassegna gli stessi id ogni notte, quindi
  un elemento creato in Nova può ricevere l'id di quello del giorno prima. Mitigazione: un id cambiato di un elemento
  con la chiave della fonte rivela l'azzeramento, e le righe con l'id si cancellano tutte.
- **Correzioni redazionali.** Con la regola «il percorso resta dell'elemento a cui è stato dato», un nome corretto su
  un altro elemento lascia quest'ultimo con il suffisso. Mitigazione: `wp wmwp detail-paths forget`.
- **Test sul sito vero.** I test del plugin girano con `wp eval-file` sul sito su cui si lanciano e ne salvano e
  ripristinano option e transient (`tests/php/detail-route.php:27-44`); righe di prova nella tabella vera
  riserverebbero percorsi. Mitigazione: tabella a parte per i test.

Accettati come ipotetici: un omonimo nuovo che arriva proprio nel giro in cui l'originale manca da una risposta parziale
dello shard accettata da `wmwp_detail_index()`; slug diversi a seconda della lingua di chi ricostruisce l'indice (solo con lingue come il tedesco in WPML);
perdita dello storico in produzione eliminando il plugin; ritorno alla 0.2.0 dopo i 301 della 0.3.0, che restano nella
cache dei browser (oggi solo team e cliente, nessun ambiente indicizzato); nessun interruttore per spegnere lo storico
senza un rilascio.

## Out of scope

- Redirect dei vecchi indirizzi Drupal (sardegnasentieri.it) di sentieri e POI: deciso in oc:8969, niente 301.
- Pagine sotto un dettaglio (`/<base>/<slug>/<altro>/`) che danno 404: dalla consulenza SEO del 12/11, «da decidere
  prima del lancio».
- Pulizia automatica dello storico: le righe restano finché il plugin non viene eliminato.
- Una pagina in `wp-admin` per vedere o modificare lo storico: le correzioni passano dal comando WP-CLI.
- Pulizia delle altre option del plugin all'eliminazione: `uninstall.php` cancella solo la tabella e la sua option.
- `_drupal_nid` di news, eventi e «da sapere» in `wp-forestas`: sono post di WordPress, con id e slug propri.

## Moduli toccati

**wp-package** (questo repo)

- `includes/detail-history.php` (nuovo): tabella, schema e versione, lettura e scrittura dello storico, chiave
  dell'elemento, protezioni (chiave crollata, shard azzerato), righe del primo giro, basi delle regole, comando
  `forget`, `wpmu_drop_tables`.
- `includes/class-wmwp-detail-paths-command.php` (nuovo): comando WP-CLI, caricato solo con WP-CLI.
- `includes/detail-index.php`: `wmwp_detail_build_index()` con lo storico, le regole nuove dei doppioni, la chiave
  ripetuta e `suffixes`; `wmwp_detail_index()` e la ricostruzione in `wmwp_detail_index_rebuild()`.
- `includes/detail-route.php`: `wmwp_detail_find()` / `wmwp_detail_resolve()` con lo storico prima dell'altra lingua e
  del ripiego per slug, tipi della base dallo storico; basi delle regole in `wmwp_detail_bases()`.
- `includes/detail-render.php`: `wmwp_detail_slug_matches()` accetta solo il suffisso dato dall'indice (correzione
  della review, fuori dal piano).
- `index.php`: versione 0.3.0, `require` dei file nuovi, registrazione del comando.
- `uninstall.php` (nuovo).
- `tests/php/history-sandbox.php` (nuovo), `tests/php/detail-history.php` (nuovo), `tests/php/detail-index.php`,
  `tests/php/detail-route.php`, `tests/php/detail-cache.php`, `tests/php/detail-render.php`; una riga di `require`
  della sandbox in `tests/php/detail-aws.php`, `detail-base.php`, `detail-seo.php`, `detail-sitemap.php`.
- `README.md`, `CHANGELOG.md`, `docs/knowledge/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress.md`.

**wp-forestas** — vedi `docs/features/8983-…/overview.md` in quel repo: commento di `forestas_dettaglio_nid()` nel
child, `WP_PACKAGE_REF` in `docker/scripts/init-wordpress.sh`, pagina di conoscenza 8969, howto
`ricreare-wordpress-da-zero.md`.

**forestas** — solo il puntatore di `wp-forestas`, su indicazione del dev.
