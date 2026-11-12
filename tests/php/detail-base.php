<?php
/**
 * Test (oc:8969): base degli indirizzi dei dettagli. Con l'interruttore spento la base è il nome del tipo nella lingua
 * (`route-type`/`poi-type`), con ripiego `track`/`poi`; acceso, è il percorso della pagina scelta.
 * Si lancia con: wp eval-file tests/php/detail-base.php
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

if ( ! function_exists( 'wmwp_detail_base' ) ) {
	echo "FAIL: wmwp_detail_base non esiste\n";
	exit( 1 );
}

// Il tema può imporre le opzioni: qui le spegniamo e le accendiamo con pre_option, come fa Forestas
$off = function () {
	return '0';
};
add_filter( 'pre_option_wmwp_detail_tracks_use_page', $off );
add_filter( 'pre_option_wmwp_detail_pois_use_page', $off );

// 1. Dal tipo, nella lingua, con ripiego
$check( 'trail' === wmwp_detail_base( 'track', [ 'it' => 'Sentiero', 'en' => 'Trail' ], 'en' ), 'base inglese dal tipo' );
$check( 'sentiero' === wmwp_detail_base( 'track', [ 'it' => 'Sentiero' ], 'en' ), 'tipo senza inglese → italiano' );
$check( 'track' === wmwp_detail_base( 'track', null, 'it' ), 'traccia senza tipo → track' );
$check( 'poi' === wmwp_detail_base( 'poi', null, 'it' ), 'POI senza tipo → poi' );
$check( 'nuraghi-e-altri-luoghi' === wmwp_detail_base( 'poi', [ 'it' => 'Nuraghi e altri luoghi' ], 'it' ), 'POI dal tipo' );

// 2. Da una pagina
$page = wp_insert_post(
	[
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Punti di interesse test',
		'post_name'   => 'punti-di-interesse-test',
	]
);
remove_filter( 'pre_option_wmwp_detail_pois_use_page', $off );
add_filter(
	'pre_option_wmwp_detail_pois_use_page',
	function () {
		return '1';
	}
);
add_filter(
	'pre_option_wmwp_detail_pois_page',
	function () use ( $page ) {
		return (string) $page;
	}
);
$check( 'punti-di-interesse-test' === wmwp_detail_base( 'poi', [ 'it' => 'Nuraghi' ], 'it' ), 'POI sotto la pagina scelta' );
$check( 'sentiero' === wmwp_detail_base( 'track', [ 'it' => 'Sentiero' ], 'it' ), 'le tracce restano dal tipo' );

// 3. Pagina non pubblicata → si torna al tipo
wp_update_post(
	[
		'ID'          => $page,
		'post_status' => 'draft',
	]
);
$check( 'nuraghi' === wmwp_detail_base( 'poi', [ 'it' => 'Nuraghi' ], 'it' ), 'pagina non pubblicata → base dal tipo' );

wp_delete_post( $page, true );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
