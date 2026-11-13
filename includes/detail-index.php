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

/**
 * JSON di una risposta dello shard, anche quando è una stringa JSON dentro un JSON (pois.geojson sul dev), o null.
 *
 * @return array<mixed>|null
 */
function wmwp_detail_get_json( string $url, int $timeout ) {
	$response = wp_remote_get( wmwp_server_url( $url ), [ 'timeout' => $timeout ] );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( is_string( $data ) ) {
		$data = json_decode( $data, true );
	}
	return is_array( $data ) ? $data : null;
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

	$tracks = wmwp_detail_get_json( add_query_arg( 'app', wmwp_app_param(), $elastic ), 10 );
	$pois   = wmwp_detail_get_json( $origin . '/api/v2/app/' . $app . '/pois.geojson', 10 );
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
 * Indice dei dettagli: dalla cache (1 ora), o ricostruito dallo shard. Con `$rebuild` si ricostruisce anche se la
 * cache c'è, ma non più di una volta ogni 5 minuti. Un indice nuovo vuoto, o con meno della metà degli elementi del
 * precedente (lista a metà durante una reindicizzazione), non si salva: resta il vecchio. Null solo se non c'è mai
 * stato un indice e lo shard non risponde.
 *
 * @return array{langs: list<string>, paths: array, urls: array, count: int}|null
 */
function wmwp_detail_index( bool $rebuild = false ): ?array {
	$cached = get_transient( WMWP_DETAIL_INDEX_KEY );
	$cached = is_array( $cached ) ? $cached : null;
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
	// Un tentativo ogni 5 minuti al massimo, riuscito o no: con lo shard giù non si richiama a ogni richiesta del sito
	if ( ! wmwp_detail_rebuild_allowed() || get_transient( WMWP_DETAIL_INDEX_LOCK ) ) {
		return $last;
	}
	set_transient( WMWP_DETAIL_INDEX_LOCK, 1, 60 );
	update_option( WMWP_DETAIL_REBUILT_AT, time(), false );

	$items = wmwp_detail_fetch_items();
	$index = null === $items ? null : wmwp_detail_build_index( $items, 'wmwp_detail_base' );
	delete_transient( WMWP_DETAIL_INDEX_LOCK );
	if ( null === $index || 0 === $index['count'] ) {
		return $last;
	}

	// Un tipo che cala di più della metà (lista a metà durante una reindicizzazione) non si salva; dopo tre scarti di
	// fila il calo si accetta: vuol dire che i dati sono cambiati davvero
	$rejects = (int) get_option( 'wmwp_detail_index_rejects', 0 );
	foreach ( WMWP_DETAIL_TYPES as $type ) {
		$before = (int) ( $last['counts'][ $type ] ?? 0 );
		if ( $before > 0 && $index['counts'][ $type ] < 0.5 * $before && $rejects < 2 ) {
			update_option( 'wmwp_detail_index_rejects', $rejects + 1, false );
			error_log( sprintf( '[wp-package] indice dei dettagli scartato: %s %d contro %d', $type, $index['counts'][ $type ], $before ) );
			return $last;
		}
	}
	delete_option( 'wmwp_detail_index_rejects' );

	$index['config'] = $config;
	set_transient( WMWP_DETAIL_INDEX_KEY, $index, HOUR_IN_SECONDS );
	update_option( WMWP_DETAIL_INDEX_LAST, $index, false );
	// Le basi in un'opzione piccola caricata sempre: le regole di rewrite (init) non leggono l'indice intero
	update_option( 'wmwp_detail_bases', wmwp_detail_index_bases( $index ), true );
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
			$bases[ substr( $path, 0, (int) strrpos( $path, '/' ) ) ] = true;
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
