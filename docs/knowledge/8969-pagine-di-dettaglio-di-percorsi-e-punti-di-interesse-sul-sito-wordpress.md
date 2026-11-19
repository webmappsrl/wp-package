# Pagine di dettaglio di tracce e POI

> Lavoro d'origine: oc:8969. Artefatti: [overview](../features/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress/overview.md),
> [piano](../features/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress/plan.md).
> Indirizzi stabili e storico dei percorsi: oc:8983, [overview](../features/8983-indirizzi-stabili-delle-pagine-di-dettaglio-doppioni-dello-slug-e-indirizzi-vecchi/overview.md),
> [note](../features/8983-indirizzi-stabili-delle-pagine-di-dettaglio-doppioni-dello-slug-e-indirizzi-vecchi/notes.md).
> Copia servita quando lo shard non risponde: oc:8990, [overview](../features/8990-enti-sul-sito-wordpress-elenco-enti-dettaglio-enti-slug-e-ricerca-dallapi-di-oc8965/overview.md).

## Come funziona oggi

Pagine `/<base>/<slug>/` per ogni traccia e POI dello shard, senza post in WordPress, risolte con un indice e lette
dalle API dello shard con una cache breve. Gli indirizzi restano stabili grazie a uno storico dei percorsi in una
tabella del plugin. Funzionamento, durate, risposte, funzioni, filtri e comando WP-CLI: README, sezioni «Interfaccia
pubblica», «Mappa» e «Pagine di dettaglio». Qui c'è solo il perché delle scelte.

## Perché così

