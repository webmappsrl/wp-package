<?php
/**
 * Pagine di dettaglio di tracce e POI (oc:8969): slug e indice.
 *
 * Le pagine non sono post: un indirizzo `/<base>/<slug>/` si risolve con un indice `percorso → elemento` per lingua,
 * costruito dalla lista delle tracce dell'API Elasticsearch e da `pois.geojson` dello shard. Lo slug viene dal nome
 * (`sanitize_title`), così al backend non serve niente; i doppioni nella stessa base e lingua prendono `-<fonte>`, la
 * chiave stabile dell'elemento (`wmwp_detail_source()`), o `-<id>` se non ce l'hanno.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nome nella lingua richiesta, con ripiego sull'italiano e poi sulla prima lingua non vuota. Spazi e tabulazioni
 * ai lati non contano: nell'API ci sono nomi con il tab in coda e traduzioni fatte solo di spazi.
 *
 * @param array<string, mixed>|string|null $names Nomi per lingua, o un nome solo.
 */
function wmwp_detail_text( $names, string $lang ): string {
	if ( is_string( $names ) ) {
		return trim( $names );
	}
	if ( ! is_array( $names ) ) {
		return '';
	}
	// I codici di WPML possono avere la regione (en-us): i nomi dell'API stanno sotto le due lettere
	foreach ( array_merge( [ $lang, strtolower( substr( $lang, 0, 2 ) ), 'it' ], array_keys( $names ) ) as $key ) {
		$value = isset( $names[ $key ] ) && is_scalar( $names[ $key ] ) ? trim( (string) $names[ $key ] ) : '';
		if ( '' !== $value ) {
			return $value;
		}
	}
	return '';
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
 * @param list<array{type: string, id: int, names: mixed, kind: ?array, source?: ?string}> $items Elementi: tipo
 *        (`track` o `poi`), id, nomi per lingua, nomi per lingua del loro tipo (`route-type` o `poi-type`, null se
 *        non ce l'hanno) e chiave stabile (`wmwp_detail_source()`, se c'è).
 * @param callable                                                     $base  function( string $type, ?array $kind, string $lang ): string
 * @param list<string>|null                                            $langs Lingue; null = wmwp_detail_languages().
 * @return array{langs: list<string>, paths: array<string, array<string, array{0: string, 1: int}>>, urls: array<string, array<int, array<string, string>>>, count: int}
 */
function wmwp_detail_build_index( array $items, callable $base, ?array $langs = null ): array {
	$langs = null === $langs ? wmwp_detail_languages() : array_values( $langs );
	$index = [
		'langs' => $langs,
		'paths' => [],
		'urls'  => [],
		'items' => [],
		'count' => 0,
	];
	// Nomi e chiave stabile: il dettaglio di un POI non ha il nome, e per controllare che l'id porti ancora allo stesso
	// elemento serve un dato che non cambia con il reset degli id
	foreach ( $items as $item ) {
		$index['items'][ (string) $item['type'] ][ (int) $item['id'] ] = [
			'names'  => $item['names'] ?? null,
			'source' => isset( $item['source'] ) && '' !== (string) $item['source'] ? (string) $item['source'] : null,
		];
	}

	foreach ( $langs as $lang ) {
		// Prima passata: base e slug di ogni elemento; si contano gli slug per base
		$planned = [];
		$used    = [];
		foreach ( $items as $item ) {
			$type = (string) $item['type'];
			$id   = (int) $item['id'];
			$kind = isset( $item['kind'] ) && is_array( $item['kind'] ) ? $item['kind'] : null;
			$path = $base( $type, $kind, $lang ) . '/' . wmwp_detail_slug( wmwp_detail_text( $item['names'] ?? null, $lang ), $id );

			$source = isset( $item['source'] ) ? sanitize_title( (string) $item['source'] ) : '';

			$planned[]      = [ $type, $id, $path, '' !== $source ? $source : (string) $id ];
			$used[ $path ] = ( $used[ $path ] ?? 0 ) + 1;
		}
		// Seconda passata: prima gli slug unici, poi i doppioni con -<fonte> (stabile fra un reset e l'altro) o -<id>; se
		// anche quello è preso (un altro elemento si chiama «Foo 5») si aggiunge un numero, così un percorso porta sempre a
		// un elemento solo
		$assigned = [];
		foreach ( $planned as [ $type, $id, $path ] ) {
			if ( 1 === $used[ $path ] ) {
				$assigned[ $path ] = [ $type, $id ];
			}
		}
		foreach ( $planned as [ $type, $id, $path, $suffix ] ) {
			if ( 1 === $used[ $path ] ) {
				continue;
			}
			$candidate = $path . '-' . $suffix;
			for ( $n = 2; isset( $assigned[ $candidate ] ); $n++ ) {
				$candidate = $path . '-' . $suffix . '-' . $n;
			}
			$assigned[ $candidate ] = [ $type, $id ];
		}
		foreach ( $assigned as $path => [ $type, $id ] ) {
			$index['paths'][ $lang ][ $path ]       = [ $type, $id ];
			$index['urls'][ $type ][ $id ][ $lang ] = $path;
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

	$tracks = wmwp_detail_get_json( add_query_arg( 'app', wmwp_app_param(), $elastic ), WMWP_DETAIL_LIST_TIMEOUT );
	$pois   = wmwp_detail_get_json( $origin . '/api/v2/app/' . $app . '/pois.geojson', WMWP_DETAIL_LIST_TIMEOUT );
	// Una risposta di Elasticsearch scaduta o con shard falliti è parziale anche se lo stato è 200
	if ( null === $tracks || null === $pois || ! empty( $tracks['timed_out'] ) || (int) ( $tracks['_shards']['failed'] ?? 0 ) > 0 ) {
		return null;
	}

	$items = [];
	$hits  = $tracks['hits'] ?? [];
	if ( isset( $hits['hits'] ) && is_array( $hits['hits'] ) ) {
		$hits = $hits['hits'];
	}
	foreach ( (array) $hits as $hit ) {
		$hit  = isset( $hit['_source'] ) && is_array( $hit['_source'] ) ? $hit['_source'] : $hit;
		$id   = (int) ( $hit['id'] ?? 0 );
		$kind = $hit['terms']['route-type'][0]['name'] ?? null;
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
 * reindicizzazione) si scarta per WMWP_DETAIL_MAX_REJECTS volte di fila, e alla volta dopo si accetta. Null solo se
 * non c'è mai stato un indice e lo shard non risponde.
 *
 * @return array{langs: list<string>, paths: array, urls: array, count: int}|null
 */
function wmwp_detail_index( bool $rebuild = false ): ?array {
	$cached = wmwp_detail_index_cached();
	// Un indice costruito con altre basi (impostazioni, tema o pagina base rinominata) non vale più: si ricostruisce
	$config = wmwp_detail_base_signature();
	if ( null !== $cached && ( $cached['config'] ?? '' ) !== $config ) {
		delete_transient( WMWP_DETAIL_INDEX_KEY );
		delete_option( WMWP_DETAIL_INDEX_LAST );
		delete_option( WMWP_DETAIL_REBUILT_AT );
		$cached = null;
	}
	if ( null !== $cached && ! $rebuild ) {
		return $cached;
	}
	$last = $cached ?? get_option( WMWP_DETAIL_INDEX_LAST, null );
	$last = is_array( $last ) && ( $last['config'] ?? '' ) === $config ? $last : null;
	// Un tentativo ogni WMWP_DETAIL_REBUILD_INTERVAL secondi al massimo, riuscito o no: con lo shard giù non si
	// richiama a ogni richiesta del sito
	if ( ! wmwp_detail_rebuild_allowed() || get_transient( WMWP_DETAIL_INDEX_LOCK ) ) {
		return $last;
	}
	set_transient( WMWP_DETAIL_INDEX_LOCK, 1, WMWP_DETAIL_LOCK_TTL );
	update_option( WMWP_DETAIL_REBUILT_AT, time(), false );

	$items = wmwp_detail_fetch_items();
	$index = null === $items ? null : wmwp_detail_build_index( $items, 'wmwp_detail_base' );
	delete_transient( WMWP_DETAIL_INDEX_LOCK );
	if ( null === $index || 0 === $index['count'] ) {
		return $last;
	}

	$rejects = (int) get_option( WMWP_DETAIL_REJECTS_OPTION, 0 );
	foreach ( WMWP_DETAIL_TYPES as $type ) {
		$before = (int) ( $last['counts'][ $type ] ?? 0 );
		if ( $before > 0 && $index['counts'][ $type ] < WMWP_DETAIL_REJECT_RATIO * $before && $rejects < WMWP_DETAIL_MAX_REJECTS ) {
			update_option( WMWP_DETAIL_REJECTS_OPTION, $rejects + 1, false );
			error_log( sprintf( '[wp-package] indice dei dettagli scartato: %s %d contro %d', $type, $index['counts'][ $type ], $before ) );
			return $last;
		}
	}
	delete_option( WMWP_DETAIL_REJECTS_OPTION );

	$index['config'] = $config;
	set_transient( WMWP_DETAIL_INDEX_KEY, $index, WMWP_DETAIL_INDEX_TTL );
	update_option( WMWP_DETAIL_INDEX_LAST, $index, false );
	// Le basi in un'opzione piccola caricata sempre: le regole di rewrite (init) non leggono l'indice intero
	update_option( WMWP_DETAIL_BASES_OPTION, wmwp_detail_index_bases( $index ), true );
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
