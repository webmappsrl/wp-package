> Ticket: oc:8969

# Pagine di dettaglio di percorsi e punti di interesse sul sito WordPress

Questa è la parte generica, valida per ogni shard. La parte di Forestas (template secondo il wireframe,
differenze per tipo, slug della pagina «Punti di interesse») sta in
`wp-forestas/docs/features/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress/overview.md`.

## Cosa cambia

Il plugin oggi fa solo la ricerca unica e la mappa: il `link_template` della source `tracks` è vuoto
(`includes/search.php:115`), e sincronizzazione, tipi di post `track`/`poi` e shortcode di dettaglio di
wp-geohub erano rimasti fuori (README).

Dopo questo lavoro il plugin serve le pagine di dettaglio di EcTrack ed EcPoi **senza creare post**:

1. una rewrite riconosce `/<base>/<slug>/` (e `/en/<base>/<slug>/` con WPML) e passa base e slug in
   query var. La base si sceglie nelle impostazioni del plugin, separatamente per le tracce e per i
   POI:
   - **dal tipo** (predefinito): per una traccia il nome del termine `route-type` passato in
     `sanitize_title` (`/sentiero/`, `/itinerario/`, `/cammino/`), `/track/` se la traccia non ha
     `route-type`, come faceva wp-geohub; per un POI il nome del suo `poi-type`, `/poi/` se manca;
   - **da una pagina**: un interruttore e una select delle pagine di WordPress; il percorso della
     pagina scelta diventa la base di tutti gli elementi (es. `/punti-di-interesse/<slug>/`);
2. lo slug si risolve in id con una tabella `slug → id` per lingua, costruita dalla lista delle tracce
   (la risposta dell'API Elasticsearch che la ricerca usa già, con id e nome) e dai POI di
   `pois.geojson`; slug = `sanitize_title(nome)` nella lingua della pagina, con `-<id>` sui doppioni;
   la tabella sta in un transient. **Base e slug seguono la lingua**: nella pagina inglese si usano il
   nome inglese della traccia o del POI e quello del suo `route-type` o `poi-type`, e dove l'inglese
   manca quello italiano (es. `/en/sentiero/salita-sul-monte-corrasi-b-401/` finché manca la
   traduzione, `/en/trail/climb-to-monte-corrasi-b-401/` quando ci sono tutte e due);
3. con l'id il plugin legge il JSON della traccia dal file pubblicato su AWS (`<awsApi>/tracks/<id>.json`,
   con la richiesta condizionale su ETag/Last-Modified; se il file manca, `<origin>/api/ec/track/<id>`)
   o il dettaglio del POI dallo shard (`<origin>/api/ec/poi/<id>`), con una cache breve di 60 secondi
   (filtro `wmwp_detail_cache_ttl`, 0 la spegne). Quello che il dettaglio porta già dentro (termini, sentieri correlati con la card, POI
   della traccia) si usa così com'è; si cerca per id in una lista solo ciò che il dettaglio non ha (es.
   il nome degli enti);
4. stampa un template sostituibile dal tema (`wp-package/track.php`, `wp-package/poi.php`) con i dati
   già letti, e imposta titolo, descrizione e canonical dal JSON.

Lo slug inesistente risponde 404. Le card della ricerca portano al dettaglio con un indirizzo per id
(`?wmwp_detail=track&wmwp_id=<id>`), che il plugin manda con un 301 all'indirizzo con base e slug
nella lingua della pagina: il JavaScript della ricerca non può calcolare lo slug come PHP (doppioni,
`sanitize_title`), così c'è una sola fonte.

## Perché

Il dettaglio è il passo dopo la ricerca (oc:8952) per ogni shard che usa il plugin. Le pagine
dinamiche, sul modello del vecchio sito di Sentiero Italia (`template-tappa-details.php`), evitano di
duplicare migliaia di tracce in WordPress e di tenerle allineate con un cron; leggere le API invece del
database tiene il plugin fuori dallo schema dello shard e non mette credenziali del DB nei temi. Lo
slug si ricava in WordPress perché id e nome ci sono già: al backend non serve nessuna modifica.

## Requisiti

