<?php
/**
 * Test (oc:8952): i nomi dei parametri dell'indirizzo e le chiavi dei filtri non possono essere query var pubbliche
 * di WordPress (`page`, `s`, `tag`, `year`…): con `?page=2` su una pagina il redirect canonico toglie il parametro e
 * la ricerca torna alla pagina 1. Un nome così torna al predefinito (parametri) o prende il prefisso `f_` (filtri),
 * con un avviso _doing_it_wrong. Si lancia con: wp eval-file tests/php/search-params.php
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
$warnings = 0;
add_action(
	'doing_it_wrong_run',
	function () use ( &$warnings ) {
		++$warnings;
	}
);
add_filter( 'doing_it_wrong_trigger_error', '__return_false' );

// 1. I predefiniti non sono query var di WordPress
remove_all_filters( 'wp_package_search_params' );
$params = wmwp_search_params();
global $wp;
foreach ( [ 'q', 'tab', 'page', 'view' ] as $key ) {
	$check( ! in_array( $params[ $key ], $wp->public_query_vars, true ), "il predefinito di {$key} ({$params[ $key ]}) è una query var di WordPress" );
}
$check( 'pg' === $params['page'], 'il parametro della pagina è pg' );

// 2. Un nome scelto dal tema che è una query var torna al predefinito, con l'avviso; gli altri restano
$warnings = 0;
add_filter(
	'wp_package_search_params',
	fn() => [
		'q'    => 's',
		'tab'  => 'tipo',
		'page' => 'page',
		'view' => 'vista',
		'map'  => 'mappa',
	]
);
$params = wmwp_search_params();
$check( 'q' === $params['q'] && 'pg' === $params['page'], 'q=s e page=page tornano ai predefiniti' );
$check( 'tipo' === $params['tab'] && 'vista' === $params['view'] && 'mappa' === $params['map'], 'i nomi validi del tema restano' );
$check( 2 === $warnings, "due avvisi per due nomi da scartare, invece di {$warnings}" );

// 3. Due parametri con lo stesso nome: il secondo torna al predefinito
remove_all_filters( 'wp_package_search_params' );
add_filter( 'wp_package_search_params', fn() => [ 'tab' => 'x', 'page' => 'x' ] );
$params = wmwp_search_params();
$check( 'x' === $params['tab'] && 'pg' === $params['page'], 'un nome già usato da un altro parametro torna al predefinito' );
remove_all_filters( 'wp_package_search_params' );

// 4. Chiavi dei filtri: una query var o un nome di parametro prende il prefisso f_, anche nel meta_facet
$warnings = 0;
add_filter(
	'wp_package_search_sources',
	function ( $sources ) {
		$sources['tracks']['facets']     = [
			[ 'key' => 'year', 'label' => 'Anno' ],
			[ 'key' => 'q', 'label' => 'Testo' ],
			[ 'key' => 'comune', 'label' => 'Comune' ],
		];
		$sources['tracks']['meta_facet'] = 'year';
		return $sources;
	}
);
$tracks = wmwp_search_sources()['tracks'];
$keys   = array_column( $tracks['facets'], 'key' );
$check( [ 'f_year', 'f_q', 'comune' ] === $keys, 'chiavi dei filtri: ' . implode( ',', $keys ) );
$check( 'f_year' === $tracks['meta_facet'], 'il meta_facet segue la chiave rinominata' );
$check( 2 === $warnings, "due avvisi per le chiavi dei filtri, invece di {$warnings}" );
remove_all_filters( 'wp_package_search_sources' );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
