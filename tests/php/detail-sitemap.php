<?php
/**
 * Test (oc:8969): sitemap e lingue dei dettagli. Il provider della sitemap elenca ogni elemento dell'indice in ogni
 * lingua, con URL del sito; gli hreflang del dettaglio danno l'indirizzo dell'elemento in ogni lingua più x-default.
 * Si lancia con: wp eval-file tests/php/detail-sitemap.php
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

if ( ! class_exists( 'WMWP_Detail_Sitemap_Provider' ) || ! function_exists( 'wmwp_detail_hreflangs' ) ) {
	echo "FAIL: WMWP_Detail_Sitemap_Provider o wmwp_detail_hreflangs non esistono\n";
	exit( 1 );
}

$saved = get_transient( 'wmwp_detail_index' );
$langs = wmwp_detail_languages();
$index = wmwp_detail_build_index(
	[
		[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Salita', 'en' => 'Climb' ], 'kind' => [ 'it' => 'Sentiero', 'en' => 'Trail' ] ],
		[ 'type' => 'track', 'id' => 6, 'names' => [ 'it' => 'Discesa' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'poi', 'id' => 9, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null ],
	],
	function ( string $type, ?array $kind, string $lang ): string {
		$name = null === $kind ? '' : wmwp_detail_text( $kind, $lang );
		return '' !== $name ? sanitize_title( $name ) : ( 'poi' === $type ? 'poi' : 'track' );
	},
	$langs
);
$index['config'] = wmwp_detail_base_signature();
set_transient( 'wmwp_detail_index', $index, 600 );

// 1. Sitemap: ogni traccia in ogni lingua
$provider = new WMWP_Detail_Sitemap_Provider();
$tracks   = $provider->get_url_list( 1, 'track' );
$check( count( $tracks ) === 2 * count( $langs ), 'sitemap delle tracce: elementi × lingue (' . count( $tracks ) . ')' );
$home = untrailingslashit( (string) get_option( 'home' ) );
foreach ( $tracks as $entry ) {
	$check( 0 === strpos( $entry['loc'], $home ), 'URL della sitemap del sito: ' . $entry['loc'] );
}
$check( 1 === $provider->get_max_num_pages( 'poi' ), 'una pagina di POI' );
$check( [ 'track', 'poi' ] === array_keys( $provider->get_object_subtypes() ), 'sottotipi track e poi' );

// 2. hreflang: una voce per lingua più x-default sulla lingua predefinita
$detail = [
	'type' => 'track',
	'id'   => 5,
	'urls' => [],
];
foreach ( $langs as $code ) {
	$detail['urls'][ $code ] = wmwp_detail_url( 'track', 5, $code );
}
$links = wmwp_detail_hreflangs( $detail );
if ( count( $langs ) > 1 ) {
	$check( count( $links ) === count( $langs ) + 1, 'una voce per lingua più x-default' );
	$default = (string) apply_filters( 'wpml_default_language', $langs[0] );
	$check( ( $links['x-default'] ?? '' ) === $detail['urls'][ $default ], 'x-default sulla lingua predefinita' );
	$check( ! isset( $links['en-us'] ) && isset( $links['en-US'] ), 'codici hreflang dal tag della lingua (en-US)' );
} else {
	$check( [] === $links, 'una lingua sola → niente hreflang' );
}

// 3. Selettore di lingua chiamato da WPML prima che il dettaglio sia caricato: basta il percorso della richiesta
if ( count( $langs ) > 1 ) {
	set_query_var( 'wmwp_detail_path', $index['urls']['track'][5][ $langs[0] ] );
	$languages = [];
	foreach ( $langs as $code ) {
		$languages[ $code ] = [ 'code' => $code, 'url' => home_url( '/' ) ];
	}
	$out = apply_filters( 'icl_ls_languages', $languages );
	$check( wmwp_detail_url( 'track', 5, $langs[1] ) === ( $out[ $langs[1] ]['url'] ?? '' ), 'selettore di lingua prima del caricamento del dettaglio' );
	set_query_var( 'wmwp_detail_path', '' );
}

false === $saved ? delete_transient( 'wmwp_detail_index' ) : set_transient( 'wmwp_detail_index', $saved, HOUR_IN_SECONDS );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
