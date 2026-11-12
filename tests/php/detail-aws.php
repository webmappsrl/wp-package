<?php
/**
 * Test (oc:8969): il JSON della traccia si legge dal file pubblicato su AWS (`<awsApi>/tracks/<id>.json`) con la
 * richiesta condizionale: entro la cache breve nessuna chiamata, dopo un `If-None-Match`/`If-Modified-Since` e con il
 * 304 si riusa il JSON già letto. Senza file su AWS, o senza `aws_api`, si legge l'API dello shard; i POI un file non
 * lo hanno e si leggono sempre dallo shard. AWS e shard sono finti (`pre_http_request`).
 * Si lancia con: wp eval-file tests/php/detail-aws.php
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

$aws_base = 'http://aws.test/wmfe/prova';
$with_aws = function ( $urls ) use ( $aws_base ) {
	$urls['aws_api'] = $aws_base;
	return $urls;
};
add_filter( 'wmwp_api_urls', $with_aws, 20 );

$aws = [
	'status'   => 200,
	'etag'     => '"e1"',
	'modified' => 'Thu, 12 Nov 2026 05:43:03 GMT',
	'name'     => 'Salita',
];
$log = [];
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$aws, &$log, $aws_base ) {
		$headers = array_change_key_case( (array) ( $args['headers'] ?? [] ), CASE_LOWER );
		if ( 0 === strpos( $url, $aws_base . '/' ) ) {
			$log[] = [ 'aws', $url, $headers ];
			$same  = ( '' !== $aws['etag'] && ( $headers['if-none-match'] ?? '' ) === $aws['etag'] )
				|| ( '' === $aws['etag'] && ( $headers['if-modified-since'] ?? '' ) === $aws['modified'] );
			$code  = 200 === $aws['status'] && $same ? 304 : $aws['status'];
			$body  = 200 === $code ? wp_json_encode( [ 'type' => 'Feature', 'properties' => [ 'id' => 5, 'name' => [ 'it' => $aws['name'] ] ], 'geometry' => null ] ) : '';
			return [
				'headers'  => array_filter( [ 'etag' => $aws['etag'], 'last-modified' => $aws['modified'] ] ),
				'body'     => $body,
				'response' => [ 'code' => $code, 'message' => '' ],
				'cookies'  => [],
			];
		}
		if ( false !== strpos( $url, '/api/ec/' ) ) {
			$log[] = [ 'api', $url, $headers ];
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'type' => 'Feature', 'properties' => [ 'id' => 5, 'name' => [ 'it' => 'Dallo shard' ] ], 'geometry' => null ] ),
				'response' => [ 'code' => 200, 'message' => '' ],
				'cookies'  => [],
			];
		}
		return $pre;
	},
	10,
	3
);

$name = function ( $feature ): string {
	return is_array( $feature ) ? (string) ( $feature['properties']['name']['it'] ?? '' ) : 'errore';
};

$saved = [ get_transient( 'wmwp_detail_json_track_5' ), get_transient( 'wmwp_detail_json_poi_5' ) ];
delete_transient( 'wmwp_detail_json_track_5' );
delete_transient( 'wmwp_detail_json_poi_5' );

// 1. Prima lettura: il file su AWS, senza intestazioni condizionali
$check( 'Salita' === $name( wmwp_detail_fetch( 'track', 5 ) ), 'traccia letta dal file su AWS' );
$check( 1 === count( $log ) && 'aws' === $log[0][0] && $aws_base . '/tracks/5.json' === $log[0][1], 'una chiamata, a <awsApi>/tracks/5.json' );
$check( ! isset( $log[0][2]['if-none-match'] ), 'la prima lettura non è condizionale' );

// 2. Entro la cache breve nessuna chiamata
wmwp_detail_fetch( 'track', 5 );
$check( 1 === count( $log ), 'seconda lettura entro 60 s → nessuna chiamata' );

// 3. Dopo la cache breve (o con la rilettura forzata): richiesta condizionale, 304, stesso JSON
$log = [];
$check( 'Salita' === $name( wmwp_detail_fetch( 'track', 5, true ) ), 'con il 304 si riusa il JSON in cache' );
$check( 1 === count( $log ) && '"e1"' === ( $log[0][2]['if-none-match'] ?? '' ), 'la richiesta porta If-None-Match' );

// 4. File cambiato su AWS: 200, JSON nuovo in cache
$aws['etag'] = '"e2"';
$aws['name'] = 'Salita nuova';
$log         = [];
$check( 'Salita nuova' === $name( wmwp_detail_fetch( 'track', 5, true ) ), 'file cambiato → JSON nuovo' );
$check( 'Salita nuova' === $name( wmwp_detail_fetch( 'track', 5 ) ), 'il JSON nuovo resta in cache' );
$check( 1 === count( $log ), 'una sola chiamata per il file cambiato' );

// 5. Solo Last-Modified (niente ETag): richiesta con If-Modified-Since
$aws['etag'] = '';
delete_transient( 'wmwp_detail_json_track_5' );
wmwp_detail_fetch( 'track', 5 );
$log = [];
wmwp_detail_fetch( 'track', 5, true );
$check( 1 === count( $log ) && $aws['modified'] === ( $log[0][2]['if-modified-since'] ?? '' ), 'senza ETag → If-Modified-Since' );

// 6. File non ancora su AWS: si legge l'API dello shard
$aws['status'] = 404;
delete_transient( 'wmwp_detail_json_track_5' );
$log = [];
$check( 'Dallo shard' === $name( wmwp_detail_fetch( 'track', 5 ) ), 'file assente su AWS → API dello shard' );
$check( 2 === count( $log ) && 'api' === $log[1][0], 'prima AWS, poi lo shard' );

// 7. I POI si leggono sempre dallo shard
$log = [];
wmwp_detail_fetch( 'poi', 5 );
$check( 1 === count( $log ) && 'api' === $log[0][0], 'POI → solo API dello shard' );

// 8. Senza aws_api le tracce si leggono dallo shard
remove_filter( 'wmwp_api_urls', $with_aws, 20 );
add_filter(
	'wmwp_api_urls',
	function ( $urls ) {
		$urls['aws_api'] = '';
		return $urls;
	},
	99
);
$aws['status'] = 200;
delete_transient( 'wmwp_detail_json_track_5' );
$log = [];
wmwp_detail_fetch( 'track', 5 );
$check( 1 === count( $log ) && 'api' === $log[0][0], 'senza aws_api → API dello shard' );

foreach ( [ 'track', 'poi' ] as $i => $type ) {
	delete_transient( 'wmwp_detail_json_' . $type . '_5' );
	if ( false !== $saved[ $i ] ) {
		set_transient( 'wmwp_detail_json_' . $type . '_5', $saved[ $i ], MINUTE_IN_SECONDS );
	}
}

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
