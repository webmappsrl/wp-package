> Ticket: oc:8969

# Pagine di dettaglio di tracce e POI — piano del plugin wp-package

> **Per chi esegue:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (consigliato) o
> superpowers:executing-plans. I passi usano le caselle (`- [ ]`). **Nessun `git commit`, `git push` o
> branch senza istruzione esplicita di Rubens**: i commit qui sotto sono istruzioni per lui.

**Obiettivo:** il plugin serve le pagine di dettaglio di EcTrack ed EcPoi di uno shard, senza post
WordPress, con indirizzi `/<base>/<slug>/` per lingua, SEO, `hreflang` e sitemap, su qualsiasi tema.

**Architettura:** un indice `percorso → elemento` per lingua, costruito dalla lista Elasticsearch delle
tracce e da `pois.geojson`, sta in un transient e si ricostruisce quando un indirizzo non si trova.
Una rewrite per le sole basi in uso passa il percorso a un dispatcher. Il dispatcher risolve, legge il
JSON dello shard con una cache breve (60 s), controlla nome ↔ slug e stampa un template sostituibile dal tema.
SEO, WPML e sitemap usano il core di WordPress, con piccoli adattatori per chi stampa già i meta.

**Tecnologie:** WordPress ≥ 6.3, PHP ≥ 7.4 (intestazione di `index.php`), WPML facoltativo, test PHP con
`wp eval-file`, test JS con `node --test`.

**Spec:** `docs/features/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress/overview.md`
di questo repo; la parte di Forestas e le decisioni stanno in
`wp-forestas/docs/features/8969-…/overview.md` e `notes.md`.

## Vincoli globali

- Il plugin è generico: niente nomi, chiavi o colori di Forestas. Niente dipendenze da Impreza, Yoast o
  Rank Math: gli adattatori si accendono solo se quel tema o plugin c'è.
- PHP 7.4: niente `match`, niente tipi unione, niente proprietà promosse.
- Testi con text domain `wp-package`; commenti e documentazione in italiano.
- Prefisso `wmwp_` per funzioni, opzioni, transient, query var e filtri nuovi.
- Dati sempre attuali: il JSON del dettaglio si legge **a ogni richiesta**, nessuna cache del JSON.
  > **Divergenza (12/11):** cache breve del JSON, 60 s (`wmwp_detail_cache_ttl`), decisa dopo la consulenza SEO: le
  > visite e i crawler sulla stessa pagina non chiamano lo shard ogni volta. Se il JSON in cache non torna con lo slug
  > (rinomina), si rilegge dallo shard prima di ricostruire l'indice.
  > **Divergenza (12/11, Rubens):** la traccia si legge dal file su AWS (`<awsApi>/tracks/<id>.json`), come in
  > produzione, con la richiesta condizionale su ETag/Last-Modified; l'API dello shard resta per i POI e per il file mancante.
  Si mette in cache solo l'indice.
