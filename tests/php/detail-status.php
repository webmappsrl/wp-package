<?php
/**
 * Test (oc:9000): percorribilità e avviso del dettaglio della traccia (includes/detail-status.php). La data della
 * percorribilità c'è solo se l'ha messa una persona (`user` non null: lo stato dell'import ha la data dell'import);
 * senza termine, o con «Sconosciuta», lo stato è di assenza con livello 0; l'avviso è acceso solo per i valori che lo
 * shard legge come veri e con un messaggio. Proprietà ridotte dal dev (traccia 99, 18/11). Nessun avviso PHP.
 * Si lancia con: wp eval-file tests/php/detail-status.php
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
set_error_handler(
	function ( $errno, $message, $file, $line ) use ( &$failures ) {
		$failures[] = "avviso PHP: $message ($file:$line)";
		return true;
	}
);
$saved = [];
foreach ( [ 'wmwp_status_families', 'wmwp_walkability_taxonomy' ] as $hook ) {
	$saved[ $hook ] = $GLOBALS['wp_filter'][ $hook ] ?? null;
	remove_all_filters( $hook );
}

$term     = fn( string $suffix, array $name ) => [ 'walkability-status' => [ [ 'identifier' => 'walkability-status-' . $suffix, 'name' => $name ] ] ];
$imported = [
	'taxonomies'  => $term( 'walkable', [ 'it' => 'Percorribile', 'en' => 'Walkable' ] ),
	'walkability' => [
		'last_update' => '2026-11-18T06:21:16+01:00',
		'user'        => null,
		'description' => null,
	],
];

// 1. Stato dell'import: niente data
$w = wmwp_walkability( $imported, 'en' );
$check( 'walkability-status-walkable' === $w['identifier'] && 'Walkable' === $w['name'] && 'en' === $w['name_lang'], 'stato e nome' );
$check( 4 === $w['level'] && 4 === $w['total'] && false === $w['absent'], 'livello 4 su 4' );
$check( null === $w['updated_at'] && '' === $w['note'], 'import: niente data né nota' );

// 2. Stato messo da Nova: data e nota (solo italiano, pagina inglese → lingua della nota)
$nova = [
	'taxonomies'  => $term( 'partially-walkable', [ 'it' => 'Parzialmente percorribile' ] ),
	'walkability' => [
		'last_update' => '2026-11-17T09:06:46+01:00',
		'user'        => 3,
		'description' => [ 'it' => 'zone di fango' ],
	],
];
$w = wmwp_walkability( $nova, 'en' );
$check( '2026-11-17T09:06:46+01:00' === $w['updated_at'], 'Nova: data' );
$check( 'zone di fango' === $w['note'] && 'it' === $w['note_lang'], 'Nova: nota con la sua lingua' );
$check( 'Partially walkable' === $w['name'] && 'en' === $w['name_lang'], 'nome dal registro se il termine non ha la lingua' );
$check( 3 === $w['level'], 'parzialmente 3' );

// 3. Senza termine, senza walkability, senza taxonomies; e «Sconosciuta»
foreach ( [ [], [ 'taxonomies' => [] ], [ 'taxonomies' => $term( 'unknown', [ 'it' => 'Sconosciuta' ] ) ] ] as $i => $props ) {
	$w = wmwp_walkability( $props, 'it' );
	$check( true === $w['absent'] && 0 === $w['level'] && 4 === $w['total'] && 'Sconosciuta' === $w['name'], "assenza $i" );
}

// 4. Stato che il registro non conosce: nome del termine, nessuna tacca, non di assenza
$w = wmwp_walkability( [ 'taxonomies' => $term( 'closed', [ 'it' => 'Chiuso' ] ) ], 'it' );
$check( 'Chiuso' === $w['name'] && 0 === $w['level'] && false === $w['absent'], 'stato nuovo' );

// 5. Avviso: acceso per i valori veri di FILTER_VALIDATE_BOOLEAN, come isNotAccessibleOn() dello shard, con messaggio
foreach ( [ true, 1, '1', 'true', 'TRUE', 'yes', 'on' ] as $on ) {
	$n = wmwp_track_notice(
		[
			'not_accessible'            => $on,
			'not_accessible_message'    => [ 'it' => 'Frana al km 3' ],
			'not_accessible_updated_at' => '2026-11-17T11:34:00+00:00',
		],
		'en'
	);
	$check( is_array( $n ) && 'Frana al km 3' === $n['message'] && 'it' === $n['lang'] && '2026-11-17T11:34:00+00:00' === $n['updated_at'], 'acceso: ' . var_export( $on, true ) );
}
foreach ( [ false, 0, '0', 'false', '', null, 'no', [ true ] ] as $off ) {
	$check( null === wmwp_track_notice( [ 'not_accessible' => $off, 'not_accessible_message' => [ 'it' => 'x' ] ], 'it' ), 'spento: ' . var_export( $off, true ) );
}
$check( null === wmwp_track_notice( [ 'not_accessible' => true, 'not_accessible_message' => [ 'it' => ' ', 'en' => '' ] ], 'it' ), 'acceso senza messaggio' );
$check( null === wmwp_track_notice( [], 'it' ), 'nessun avviso' );
$n = wmwp_track_notice( [ 'not_accessible' => true, 'not_accessible_message' => [ 'it' => 'Chiuso' ] ], 'it' );
$check( is_array( $n ) && null === $n['updated_at'], 'avviso senza data' );

foreach ( $saved as $hook => $callbacks ) {
	if ( null !== $callbacks ) {
		$GLOBALS['wp_filter'][ $hook ] = $callbacks;
	}
}
restore_error_handler();
if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
