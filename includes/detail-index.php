<?php
/**
 * Pagine di dettaglio di tracce e POI (oc:8969): slug e indice.
 *
 * Le pagine non sono post: un indirizzo `/<base>/<slug>/` si risolve con un indice `percorso → elemento` per lingua,
 * costruito dalla lista delle tracce dell'API Elasticsearch e da `pois.geojson` dello shard. Lo slug viene dal nome
 * (`sanitize_title`), così al backend non serve niente; fra i doppioni nella stessa base e lingua chi ha già il percorso
 * lo tiene e gli altri prendono `-2`, `-3`… in ordine di chiave (`includes/detail-history.php`, oc:8983).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nome nella lingua richiesta, con ripiego sull'italiano e poi sulla prima lingua non vuota (wmwp_text(), oc:9000).
 * Spazi e tabulazioni ai lati non contano: nell'API ci sono nomi con il tab in coda e traduzioni fatte solo di spazi.
 *
 * @param array<string, mixed>|string|null $names Nomi per lingua, o un nome solo.
 */
function wmwp_detail_text( $names, string $lang ): string {
	// Il nome fa lo slug e l'indirizzo stabile (oc:8983): una lingua con una lista si salta, come prima di oc:9000,
	// invece di unirla come fa wmwp_text()
	return wmwp_text( is_array( $names ) ? array_filter( $names, 'is_scalar' ) : $names, $lang )['text'];
}

/** Percorso `<base>/<slug>` della richiesta in forma canonica: minuscolo, senza barre ai lati. */
function wmwp_detail_normalize_path( string $path ): string {
	return trim( strtolower( $path ), '/' );
}

/**
 * Base e slug di un percorso `<base>/<slug>` (la base può avere più segmenti, lo slug è l'ultimo).
 *
 * @return array{0: string, 1: string}
 */
function wmwp_detail_split_path( string $path ): array {
	$cut = strrpos( $path, '/' );
	return false === $cut ? [ '', $path ] : [ substr( $path, 0, $cut ), substr( $path, $cut + 1 ) ];
}

/** Slug dal nome; se il nome non ha niente da tenere (solo punteggiatura o simboli) lo slug è l'id. */
function wmwp_detail_slug( string $name, int $id ): string {
	$slug = sanitize_title( $name );
	return '' !== $slug ? $slug : (string) $id;
}

/**
 * Chiave stabile di un elemento, che non cambia quando lo shard rinumera gli id (reset, reimport): di base
 * `out_source_feature_id`, l'id della fonte da cui lo shard ha importato l'elemento; '' se non c'è. Un tema la cambia
 * con il filtro `wmwp_detail_source` (`$source`, `$type`, `$props`), chiamato su tre forme dei dati: le proprietà del
 * dettaglio, quelle di `pois.geojson` e il `_source` dell'indice Elasticsearch delle tracce.
 *
 * @param string               $type  `track` o `poi`.
 * @param array<string, mixed> $props Proprietà dell'elemento.
 */
function wmwp_detail_source( string $type, array $props ): string {
	$source = isset( $props['out_source_feature_id'] ) && is_scalar( $props['out_source_feature_id'] ) ? trim( (string) $props['out_source_feature_id'] ) : '';
	$source = apply_filters( 'wmwp_detail_source', $source, $type, $props );
	return is_scalar( $source ) ? trim( (string) $source ) : '';
}

/**
 * Lingue dei dettagli: quelle attive di WPML, o la lingua del sito.
 *
 * @return list<string>
 */
function wmwp_detail_languages(): array {
	$active = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
	if ( is_array( $active ) && $active ) {
		return array_values( array_map( 'strval', array_keys( $active ) ) );
	}
	return [ substr( (string) get_locale(), 0, 2 ) ];
}