- Un indirizzo non mostra mai un elemento diverso da quello richiesto.
- Lingue: nella pagina inglese il nome inglese dell'elemento e del suo tipo, l'italiano dove manca
  (vuoto o solo spazi e tabulazioni, come capita nei nomi dell'API).
- Verifiche sempre in locale: WordPress `http://localhost:8080`, shard `http://localhost:8000`
  (allineato al dev il 12/11). Il plugin in locale è un link simbolico montato in sola lettura:
  `wp i18n` e ogni comando che scrive nel plugin girano su una copia (CLAUDE.md di wp-forestas).
- Comando dei test PHP: `docker exec -w /var/www/html wordpress-forestas wp eval-file wp-content/plugins/wp-package/tests/php/<file>.php --allow-root`
  (stampa `PASS` o `FAIL: …`, come i test esistenti). Test JS: `node --test tests/js/*.test.js`.

## Da controllare in review

1. **Nome che diventa slug vuoto** (solo punteggiatura o emoji): lo slug diventa l'id
   (`/sentiero/123/`), mai `/sentiero//`. Test nel Task 1.
2. **Shard irraggiungibile o risposta parziale mentre si ricostruisce l'indice:** resta l'indice
   vecchio, non si salva mai un indice vuoto o con meno della metà degli elementi del precedente.
   Test nel Task 3.
3. **Bot che chiedono slug inventati:** al massimo una ricostruzione ogni 5 minuti; le altre richieste
   rispondono 404 senza chiamare lo shard. Test nel Task 3.
4. **Pagina vera sotto una base** (es. una pagina figlia `/punti-di-interesse/mappa/`): la serve
   WordPress, non il dettaglio. Test nel Task 4.
5. **File dell'id passato a un'altra traccia** (reset notturno, rigenerazione in corso): il controllo
   nome ↔ slug ricostruisce l'indice una volta e, se non torna, risponde 404. Test nel Task 4.

---

### Task 1: slug e indice

**File:**
- Create: `includes/detail-index.php`
- Test: `tests/php/detail-index.php`
- Modify: `index.php` (require dei file nuovi, dopo `settings.php`)

**Interfacce:**
- Produce:
  - `wmwp_detail_text( $names, string $lang ): string`: nome nella lingua, con ripiego `it` e poi la
    prima lingua non vuota; `trim()` anche di tabulazioni; `''` se non c'è niente. Accetta un array per
    lingua o una stringa.
  - `wmwp_detail_slug( string $name, int $id ): string`: `sanitize_title( $name )`, oppure `(string) $id`
    se esce vuoto.
  - `wmwp_detail_build_index( array $items, callable $base ): array`.
    - `$items`: lista di `[ 'type' => 'track'|'poi', 'id' => int, 'names' => array, 'kind' => array|null ]`,
      dove `kind` sono i nomi per lingua del `route-type` o del `poi-type`.
    - `$base`: `function( string $type, ?array $kind, string $lang ): string`.
    - Restituisce `[ 'langs' => string[], 'paths' => [ lang => [ "base/slug" => [ type, id ] ] ], 'urls' => [ type => [ id => [ lang => "base/slug" ] ] ], 'count' => int ]`.
  - Doppioni: dentro la stessa lingua e la stessa base, **tutti** gli elementi con lo stesso slug
    prendono `-<id>`; gli altri restano senza.
  - Lingue: con WPML `apply_filters( 'wpml_active_languages', null )`, altrimenti la lingua del sito
    (`substr( get_locale(), 0, 2 )`).

- [ ] **Passo 1: test che fallisce** — `tests/php/detail-index.php` con questi controlli:
  - `wmwp_detail_text( [ 'it' => "\tSalita\t", 'en' => '' ], 'en' ) === 'Salita'`;
  - `wmwp_detail_slug( '— !!', 77 ) === '77'`;
  - due tracce con il nome «Monte Corrasi» sotto la base `sentiero` diventano
    `sentiero/monte-corrasi-1` e `sentiero/monte-corrasi-2`, mentre una terza «Monte Corrasi» con base
    `itinerario` resta `itinerario/monte-corrasi`;
  - una traccia con `names = [ 'it' => 'Salita', 'en' => 'Climb' ]` e
    `kind = [ 'it' => 'Sentiero', 'en' => 'Trail' ]` ha `urls['track'][5]` uguale a
    `[ 'it' => 'sentiero/salita', 'en' => 'trail/climb' ]`;
  - senza nome inglese, `en` vale `sentiero/salita`.

  Per i test passa una callable `$base` finta che usa `sanitize_title( wmwp_detail_text( $kind, $lang ) )`.
- [ ] **Passo 2:** lancia il test e verifica che dia `FAIL` (funzioni non definite).
- [ ] **Passo 3:** implementa le tre funzioni in `includes/detail-index.php` e il require in `index.php`.
- [ ] **Passo 4:** rilancia il test e verifica che dia `PASS`.
- [ ] **Passo 5 (commit, istruzione per Rubens):** `feat(oc:8969): slug e indice dei dettagli`.

### Task 2: impostazioni delle basi

> ⚠️ L'implementazione ha deviato da questo task: `wp-forestas/docs/features/8969-…/notes.md`, «Divergenze dal piano, task per task».

**File:**
- Modify: `includes/settings.php` (registrazione e campi nella pagina «WP Package»)
- Create: `includes/detail-base.php`
- Test: `tests/php/detail-base.php`

**Interfacce:**
- Opzioni nuove:
  - `wmwp_detail_tracks_use_page` e `wmwp_detail_pois_use_page` (bool, predefinito `false`);
  - `wmwp_detail_tracks_page` e `wmwp_detail_pois_page` (int, id della pagina).
  - Il tema le può imporre con `pre_option_<nome>`, senza id nel database (è così che lo fa Forestas).
- Produce `wmwp_detail_base( string $type, ?array $kind, string $lang ): string`:
  - **interruttore acceso e pagina pubblicata:** il percorso della pagina nella lingua, cioè
    `get_page_uri()` della traduzione WPML (`wpml_object_id`) se c'è, altrimenti della pagina;
  - **altrimenti:** `sanitize_title( wmwp_detail_text( $kind, $lang ) )`, con ripiego `track` per le
    tracce e `poi` per i POI.

- [ ] **Passo 1: test che fallisce**, con questi controlli:
  - con l'interruttore spento, `wmwp_detail_base( 'track', [ 'it' => 'Sentiero', 'en' => 'Trail' ], 'en' ) === 'trail'`;
  - con `kind = null`, la base è `track` per una traccia e `poi` per un POI;
  - con `pre_option_wmwp_detail_pois_use_page` a `1` e `pre_option_wmwp_detail_pois_page` uguale
    all'id di una pagina creata nel test con slug `punti-di-interesse-test`, la base è
    `punti-di-interesse-test`;
  - alla fine il test cancella la pagina.
- [ ] **Passo 2:** verifica che dia `FAIL`.
- [ ] **Passo 3:** implementa `wmwp_detail_base()` e i campi della pagina delle impostazioni. Per ogni
  tipo servono la checkbox «Indirizzo sotto una pagina» e `wp_dropdown_pages()`. La select si
  disattiva quando la checkbox è spenta, e si salva con `absint`.
- [ ] **Passo 4:** verifica che dia `PASS`, e controlla a vista la pagina `/wp-admin/options-general.php?page=wp-package`.
- [ ] **Passo 5 (commit):** `feat(oc:8969): basi degli indirizzi dei dettagli nelle impostazioni`.

### Task 3: lettura dei dati e cache dell'indice

> ⚠️ L'implementazione ha deviato da questo task: `wp-forestas/docs/features/8969-…/notes.md`, «Divergenze dal piano, task per task».

**File:**
- Modify: `includes/detail-index.php`
- Test: `tests/php/detail-cache.php`

**Interfacce:**
- Consuma `wmwp_configured_api_urls()` (`settings.php`), `wmwp_elastic_url()` e `wmwp_app_param()`.
- Produce:
  - `wmwp_detail_fetch_items(): ?array`. Legge la lista Elasticsearch della ricerca (id, `name`,
    `terms.route-type[0].name`) e `<origin>/api/v2/app/<app>/pois.geojson`, dove la risposta può essere
    una stringa JSON da decodificare una seconda volta (dev, 12/11), con `id`, `name` e
    `taxonomies.poi-type[0].name`. Usa `wp_remote_get` con timeout 10 s. Restituisce `null` se una delle
    due chiamate fallisce.
  - `wmwp_detail_index( bool $rebuild = false ): ?array`. Transient `wmwp_detail_index`, durata 1 ora.
    La ricostruzione richiede che il lock `wmwp_detail_index_lock` (60 s) sia libero.
  - `wmwp_detail_rebuild_allowed(): bool`. Restituisce `false` se l'ultima ricostruzione forzata
    (opzione `wmwp_detail_index_rebuilt_at`) ha meno di 300 s.
  - Regola di salvataggio: un indice nuovo si salva solo se `count > 0` e se
    `count >= 0.5 * count_precedente`. Altrimenti resta il vecchio e si registra un `error_log`.
- Filtro `wmwp_detail_items` sugli elementi, prima della costruzione dell'indice.

- [ ] **Passo 1: test che fallisce.** Intercetta le chiamate con `pre_http_request`, che restituisce
  fixture minime: 3 tracce e 2 POI. Controlli:
  - il primo `wmwp_detail_index()` ha `count === 5`;
  - con lo shard che risponde 500, `wmwp_detail_index( true )` restituisce ancora l'indice da 5;
  - con lo shard che restituisce una sola traccia, l'indice resta da 5;
  - due `wmwp_detail_index( true )` di fila: il secondo non chiama lo shard (contatore delle chiamate
    fermo).
- [ ] **Passo 2:** verifica che dia `FAIL`.
- [ ] **Passo 3:** implementa.
- [ ] **Passo 4:** verifica che dia `PASS`. Poi prova contro lo shard locale:
  `wp eval 'echo wmwp_detail_index(true)["count"];' --allow-root` deve stampare `1745` (769 tracce +
  976 POI, dati del dev del 12/11).
- [ ] **Passo 5 (commit):** `feat(oc:8969): indice dei dettagli dalle API dello shard`.

### Task 4: rewrite, risoluzione e redirect

> ⚠️ L'implementazione ha deviato da questo task: `wp-forestas/docs/features/8969-…/notes.md`, «Divergenze dal piano, task per task».

**File:**
- Create: `includes/detail-route.php`
- Test: `tests/php/detail-route.php`

**Interfacce:**
- Query var: `wmwp_detail_path` (rewrite), `wmwp_detail` e `wmwp_id` (indirizzo per id).
- `wmwp_detail_bases(): array`: basi distinte dell'indice in tutte le lingue.
- Regola: `^(<basi con preg_quote, unite da |>)/([^/]+)/?$` → `index.php?wmwp_detail_path=$matches[1]/$matches[2]`,
  in cima (`'top'`). La firma (lista delle basi più `WMWP_VERSION`) sta nell'opzione
  `wmwp_detail_rules_signature`; quando la firma cambia, `flush_rewrite_rules( false )`. Con WPML in
  modalità directory, `/en/` lo toglie WPML prima delle regole.
- `wmwp_detail_resolve( string $lang, string $path ): array` restituisce
  `[ 'status' => 200|301|404, 'type' => …, 'id' => …, 'location' => … ]`:
  - **200:** il percorso è nell'indice della lingua;
  - **301:** il percorso è nell'indice di un'altra lingua, oppure la base è diversa da quella attuale
    dell'elemento. La destinazione è l'URL attuale nella lingua della richiesta;
  - **404:** il percorso non si trova. Se `wmwp_detail_rebuild_allowed()`, prima si ricostruisce l'indice
    una volta e si riprova.
- `wmwp_detail_url( string $type, int $id, string $lang ): string`: URL assoluto, con il prefisso di
  lingua di WPML (`wpml_permalink` su `home_url( '/<percorso>/' )`), oppure `''`.
- `request` filter: se esiste una pagina vera con quel percorso (`get_page_by_path`), si toglie
  `wmwp_detail_path` e WordPress serve la pagina.
- `template_redirect` a priorità 1:
  - con `wmwp_detail` + `wmwp_id`, 301 a `wmwp_detail_url()`, oppure 404;
  - con `wmwp_detail_path`, `wmwp_detail_resolve()` e, se serve, `wp_safe_redirect( …, 301 )`.

- [ ] **Passo 1: test che fallisce**, con l'indice delle fixture del Task 3:
  - `wmwp_detail_resolve( 'it', 'sentiero/salita' )['status'] === 200`;
  - `wmwp_detail_resolve( 'en', 'sentiero/salita' )` dà 301 verso un URL che finisce con
    `/en/trail/climb/`, se WPML è attivo;
  - `wmwp_detail_resolve( 'it', 'itinerario/salita' )` dà 301 verso `/sentiero/salita/`;
  - `wmwp_detail_resolve( 'it', 'sentiero/inventato' )` dà 404;
  - con una pagina vera `sentiero/pagina-vera` creata nel test, `apply_filters( 'request', [ 'wmwp_detail_path' => 'sentiero/pagina-vera' ] )`
    non contiene più `wmwp_detail_path`.
- [ ] **Passo 2:** verifica che dia `FAIL`.
- [ ] **Passo 3:** implementa.
- [ ] **Passo 4:** verifica che dia `PASS`. Poi, in locale:
  - `curl -sI localhost:8080/?wmwp_detail=track\&wmwp_id=311` dà `301` con `Location: …/cammino/<slug>/`;
  - `curl -sI localhost:8080/cammino/inventato-xyz/` dà `404`.
- [ ] **Passo 5 (commit):** `feat(oc:8969): indirizzi dei dettagli e redirect`.

### Task 5: lettura del JSON, controllo nome ↔ slug e template

> ⚠️ L'implementazione ha deviato da questo task: `wp-forestas/docs/features/8969-…/notes.md`, «Divergenze dal piano, task per task».

**File:**
- Create: `includes/detail-render.php`, `templates/track.php`, `templates/poi.php`, `templates/detail-unavailable.php`
- Test: `tests/php/detail-render.php`

**Interfacce:**
- `wmwp_detail_fetch( string $type, int $id ): array|WP_Error`:
  - legge `<origin>/api/ec/track/<id>` oppure `<origin>/api/ec/poi/<id>`, a ogni richiesta, con timeout 5 s
    (divergenza: cache di 60 s, transient `wmwp_detail_json_<tipo>_<id>`, mai per gli errori);
  - decodifica due volte se la risposta è una stringa JSON;
  - restituisce un `WP_Error` se lo status non è 200 o il corpo non è una Feature.
- `wmwp_detail_current(): ?array`: il dettaglio della richiesta, letto una volta sola, con le chiavi
  `type`, `id`, `lang`, `path`, `url`, `urls` (lingua → URL), `feature` (il JSON).
- Controllo nome ↔ slug: lo slug richiesto deve essere uguale a
  `wmwp_detail_slug( wmwp_detail_text( feature.properties.name, lang ), id )`, oppure a quello con
  `-<id>`. Se non torna: ricostruzione dell'indice (se permessa), nuova risoluzione, e se ancora non
  torna 404.
- Al render:
  - `pre_handle_404` restituisce `true` e lo stato è 200;
  - `template_include` usa `wmwp_locate_template( '<type>.php' )`;
  - `body_class` aggiunge `wmwp-detail` e `wmwp-detail-<type>`.
- Shard che non risponde: stato 503, header `Retry-After: 120`, template `detail-unavailable.php`.
- I template predefiniti contengono `get_header()`, il nome (`h1`), la mappa e la descrizione, poi
  `get_footer()`. La mappa è `do_shortcode( '[wmwp_map tracks="<id>"]' )` per una traccia e
  `[wmwp_map geojson="<origin>/api/ec/poi/<id>"]` per un POI. Il dettaglio arriva al template come
  variabile `$detail`, cioè `wmwp_detail_current()`.
- Il template regge le chiavi mancanti: il JSON di ripiego del package può non avere i dati dello
  shard (note di wp-forestas, «Rimandato»).

- [ ] **Passo 1: test che fallisce**, con `pre_http_request` che restituisce una Feature finta:
  - una Feature con nome «Salita» per `sentiero/salita` è valida;
  - una Feature con nome «Altro nome» porta a 404 quando la ricostruzione è già stata fatta;
  - con lo shard a 500, il risultato è un `WP_Error` e lo stato è 503.
- [ ] **Passo 2:** verifica che dia `FAIL`.
- [ ] **Passo 3:** implementa.
- [ ] **Passo 4:** verifica che dia `PASS`. Poi, con il tema Twenty Twenty-Five attivo in locale
  (`wp theme activate twentytwentyfive --allow-root`, e alla fine si riattiva `forestas-child`):
  `curl -s localhost:8080/cammino/<slug>/` dà 200 e contiene il nome e la mappa.
- [ ] **Passo 5 (commit):** `feat(oc:8969): pagina di dettaglio con template sostituibile`.

### Task 6: SEO senza dipendenze

> ⚠️ L'implementazione ha deviato da questo task: `wp-forestas/docs/features/8969-…/notes.md`, «Divergenze dal piano, task per task».

**File:**
- Create: `includes/detail-seo.php`
- Test: `tests/php/detail-seo.php`

**Interfacce:**
- `wmwp_detail_meta( array $detail ): array` restituisce `[ 'title', 'description', 'image', 'url', 'locale' ]`:
  - `description`: dalla descrizione nella lingua, senza tag, tagliata a 160 caratteri sulla parola;
  - `image`: l'URL di `feature_image`;
  - il tutto passa dal filtro `wmwp_detail_meta`.
- Il filtro `wmwp_detail_print_meta` (predefinito `true`) spegne la stampa del plugin.
- In `wp_head`:
  - `<meta name="description">`, `og:title`, `og:description`, `og:image`, `og:url`, `og:locale`,
    `og:type=website`;
  - `<link rel="canonical">` sull'URL attuale;
  - si toglie `rel_canonical` del core.
- `pre_get_document_title` restituisce il titolo più il nome del sito, come il core.
- Adattatori, ognuno attivo solo se c'è la funzione o la costante del tema o plugin:
  - **Impreza (`us_meta_tags`):** sui dettagli restituisce i meta del plugin al posto di quelli della
    pagina madre, e il plugin non stampa i suoi;
  - **Yoast (`WPSEO_VERSION`):** `wpseo_frontend_presenters` restituisce `[]` sui dettagli;
  - **Rank Math (`RANK_MATH_VERSION`):** `rank_math/head` rimosso sui dettagli.

- [ ] **Passo 1: test che fallisce.** `wmwp_detail_meta()` su una Feature finta: la descrizione è
  tagliata a 160 caratteri senza HTML, e `url` è uguale a `wmwp_detail_url()`.
- [ ] **Passo 2:** verifica che dia `FAIL`.
- [ ] **Passo 3:** implementa.
- [ ] **Passo 4:** verifica che dia `PASS`. Poi controlla il sorgente HTML della pagina servita, sia con
  Impreza (forestas-child) sia con Twenty Twenty-Five. Il comando
  `curl -s localhost:8080/cammino/<slug>/ | grep -cE 'rel="canonical"|name="description"|property="og:title"'`
  deve dare `3`: un tag per tipo, nessun doppione.
- [ ] **Passo 5 (commit):** `feat(oc:8969): SEO delle pagine di dettaglio`.

### Task 7: WPML e sitemap

> ⚠️ L'implementazione ha deviato da questo task: `wp-forestas/docs/features/8969-…/notes.md`, «Divergenze dal piano, task per task».

**File:**
- Create: `includes/detail-wpml.php`, `includes/class-wmwp-detail-sitemap-provider.php`
- Test: `tests/php/detail-sitemap.php`

**Interfacce:**
- **WPML** (solo se `defined( 'ICL_SITEPRESS_VERSION' )`):
  - `wpml_hreflangs`: sui dettagli restituisce `[ codice => wmwp_detail_url( type, id, lingua ) ]` più
    `x-default` (lingua predefinita);
  - `icl_ls_languages`: imposta `url` di ogni lingua con lo stesso URL.
- **Sitemap:** `WMWP_Detail_Sitemap_Provider extends WP_Sitemaps_Provider`, con nome `wmwpdetail` e
  sottotipi `track` e `poi`. `get_url_list( $page, $subtype )` restituisce `[ 'loc' => URL ]` per ogni
  elemento e lingua dell'indice, 2000 per pagina. La registra `wp_register_sitemap_provider()` su `init`.

- [ ] **Passo 1: test che fallisce.** Con l'indice delle fixture: il provider restituisce `count` uguale
  a (tracce × lingue) per il sottotipo `track`, e ogni `loc` inizia con `home_url()`.
- [ ] **Passo 2:** verifica che dia `FAIL`.
- [ ] **Passo 3:** implementa.
- [ ] **Passo 4:** verifica che dia `PASS`. Poi, in locale, con `wp option update blog_public 1`
  (e alla fine si rimette `0`):
  - `curl -s localhost:8080/wp-sitemap.xml` elenca `wmwpdetail-track` e `wmwpdetail-poi`;
  - il sorgente di `/cammino/<slug>/` ha due `hreflang` con URL diversi;
  - il selettore di lingua porta a `/en/walk/<slug-en>/`.
- [ ] **Passo 5 (commit):** `feat(oc:8969): hreflang, selettore di lingua e sitemap dei dettagli`.

### Task 8: link della ricerca, documentazione, traduzioni

**File:**
- Modify: `includes/search.php`, cioè il `link_template` predefinito della source `tracks` in
  `wmwp_search_sources()`
- Modify: `tests/js/source-tracks.test.js`
- Modify: `README.md`, `CHANGELOG.md`, `languages/` (`.pot`, `.po` it, `.mo`, JSON), `index.php`
  (versione `0.2.0`)

**Interfacce:**
- `link_template` predefinito: `add_query_arg( [ 'wmwp_detail' => 'track', 'wmwp_id' => '{id}' ], wmwp_detail_home() )`,
  dove `wmwp_detail_home()` è la home nella lingua corrente (WPML `wpml_home_url`, altrimenti `home_url( '/' )`).
  Con WPML in modalità directory la lingua è nel percorso, quindi l'id redirect del Task 4 risponde
  nella lingua giusta. `{id}` resta letterale: `add_query_arg` non lo codifica, e il test lo controlla.

- [ ] **Passo 1: test JS che fallisce** in `tests/js/source-tracks.test.js`: con
  `link_template = 'http://x/?wmwp_detail=track&wmwp_id={id}'`, la card dell'hit 473 ha
  `url === 'http://x/?wmwp_detail=track&wmwp_id=473'`.
- [ ] **Passo 2:** `node --test tests/js/source-tracks.test.js` deve fallire solo se il JS non sostituisce.
  Se passa già, il test resta come protezione e si va avanti.
- [ ] **Passo 3:** imposta il predefinito in `includes/search.php`. Poi la documentazione:
  - README, sezione «Interfaccia pubblica»: le opzioni, i filtri `wmwp_detail_items`, `wmwp_detail_meta`
    e `wmwp_detail_print_meta`, i template `track.php`, `poi.php` e `detail-unavailable.php`,
    l'indirizzo per id, la sitemap, il requisito della rewrite;
  - CHANGELOG `0.2.0`;
  - traduzioni rigenerate **su una copia** del plugin.
- [ ] **Passo 4:** in locale, la ricerca `/cerca-nel-sito/` (o la pagina della ricerca) ha i link delle
  righe che portano al dettaglio. Si prova un clic con il browser, sia in italiano sia in inglese.
- [ ] **Passo 5 (commit):** `feat(oc:8969): link della ricerca ai dettagli, README e traduzioni`.