- [ ] La pagina mostra sempre i dati attuali del backend: un elemento aggiunto, modificato o cancellato
      (anche da un reset dello shard che riassegna gli id) si vede nel dettaglio entro un minuto (la
      cache breve del JSON), senza interventi, e un indirizzo non mostra mai un elemento diverso da quello richiesto.
- [ ] Nella pagina delle impostazioni del plugin («WP Package»), per le tracce e per i POI: interruttore
      «Indirizzo sotto una pagina» e select delle pagine. Spento, la base viene dal tipo (`route-type`
      con ripiego `track`, `poi-type` con ripiego `poi`); acceso, dal percorso della pagina scelta.
- [ ] Un elemento chiesto con la base sbagliata (es. la traccia ha cambiato `route-type`) risponde con
      un 301 all'indirizzo giusto, mai con la pagina sotto la base vecchia.
- [ ] Rewrite e query var registrate solo per le basi in uso (quelle ricavate dai dati e la pagina
      scelta), così le pagine esistenti con lo stesso primo pezzo di indirizzo non vengono
      intercettate; con la base da una pagina, la pagina continua a servire il suo contenuto e il
      dettaglio risponde solo con lo slug dopo. Regole rigenerate quando cambiano le impostazioni o
      compare una base nuova nei dati.
- [ ] Tabella `slug → id` per tracce e POI in transient; a uno slug non trovato si ricostruisce una
      volta prima del 404, così una traccia appena pubblicata è subito raggiungibile.
- [ ] Doppioni dello slug risolti con `-<id>` sui soli elementi in conflitto; una sola funzione
      produce lo slug, per la tabella, la sitemap e il redirect dell'indirizzo per id.
- [ ] Base e slug per lingua: nella lingua della pagina se il nome c'è, altrimenti in italiano. Anche
      questo è dinamico: quando nel backend arriva o cambia una traduzione, l'indirizzo della pagina in
      quella lingua passa al nome nuovo senza interventi.
- [ ] L'indirizzo che non è più quello giusto (es. slug italiano sotto `/en/` dopo che è arrivata la
      traduzione, o slug dell'altra lingua) risponde con un 301 all'indirizzo attuale, se lo slug si
      trova nelle tabelle; altrimenti 404.
- [ ] Ogni dettaglio collega le sue versioni nelle due lingue (`hreflang` e selettore di lingua di
      WPML), ciascuna con il suo indirizzo.
- [ ] Lettura del JSON della traccia e del dettaglio del POI con timeout; se lo shard non
      risponde, una pagina d'errore con stato 503, non un 404 e non una pagina vuota.
- [ ] Template predefiniti minimi (nome, mappa, descrizione) sostituibili copiandoli in `wp-package/`
      nel tema, come quelli della ricerca; i dati arrivano al template già letti, in una variabile.
- [ ] SEO di ogni dettaglio, come una pagina vera, **con il solo core di WordPress, su qualsiasi tema**:
      il plugin non dipende da Impreza né da un plugin SEO.
      - titolo del documento (`pre_get_document_title`);
      - meta description e anteprima per i social (`og:title`, `og:description`, `og:image`,
        `og:url`, `og:locale`) dal JSON, nella lingua della pagina, stampati dal plugin in `wp_head`;
      - canonical sull'indirizzo attuale del dettaglio (il core la stampa solo per i post);
      - dati del dettaglio esposti con un filtro (`wmwp_detail_meta`) e stampa disattivabile, così un
        tema o un plugin SEO può prendere il posto del plugin;
      - niente doppioni: dove il tema o un plugin SEO stampa già i suoi meta (Impreza, Yoast, Rank
        Math), sulle pagine di dettaglio il plugin passa a loro i dati o toglie i loro, con un piccolo
        adattatore attivo solo se quel tema o plugin c'è;
      - con WPML attivo, `hreflang` (`wpml_hreflangs`) e selettore di lingua (`icl_ls_languages`) con
        l'indirizzo dell'elemento nell'altra lingua, non quello della pagina madre; senza WPML, niente;
      - la pagina di dettaglio non viene indicizzata come la pagina madre.