/**
 * Indice dei dettagli.
 *
 * I percorsi si assegnano per lingua in tre passate, con gli elementi in ordine di chiave (wmwp_detail_key_compare(),
 * poi tipo e id), così il risultato non dipende dall'ordine in cui arrivano dall'API (oc:8983):
 * 1. ogni elemento tiene il percorso che lo storico segna come suo attuale, se la base e lo slug del nome sono ancora
 *    quelli da cui è nato;
 * 2. gli altri prendono `<base>/<slug>`, se è libero;
 * 3. i rimasti prendono il primo `<base>/<slug>-<n>` libero, con `n` da 2.
 * «Libero» vuol dire non assegnato in questo giro e non dato nello storico a un altro elemento che c'è ancora: un
 * percorso resta dell'elemento a cui è stato dato finché quell'elemento esiste.
 *
 * @param list<array{type: string, id: int, names: mixed, kind: ?array, source?: ?string}> $items Elementi: tipo
 *        (`track` o `poi`), id, nomi per lingua, nomi per lingua del loro tipo (per le tracce la famiglia di
 *        `wmwp_track_kind_taxonomy()`, per i POI `poi-type`; null se non ce l'hanno) e chiave stabile
 *        (`wmwp_detail_source()`, se c'è).
 * @param callable                                                     $base  function( string $type, ?array $kind, string $lang ): string
 * @param list<string>|null                                            $langs Lingue; null = wmwp_detail_languages().
 * @param array<string, array<string, array{type: string, key: string, base: string, root: string, suffix: int, current: bool}>> $history
 *        Storico dei percorsi, lingua → percorso → riga (wmwp_detail_history_load()); vuoto = nessuno storico.
 * @param list<array{lang: string, path: string, type: string, id: int, key: string, base: string, root: string, suffix: int}>|null $assignments
 *        In uscita, un elemento per percorso assegnato (`suffix` 0 = senza suffisso): serve ad aggiornare lo storico.
 * @return array{langs: list<string>, paths: array<string, array<string, array{0: string, 1: int}>>, urls: array<string, array<int, array<string, string>>>, items: array<string, array<int, array{names: mixed, source: ?string}>>, suffixes?: array<string, array<int, array<string, int>>>, counts: array<string, int>, count: int}
 */
