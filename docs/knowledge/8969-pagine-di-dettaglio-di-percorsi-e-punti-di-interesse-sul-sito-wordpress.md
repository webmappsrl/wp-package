# Pagine di dettaglio di tracce e POI

> Lavoro d'origine: oc:8969. Artefatti: [overview](../features/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress/overview.md),
> [piano](../features/8969-pagine-di-dettaglio-di-percorsi-e-punti-di-interesse-sul-sito-wordpress/plan.md).

## Come funziona oggi

Pagine `/<base>/<slug>/` per ogni traccia e POI dello shard, senza post in WordPress, risolte con un indice e lette
dalle API dello shard con una cache breve. Funzionamento, durate, risposte, funzioni e filtri: README, sezioni
«Interfaccia pubblica», «Mappa» e «Pagine di dettaglio». Qui c'è solo il perché delle scelte.

## Perché così

- **Pagine dinamiche invece di post sincronizzati** (l'approccio di wp-geohub, scartato): i dati restano solo nello
  shard e la pagina li mostra entro un minuto da una modifica, senza un processo di sincronizzazione da tenere
  allineato. Il prezzo è l'indice da ricostruire quando i dati cambiano: per questo si ricostruisce da sé, con un
  limite di frequenza perché i bot che chiedono slug inventati non scarichino le liste a ogni richiesta.
- **Una chiave stabile accanto all'id**: su dev e UAT lo shard rinumera gli id a ogni reset notturno. Con il solo id un
  indirizzo poteva mostrare un altro elemento e i doppioni dello slug cambiavano suffisso; la chiave riconosce
  l'elemento anche dopo il reset. Il plugin non sa quale campo sia stabile in uno shard: di base usa
  `out_source_feature_id` e il tema lo cambia con il filtro `wmwp_detail_source` (Forestas, nel child di wp-forestas:
  il nid di Drupal).
- **Il 503 solo quando la fonte non risponde**: un elemento che la fonte dice cancellato è un 404, non «riprova più
  tardi»; altrimenti la pagina resta nella sitemap e i crawler riprovano un elemento che non c'è.
- **Redirect temporaneo per l'indirizzo per id**: la destinazione cambia con una rinomina o un reset, e un 301
  resterebbe nella cache del browser. Prima del redirect si controlla l'elemento, perché la ricerca legge
  Elasticsearch dal vivo e può avere elementi più nuovi dell'indice.
- **`hreflang` stampati dal plugin**: WPML li stampa solo per gli oggetti della query, cioè i post; il filtro
  `wpml_hreflangs` previsto nel piano non basta per una pagina senza post.
- **File su AWS e MinIO con la stessa logica**: in produzione il frontend legge i file su AWS; dev e UAT servono gli
  stessi file da MinIO, quindi il plugin non distingue gli ambienti e legge l'indirizzo `awsApi` di `environment.ts`.

## Come ci siamo arrivati

- Il controllo dell'elemento dei POI usava `out_source_feature_id` del dettaglio; quando lo shard l'ha tolto dal
  dettaglio del POI (oc:8959) il controllo non controllava più niente. La chiave è diventata un filtro, con il nid di
  Drupal che oc:8959 ha rimesso nelle API.
- La review interna di oc:8969 ha mostrato che l'indirizzo per id non controllava l'elemento e non ricostruiva
  l'indice (una traccia nuova dava 404) e che un elemento cancellato rispondeva 503: da qui il controllo prima del
  redirect e la distinzione fra 404 e 503 della fonte.