- [ ] Sitemap dei dettagli nella sitemap del core (`wp-sitemap.xml`), con un provider registrato da
      `wp_register_sitemap_provider()`: tutte le tracce e tutti i POI con l'indirizzo in ogni lingua,
      presi dalle tabelle `slug → id`, quindi sempre allineati ai dati del backend.
- [ ] Indirizzo per id `?wmwp_detail=<track|poi>&wmwp_id=<id>` che risponde con un 301 all'indirizzo
      del dettaglio nella lingua della richiesta (404 se l'id non c'è); è il `link_template`
      predefinito della source `tracks`, quindi card, righe e card della mappa della ricerca portano
      al dettaglio senza che il tema scriva niente.
- [ ] Mappa del dettaglio con lo shortcode esistente `[wmwp_map]` (`tracks` per una traccia, `geojson`
      per un POI).
- [ ] README aggiornato nella sezione «Interfaccia pubblica» (impostazioni, filter hook, template,
      indirizzo per id, sitemap) e voce in CHANGELOG.
- [ ] Testi del plugin con text domain `wp-package`, italiano e inglese.
- [ ] Test PHP per slug, doppioni e risoluzione `slug → id` (cartella `tests/`).

## Rischi

- **Peso della lista dei POI**: `pois.geojson` sul dev pesa 5,6 MB. In cache va solo la tabella
  `slug → id`, non il file; il file si rilegge solo quando la tabella va ricostruita.
- **SEO e WPML su pagine senza post**: la canonical del core, i meta di temi e plugin SEO e il
  selettore di lingua di WPML ragionano sui post. Il plugin stampa da sé l'essenziale e si limita a
  evitare i doppioni con chi stampa già qualcosa; va provato con la pagina servita (sorgente HTML),
  non solo nel codice, e su un tema diverso da Impreza (es. Twenty Twenty-Five, già installato).
- **Sitemap spenta su dev e UAT**: la sitemap del core esiste solo con `blog_public = 1`, che su
  dev e UAT è 0 di proposito. Si prova in locale accendendolo, e in produzione si accende al lancio.
- **JSON di ripiego quando il file della traccia non c'è ancora**: `api/ec/track/<id>` restituisce il
  file pubblicato; se manca (traccia appena creata dall'import o da Nova finché il suo job non scrive
  il file, storage che non risponde, ambiente dove i file non sono mai stati generati) lo costruisce
  al volo con la classe del package (`EcTrackController::getGeojson`, che
  dichiara `Wm\WmPackage\Models\EcTrack`), quindi senza i dati che lo shard aggiunge (per Forestas:
  enti, sentieri correlati, download; allegati e video con le chiavi vecchie). La pagina in quei
  minuti esce incompleta. Correzione nel package, rimandata perché il caso è raro (note di wp-forestas): il ripiego usa il modello
  configurato (`wm-package.ec_track_model`), così esce uguale al file. Intanto il template tratta le
  chiavi mancanti come dato assente, senza errori. Il reset notturno invece non cancella i file
  (`resetData()` svuota solo tabelle): fino alla rigenerazione di fine import il sito mostra i dati
  del giorno prima, file e indice insieme; un file che nel frattempo appartiene a un'altra traccia lo
  ferma il controllo nome ↔ slug.

## Out of scope

- Sincronizzazione di tracce e POI in post WordPress (l'approccio di wp-geohub): sostituita dalle
  pagine dinamiche.
- Redirect dai vecchi indirizzi del sito precedente: dipendono da ogni shard.
- Pagine dei layer (itinerari a tappe, cammini interi).

## Moduli toccati

Repo `wp-package` (questo):

- `includes/settings.php` — interruttore e select della pagina per tracce e POI.
- `includes/detail.php` (nuovo) — calcolo delle basi, rewrite e query var, tabella
  `slug → id`, lettura del JSON, titolo, meta, canonical, `hreflang`, selettore di lingua,
  provider della sitemap, rendering del template.
- `includes/search.php` — `link_template` predefinito con l'indirizzo per id.
- `templates/track.php`, `templates/poi.php` (nuovi) — template predefiniti.
- `index.php` — caricamento del file nuovo.
- `languages/` — stringhe nuove.
- `tests/` — test di slug e risoluzione.
- `README.md`, `CHANGELOG.md`.

Repo `wp-forestas`: vedi la sua overview.