function wmwp_detail_build_index( array $items, callable $base, ?array $langs = null, array $history = [], ?array &$assignments = null ): array {
	$langs       = null === $langs ? wmwp_detail_languages() : array_values( $langs );
	$assignments = [];
	// `suffixes` c'è sempre, anche vuoto: dice che l'indice è della 0.3.0 (wmwp_detail_slug_matches())
	$index = [
		'langs'    => $langs,
		'paths'    => [],
		'urls'     => [],
		'items'    => [],
		'suffixes' => [],
		'count'    => 0,
	];
	// Una chiave della fonte ripetuta (due elementi con lo stesso nid) resta all'id più basso: gli altri elementi sono
	// riconosciuti dall'id, altrimenti i due si contenderebbero lo stesso proprietario e i percorsi si scambierebbero
	$owners = [];
	foreach ( $items as $item ) {
		$source = trim( (string) ( $item['source'] ?? '' ) );
		$slot   = (string) $item['type'] . '|' . $source;
		if ( '' !== $source && ( ! isset( $owners[ $slot ] ) || (int) $item['id'] < $owners[ $slot ] ) ) {
			$owners[ $slot ] = (int) $item['id'];
		}
	}
	$source_of = function ( array $item ) use ( $owners ): ?string {
		$source = trim( (string) ( $item['source'] ?? '' ) );
		return '' !== $source && ( $owners[ (string) $item['type'] . '|' . $source ] ?? null ) === (int) $item['id'] ? $source : null;
	};
	// Nomi e chiave stabile: il dettaglio di un POI non ha il nome, e per controllare che l'id porti ancora allo stesso
	// elemento serve un dato che non cambia con il reset degli id
	foreach ( $items as $item ) {
		$index['items'][ (string) $item['type'] ][ (int) $item['id'] ] = [
			'names'  => $item['names'] ?? null,
			'source' => $source_of( $item ),
		];
	}

	// Elementi in ordine di chiave, poi tipo e id: l'ordine totale rende le passate uguali a ogni giro
	$entries = [];
	$present = [];
	foreach ( $items as $item ) {
		$type      = (string) $item['type'];
		$id        = (int) $item['id'];
		$key       = wmwp_detail_item_key( $source_of( $item ), $id );
		$entries[] = [
			'type'  => $type,
			'id'    => $id,
			'key'   => $key,
			'owner' => $type . '|' . $key,
			'names' => $item['names'] ?? null,
			'kind'  => isset( $item['kind'] ) && is_array( $item['kind'] ) ? $item['kind'] : null,
		];
		$present[ $type . '|' . $key ] = true;
	}
	usort(
		$entries,
		function ( array $a, array $b ): int {
			return wmwp_detail_key_compare( $a['key'], $b['key'] ) ?: strcmp( $a['type'], $b['type'] ) ?: $a['id'] <=> $b['id'];
		}
	);

	foreach ( $langs as $lang ) {
		$rows = (array) ( $history[ $lang ] ?? [] );
		// Percorso attuale di ogni elemento secondo lo storico
		$current = [];
		foreach ( $rows as $path => $row ) {
			if ( ! empty( $row['current'] ) ) {
				$current[ $row['type'] . '|' . $row['key'] ] = [ (string) $path, $row ];
			}
		}
		// Un percorso dato nello storico a un altro elemento che c'è ancora non si può prendere
		$taken = function ( string $path, string $owner ) use ( $rows, $present ): bool {
			$row = $rows[ $path ] ?? null;
			if ( null === $row ) {
				return false;
			}
			$holder = $row['type'] . '|' . $row['key'];
			return $holder !== $owner && isset( $present[ $holder ] );
		};

		$planned  = [];
		$assigned = [];
		$result   = [];
		foreach ( $entries as $n => $entry ) {
			$planned[ $n ] = [
				$base( $entry['type'], $entry['kind'], $lang ),
				wmwp_detail_slug( wmwp_detail_text( $entry['names'], $lang ), $entry['id'] ),
			];
		}
		// 1. Il percorso attuale dello storico, se nasce ancora dalla stessa base e dallo stesso slug
		foreach ( $entries as $n => $entry ) {
			if ( ! isset( $current[ $entry['owner'] ] ) ) {
				continue;
			}
			[ $path, $row ] = $current[ $entry['owner'] ];
			[ $b, $r ]      = $planned[ $n ];
			$suffix         = (int) $row['suffix'];
			if ( (string) $row['base'] === $b && (string) $row['root'] === $r && $path === $b . '/' . $r . ( $suffix > 0 ? '-' . $suffix : '' ) && ! isset( $assigned[ $path ] ) ) {
				$assigned[ $path ] = true;
				$result[ $n ]      = [ $path, $suffix ];
			}
		}
		// 2. Lo slug senza suffisso, se è libero
		foreach ( $entries as $n => $entry ) {
			[ $b, $r ] = $planned[ $n ];
			$path      = $b . '/' . $r;
			if ( ! isset( $result[ $n ] ) && ! isset( $assigned[ $path ] ) && ! $taken( $path, $entry['owner'] ) ) {
				$assigned[ $path ] = true;
				$result[ $n ]      = [ $path, 0 ];
			}
		}
		// 3. Il primo suffisso libero da 2: un percorso porta sempre a un elemento solo, anche se un altro elemento si
		// chiama «Foo 2»
		foreach ( $entries as $n => $entry ) {
			if ( isset( $result[ $n ] ) ) {
				continue;
			}
			[ $b, $r ] = $planned[ $n ];
			$suffix    = 2;
			while ( isset( $assigned[ $b . '/' . $r . '-' . $suffix ] ) || $taken( $b . '/' . $r . '-' . $suffix, $entry['owner'] ) ) {
				++$suffix;
			}
			$path              = $b . '/' . $r . '-' . $suffix;
			$assigned[ $path ] = true;
			$result[ $n ]      = [ $path, $suffix ];
		}

		ksort( $result );
		foreach ( $result as $n => [ $path, $suffix ] ) {
			$entry = $entries[ $n ];
			$index['paths'][ $lang ][ $path ]                         = [ $entry['type'], $entry['id'] ];
			$index['urls'][ $entry['type'] ][ $entry['id'] ][ $lang ] = $path;
			// Il suffisso dato all'elemento, solo se c'è: wmwp_detail_slug_matches() accetta quello e nessun altro
			if ( $suffix > 0 ) {
				$index['suffixes'][ $entry['type'] ][ $entry['id'] ][ $lang ] = $suffix;
			}
			$assignments[] = [
				'lang'   => $lang,
				'path'   => $path,
				'type'   => $entry['type'],
				'id'     => $entry['id'],
				'key'    => $entry['key'],
				'base'   => $planned[ $n ][0],
				'root'   => $planned[ $n ][1],
				'suffix' => $suffix,
			];
		}
	}

	$index['counts'] = [
		'track' => count( $index['urls']['track'] ?? [] ),
		'poi'   => count( $index['urls']['poi'] ?? [] ),
	];
	$index['count']  = array_sum( $index['counts'] );

	return $index;
}

