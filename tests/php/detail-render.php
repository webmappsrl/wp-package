<?php
/**
 * Test (oc:8969): lettura del dettaglio e controllo che l'id porti ancora all'elemento chiesto. Lo shard è finto
 * (`pre_http_request`). Una traccia il cui nome dà lo slug chiesto è valida; con un altro nome (l'id è passato a
 * un'altra traccia) è un 404; un POI, il cui dettaglio non ha il nome, si controlla con l'id della fonte; con lo shard
 * in errore il risultato è un WP_Error (503).
 * Si lancia con: wp eval-file tests/php/detail-render.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$failures = [];
$check    = function ( bool $ok, string $what ) use ( &$failures ) {
	if ( ! $ok ) {
		$failures[] = $what;
	}
};

if ( ! function_exists( 'wmwp_detail_load' ) ) {
	echo "FAIL: wmwp_detail_load non esiste\n";
	exit( 1 );
}

// Le tracce finte stanno solo nell'API dello shard: niente file su AWS, anche se l'ambiente ha `aws_api`
add_filter(
	'wmwp_api_urls',
	function ( $urls ) {
		$urls['aws_api'] = '';
		return $urls;
	},
	99
);

$saved = [ get_transient( 'wmwp_detail_index' ), get_option( 'wmwp_detail_index_rebuilt_at' ) ];
$index = wmwp_detail_build_index(
	[
		[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Salita' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'poi', 'id' => 9, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null, 'source' => 'D-77' ],
	],
	function ( string $type, ?array $kind, string $lang ): string {
		$name = null === $kind ? '' : wmwp_detail_text( $kind, $lang );
		return '' !== $name ? sanitize_title( $name ) : ( 'poi' === $type ? 'poi' : 'track' );
	},
	[ 'it' ]
);
$index['config'] = wmwp_detail_base_signature();
set_transient( 'wmwp_detail_index', $index, 600 );
// Nessuna ricostruzione: il test non chiama la lista dello shard
update_option( 'wmwp_detail_index_rebuilt_at', time(), false );

$shard = [
	'status' => 200,
	'track'  => [ 'it' => 'Salita' ],
	'source' => 'D-77',
];
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$shard ) {
		if ( false === strpos( $url, '/api/ec/' ) ) {
			return $pre;
		}
		$feature = false !== strpos( $url, '/api/ec/track/' )
			? [ 'type' => 'Feature', 'properties' => [ 'id' => 5, 'name' => $shard['track'] ], 'geometry' => null ]
			: [ 'type' => 'Feature', 'properties' => [ 'id' => 9, 'out_source_feature_id' => $shard['source'] ], 'geometry' => null ];
		return [
			'headers'  => [],
			'body'     => 200 === $shard['status'] ? wp_json_encode( $feature ) : 'errore',
			'response' => [
				'code'    => $shard['status'],
				'message' => '',
			],
			'cookies'  => [],
		];
	},
	10,
	3
);

$track = [
	'status' => 200,
	'type'   => 'track',
	'id'     => 5,
	'lang'   => 'it',
	'path'   => 'sentiero/salita',
];
$poi   = [
	'status' => 200,
	'type'   => 'poi',
	'id'     => 9,
	'lang'   => 'it',
	'path'   => 'poi/nuraghe',
];

// 1. Traccia con il nome giusto
$detail = wmwp_detail_load( $track );
$check( is_array( $detail ) && 5 === $detail['id'] && 'Salita' === $detail['name'], 'traccia valida' );
$check( is_array( $detail ) && isset( $detail['feature']['properties'] ), 'il dettaglio porta il JSON' );

// 2. L'id è passato a un'altra traccia → 404 (null)
delete_transient( 'wmwp_detail_json_track_5' );
delete_transient( 'wmwp_detail_json_poi_9' );
$shard['track'] = [ 'it' => 'Altro nome' ];
$check( null === wmwp_detail_load( $track ), 'nome che non torna → null' );

// 3. POI senza nome: controllo con l'id della fonte, nome dall'indice
delete_transient( 'wmwp_detail_json_track_5' );
delete_transient( 'wmwp_detail_json_poi_9' );
$detail = wmwp_detail_load( $poi );
$check( is_array( $detail ) && 'Nuraghe' === $detail['name'], 'POI valido con il nome dall\'indice' );
$shard['source'] = 'D-78';
delete_transient( 'wmwp_detail_json_poi_9' );
$check( null === wmwp_detail_load( $poi ), 'POI con un altro id della fonte → null' );

// 3b. Cache breve del JSON (60 s): due letture di fila chiamano lo shard una volta; un errore non si mette in cache
$shard['source'] = 'D-77';
$calls8969       = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$calls8969 ) {
		if ( false !== strpos( $url, '/api/ec/' ) ) {
			++$calls8969;
		}
		return $pre;
	},
	5,
	3
);
delete_transient( 'wmwp_detail_json_track_5' );
$shard['track'] = [ 'it' => 'Salita' ];
wmwp_detail_fetch( 'track', 5 );
wmwp_detail_fetch( 'track', 5 );
$check( 1 === $calls8969, 'due letture in 60 s → una chiamata allo shard (' . $calls8969 . ')' );
add_filter( 'wmwp_detail_cache_ttl', '__return_zero' );
delete_transient( 'wmwp_detail_json_track_5' );
$calls8969 = 0;
wmwp_detail_fetch( 'track', 5 );
wmwp_detail_fetch( 'track', 5 );
$check( 2 === $calls8969, 'cache spenta con il filtro → due chiamate' );
remove_all_filters( 'wmwp_detail_cache_ttl' );
delete_transient( 'wmwp_detail_json_track_5' );
// In cache la versione di prima della rinomina: si rilegge lo shard invece di dare 404
set_transient( 'wmwp_detail_json_track_5', [ 'feature' => [ 'type' => 'Feature', 'properties' => [ 'id' => 5, 'name' => [ 'it' => 'Vecchia' ] ], 'geometry' => null ], 'checked' => time() ], 60 );
$riletta = wmwp_detail_load( $track );
$check( is_array( $riletta ) && 'Salita' === $riletta['name'], 'JSON vecchio in cache → riletto dallo shard, non 404' );
$check( 'Salita' === ( get_transient( 'wmwp_detail_json_track_5' )['feature']['properties']['name']['it'] ?? '' ), 'la cache prende il JSON nuovo' );
delete_transient( 'wmwp_detail_json_track_5' );

// 4. Shard in errore → WP_Error
$shard['status'] = 500;
delete_transient( 'wmwp_detail_json_track_5' );
$check( is_wp_error( wmwp_detail_load( $track ) ), 'shard in errore → WP_Error' );
$check( false === get_transient( 'wmwp_detail_json_track_5' ), 'l\'errore non va in cache' );

false === $saved[0] ? delete_transient( 'wmwp_detail_index' ) : set_transient( 'wmwp_detail_index', $saved[0], HOUR_IN_SECONDS );
false === $saved[1] ? delete_option( 'wmwp_detail_index_rebuilt_at' ) : update_option( 'wmwp_detail_index_rebuilt_at', $saved[1], false );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
