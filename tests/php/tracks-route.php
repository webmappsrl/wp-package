<?php
/**
 * Test (oc:9003): ricerca delle griglie sulla copia delle tracce, con le regole di search-logic.js. Layer fisso; OR
 * dentro un filtro e AND fra filtri; ogni faccetta contata senza il proprio filtro; slider con gli estremi aperti e
 * percorsi senza valore esclusi da un filtro attivo; valori sconosciuti ignorati; pagina oltre l'ultima → ultima; senza
 * copia la rotta risponde 503.
 * Si lancia con: wp eval-file tests/php/tracks-route.php
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
	null === $saved ? delete_option( WMWP_TRACKS_COPY_OPTION ) : update_option( WMWP_TRACKS_COPY_OPTION, $saved, false );
};
set_exception_handler(
	function ( Throwable $e ) use ( $restore ) {
		$restore();
		echo 'FAIL: errore nel test: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
		exit( 1 );
	}
);

$track = fn( int $id, array $layers, array $activities, ?string $walkability, float $km ) => [
	'id'                 => $id,
	'name'               => [ 'it' => 'Sentiero ' . $id ],
	'ref'                => 'T ' . $id,
	'feature_image'      => '',
	'distance'           => $km,
	'duration_forward'   => 0.0,
	'ascent'             => 0.0,
	'layers'             => $layers,
	'terms'              => $walkability ? [ 'walkability-status' => [ [ 'identifier' => $walkability, 'name' => [ 'it' => $walkability ] ] ] ] : [],
	'taxonomyActivities' => $activities,
	'taxonomyWheres'     => [ 'Sardegna' ],
	'taxonomyIcons'      => [],
];
$copy = [
	$track( 1, [ 11 ], [ 'mtb' ], 'open', 0.6 ),
	$track( 2, [ 11 ], [ 'horse' ], 'open', 0 ),
	$track( 3, [ 11 ], [ 'hiking' ], 'closed', 12 ),
	$track( 4, [ 11, 3 ], [ 'mtb', 'hiking' ], 'closed', 5 ),
	$track( 5, [ 11 ], [ 'hiking' ], null, 3 ),
	$track( 6, [ 10 ], [ 'mtb' ], 'open', 2 ),
];
$facets = [
	[ 'key' => 'activity', 'label' => 'Attività', 'type' => 'list', 'field' => 'taxonomyActivities' ],
	[ 'key' => 'walkability', 'label' => 'Percorribilità', 'type' => 'terms', 'field' => 'terms.walkability-status', 'missing' => 'unknown' ],
	[ 'key' => 'track_distance', 'label' => 'Lunghezza', 'type' => 'slider', 'field' => 'distance', 'min' => 1.0, 'max' => 30.0, 'step' => 3.0, 'units' => 'km' ],
];
$ids   = fn( array $result ) => array_column( $result['items'], 'id' );
$count = function ( array $result, string $key ) {
	foreach ( $result['facets'] as $facet ) {
		if ( $key === $facet['key'] ) {
			return array_column( $facet['values'] ?? [], 'count', 'id' );
		}
	}
	return null;
};

// 1. Layer fisso
$all = wmwp_tracks_query( $copy, 11, $facets, [], 'it', 1, 9 );
$check( [ 1, 2, 3, 4, 5 ] === $ids( $all ) && 5 === $all['total'], 'layer 11: solo le sue tracce, nell\'ordine della copia' );
$check( 0 === wmwp_tracks_query( $copy, 0, $facets, [], 'it', 1, 9 )['total'], 'layer 0: nessuna traccia, mai tutte' );
$check( 0 === wmwp_tracks_query( $copy, 99, $facets, [], 'it', 1, 9 )['total'], 'layer senza tracce: nessuna traccia' );

// 2. OR dentro un filtro, AND fra filtri, conteggi senza il proprio filtro
$or = wmwp_tracks_query( $copy, 11, $facets, [ 'activity' => [ 'mtb', 'horse' ] ], 'it', 1, 9 );
$check( [ 1, 2, 4 ] === $ids( $or ), 'attività: OR' );
$check( [ 'hiking' => 3, 'mtb' => 2, 'horse' => 1 ] === $count( $or, 'activity' ), 'attività: conteggi senza il proprio filtro, per numero' );
$and = wmwp_tracks_query( $copy, 11, $facets, [ 'activity' => [ 'mtb', 'horse' ], 'walkability' => [ 'open' ] ], 'it', 1, 9 );
$check( [ 1, 2 ] === $ids( $and ), 'attività e percorribilità: AND' );
$check( 1 === ( $count( $and, 'walkability' )['closed'] ?? null ), 'percorribilità: contata con il solo filtro delle attività' );
$check( 1 === ( $count( $all, 'walkability' )['unknown'] ?? null ), 'percorribilità: traccia senza termine come «missing»' );

// 3. Slider: «fino a 10 km» tiene 0,6 km, toglie 0 e 12 km; ai due estremi non toglie nulla
$slider = wmwp_tracks_query( $copy, 11, $facets, [ 'track_distance' => [ '-10' ] ], 'it', 1, 9 );
$check( [ 1, 4, 5 ] === $ids( $slider ), 'slider: fino a 10 km' );
$check( 5 === wmwp_tracks_query( $copy, 11, $facets, [ 'track_distance' => [ '1-28' ] ], 'it', 1, 9 )['total'], 'slider ai due estremi: nessun limite' );
$view = null;
foreach ( $all['facets'] as $facet ) {
	if ( 'track_distance' === $facet['key'] ) {
		$view = $facet;
	}
}
$check( 'slider' === ( $view['type'] ?? '' ) && true === ( $view['available'] ?? null ), 'slider: disponibile con dati' );
$zero  = array_map( fn( $t ) => array_merge( $t, [ 'distance' => 0.0 ] ), $copy );
$empty = wmwp_tracks_query( $zero, 11, $facets, [], 'it', 1, 9 );
$check( false === ( array_values( array_filter( $empty['facets'], fn( $f ) => 'track_distance' === $f['key'] ) )[0]['available'] ?? null ), 'slider: non disponibile senza dati' );

// 4. Valori sconosciuti e pagine
$check( 5 === wmwp_tracks_query( $copy, 11, $facets, [ 'track_distance' => [ 'abc' ] ], 'it', 1, 9 )['total'], 'slider malformato: ignorato' );
$unknown = wmwp_tracks_query( $copy, 11, $facets, [ 'activity' => [ 'inesistente' ] ], 'it', 1, 9 );
$check( 0 === $unknown['total'] && 0 === ( $count( $unknown, 'activity' )['inesistente'] ?? null ), 'valore sconosciuto: scelto con 0, come la ricerca' );
$page = wmwp_tracks_query( $copy, 11, $facets, [], 'it', 999, 2 );
$check( 3 === $page['page'] && 3 === $page['pages'] && [ 5 ] === $ids( $page ), 'pagina oltre l\'ultima: l\'ultima' );

// 5. Filtri dall'indirizzo: chiavi delle faccette, valori separati da virgola
$filters = wmwp_grid_filters_from_params( [ 'activity' => 'mtb,horse', 'track_distance' => '-10', 'altro' => 'x' ], $facets );
$check( [ 'activity' => [ 'mtb', 'horse' ], 'track_distance' => [ '-10' ] ] === $filters, 'filtri dall\'indirizzo' );

// 5b. Casi condivisi con tests/js/search-logic.test.js (tests/fixtures/grid-parity.json): stessi risultati e conteggi
$parity = json_decode( (string) file_get_contents( WMWP_PATH . 'tests/fixtures/grid-parity.json' ), true );
foreach ( $parity['cases'] as $n => $case ) {
	$result = wmwp_tracks_query( $parity['tracks'], 11, $parity['facets'], $case['filters'], 'it', 1, 48 );
	$check( $case['ids'] === array_column( $result['items'], 'id' ), "caso condiviso {$n}: tracce" );
	foreach ( $case['counts'] as $key => $expected ) {
		$got = $count( $result, $key ) ?? [];
		ksort( $got );
		ksort( $expected );
		$check( $expected === $got, "caso condiviso {$n}: conteggi di {$key}" );
	}
}
// Valore dello slider nella risposta: malformato → '' (nessun filtro attivo), rovesciato → in ordine
$slider_value = function ( array $filters ) use ( $parity ) {
	foreach ( wmwp_tracks_query( $parity['tracks'], 11, $parity['facets'], $filters, 'it', 1, 48 )['facets'] as $facet ) {
		if ( 'track_distance' === $facet['key'] ) {
			return $facet['value'];
		}
	}
	return null;
};
$check( '' === $slider_value( [ 'track_distance' => [ 'abc' ] ] ), 'slider malformato: nessun valore' );
$check( '4-10' === $slider_value( [ 'track_distance' => [ '10-4' ] ] ), 'slider rovesciato: valore in ordine' );
$check( [] === wmwp_grid_filters_from_params( [ 'track_distance' => 'abc' ], $parity['facets'] ), 'slider malformato: non è un filtro' );

// 5c. Testo cercato (oc:9003): ripulito come cleanQuery() di search-logic.js, id dall'API dello shard (finta qui),
// ordine per pertinenza come rankByText()
$check( 'oliena' === wmwp_grid_clean_query( '(oliena' ) && 'a b' === wmwp_grid_clean_query( 'a/b' ) && 'oliena' === wmwp_grid_clean_query( '"oliena:' ), 'testo: caratteri di Lucene tolti' );
$check( 'perda liana' === wmwp_grid_clean_query( '  perda   liana ' ) && '' === wmwp_grid_clean_query( '/' ) && 'B\\-401' === wmwp_grid_clean_query( 'B-401' ), 'testo: spazi e trattino nei codici' );
$check( 'sentiero and' === wmwp_grid_clean_query( 'sentiero AND' ), 'testo: operatori in minuscolo' );
$named = [
	array_merge( $track( 1, [ 11 ], [ 'mtb' ], 'open', 1 ), [ 'name' => [ 'it' => 'Anello del Monte Albo' ] ] ),
	array_merge( $track( 2, [ 11 ], [ 'mtb' ], 'open', 1 ), [ 'name' => [ 'it' => 'Monte Albo' ] ] ),
	array_merge( $track( 3, [ 11 ], [ 'mtb' ], 'open', 1 ), [ 'name' => [ 'it' => 'Albo, cresta del Monte' ] ] ),
	array_merge( $track( 4, [ 11 ], [ 'mtb' ], 'open', 1 ), [ 'name' => [ 'it' => 'Fuori dal testo' ] ] ),
];
$text = wmwp_tracks_query( $named, 11, $facets, [], 'it', 1, 9, [ 3, 1, 2 ], 'monte albo' );
$check( [ 1, 2, 3 ] === $ids( $text ), 'testo: solo gli id dell\'API, prima chi ha la frase nel nome (a pari pertinenza ordine della copia)' );
$check( 3 === ( $count( $text, 'activity' )['mtb'] ?? 0 ), 'testo: conteggi sulle sole tracce trovate (la 4 resta fuori)' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'query=' ) ) {
			return $pre;
		}
		if ( false !== strpos( $url, 'query=giu' ) ) {
			return new WP_Error( 'http_request_failed', 'shard giù' );
		}
		return [ 'headers' => [], 'body' => wp_json_encode( [ 'hits' => [ 'hits' => [ [ '_source' => [ 'id' => '2' ] ], [ '_source' => [ 'id' => 3 ] ] ] ] ] ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
	},
	10,
	3
);
$check( [ 2, 3 ] === wmwp_grid_text_ids( 'albo' ), 'testo: id dall\'API dello shard' );
$check( null === wmwp_grid_text_ids( 'giu' ), 'testo: shard giù → null' );
// Rotta pubblica: un testo a caso non deve scrivere righe in wp_options (review interna). Senza object cache esterno la
// cache dura la richiesta.
global $wpdb;
$text_rows = fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%wmwp\\_grid\\_text%'" );
$before    = $text_rows();
wmwp_grid_text_ids( 'albo ' . wp_generate_password( 8, false ) );
$check( wp_using_ext_object_cache() || $before === $text_rows(), 'testo: nessuna riga in wp_options per un testo nuovo' );

// 6. Rotta senza copia: 503
delete_option( WMWP_TRACKS_COPY_OPTION );
$request = new WP_REST_Request( 'GET', '/wmwp/v1/tracks' );
$request->set_param( 'grid', 'prova' );
$response = rest_do_request( $request );
$check( 503 === $response->get_status(), 'rotta senza copia: 503' );

$restore();

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