const WMWP_DETAIL_INDEX_KEY  = 'wmwp_detail_index';
const WMWP_DETAIL_INDEX_LAST = 'wmwp_detail_index_last';
const WMWP_DETAIL_INDEX_LOCK = 'wmwp_detail_index_lock';
const WMWP_DETAIL_REBUILT_AT = 'wmwp_detail_index_rebuilt_at';
// Una ricostruzione forzata (indirizzo non trovato, nome che non torna) al massimo ogni 5 minuti: i bot che
// chiedono slug inventati non devono scaricare la lista ogni volta
const WMWP_DETAIL_REBUILD_INTERVAL = 300;
// Altre opzioni dell'indice: le basi in uso (piccola, caricata sempre, per le regole di rewrite) e gli scarti di fila
const WMWP_DETAIL_BASES_OPTION   = 'wmwp_detail_bases';
const WMWP_DETAIL_REJECTS_OPTION = 'wmwp_detail_index_rejects';
// Durata dell'indice in cache e del blocco di una ricostruzione in corso, in secondi. L'indice lo rifà ogni ora un
// evento cron (WMWP_DETAIL_CRON_HOOK), prima che scada: di solito la ricostruzione non la paga un visitatore
const WMWP_DETAIL_INDEX_TTL = 2 * HOUR_IN_SECONDS;
const WMWP_DETAIL_CRON_HOOK = 'wmwp_detail_refresh_index';
const WMWP_DETAIL_LOCK_TTL  = 60;
// Un tipo che cala sotto questa frazione dell'indice precedente è una lista a metà: si scarta, al massimo per
// WMWP_DETAIL_MAX_REJECTS volte di fila; alla volta dopo il calo si accetta
const WMWP_DETAIL_REJECT_RATIO = 0.5;
const WMWP_DETAIL_MAX_REJECTS  = 2;
// Durata del JSON di un dettaglio in cache (oltre la cache breve serve solo alla richiesta condizionale): ogni JSON con
// la geometria pesa decine di KB in wp_options
const WMWP_DETAIL_JSON_TTL = 6 * HOUR_IN_SECONDS;
// Pagina «non disponibile» (503): fra quanti secondi riprovare
const WMWP_DETAIL_RETRY_AFTER = 120;
// Timeout delle richieste: il dettaglio di un elemento e le due liste dell'indice
const WMWP_DETAIL_TIMEOUT      = 5;
const WMWP_DETAIL_LIST_TIMEOUT = 10;

/**
 * JSON di una risposta dello shard (all'indirizzo del server, wmwp_server_url()), o null.
 *
 * @return array<mixed>|null
 */
function wmwp_detail_get_json( string $url, int $timeout ) {
	return wmwp_http_json( wmwp_server_url( $url ), $timeout )['data'];
}

/**
 * Elementi dei dettagli dallo shard: le tracce dalla lista Elasticsearch della ricerca, i POI da `pois.geojson`.
 * Null se una delle due chiamate fallisce: un elenco a metà non deve diventare l'indice.
 *
 * @return list<array{type: string, id: int, names: mixed, kind: ?array}>|null
 */