- **Pagine dinamiche invece di post sincronizzati** (l'approccio di wp-geohub, scartato): i dati restano solo nello
  shard e la pagina li mostra entro un minuto da una modifica, senza un processo di sincronizzazione da tenere
  allineato. Il prezzo è l'indice da ricostruire quando i dati cambiano: per questo si ricostruisce da sé, con un
  limite di frequenza perché i bot che chiedono slug inventati non scarichino le liste a ogni richiesta.
- **Una chiave per riconoscere gli elementi**: su dev e UAT lo shard rinumera gli id a ogni reset notturno, quindi
  l'id da solo non basta. Il plugin non sa quale campo sia stabile in uno shard: di base usa `out_source_feature_id` e
  il tema lo cambia con il filtro `wmwp_detail_source` (Forestas, nel child di wp-forestas: il nid di Drupal). Chi non
  ha quel campo è riconosciuto dall'id, che dove non c'è un reset non cambia (oc:8983); una chiave ripetuta resta a un
  elemento solo, perché due elementi con lo stesso proprietario nello storico si scambierebbero gli indirizzi.
- **Un percorso resta dell'elemento a cui è stato dato** (oc:8983): un indirizzo è l'identità di una pagina per chi lo
  ha salvato e per i motori di ricerca, quindi non passa a un altro elemento finché il suo esiste. Il prezzo, accettato,
  è che un nome corretto su un altro elemento lascia quest'ultimo con il suffisso finché qualcuno non libera
  l'indirizzo a mano.
- **Doppioni con `-2`, `-3` e non con un identificativo** (oc:8983): dalla consulenza SEO del 12/11, l'indirizzo non
  espone identificativi interni, come fa WordPress con gli slug dei post. La stabilità la dà lo storico, non il suffisso.
- **Storico in una tabella propria, non in un'option** (oc:8983): cresce per sempre e si cerca un percorso alla volta;
  un'option andrebbe letta e riscritta per intero.
- **Indice scartato sempre se la chiave della fonte crolla** (oc:8983): un indice senza chiavi libererebbe i percorsi e
  farebbe scambiare l'indirizzo agli omonimi, e lo storico lo terrebbe scritto. Meglio un sito fermo all'ultimo indice
  buono, che torna a posto da solo quando le API tornano, di uno storico da correggere a mano. Per lo stesso motivo
  uno storico che non si legge tiene l'indice di prima.
- **Righe con l'id dimenticate quando lo shard viene svuotato** (oc:8983): dopo il reset di dev e UAT gli stessi id
  vanno ad altri elementi, anche a elementi creati in Nova il giorno dopo, quindi una riga con l'id non si può più
  controllare una per una. Un solo id cambiato invece non basta a dirlo: anche in produzione un elemento cancellato e
  reimportato cambia id.
- **Il 503 solo quando la fonte non risponde e una copia non c'è** (oc:8990, plugin 0.4.0): se lo shard non risponde
  (qualunque codice diverso da 200, 304 e 404: rete, timeout, 400, 401/403, 429, 5xx; o una risposta che non è un
  dettaglio) e in cache c'è già il JSON dell'elemento, la pagina esce dalla copia con 200, per al massimo 6 ore
  dall'ultimo controllo riuscito. Così un guasto dello shard non spegne le pagine di tracce e POI. La risposta ha `X-WMWP-Stale: 1` e gli header no-cache, perché una cache di pagina non
  tenga la versione vecchia oltre il guasto. Il plugin è usato da più shard, quindi il filtro
  `wmwp_detail_stale_if_error` spegne il ripiego senza un rilascio. Un elemento che la fonte dice cancellato resta un
  404, e la sua copia si cancella: un 503 lascerebbe la pagina nella sitemap e i crawler riproverebbero un elemento che
  non c'è.
- **Redirect temporaneo per l'indirizzo per id**: la destinazione cambia con una rinomina o un reset, e un 301
  resterebbe nella cache del browser. Prima del redirect si controlla l'elemento, perché la ricerca legge
  Elasticsearch dal vivo e può avere elementi più nuovi dell'indice.
- **`hreflang` stampati dal plugin**: WPML li stampa solo per gli oggetti della query, cioè i post; il filtro
  `wpml_hreflangs` previsto nel piano non basta per una pagina senza post.
- **Versione del file della traccia scelta dal tema, con ripiego** (oc:9000): lo shard scrive `tracks/<id>.json` e, se
  la dichiara, una versione accanto (`tracks/v1/<id>.json`, oc:8997 di wm-package). Il plugin è usato da shard che la
  versione non la scrivono ancora, quindi di base legge il file senza versione e il tema sceglie la sua con
  `wmwp_track_json_version`; senza quel file si legge il file senza versione, poi l'API. La copia in cache ricorda da
  quale file viene: l'ETag di un file non va mandato a un altro.
- **File su AWS e MinIO con la stessa logica**: in produzione il frontend legge i file su AWS; dev e UAT servono gli
  stessi file da MinIO, quindi il plugin non distingue gli ambienti e legge l'indirizzo `awsApi` di `environment.ts`.

## Come ci siamo arrivati

- Il controllo dell'elemento dei POI usava `out_source_feature_id` del dettaglio; quando lo shard l'ha tolto dal
  dettaglio del POI (oc:8959) il controllo non controllava più niente. La chiave è diventata un filtro, con il nid di
  Drupal che oc:8959 ha rimesso nelle API.
- La review interna di oc:8969 ha mostrato che l'indirizzo per id non controllava l'elemento e non ricostruiva
  l'indice (una traccia nuova dava 404) e che un elemento cancellato rispondeva 503: da qui il controllo prima del
  redirect e la distinzione fra 404 e 503 della fonte.
- **503 a ogni guasto della fonte** (oc:8969, superato da oc:8990): gli errori non andavano in cache, e lo shard che
  non rispondeva dava 503 anche con il JSON dell'elemento salvato da pochi minuti. Il ripiego sulla copia (fino a 6
  ore, spento dal filtro `wmwp_detail_stale_if_error`) evita che un guasto dello shard spenga le pagine.
- **Doppioni con `-<id>`, poi con `-<chiave stabile>`** (oc:8969, superati da oc:8983): l'id cambiava con il reset, e
  la chiave stabile risolveva solo quello. Restavano tre problemi: all'arrivo di un omonimo tutti i doppioni prendevano
  il suffisso, quindi anche chi c'era cambiava indirizzo; il 301 di una rinomina valeva solo finché il percorso vecchio
  era nell'indice, cioè fino alla ricostruzione successiva; il suffisso esponeva un identificativo interno.
- **Chiave crollata misurata sul numero, poi sulla quota del totale** (oc:8983, superate nelle review interne): tutte
  e due fermavano l'indice per sempre anche senza un problema delle API, la prima con la cancellazione vera di metà
  degli elementi, la seconda con la cancellazione dei soli elementi con la chiave o con un import in blocco senza
  chiave. Per questo si guardano solo gli elementi presenti in tutti e due gli indici.
