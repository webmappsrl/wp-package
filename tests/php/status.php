<?php
/**
 * Test (oc:9000): registro degli stati delle famiglie (includes/status.php). La percorribilità di wm-package c'è già,
 * con «Sconosciuta» come stato di assenza; il totale conta tutti gli stati, assenza compresa, e il livello è la posizione
 * dello stato (l'assenza vale 0); una
 * famiglia aggiunta dal tema e uno stato in più cambiano il totale; il nome della famiglia della percorribilità si
 * cambia con un filtro e i prefissi seguono. Il test toglie i filtri del tema attivo e li rimette alla fine.
 * Si lancia con: wp eval-file tests/php/status.php
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

$saved = [];
foreach ( [ 'wmwp_status_families', 'wmwp_walkability_taxonomy' ] as $hook ) {
	$saved[ $hook ] = $GLOBALS['wp_filter'][ $hook ] ?? null;
	remove_all_filters( $hook );
}

// 1. La percorribilità di wm-package
$check( 'walkability-status' === wmwp_walkability_taxonomy(), 'famiglia della percorribilità di default' );
$families = wmwp_status_families();
$walk     = $families['walkability-status'] ?? null;
$check( is_array( $walk ) && 'walkability-status-unknown' === $walk['absent'], 'stato di assenza: sconosciuta' );
$check(
	is_array( $walk ) && [ 'walkability-status-unknown', 'walkability-status-not-walkable', 'walkability-status-partially-walkable', 'walkability-status-walkable' ] === array_column( $walk['states'], 'identifier' ),
	'quattro stati in ordine'
);
// oc:9018: nomi e icone dai termini dello shard; nel registro resta solo il nome dello stato di assenza, che non arriva
// con nessun dato (un percorso senza stato non ha il termine)
$check( ! isset( $walk['states'][2]['name'] ), 'nessun nome per gli stati che arrivano con il termine' );
$check( [ 'it' => 'Sconosciuta', 'en' => 'Unknown' ] === ( $walk['states'][0]['name'] ?? null ), 'nome dello stato di assenza' );

// 2. Livello e totale: tante tacche quanti sono gli stati della famiglia nello shard, assenza compresa; ogni stato
// accende fino alla sua posizione, lo stato di assenza nessuna (Rubens, 18/11)
$level = fn( ?string $id ) => array_values( wmwp_status_level( 'walkability-status', $id ) );
$check( [ 2, 4, true ] === $level( 'walkability-status-not-walkable' ), 'non percorribile 2 su 4' );
$check( [ 3, 4, true ] === $level( 'walkability-status-partially-walkable' ), 'parzialmente 3 su 4' );
$check( [ 4, 4, true ] === $level( 'walkability-status-walkable' ), 'percorribile 4 su 4' );
$check( [ 0, 4, true ] === $level( 'walkability-status-unknown' ), 'sconosciuta 0 su 4' );
$check( [ 0, 4, true ] === $level( null ), 'senza termine 0 su 4' );
$check( [ 0, 4, false ] === $level( 'walkability-status-nuovo' ), 'stato che il registro non conosce' );
$check( [ 0, 0, false ] === array_values( wmwp_status_level( 'nessuna-famiglia', 'x' ) ), 'famiglia che il registro non conosce' );

// 3. Nomi nella lingua
$check( [ 'text' => 'Unknown', 'lang' => 'en' ] === wmwp_status_name( 'walkability-status', 'walkability-status-unknown', 'en' ), 'nome inglese dello stato di assenza' );
$check( [ 'text' => '', 'lang' => null ] === wmwp_status_name( 'walkability-status', 'walkability-status-walkable', 'it' ), 'nessun nome di registro per uno stato con il termine' );
$check( [ 'text' => '', 'lang' => null ] === wmwp_status_name( 'walkability-status', 'walkability-status-nuovo', 'it' ), 'nome di uno stato sconosciuto' );

// 4. Famiglia del tema, senza stato di assenza, e uno stato in più
$theme = function ( array $families ): array {
	$families['inclusivity-difficulty'] = [
		'absent' => null,
		'states' => [
			[ 'identifier' => 'inclusivity-difficulty-at', 'name' => [ 'it' => 'AT' ] ],
			[ 'identifier' => 'inclusivity-difficulty-ae', 'name' => [ 'it' => 'AE' ] ],
			[ 'identifier' => 'inclusivity-difficulty-aee', 'name' => [ 'it' => 'AEE' ] ],
		],
	];
	$families['walkability-status']['states'][] = [ 'identifier' => 'walkability-status-closed', 'name' => [ 'it' => 'Chiuso' ] ];
	return $families;
};
add_filter( 'wmwp_status_families', $theme );
$check( [ 1, 3, true ] === array_values( wmwp_status_level( 'inclusivity-difficulty', 'inclusivity-difficulty-at' ) ), 'AT 1 su 3' );
$check( [ 3, 3, true ] === array_values( wmwp_status_level( 'inclusivity-difficulty', 'inclusivity-difficulty-aee' ) ), 'AEE 3 su 3' );
$check( [ 0, 3, true ] === array_values( wmwp_status_level( 'inclusivity-difficulty', null ) ), 'accessibilità senza termine 0' );
$check( [ 5, 5, true ] === $level( 'walkability-status-closed' ), 'uno stato in più cambia il totale' );
remove_filter( 'wmwp_status_families', $theme );

// 5. Nome della famiglia cambiato dallo shard: i prefissi seguono
$name = fn() => 'percorribilita';
add_filter( 'wmwp_walkability_taxonomy', $name );
$families = wmwp_status_families();
$check( isset( $families['percorribilita'] ) && 'percorribilita-unknown' === $families['percorribilita']['absent'], 'prefissi dal nome della famiglia' );
remove_filter( 'wmwp_walkability_taxonomy', $name );

foreach ( $saved as $hook => $callbacks ) {
	if ( null !== $callbacks ) {
		$GLOBALS['wp_filter'][ $hook ] = $callbacks;
	}
}
if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