function wmwp_detail_fetch_items(): ?array {
	$elastic = wmwp_elastic_url();
	$origin  = wmwp_configured_api_urls()['origin'];
	$app     = (int) get_option( WMWP_OPTION_APP_ID, 0 );
	if ( '' === $elastic || '' === $origin || $app <= 0 ) {
		return null;
	}

	wmwp_poi_search_pending( null, true );
	$tracks = wmwp_detail_get_json( add_query_arg( 'app', wmwp_app_param(), $elastic ), WMWP_DETAIL_LIST_TIMEOUT );
	$pois   = wmwp_detail_get_json( $origin . '/api/v2/app/' . $app . '/pois.geojson', WMWP_DETAIL_LIST_TIMEOUT );
	// Una risposta di Elasticsearch scaduta o con shard falliti è parziale anche se lo stato è 200
	if ( null === $tracks || null === $pois || ! empty( $tracks['timed_out'] ) || (int) ( $tracks['_shards']['failed'] ?? 0 ) > 0 ) {
		return null;
	}

	// Copia per la ricerca dei POI (oc:9000): dallo stesso download, scritta solo se l'indice viene accettato
	wmwp_poi_search_pending( (array) ( $pois['features'] ?? [] ) );
	$items  = [];
	$family = wmwp_track_kind_taxonomy();
	$hits   = $tracks['hits'] ?? [];
	if ( isset( $hits['hits'] ) && is_array( $hits['hits'] ) ) {
		$hits = $hits['hits'];
	}
	foreach ( (array) $hits as $hit ) {
		$hit  = isset( $hit['_source'] ) && is_array( $hit['_source'] ) ? $hit['_source'] : $hit;
		$id   = (int) ( $hit['id'] ?? 0 );
		$kind = '' !== $family ? ( $hit['terms'][ $family ][0]['name'] ?? null ) : null;
		if ( $id > 0 ) {
			$source  = wmwp_detail_source( 'track', (array) $hit );
			$items[] = [
				'type'   => 'track',
				'id'     => $id,
				'names'  => $hit['name'] ?? null,
				'kind'   => is_array( $kind ) ? $kind : null,
				'source' => '' !== $source ? $source : null,
			];
		}
	}
	foreach ( (array) ( $pois['features'] ?? [] ) as $feature ) {
		$props = (array) ( $feature['properties'] ?? [] );
		$id    = (int) ( $props['id'] ?? 0 );
		$kind  = $props['taxonomies']['poi-type'][0]['name'] ?? null;
		if ( $id > 0 ) {
			$source  = wmwp_detail_source( 'poi', $props );
			$items[] = [
				'type'   => 'poi',
				'id'     => $id,
				'names'  => $props['name'] ?? null,
				'kind'   => is_array( $kind ) ? $kind : null,
				'source' => '' !== $source ? $source : null,
			];
		}
	}

	/**
	 * Elementi dei dettagli prima della costruzione dell'indice: un tema può toglierne o cambiarne il tipo.
	 *
	 * @param list<array{type: string, id: int, names: mixed, kind: ?array}> $items
	 */
	return (array) apply_filters( 'wmwp_detail_items', $items );
}

/** Una ricostruzione forzata è permessa se l'ultima ha più di WMWP_DETAIL_REBUILD_INTERVAL secondi. */
function wmwp_detail_rebuild_allowed(): bool {
	return time() - (int) get_option( WMWP_DETAIL_REBUILT_AT, 0 ) >= WMWP_DETAIL_REBUILD_INTERVAL;
}

/**
 * Indice in cache, letto una volta per richiesta: è grande (circa 800 KB con 1700 elementi) e il tema e la sitemap
 * chiedono un indirizzo per ogni elemento. La copia della richiesta si butta quando il transient si scrive o si
 * cancella.
 *
 * @return array<string, mixed>|null
 */
function wmwp_detail_index_cached(): ?array {
	if ( ! array_key_exists( 'wmwp_detail_index_memo', $GLOBALS ) ) {
		$cached                              = get_transient( WMWP_DETAIL_INDEX_KEY );
		$GLOBALS['wmwp_detail_index_memo'] = is_array( $cached ) ? $cached : null;
	}
	return $GLOBALS['wmwp_detail_index_memo'];
}

/** Butta la copia dell'indice della richiesta quando il transient cambia. */
function wmwp_detail_index_forget( string $transient ): void {
	if ( WMWP_DETAIL_INDEX_KEY === $transient ) {
		unset( $GLOBALS['wmwp_detail_index_memo'] );
	}
}
add_action( 'setted_transient', 'wmwp_detail_index_forget' );
add_action( 'deleted_transient', 'wmwp_detail_index_forget' );

/**
 * Indice dei dettagli: dalla cache (WMWP_DETAIL_INDEX_TTL), o ricostruito dallo shard. Con `$rebuild` si ricostruisce
 * anche se la cache c'è, ma non più di una volta ogni WMWP_DETAIL_REBUILD_INTERVAL secondi. Un indice nuovo vuoto non
 * si salva; uno in cui un tipo cala sotto WMWP_DETAIL_REJECT_RATIO del precedente (lista a metà durante una
 * reindicizzazione) si scarta per WMWP_DETAIL_MAX_REJECTS volte di fila, e alla volta dopo si accetta; uno in cui la
 * chiave della fonte crolla (API cambiate) si scarta sempre. Lo storico dei percorsi (oc:8983) entra nella costruzione
 * e si scrive solo con un indice accettato: i percorsi nuovi, al primo giro quelli dell'indice esistente, e quando lo
 * shard risulta svuotato e ricaricato si dimenticano le righe registrate con l'id. I controlli usano l'ultimo indice
 * anche se nel frattempo è cambiata la configurazione delle basi. Null solo se non c'è mai stato un indice e lo shard
 * non risponde.
 *
 * @return array{langs: list<string>, paths: array, urls: array, items: array, counts: array<string, int>, count: int, config: string}|null
 */
