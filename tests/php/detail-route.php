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
$saved       = [ get_transient( 'wmwp_detail_index' ), get_option( 'wmwp_detail_index_rebuilt_at' ) ];
$saved_extra = [ get_option( 'wmwp_detail_index_last' ), get_option( 'wmwp_detail_bases' ) ];
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

// 3b. Il ripiego per slug resta fra i tipi della base chiesta: l'indirizzo vecchio di una traccia non porta a un POI
// con lo stesso slug
$r = wmwp_detail_resolve( 'it', 'sentiero/nuraghe' );
$check( 404 === $r['status'], 'slug di un POI sotto la base delle tracce → 404, non 301 al POI' );

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

// 6c. La regola dei dettagli lascia a WordPress feed, embed, trackback e pagine dei commenti della pagina base, e i
// nomi riservati di WordPress non diventano basi
$regola = wmwp_detail_rewrite_regex( [ 'punti-di-interesse', 'sentiero' ] );
$check( 1 === preg_match( '#' . $regola . '#', 'punti-di-interesse/nuraghe-losa/' ) && 1 === preg_match( '#' . $regola . '#', 'sentiero/77' ), 'regola: dettaglio (anche con lo slug numerico)' );
$riservati = array_filter( [ 'punti-di-interesse/feed/', 'punti-di-interesse/embed/', 'sentiero/trackback/', 'punti-di-interesse/comment-page-2/' ], fn( $u ) => 1 === preg_match( '#' . $regola . '#', $u ) );
$check( [] === $riservati, 'regola: feed, embed, trackback e comment-page lasciati a WordPress (' . implode( ', ', $riservati ) . ')' );
update_option( 'wmwp_detail_bases', [ 'feed', 'sentiero', 'search' ], true );
$check( [ 'sentiero' ] === wmwp_detail_bases(), 'nomi riservati di WordPress esclusi dalle basi' );

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

// 8. Indirizzo per id (link della ricerca, che legge Elasticsearch dal vivo): prima del redirect si controlla che l'id
// porti ancora allo stesso elemento (chiave stabile del JSON contro quella dell'indice). Un id sconosciuto, o passato a
// un altro elemento dopo un reset, ricostruisce l'indice una volta, se permesso
$no_aws      = function ( $urls ) {
	$urls['aws_api'] = '';
	return $urls;
};
add_filter( 'wmwp_api_urls', $no_aws, 99 );
$fonti = [ 5 => 'T-5' ];
$es    = [ [ 'id' => 5, 'name' => [ 'it' => 'Salita' ], 'out_source_feature_id' => 'T-5', 'terms' => [ 'route-type' => [ [ 'name' => [ 'it' => 'Sentiero' ] ] ] ] ] ];
$mock  = function ( $pre, $args, $url ) use ( &$fonti, &$es ) {
	$risposta = function ( int $code, $data ) {
		return [ 'headers' => [], 'body' => wp_json_encode( $data ), 'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [] ];
	};
	if ( preg_match( '#/api/ec/track/(\d+)$#', $url, $m ) ) {
		$id = (int) $m[1];
		return isset( $fonti[ $id ] ) ? $risposta( 200, [ 'type' => 'Feature', 'properties' => [ 'id' => $id, 'out_source_feature_id' => $fonti[ $id ] ], 'geometry' => null ] ) : $risposta( 404, [ 'message' => 'Not found' ] );
	}
	if ( false !== strpos( $url, 'elasticsearch' ) ) {
		return $risposta( 200, [ 'hits' => $es ] );
	}
	if ( false !== strpos( $url, 'pois.geojson' ) ) {
		return $risposta( 200, [ 'type' => 'FeatureCollection', 'features' => [ [ 'type' => 'Feature', 'properties' => [ 'id' => 9, 'name' => [ 'it' => 'Nuraghe' ] ] ] ] ] );
	}
	return $pre;
};
add_filter( 'pre_http_request', $mock, 10, 3 );
$con_fonte = wmwp_detail_build_index(
	[
		[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Salita' ], 'kind' => [ 'it' => 'Sentiero' ], 'source' => 'T-5' ],
		[ 'type' => 'poi', 'id' => 9, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null ],
	],
	'wmwp_detail_base'
);
$con_fonte['config'] = wmwp_detail_base_signature();
set_transient( 'wmwp_detail_index', $con_fonte, 600 );
update_option( 'wmwp_detail_index_rebuilt_at', time(), false );
delete_transient( 'wmwp_detail_json_track_5' );
$check( '/sentiero/salita/' === substr( wmwp_detail_url_by_id( 'track', 5, 'it' ), -17 ), 'per id: stesso elemento → indirizzo del dettaglio' );
// Dopo un reset l'id 5 è passato a un'altra traccia, e la ricostruzione non è permessa: niente indirizzo sbagliato
$fonti = [ 5 => 'T-77' ];
delete_transient( 'wmwp_detail_json_track_5' );
$check( '' === wmwp_detail_url_by_id( 'track', 5, 'it' ), 'per id: id passato a un altro elemento, senza ricostruzione → niente' );
// Con la ricostruzione permessa, l'indice nuovo dice dove sta l'elemento che ora ha l'id 5
$es = [ [ 'id' => 5, 'name' => [ 'it' => 'Discesa' ], 'out_source_feature_id' => 'T-77', 'terms' => [ 'route-type' => [ [ 'name' => [ 'it' => 'Sentiero' ] ] ] ] ] ];
delete_option( 'wmwp_detail_index_rebuilt_at' );
delete_transient( 'wmwp_detail_json_track_5' );
$check( '/sentiero/discesa/' === substr( wmwp_detail_url_by_id( 'track', 5, 'it' ), -18 ), 'per id: id passato a un altro elemento → indice ricostruito, indirizzo giusto' );
// Traccia nuova, che la ricerca già mostra ma l'indice in cache no
$fonti[8] = 'T-8';
$es[]     = [ 'id' => 8, 'name' => [ 'it' => 'Nuova' ], 'out_source_feature_id' => 'T-8', 'terms' => [ 'route-type' => [ [ 'name' => [ 'it' => 'Sentiero' ] ] ] ] ];
delete_option( 'wmwp_detail_index_rebuilt_at' );
$check( '/sentiero/nuova/' === substr( wmwp_detail_url_by_id( 'track', 8, 'it' ), -16 ), 'per id: traccia nuova → indice ricostruito' );
delete_option( 'wmwp_detail_index_rebuilt_at' );
$check( '' === wmwp_detail_url_by_id( 'track', 999, 'it' ), 'per id: id che non esiste → niente' );
remove_filter( 'pre_http_request', $mock, 10 );
remove_filter( 'wmwp_api_urls', $no_aws, 99 );
foreach ( [ 5, 8, 999 ] as $id ) {
	delete_transient( 'wmwp_detail_json_track_' . $id );
}
false === $saved_extra[0] ? delete_option( 'wmwp_detail_index_last' ) : update_option( 'wmwp_detail_index_last', $saved_extra[0], false );
false === $saved_extra[1] ? delete_option( 'wmwp_detail_bases' ) : update_option( 'wmwp_detail_bases', $saved_extra[1], true );

// Stato di prima
false === $saved[0] ? delete_transient( 'wmwp_detail_index' ) : set_transient( 'wmwp_detail_index', $saved[0], HOUR_IN_SECONDS );
false === $saved[1] ? delete_option( 'wmwp_detail_index_rebuilt_at' ) : update_option( 'wmwp_detail_index_rebuilt_at', $saved[1], false );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
