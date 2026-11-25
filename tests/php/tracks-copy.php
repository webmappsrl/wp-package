<?php
/**
 * Test (oc:9003): copia delle tracce per le griglie. Dalla lista Elasticsearch, nell'ordine dell'API, con i soli campi
 * di card, faccette e slider; scritta solo dal commit (cioè quando il rebuild dell'indice dei dettagli la accetta);
 * layer di una famiglia con più tracce di un termine, fra quelli ammessi.
 * Si lancia con: wp eval-file tests/php/tracks-copy.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1. Rimette l'option com'era, anche su errore.
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

$saved   = get_option( WMWP_TRACKS_COPY_OPTION, null );
$restore = function () use ( $saved ) {
	wmwp_tracks_copy_pending( null, true );
	null === $saved ? delete_option( WMWP_TRACKS_COPY_OPTION ) : update_option( WMWP_TRACKS_COPY_OPTION, $saved, false );
};
set_exception_handler(
	function ( Throwable $e ) use ( $restore ) {
		$restore();
		echo 'FAIL: errore nel test: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
		exit( 1 );
	}
);

$trail = fn( int $id, string $name, array $layers, float $km = 0 ) => [
	'_source' => [
		'id'                 => (string) $id,
		'name'               => [ 'it' => $name ],
		'ref'                => 'T ' . $id,
		'feature_image'      => '',
		'distance'           => (string) $km,
		'duration_forward'   => '0',
		'ascent'             => '0',
		'layers'             => $layers,
		'terms'              => [ 'route-type' => [ [ 'identifier' => 'route-type-trail' ] ] ],
		'taxonomyActivities' => [ 'hiking' ],
		'taxonomyWheres'     => [ 'Sardegna', 'Nuoro' ],
		'taxonomyIcons'      => [ 'hiking' => [ 'label' => [ 'it' => 'Escursionismo' ] ] ],
		'searchable'         => 'testo lungo che non serve',
		'start'              => [ [ 9, 40 ] ],
		'sardegnasentieri_id' => '1272',
	],
];
$walk = $trail( 9, 'Cammino', [ 8 ] );
$walk['_source']['terms']['route-type'][0]['identifier'] = 'route-type-walk';
$hits = [ $trail( 3, 'Zeta', [ 11, 2 ], 4.5 ), $trail( 1, 'Alfa', [ 11 ] ), $trail( 2, 'Beta', [ 9 ] ), $trail( 4, 'Gamma', [ 9 ] ), $trail( 5, 'Delta', [ 9 ] ), $walk ];

// 1. Copia: ordine dell'API e soli campi usati
$copy = wmwp_tracks_copy_build( $hits );
$check( [ 1, 2, 9, 5, 4, 3 ] === array_column( $copy, 'id' ), 'copia: ordine per nome e id interi' );
$keys = array_keys( $copy[0] );
sort( $keys );
$check( [ 'ascent', 'distance', 'duration_forward', 'feature_image', 'id', 'layers', 'name', 'ref', 'taxonomyActivities', 'taxonomyIcons', 'taxonomyWheres', 'terms' ] === $keys, 'copia: solo i campi di card, faccette e slider' );
$zeta = array_values( array_filter( $copy, fn( $t ) => 3 === $t['id'] ) )[0];
$check( 4.5 === $zeta['distance'] && [ 11, 2 ] === $zeta['layers'], 'copia: numeri e layer come numeri' );
$check( [] === wmwp_tracks_copy_build( [ [ '_source' => [ 'name' => 'senza id' ] ] ] ), 'copia: senza id non entra' );

// 1b. Ordine per nome italiano senza spazi e tab ai lati, come sortName() di search-logic.js (la ricerca unica)
$named = wmwp_tracks_copy_build( [ $trail( 7, "\tZeta", [ 11 ] ), $trail( 8, ' Alfa', [ 11 ] ), $trail( 6, 'Beta', [ 11 ] ) ] );
$check( [ 8, 6, 7 ] === array_column( $named, 'id' ), 'copia: ordine per nome ripulito' );

// 2. Si scrive solo con il commit, e solo se c'è una copia in attesa
delete_option( WMWP_TRACKS_COPY_OPTION );
wmwp_tracks_copy_pending( null, true );
wmwp_tracks_copy_commit();
$check( null === wmwp_tracks_copy(), 'commit senza copia in attesa: non scrive' );
wmwp_tracks_copy_pending( $hits );
$check( null === wmwp_tracks_copy(), 'copia in attesa: non ancora scritta' );
wmwp_tracks_copy_commit();
$check( 6 === count( (array) wmwp_tracks_copy() ), 'commit: copia scritta' );

// 2b. Una lettura per richiesta, rinnovata quando l'option cambia (commit, comando, test)
$first = wmwp_tracks_copy();
update_option( WMWP_TRACKS_COPY_OPTION, array_slice( (array) $first, 0, 2 ), false );
$check( 2 === count( (array) wmwp_tracks_copy() ), 'lettura unica: aggiornata quando l\'option cambia' );
wmwp_tracks_copy_pending( $hits );
wmwp_tracks_copy_commit();
$check( 6 === count( (array) wmwp_tracks_copy() ), 'lettura unica: aggiornata dal commit' );

// 3. Layer con più tracce di un termine, fra quelli ammessi (il 9 ha più sentieri ma non è ammesso)
$check( 11 === wmwp_tracks_layer_for_term( 'route-type', 'route-type-trail', [ 11, 10, 8 ] ), 'layer: il più numeroso fra gli ammessi' );
$check( 9 === wmwp_tracks_layer_for_term( 'route-type', 'route-type-trail', [ 11, 9 ] ), 'layer: il 9 vince se ammesso' );
$check( 8 === wmwp_tracks_layer_for_term( 'route-type', 'route-type-walk', [ 11, 10, 8 ] ), 'layer: cammini' );
$check( null === wmwp_tracks_layer_for_term( 'route-type', 'route-type-itinerary', [ 11, 10, 8 ] ), 'layer: nessuna traccia del termine' );
$check( null === wmwp_tracks_layer_for_term( 'route-type', 'route-type-trail', [] ), 'layer: nessun layer ammesso' );
delete_option( WMWP_TRACKS_COPY_OPTION );
$check( null === wmwp_tracks_layer_for_term( 'route-type', 'route-type-trail', [ 11 ] ), 'layer: senza copia' );

$restore();

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