function wmwp_detail_index( bool $rebuild = false ): ?array {
	$cached = wmwp_detail_index_cached();
	// Un indice costruito con altre basi (impostazioni, tema o pagina base rinominata) non vale più: si ricostruisce.
	// L'ultimo indice buono resta, perché i controlli sugli elementi non dipendono dalle basi
	$config = wmwp_detail_base_signature();
	if ( null !== $cached && ( $cached['config'] ?? '' ) !== $config ) {
		delete_transient( WMWP_DETAIL_INDEX_KEY );
		delete_option( WMWP_DETAIL_REBUILT_AT );
		$cached = null;
	}
	if ( null !== $cached && ! $rebuild ) {
		return $cached;
	}
	$stored = $cached ?? get_option( WMWP_DETAIL_INDEX_LAST, null );
	$guard  = is_array( $stored ) ? $stored : null;
	$last   = null !== $guard && ( $guard['config'] ?? '' ) === $config ? $guard : null;
	// Un tentativo ogni WMWP_DETAIL_REBUILD_INTERVAL secondi al massimo, riuscito o no: con lo shard giù non si
	// richiama a ogni richiesta del sito
	// Senza un indice con questa configurazione (basi appena cambiate) si risponde con l'ultimo indice buono, come quando
	// la ricostruzione si scarta: le pagine restano raggiungibili con le basi vecchie, ancora nelle regole di rewrite
	if ( ! wmwp_detail_rebuild_allowed() || get_transient( WMWP_DETAIL_INDEX_LOCK ) ) {
		return $last ?? $guard;
	}
	set_transient( WMWP_DETAIL_INDEX_LOCK, 1, WMWP_DETAIL_LOCK_TTL );
	update_option( WMWP_DETAIL_REBUILT_AT, time(), false );
	try {
		$index = wmwp_detail_index_rebuild( $guard, $config );
	} finally {
		delete_transient( WMWP_DETAIL_INDEX_LOCK );
	}
	return $index ?? $last ?? $guard;
}

/**
 * Una ricostruzione dell'indice, con il lock già preso da wmwp_detail_index(): l'indice nuovo, salvato insieme allo
 * storico, o null se si scarta (shard giù, chiave crollata, storico illeggibile, indice vuoto o a metà).
 *
 * @param array<string, mixed>|null $guard  Ultimo indice buono, anche con un'altra configurazione delle basi.
 * @param string                    $config Impronta della configurazione delle basi.
 * @return array<string, mixed>|null
 */
