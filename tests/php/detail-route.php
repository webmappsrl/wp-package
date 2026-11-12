<?php
/**
 * Test (oc:8969): risoluzione degli indirizzi dei dettagli. Un percorso dell'indice della lingua risponde 200; lo
 * stesso elemento chiesto con lo slug di un'altra lingua o con la base vecchia risponde 301 all'indirizzo attuale;
 * un percorso sconosciuto 404; una pagina vera con lo stesso percorso la serve WordPress.
 * Si lancia con: wp eval-file tests/php/detail-route.php
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

if ( ! function_exists( 'wmwp_detail_resolve' ) ) {
	echo "FAIL: wmwp_detail_resolve non esiste\n";
	exit( 1 );
}

// Indice finto in cache; nessuna ricostruzione permessa, così il test non chiama lo shard
$saved = [ get_transient( 'wmwp_detail_index' ), get_option( 'wmwp_detail_index_rebuilt_at' ) ];
$langs = function_exists( 'wmwp_detail_languages' ) ? wmwp_detail_languages() : [ 'it' ];
$en    = in_array( 'en-us', $langs, true ) ? 'en-us' : ( in_array( 'en', $langs, true ) ? 'en' : null );
$index = wmwp_detail_build_index(
	[
		[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Salita', 'en' => 'Climb' ], 'kind' => [ 'it' => 'Sentiero', 'en' => 'Trail' ] ],
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
update_option( 'wmwp_detail_index_rebuilt_at', time(), false );

// 1. Indirizzo giusto
$r = wmwp_detail_resolve( 'it', 'sentiero/salita' );
$check( 200 === $r['status'] && 'track' === $r['type'] && 5 === $r['id'], 'indirizzo italiano → 200' );

// 2. Slug italiano chiesto in inglese → indirizzo inglese
if ( null !== $en ) {
	$r = wmwp_detail_resolve( $en, 'sentiero/salita' );
	$check( 301 === $r['status'] && '/en/trail/climb/' === substr( $r['location'], -16 ), 'slug di un\'altra lingua → 301 alla lingua giusta (' . ( $r['location'] ?? '' ) . ')' );
}

// 2b. Base inglese con lo slug italiano (indirizzo di prima che arrivasse la traduzione del nome) → indirizzo attuale
if ( null !== $en ) {
	$r = wmwp_detail_resolve( $en, 'trail/salita' );
	$check( 301 === $r['status'] && '/en/trail/climb/' === substr( $r['location'] ?? '', -16 ), 'slug di un\'altra lingua sotto la base giusta → 301 (' . ( $r['location'] ?? '' ) . ')' );
}

// 3. Base vecchia → base attuale
$r = wmwp_detail_resolve( 'it', 'itinerario/salita' );
$check( 301 === $r['status'] && '/sentiero/salita/' === substr( $r['location'], -17 ), 'base vecchia → 301 (' . ( $r['location'] ?? '' ) . ')' );

// 4. Sconosciuto → 404
$r = wmwp_detail_resolve( 'it', 'sentiero/inventato' );
$check( 404 === $r['status'], 'percorso sconosciuto → 404' );

// 5. Indirizzo per id
$check( '/sentiero/salita/' === substr( wmwp_detail_url( 'track', 5, 'it' ), -17 ), 'wmwp_detail_url italiano' );
$check( '' === wmwp_detail_url( 'track', 999, 'it' ), 'id sconosciuto → stringa vuota' );

// 6. I link della ricerca portano al dettaglio: link_template predefinito con l'indirizzo per id
$template = (string) ( wmwp_search_sources()['tracks']['link_template'] ?? '' );
$check( false !== strpos( $template, 'wmwp_detail=track' ) && false !== strpos( $template, 'wmwp_id={id}' ), 'link_template predefinito per id (' . $template . ')' );

// 6b. Una base uguale allo slug di un tipo di contenuto pubblico non diventa una regola (ne ruberebbe gli indirizzi)
register_post_type( 'evento8969', [ 'public' => true, 'rewrite' => [ 'slug' => 'sentiero' ] ] );
$check( ! in_array( 'sentiero', wmwp_detail_bases(), true ), 'base uguale allo slug di un CPT esclusa' );
unregister_post_type( 'evento8969' );

// 7. Una pagina vera vince sul dettaglio
$page = wp_insert_post(
	[
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Sentiero test',
		'post_name'   => 'sentiero-test-8969',
	]
);
$vars = apply_filters( 'request', [ 'wmwp_detail_path' => 'sentiero-test-8969' ] );
$check( ! isset( $vars['wmwp_detail_path'] ), 'pagina vera → niente dettaglio' );
$vars = apply_filters( 'request', [ 'wmwp_detail_path' => 'sentiero/salita' ] );
$check( isset( $vars['wmwp_detail_path'] ), 'dettaglio senza pagina → resta' );
wp_delete_post( $page, true );

// Stato di prima
false === $saved[0] ? delete_transient( 'wmwp_detail_index' ) : set_transient( 'wmwp_detail_index', $saved[0], HOUR_IN_SECONDS );
false === $saved[1] ? delete_option( 'wmwp_detail_index_rebuilt_at' ) : update_option( 'wmwp_detail_index_rebuilt_at', $saved[1], false );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
