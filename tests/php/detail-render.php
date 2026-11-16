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
require __DIR__ . '/history-sandbox.php';

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
		[ 'type' => 'track', 'id' => 6, 'names' => [ 'it' => 'Discesa' ], 'kind' => [ 'it' => 'Sentiero' ], 'source' => 'T-6' ],
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
	'track6' => [ 'it' => 'Discesa' ],
	'fonte6' => 'T-6',
];
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$shard ) {
		if ( false === strpos( $url, '/api/ec/' ) ) {
			return $pre;
		}
		if ( false !== strpos( $url, '/api/ec/track/6' ) ) {
			$feature = [ 'type' => 'Feature', 'properties' => [ 'id' => 6, 'name' => $shard['track6'], 'out_source_feature_id' => $shard['fonte6'] ], 'geometry' => null ];
		} else {
			$feature = false !== strpos( $url, '/api/ec/track/' )
				? [ 'type' => 'Feature', 'properties' => [ 'id' => 5, 'name' => $shard['track'] ], 'geometry' => null ]
				: [ 'type' => 'Feature', 'properties' => [ 'id' => 9, 'out_source_feature_id' => $shard['source'] ], 'geometry' => null ];
		}
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

// 1b. Doppioni della 0.3.0 (oc:8983): il suffisso accettato è quello che l'indice ha dato all'elemento. Una traccia
// senza chiave della fonte su `-2` ha la sua pagina; una con la chiave non fa ricostruire l'indice al visitatore
$indice_render = get_transient( 'wmwp_detail_index' );
$base_render   = function ( string $type, ?array $kind, string $lang ): string {
	$name = null === $kind ? '' : wmwp_detail_text( $kind, $lang );
	return '' !== $name ? sanitize_title( $name ) : ( 'poi' === $type ? 'poi' : 'track' );
};
$con_doppi     = wmwp_detail_build_index(
	[
		[ 'type' => 'track', 'id' => 4, 'names' => [ 'it' => 'Salita' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Salita' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'track', 'id' => 3, 'names' => [ 'it' => 'Discesa' ], 'kind' => [ 'it' => 'Sentiero' ], 'source' => 'T-1' ],
		[ 'type' => 'track', 'id' => 6, 'names' => [ 'it' => 'Discesa' ], 'kind' => [ 'it' => 'Sentiero' ], 'source' => 'T-6' ],
		[ 'type' => 'track', 'id' => 7, 'names' => [ 'it' => 'Foo 3' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'poi', 'id' => 9, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null, 'source' => 'D-77' ],
	],
	$base_render,
	[ 'it' ]
);
$con_doppi['config'] = wmwp_detail_base_signature();
set_transient( 'wmwp_detail_index', $con_doppi, 600 );
$salita_2 = [ 'status' => 200, 'type' => 'track', 'id' => 5, 'lang' => 'it', 'path' => 'sentiero/salita-2' ];
$check( 'sentiero/salita-2' === ( $con_doppi['urls']['track'][5]['it'] ?? null ), 'indice di prova: Salita 5 su -2' );
$check( is_array( wmwp_detail_load( $salita_2 ) ), 'doppione -2 senza chiave della fonte → pagina' );
$check( false === wmwp_detail_slug_matches( [ 'id' => 5, 'type' => 'track', 'lang' => 'it', 'path' => 'sentiero/salita-3' ], [ 'name' => [ 'it' => 'Salita' ] ], '' ), 'suffisso che l\'indice non ha dato all\'elemento → no' );
$check( false === wmwp_detail_slug_matches( [ 'id' => 5, 'type' => 'track', 'lang' => 'it', 'path' => 'sentiero/salita-alta' ], [ 'name' => [ 'it' => 'Salita' ] ], '' ), 'slug diverso → no' );
// Rinominata da «Foo 3» a «Foo»: l'indice (non ancora ricostruito) le ha dato `foo-3` senza suffisso, quindi il nome
// nuovo non torna
$check( false === wmwp_detail_slug_matches( [ 'id' => 7, 'type' => 'track', 'lang' => 'it', 'path' => 'sentiero/foo-3' ], [ 'name' => [ 'it' => 'Foo' ] ], '' ), 'rinomina da «Foo 3» a «Foo»: foo-3 non è più il suo slug' );
// Con un indice della 0.3.0 le forme della 0.2.0 (`-<id>`, `-<chiave>`) non valgono più: «Foo 3» con id 3 rinominata
// «Foo» non resta servita su `foo-3`
$foo_tre = wmwp_detail_build_index( [ [ 'type' => 'track', 'id' => 3, 'names' => [ 'it' => 'Foo 3' ], 'kind' => [ 'it' => 'Sentiero' ] ] ], $base_render, [ 'it' ] );
$foo_tre['config'] = wmwp_detail_base_signature();
set_transient( 'wmwp_detail_index', $foo_tre, 600 );
$check( isset( $foo_tre['suffixes'] ), 'indice 0.3.0 riconoscibile anche senza doppioni' );
$check( false === wmwp_detail_slug_matches( [ 'id' => 3, 'type' => 'track', 'lang' => 'it', 'path' => 'sentiero/foo-3' ], [ 'name' => [ 'it' => 'Foo' ] ], '' ), 'rinomina da «Foo 3» a «Foo» con id 3: la forma -<id> non vale più' );
set_transient( 'wmwp_detail_index', $con_doppi, 600 );
delete_option( 'wmwp_detail_index_rebuilt_at' );
$check( is_array( wmwp_detail_load( [ 'status' => 200, 'type' => 'track', 'id' => 6, 'lang' => 'it', 'path' => 'sentiero/discesa-2' ] ) ), 'doppione -2 con la chiave della fonte → pagina' );
$check( false === get_option( 'wmwp_detail_index_rebuilt_at' ), 'doppione -2 con la chiave della fonte → nessuna ricostruzione' );
set_transient( 'wmwp_detail_index', $indice_render, 600 );
update_option( 'wmwp_detail_index_rebuilt_at', time(), false );
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

// 3a. Traccia con la chiave stabile della fonte: decide la fonte, non il nome. Rinominata resta lei; con lo stesso nome
// ma un'altra fonte (dopo un reset l'id è passato a un'altra traccia omonima) è un 404
$discesa = [
	'status' => 200,
	'type'   => 'track',
	'id'     => 6,
	'lang'   => 'it',
	'path'   => 'sentiero/discesa',
];
$detail  = wmwp_detail_load( $discesa );
$check( is_array( $detail ) && 6 === $detail['id'], 'traccia con la stessa fonte' );
delete_transient( 'wmwp_detail_json_track_6' );
$shard['track6'] = [ 'it' => 'Discesa al mare' ];
$check( is_array( wmwp_detail_load( $discesa ) ), 'traccia rinominata con la stessa fonte → resta valida' );
delete_transient( 'wmwp_detail_json_track_6' );
$shard['track6'] = [ 'it' => 'Discesa' ];
$shard['fonte6'] = 'T-99';
$check( null === wmwp_detail_load( $discesa ), 'stesso nome, altra fonte → null' );
$shard['fonte6'] = 'T-6';
delete_transient( 'wmwp_detail_json_track_6' );

// Doppione con l'indirizzo -<fonte> e un dettaglio senza la fonte (file generato prima che la fonte ci fosse): il nome
// decide, con il suffisso della fonte dell'indice
// Le forme della 0.2.0 valgono solo con un indice della 0.2.0 in cache: senza `suffixes`
$indice_020 = get_transient( 'wmwp_detail_index' );
unset( $indice_020['suffixes'] );
set_transient( 'wmwp_detail_index', $indice_020, 600 );
$monte = [ 'type' => 'track', 'id' => 21, 'lang' => 'it', 'path' => 'sentiero/monte-4076' ];
$check( wmwp_detail_matches( $monte, [ 'properties' => [ 'name' => [ 'it' => 'Monte' ] ] ], [ 'source' => '4076' ] ), 'doppione -<fonte>, dettaglio senza fonte' );
// Un suffisso numerico non dice più di chi è il percorso (`-2` e `-4076` hanno la stessa forma, oc:8983): la forma
// vecchia con una chiave testuale sì
$check( ! wmwp_detail_matches( [ 'type' => 'track', 'id' => 21, 'lang' => 'it', 'path' => 'sentiero/monte-n-12-b' ], [ 'properties' => [ 'name' => [ 'it' => 'Monte' ] ] ], [ 'source' => '4077' ] ), 'doppione -<fonte> testuale di un altro elemento' );
set_transient( 'wmwp_detail_index', $index, 600 );

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

// 4. Elemento che la fonte non ha (404: cancellato, o id che non esiste): pagina 404, non 503
$shard['status'] = 404;
delete_transient( 'wmwp_detail_json_track_5' );
$assente = wmwp_detail_fetch( 'track', 5, true );
$check( is_wp_error( $assente ) && 'wmwp_detail_not_found' === $assente->get_error_code(), 'fetch: 404 della fonte → wmwp_detail_not_found' );
$check( null === wmwp_detail_load( $track ), 'elemento cancellato → null (404), non WP_Error (503)' );
$check( null === wmwp_detail_load( $poi ), 'POI cancellato → null (404)' );

// 4b. Shard in errore (5xx, rete) → WP_Error: la pagina è «non disponibile» (503)
$shard['status'] = 500;
delete_transient( 'wmwp_detail_json_track_5' );
$errore = wmwp_detail_load( $track );
$check( is_wp_error( $errore ) && 'wmwp_detail_unavailable' === $errore->get_error_code(), 'shard in errore → WP_Error wmwp_detail_unavailable' );
$check( false === get_transient( 'wmwp_detail_json_track_5' ), 'l\'errore non va in cache' );

false === $saved[0] ? delete_transient( 'wmwp_detail_index' ) : set_transient( 'wmwp_detail_index', $saved[0], HOUR_IN_SECONDS );
false === $saved[1] ? delete_option( 'wmwp_detail_index_rebuilt_at' ) : update_option( 'wmwp_detail_index_rebuilt_at', $saved[1], false );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