function wmwp_detail_index_rebuild( ?array $guard, string $config ): ?array {
	$items = wmwp_detail_fetch_items();
	if ( null === $items ) {
		return null;
	}
	// La chiave della fonte crollata vuol dire API cambiate: l'indice si scarta sempre, non solo per due volte come il
	// calo degli elementi, perché accettarlo riscriverebbe lo storico (oc:8983)
	if ( wmwp_detail_keys_collapsed( $guard, $items ) ) {
		error_log( '[wp-package] indice dei dettagli scartato: la chiave della fonte è crollata (' . wp_json_encode( wmwp_detail_count_sources( $items ) ) . ')' );
		return null;
	}
	$history = wmwp_detail_history_load();
	// Storico che c'è ma non si legge: un indice costruito senza riassegnerebbe i percorsi, resta quello di prima
	if ( null === $history ) {
		return null;
	}
	// Primo giro con lo storico: i percorsi dell'indice esistente entrano nello storico prima di ricalcolarli. Qui solo
	// in memoria: si scrivono con l'indice, se viene accettato
	$seed = [];
	if ( get_option( WMWP_DETAIL_PATHS_SEED_OPTION, 0 ) && null !== $guard ) {
		foreach ( wmwp_detail_history_seed_rows( $guard ) as $row ) {
			if ( ! isset( $history[ $row['lang'] ][ $row['path'] ] ) ) {
				$seed[]                                     = $row;
				$history[ $row['lang'] ][ $row['path'] ] = array_diff_key( $row, [ 'lang' => 0, 'path' => 0 ] );
			}
		}
	}
	// Shard svuotato e ricaricato: gli id delle righe registrate con l'id ora sono di altri elementi. Anche questo solo
	// in memoria fino all'accettazione
	$renumbered = wmwp_detail_shard_renumbered( $guard, $items );
	foreach ( $renumbered as $type ) {
		foreach ( $history as $lang => $rows ) {
			foreach ( $rows as $path => $row ) {
				if ( $type === $row['type'] && 0 === strpos( $row['key'], 'i:' ) ) {
					unset( $history[ $lang ][ $path ] );
				}
			}
		}
	}

	$assignments = null;
	$index       = wmwp_detail_build_index( $items, 'wmwp_detail_base', null, $history, $assignments );
	if ( 0 === $index['count'] ) {
		return null;
	}
	$rejects = (int) get_option( WMWP_DETAIL_REJECTS_OPTION, 0 );
	foreach ( WMWP_DETAIL_TYPES as $type ) {
		$before = (int) ( $guard['counts'][ $type ] ?? 0 );
		if ( $before > 0 && $index['counts'][ $type ] < WMWP_DETAIL_REJECT_RATIO * $before && $rejects < WMWP_DETAIL_MAX_REJECTS ) {
			update_option( WMWP_DETAIL_REJECTS_OPTION, $rejects + 1, false );
			error_log( sprintf( '[wp-package] indice dei dettagli scartato: %s %d contro %d', $type, $index['counts'][ $type ], $before ) );
			return null;
		}
	}
	delete_option( WMWP_DETAIL_REJECTS_OPTION );

	$index['config'] = $config;
	set_transient( WMWP_DETAIL_INDEX_KEY, $index, WMWP_DETAIL_INDEX_TTL );
	update_option( WMWP_DETAIL_INDEX_LAST, $index, false );
	wmwp_poi_search_commit();
	// Solo con un indice accettato lo storico cambia: righe con l'id dimenticate, primo giro, percorsi nuovi (si
	// scrivono solo le righe che cambiano). L'option del primo giro si toglie solo se la scrittura è riuscita
	foreach ( $renumbered as $type ) {
		wmwp_detail_history_forget_ids( $type );
	}
	$written = wmwp_detail_history_write( $seed ) && wmwp_detail_history_write( wmwp_detail_history_changes( $history, (array) $assignments ) );
	if ( $written && get_option( WMWP_DETAIL_PATHS_SEED_OPTION, 0 ) ) {
		delete_option( WMWP_DETAIL_PATHS_SEED_OPTION );
	}
	// Le basi in un'opzione piccola caricata sempre: le regole di rewrite (init) non leggono l'indice intero
	update_option( WMWP_DETAIL_BASES_OPTION, wmwp_detail_rule_bases( $index ), true );
	return $index;
}

/**
 * Basi di un indice, in tutte le lingue.
 *
 * @return list<string>
 */
function wmwp_detail_index_bases( array $index ): array {
	$bases = [];
	foreach ( (array) $index['paths'] as $paths ) {
		foreach ( array_keys( $paths ) as $path ) {
			$bases[ wmwp_detail_split_path( $path )[0] ] = true;
		}
	}
	$bases = array_map( 'strval', array_keys( $bases ) );
	sort( $bases );
	return $bases;
}

/** Impronta della configurazione delle basi (interruttore e pagina per tipo): cambia l'impronta, cambia l'indice. */
function wmwp_detail_base_signature(): string {
	$parts = [];
	foreach ( WMWP_DETAIL_TYPES as $type ) {
		$page = wmwp_detail_base_page( $type );
		// Anche il percorso della pagina in ogni lingua: una pagina base rinominata cambia gli indirizzi
		$parts[] = $page > 0 ? $page . ':' . implode( '|', array_map( fn( $lang ) => wmwp_detail_base( $type, null, $lang ), wmwp_detail_languages() ) ) : '0';
	}
	return implode( ',', $parts );
}

// Ricostruzione in anticipo, ogni ora (anche lo scarto delle liste a metà vale come per le altre ricostruzioni)
add_action(
	WMWP_DETAIL_CRON_HOOK,
	function () {
		wmwp_detail_index( true );
	}
);
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( WMWP_DETAIL_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', WMWP_DETAIL_CRON_HOOK );
		}
	}
);
