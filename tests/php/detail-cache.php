<?php
/**
 * Test (oc:8969): lettura degli elementi dallo shard e cache dell'indice. Lo shard è finto (`pre_http_request`):
 * l'indice si costruisce dalla lista delle tracce di Elasticsearch e da `pois.geojson` (che sul dev arriva come
 * stringa JSON da decodificare due volte); con lo shard in errore o con una risposta parziale resta l'indice vecchio;
 * una ricostruzione forzata non si ripete prima di 5 minuti.
 * Si lancia con: wp eval-file tests/php/detail-cache.php
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

if ( ! function_exists( 'wmwp_detail_index' ) ) {
	echo "FAIL: wmwp_detail_index non esiste\n";
	exit( 1 );
}

// Le basi del test non dipendono dal tema attivo (Forestas mette i POI sotto una pagina)
foreach ( [ 'wmwp_detail_tracks_use_page', 'wmwp_detail_tracks_page', 'wmwp_detail_pois_use_page', 'wmwp_detail_pois_page' ] as $option ) {
	remove_all_filters( 'pre_option_' . $option );
}

// Stato pulito: niente indice, niente ricostruzioni recenti
$reset = function () {
	delete_transient( 'wmwp_detail_index' );
	delete_transient( 'wmwp_detail_index_lock' );
	delete_option( 'wmwp_detail_index_rebuilt_at' );
	delete_option( 'wmwp_detail_index_last' );
};
$reset();

$hit  = function ( int $id, string $name, ?string $type ) {
	$hit = [
		'id'   => $id,
		'name' => [ 'it' => $name ],
	];
	if ( null !== $type ) {
		$hit['terms'] = [ 'route-type' => [ [ 'identifier' => 'x', 'name' => [ 'it' => $type ] ] ] ];
	}
	return $hit;
};
$poi  = function ( int $id, string $name ) {
	return [
		'type'       => 'Feature',
		'properties' => [
			'id'         => $id,
			'name'       => [ 'it' => $name ],
			'taxonomies' => [ 'poi-type' => [ [ 'identifier' => 'y', 'name' => [ 'it' => 'Nuraghi' ] ] ] ],
		],
	];
};
$full = [
	'tracks' => [ $hit( 1, 'Uno', 'Sentiero' ), $hit( 2, 'Due', 'Cammino' ), $hit( 3, 'Tre', null ) ],
	'pois'   => [ $poi( 10, 'Nuraghe A' ), $poi( 11, 'Nuraghe B' ) ],
];

$shard = [
	'status' => 200,
	'data'   => $full,
];
$calls = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$shard, &$calls ) {
		if ( false === strpos( $url, 'elasticsearch' ) && false === strpos( $url, 'pois.geojson' ) ) {
			return $pre;
		}
		++$calls;
		if ( 200 !== $shard['status'] ) {
			return [
				'headers'  => [],
				'body'     => 'errore',
				'response' => [
					'code'    => $shard['status'],
					'message' => 'Errore',
				],
				'cookies'  => [],
			];
		}
		$body = false !== strpos( $url, 'elasticsearch' )
			? wp_json_encode( [ 'hits' => $shard['data']['tracks'] ] )
			// Come sul dev: il file è una stringa JSON dentro un JSON
			: wp_json_encode( wp_json_encode( [ 'type' => 'FeatureCollection', 'features' => $shard['data']['pois'] ] ) );
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
		];
	},
	10,
	3
);

// 1. Primo indice dallo shard
$index = wmwp_detail_index();
$check( is_array( $index ) && 5 === $index['count'], 'indice da 5 elementi (3 tracce + 2 POI)' );
$check( [ 'track', 2 ] === ( $index['paths']['it']['cammino/due'] ?? null ), 'traccia sotto la base del suo tipo' );
$check( [ 'track', 3 ] === ( $index['paths']['it']['track/tre'] ?? null ), 'traccia senza tipo sotto track' );
$check( [ 'poi', 10 ] === ( $index['paths']['it']['nuraghi/nuraghe-a'] ?? null ), 'POI dal pois.geojson con doppia codifica' );

// 2. Shard in errore: resta l'indice vecchio
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard['status'] = 500;
$index           = wmwp_detail_index( true );
$check( is_array( $index ) && 5 === $index['count'], 'shard in errore → indice vecchio' );

// 3. Risposta parziale (meno della metà): resta l'indice vecchio
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard  = [
	'status' => 200,
	'data'   => [
		'tracks' => [ $hit( 1, 'Uno', 'Sentiero' ) ],
		'pois'   => [],
	],
];
$index = wmwp_detail_index( true );
$check( is_array( $index ) && 5 === $index['count'], 'risposta parziale → indice vecchio' );

// 4. Due ricostruzioni forzate di fila: la seconda non chiama lo shard
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard = [
	'status' => 200,
	'data'   => $full,
];
wmwp_detail_index( true );
$before = $calls;
wmwp_detail_index( true );
$check( $before === $calls, 'seconda ricostruzione entro 5 minuti → nessuna chiamata' );
$check( false === wmwp_detail_rebuild_allowed(), 'ricostruzione non permessa subito dopo' );

// 5. Base cambiata (impostazioni o tema): l'indice in cache non vale più e si ricostruisce
delete_option( 'wmwp_detail_index_rebuilt_at' );
$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'POI test', 'post_name' => 'poi-test-8969' ] );
add_filter( 'pre_option_wmwp_detail_pois_use_page', function () { return '1'; } );
add_filter( 'pre_option_wmwp_detail_pois_page', function () use ( $page ) { return (string) $page; } );
$index = wmwp_detail_index();
$check( [ 'poi', 10 ] === ( $index['paths']['it']['poi-test-8969/nuraghe-a'] ?? null ), 'base dei POI cambiata → indice ricostruito con la pagina' );
remove_all_filters( 'pre_option_wmwp_detail_pois_use_page' );
remove_all_filters( 'pre_option_wmwp_detail_pois_page' );
wp_delete_post( $page, true );

// 6. Elasticsearch vuoto (POI interi): l'indice non perde le tracce
$reset();
$shard = [ 'status' => 200, 'data' => $full ];
wmwp_detail_index();
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard['data'] = [ 'tracks' => [], 'pois' => $full['pois'] ];
$index = wmwp_detail_index( true );
$check( is_array( $index ) && 3 === ( $index['counts']['track'] ?? 0 ), 'lista delle tracce vuota → indice vecchio' );
// Base rinominata (stesso id, altro percorso): l'impronta cambia
$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Base', 'post_name' => 'base-8969-a' ] );
add_filter( 'pre_option_wmwp_detail_pois_use_page', function () { return '1'; } );
add_filter( 'pre_option_wmwp_detail_pois_page', function () use ( $page ) { return (string) $page; } );
$prima = wmwp_detail_base_signature();
wp_update_post( [ 'ID' => $page, 'post_name' => 'base-8969-b' ] );
$check( $prima !== wmwp_detail_base_signature(), 'pagina base rinominata → impronta diversa' );
remove_all_filters( 'pre_option_wmwp_detail_pois_use_page' );
remove_all_filters( 'pre_option_wmwp_detail_pois_page' );
wp_delete_post( $page, true );
// Le basi stanno in un'opzione piccola: init non carica l'indice
$shard = [ 'status' => 200, 'data' => $full ];
$reset();
wmwp_detail_index();
$check( in_array( 'sentiero', (array) get_option( 'wmwp_detail_bases' ), true ), 'basi salvate nell\'opzione' );

// 7. Senza indice in cache e shard giù: null, mai un indice vuoto salvato, e niente nuovo tentativo subito
$reset();
$shard['status'] = 500;
$check( null === wmwp_detail_index(), 'niente cache e shard giù → null' );
$check( false === get_transient( 'wmwp_detail_index' ), 'nessun indice vuoto salvato' );
$before = $calls;
wmwp_detail_index();
$check( $before === $calls, 'shard giù: il tentativo successivo aspetta 5 minuti' );

$reset();

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
