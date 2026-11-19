<?php
/**
 * Test (oc:9000): la famiglia che dà il tipo delle tracce (base degli indirizzi, `kind` dell'indice) la indica il tema
 * con il filtro `wmwp_track_kind_taxonomy`: il plugin non conosce `route-type`, che nasce in uno shard. Senza filtro
 * le tracce non hanno tipo e la base è `track`. Lista Elasticsearch e `pois.geojson` finti (`pre_http_request`).
 * Si lancia con: wp eval-file tests/php/detail-kind.php
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

$fake = function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'pois.geojson' ) ) {
		$body = [ 'type' => 'FeatureCollection', 'features' => [] ];
	} elseif ( false !== strpos( $url, 'elasticsearch' ) ) {
		$body = [
			'hits' => [
				[
					'id'    => 7,
					'name'  => [ 'it' => 'Monte' ],
					'terms' => [ 'route-type' => [ [ 'identifier' => 'route-type-trail', 'name' => [ 'it' => 'Sentiero', 'en' => 'Trail' ] ] ] ],
				],
			],
		];
	} else {
		return $pre;
	}
	return [
		'headers'  => [],
		'body'     => wp_json_encode( $body ),
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'cookies'  => [],
	];
};
add_filter( 'pre_http_request', $fake, 10, 3 );

// Il test non dipende dal tema attivo: si tolgono i filtri già agganciati (il child di Forestas indica route-type)
$saved = $GLOBALS['wp_filter']['wmwp_track_kind_taxonomy'] ?? null;
remove_all_filters( 'wmwp_track_kind_taxonomy' );

// 1. Senza filtro: nessuna famiglia, nessun tipo
$check( '' === wmwp_track_kind_taxonomy(), 'nessuna famiglia di default' );
$items = wmwp_detail_fetch_items();
$track = is_array( $items ) ? current( array_filter( $items, fn( $i ) => 'track' === $i['type'] ) ) : null;
$check( is_array( $track ) && null === $track['kind'], 'senza famiglia la traccia non ha tipo' );
$check( 'track' === wmwp_detail_base( 'track', null, 'it' ), 'senza tipo la base è track' );
$sources = wmwp_search_sources();
$check( '' === ( $sources['tracks']['kind_field'] ?? null ), 'kind_field vuoto nella source dei percorsi' );

// 2. Con il filtro del tema: il tipo dalla famiglia indicata
$theme = fn() => 'route-type';
add_filter( 'wmwp_track_kind_taxonomy', $theme );
$check( 'route-type' === wmwp_track_kind_taxonomy(), 'famiglia dal tema' );
$items = wmwp_detail_fetch_items();
$track = is_array( $items ) ? current( array_filter( $items, fn( $i ) => 'track' === $i['type'] ) ) : null;
$check( is_array( $track ) && [ 'it' => 'Sentiero', 'en' => 'Trail' ] === $track['kind'], 'tipo dalla famiglia del tema' );
$check( 'sentiero' === wmwp_detail_base( 'track', $track['kind'] ?? null, 'it' ), 'base dal tipo' );
$sources = wmwp_search_sources();
$check( 'terms.route-type' === ( $sources['tracks']['kind_field'] ?? null ), 'kind_field nella source dei percorsi' );
remove_filter( 'wmwp_track_kind_taxonomy', $theme );

remove_filter( 'pre_http_request', $fake, 10 );
if ( null !== $saved ) {
	$GLOBALS['wp_filter']['wmwp_track_kind_taxonomy'] = $saved;
}
if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
