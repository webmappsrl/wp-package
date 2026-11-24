<?php
/**
 * Test (oc:9000): sorgente «Punti di interesse» della ricerca unica. La copia per la ricerca si scrive solo quando
 * l'indice dei dettagli accetta l'elenco (anche vuota, per un'app senza POI), la rotta `wmwp/v1/pois` cerca nel testo
 * della lingua della pagina con ripiego sull'italiano, mette prima i POI con il testo nel nome, conta la faccetta dei
 * tipi sui risultati del testo (i tipi scelti restano anche a 0) e risponde 503 senza copia. Lo shard è finto
 * (`pre_http_request`); lo stato vero del sito lo rimette la sandbox. Si lancia con: wp eval-file tests/php/search-pois.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require __DIR__ . '/history-sandbox.php';

$failures = [];
$check    = function ( bool $ok, string $what ) use ( &$failures ) {
	if ( ! $ok ) {
		$failures[] = $what;
	}
};

if ( ! function_exists( 'wmwp_poi_search_build' ) ) {
	echo "FAIL: wmwp_poi_search_build non esiste\n";
	exit( 1 );
}

$type_site   = [ 'identifier' => 'siti', 'name' => [ 'it' => 'Siti', 'en' => 'Sites' ] ];
$type_nuraghe = [ 'identifier' => 'nuraghi', 'name' => [ 'it' => 'Nuraghi' ] ];
$type_fonte  = [ 'identifier' => 'fonti', 'name' => [ 'it' => 'Fonti', 'en' => 'Springs' ] ];
$poi         = function ( int $id, array $name, string $description, array $types, $image = null ) {
	return [
		'type'       => 'Feature',
		'geometry'   => null,
		'properties' => [
			'id'            => $id,
			'name'          => $name,
			'description'   => [ 'it' => $description ],
			'searchable'    => '{it:' . $name['it'] . '} {it:' . wp_strip_all_tags( $description ) . '}',
			'feature_image' => $image,
			// Una voce come stringa JSON, come nella risposta dello shard
			'taxonomies'    => 2 === $id ? wp_json_encode( [ 'poi-type' => $types ] ) : [ 'poi-type' => $types ],
			// Luoghi come li dà lo shard (regione, area, settore CAI, provincia, comune); una voce come stringa JSON
			'taxonomyWheres' => 2 === $id ? wp_json_encode( $GLOBALS['wmwp_test_wheres'][ $id ] ?? [] ) : ( $GLOBALS['wmwp_test_wheres'][ $id ] ?? [] ),
		],
	];
};
$GLOBALS['wmwp_test_wheres'] = [
	1 => [ 'Sardegna', 'Area G (Nord-EST)', 'ZNUG6', 'Nuoro', 'Bolotana' ],
	2 => [ 'Sardegna', 'Area G (Nord-EST)', 'Nuoro', 'Bolotana' ],
	3 => [ 'Sardegna', 'Area T (Centrale)', 'Oristano', 'Ghilarza' ],
];
$features    = [
	$poi( 1, [ 'it' => 'Nuraghe Losa' ], '<p>Vicino a <b>Bolotana</b> &amp; dintorni.</p>', [ $type_site, $type_nuraghe ], [ 'thumbnail' => 'https://x.example/t.jpg' ] ),
	$poi( 2, [ 'it' => 'Bolotana centro' ], '<p>Il paese</p>', [ $type_site ] ),
	$poi( 3, [ 'it' => 'Fonte', 'en' => 'Spring' ], '<p>Presso Bolotana</p>', [ $type_fonte ] ),
	$poi( 4, [ 'it' => 'Domus' ], '<p>Tomba</p>', [] ),
];

// Le faccette del tema attivo (es. tipi dal config.json, oc:9018) non devono cambiare il test: si tolgono e si rimettono
$theme_sources = $GLOBALS['wp_filter']['wp_package_search_sources'] ?? null;
remove_all_filters( 'wp_package_search_sources' );

$shard = [ 'features' => $features ];
$fake  = function ( $pre, $args, $url ) use ( &$shard ) {
	if ( false !== strpos( $url, 'pois.geojson' ) ) {
		return [ 'headers' => [], 'body' => wp_json_encode( [ 'type' => 'FeatureCollection', 'features' => $shard['features'] ] ), 'response' => [ 'code' => 200, 'message' => '' ], 'cookies' => [] ];
	}
	if ( false !== strpos( $url, 'elasticsearch' ) ) {
		return [ 'headers' => [], 'body' => wp_json_encode( [ 'hits' => [ [ 'id' => 5, 'name' => [ 'it' => 'Salita' ] ] ] ] ), 'response' => [ 'code' => 200, 'message' => '' ], 'cookies' => [] ];
	}
	return $pre;
};
add_filter( 'pre_http_request', $fake, 10, 3 );

// Una ricostruzione dell'indice senza ultimo indice buono (il sandbox ripristina lo stato vero), con la copia vuota
$rebuild = function () {
	delete_transient( 'wmwp_detail_index' );
	delete_transient( 'wmwp_detail_index_lock' );
	delete_option( 'wmwp_detail_index_last' );
	delete_option( 'wmwp_detail_index_rebuilt_at' );
	wmwp_detail_index( true );
};

// 1. Senza copia la rotta risponde 503
delete_option( 'wmwp_poi_search_copy' );
$get = function ( array $params = [] ) {
	$request = new WP_REST_Request( 'GET', '/wmwp/v1/pois' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
// Senza copia (subito dopo il deploy della 0.5.0) la ricostruzione dell'indice parte subito, non al giro orario del
// cron: il tab dei POI non resta in errore fino a un'ora (review finale oc:9000, I3). Si parte senza eventi: il plugin
// rimette quello orario al caricamento successivo
// e senza una ricostruzione recente (il caso 1a prova l'attesa fra due ricostruzioni; la sandbox rimette la data vera)
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
delete_option( WMWP_DETAIL_REBUILT_AT );
$response = $get( [ 'q' => 'nuraghe' ] );
$check( 503 === $response->get_status(), 'senza copia la rotta risponde 503, stato ' . $response->get_status() );
$next = wp_next_scheduled( WMWP_DETAIL_CRON_HOOK );
$check( false !== $next && $next <= time() + MINUTE_IN_SECONDS, 'senza copia: ricostruzione dell\'indice programmata entro un minuto' );
$get( [ 'q' => 'nuraghe' ] );
$check( 1 === count( array_filter( (array) _get_cron_array(), fn( $hooks ) => isset( $hooks[ WMWP_DETAIL_CRON_HOOK ] ) ) ), 'una sola ricostruzione anche con più richieste' );
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );

// 1a. Durante l'attesa fra due ricostruzioni (WMWP_DETAIL_REBUILD_INTERVAL) le richieste senza copia non programmano un
// evento a testa: uno solo, alla fine dell'attesa, anche con l'evento orario già in calendario
update_option( WMWP_DETAIL_REBUILT_AT, time(), false );
wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', WMWP_DETAIL_CRON_HOOK );
$get( [ 'q' => 'nuraghe' ] );
$get( [ 'q' => 'nuraghe' ] );
$events = array_filter( (array) _get_cron_array(), fn( $hooks ) => isset( $hooks[ WMWP_DETAIL_CRON_HOOK ] ) );
$check( 2 === count( $events ) && isset( $events[ (int) get_option( WMWP_DETAIL_REBUILT_AT ) + WMWP_DETAIL_REBUILD_INTERVAL ] ), 'attesa fra due ricostruzioni: un evento solo, alla fine dell\'attesa (più quello orario)' );
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
delete_option( WMWP_DETAIL_REBUILT_AT );

// 1b. Una copia nel formato di prima (testo cercabile in una stringa sola, review interna di oc:9000) vale come assente:
// 503 e ricostruzione, invece di 0 risultati per ogni testo fino al rinnovo orario
update_option( 'wmwp_poi_search_copy', [ [ 'id' => 1, 'name' => [ 'it' => 'Nuraghe' ], 'searchable' => 'nuraghe' ] ], false );
$response = $get( [ 'q' => 'nuraghe' ] );
$next     = wp_next_scheduled( WMWP_DETAIL_CRON_HOOK );
$check( 503 === $response->get_status() && false !== $next && $next <= time() + MINUTE_IN_SECONDS, 'copia nel formato di prima: 503 e ricostruzione' );
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
delete_option( 'wmwp_poi_search_copy' );

// 2. La copia si scrive quando l'indice accetta l'elenco
$rebuild();
$copy = get_option( 'wmwp_poi_search_copy', null );
$check( is_array( $copy ) && 4 === count( $copy ), 'copia scritta all\'accettazione dell\'indice: ' . ( is_array( $copy ) ? count( $copy ) : 'assente' ) );

// Lingua dei testi che ripiegano (review finale oc:9000, I4): su una pagina inglese il titolo, l'estratto e il nome
// del tipo solo italiani dicono «it», per marcarli con `lang` (WCAG 3.1.2); quelli inglesi dicono «en»
$en       = $get( [ 'q' => 'bolotana', 'lang' => 'en' ] )->get_data();
$by_id    = array_column( (array) ( $en['items'] ?? [] ), null, 'id' );
$check( 'it' === ( $by_id[1]['titleLang'] ?? null ) && 'it' === ( $by_id[1]['metaLang'] ?? null ), 'POI solo italiano: titolo ed estratto in italiano' );
$check( 'en' === ( $by_id[3]['titleLang'] ?? null ), 'POI con il nome inglese: titolo in inglese' );
$types_en = array_column( (array) ( $en['facets'][0]['values'] ?? [] ), null, 'id' );
$check( 'it' === ( $types_en['nuraghi']['lang'] ?? null ) && 'en' === ( $types_en['siti']['lang'] ?? null ), 'nomi dei tipi con la loro lingua' );

$first = is_array( $copy ) ? ( $copy[0] ?? [] ) : [];
$check( 1 === ( $first['id'] ?? null ) && 'Nuraghe Losa' === ( $first['name']['it'] ?? '' ), 'id e nome per lingua nella copia' );
$check( 'https://x.example/t.jpg' === ( $first['image'] ?? null ), 'immagine dalla thumbnail' );
$check( 'Vicino a Bolotana & dintorni.' === ( $first['excerpt']['it'] ?? '' ), 'estratto senza tag, con entità decodificate' );
$check( [ 'siti', 'nuraghi' ] === array_column( (array) ( $first['types'] ?? [] ), 'identifier' ), 'tipi nella copia' );
$check( 'nuraghe losa vicino a bolotana & dintorni. nuraghe losa' === ( $first['searchable']['it'] ?? '' ), 'testo cercabile per lingua, minuscolo, senza marcatori: ' . wp_json_encode( $first['searchable'] ?? null ) );
$check( 'spring' === substr( (string) ( $copy[2]['searchable']['en'] ?? '' ), -6 ) && 'fonte presso bolotana fonte' === ( $copy[2]['searchable']['it'] ?? '' ), 'in inglese i campi senza inglese ripiegano sull\'italiano' );

// 2a. Ricerca nella lingua della pagina (review interna oc:9000, B2): i marcatori di lingua non trovano nulla, e una
// parola solo inglese non trova il POI sulla pagina italiana se il testo italiano c'è
$check( 0 === ( $get( [ 'q' => 'it' ] )->get_data()['total'] ?? null ), 'q=it non trova tutto' );
$check( [] === array_filter( array_merge( ...array_map( fn( $poi ) => array_values( (array) $poi['searchable'] ), $copy ) ), fn( $text ) => false !== strpos( $text, '{' ) || false !== strpos( $text, 'it:' ) ), 'nessun marcatore di lingua nel testo cercabile' );
$check( 0 === ( $get( [ 'q' => 'spring', 'lang' => 'it' ] )->get_data()['total'] ?? null ), 'parola solo inglese: nessun risultato sulla pagina italiana' );
// Graffe nel testo e «,da:» (una parola seguita da due punti) non spezzano il campo e non creano una lingua finta
$by_lang = wmwp_poi_search_by_lang( '{it:Grotta {di} Ispinigoli,en:Ispinigoli cave} {it:Partenza,da: Dorgali,en:Start}', [] );
$check( [ 'it', 'en' ] === array_keys( $by_lang ), 'solo le lingue vere: ' . wp_json_encode( array_keys( $by_lang ) ) );
$check( 'grotta {di} ispinigoli partenza,da: dorgali' === ( $by_lang['it'] ?? '' ) && 'ispinigoli cave start' === ( $by_lang['en'] ?? '' ), 'graffe e «,da:» restano nel testo: ' . wp_json_encode( $by_lang ) );
$check( [ 3 ] === array_column( (array) ( $get( [ 'q' => 'spring', 'lang' => 'en' ] )->get_data()['items'] ?? [] ), 'id' ), 'parola inglese trovata sulla pagina inglese' );

// 2c. Un tipo scelto con 0 risultati resta nella faccetta, con il suo nome (review interna oc:9000, B3)
$zero   = $get( [ 'q' => 'domus', 'poi_type' => [ 'fonti' ] ] )->get_data();
$chosen = array_column( (array) ( $zero['facets'][0]['values'] ?? [] ), null, 'id' );
$check( 0 === ( $zero['total'] ?? null ) && 0 === ( $chosen['fonti']['count'] ?? null ) && true === ( $chosen['fonti']['selected'] ?? null ) && 'Fonti' === ( $chosen['fonti']['label'] ?? '' ), 'tipo scelto a 0 con il suo nome: ' . wp_json_encode( $zero['facets'] ?? null ) );
$check( 2 === ( $copy[1]['id'] ?? null ) && [ 'siti' ] === array_column( (array) ( $copy[1]['types'] ?? [] ), 'identifier' ), 'taxonomies come stringa JSON' );

// 2b. Un elenco scartato (calo sotto la metà) non cambia la copia
$before = $copy;
update_option( 'wmwp_detail_index_last', [ 'counts' => [ 'track' => 1, 'poi' => 100 ], 'config' => wmwp_detail_base_signature(), 'langs' => [ 'it' ], 'paths' => [], 'urls' => [], 'items' => [], 'count' => 101 ], false );
delete_transient( 'wmwp_detail_index' );
delete_transient( 'wmwp_detail_index_lock' );
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard['features'] = [ $features[0] ];
wmwp_detail_index( true );
$check( $before === get_option( 'wmwp_poi_search_copy', null ), 'un elenco scartato non cambia la copia' );
$shard['features'] = $features;
delete_option( 'wmwp_detail_index_rejects' );
$rebuild();

// 3. «bolotana» trova il POI con la parola solo nella descrizione, e il nome viene prima
$response = $get( [ 'q' => 'Bolotana', 'lang' => 'it' ] );
$data     = $response->get_data();
$ids      = array_column( (array) ( $data['items'] ?? [] ), 'id' );
$check( 200 === $response->get_status() && 3 === ( $data['total'] ?? null ), 'bolotana: tre risultati' );
$check( [ 2, 3, 1 ] === $ids, 'nome prima, poi gli altri per nome: ' . implode( ',', $ids ) );

// 4. Card
$card = $data['items'][0] ?? [];
$check( 'poi' === ( $card['typeKey'] ?? '' ) && 'Bolotana centro' === ( $card['title'] ?? '' ), 'card: typeKey e titolo' );
$check( false !== strpos( (string) ( $card['url'] ?? '' ), 'wmwp_id=2' ) && false === strpos( (string) ( $card['url'] ?? '' ), '{id}' ), 'card: url con l\'id' );
$check( array_key_exists( 'code', $card ) && null === $card['code'] && array_key_exists( 'badge', $card ) && null === $card['badge'] && [ 'Il paese' ] === ( $card['meta'] ?? null ), 'card: code, badge e meta' );
$check( array_key_exists( 'image', $card ) && null === $card['image'], 'card senza immagine' );
$check( [ 'src' => 'https://x.example/t.jpg', 'alt' => '' ] === ( $data['items'][2]['image'] ?? null ), 'card con immagine' );
$en = $get( [ 'q' => 'bolotana', 'lang' => 'en' ] )->get_data();
$check( 'Spring' === ( array_column( (array) $en['items'], 'title', 'id' )[3] ?? '' ), 'titolo nella lingua con ripiego sull\'italiano' );

// 5. Faccetta dei tipi, contata sui risultati del testo
$facet  = ( $data['facets'][0] ?? [] );
$counts = [];
foreach ( (array) ( $facet['values'] ?? [] ) as $value ) {
	$counts[ $value['id'] ] = $value['count'];
}
$check( 'poi-type' === ( $facet['key'] ?? '' ), 'faccetta dei tipi, chiave in inglese' );
ksort( $counts );
$check( [ 'fonti' => 1, 'nuraghi' => 1, 'siti' => 2 ] === $counts, 'conteggi: un POI con due tipi conta in entrambi: ' . wp_json_encode( $counts ) );
$labels = array_column( (array) ( $en['facets'][0]['values'] ?? [] ), 'label', 'id' );
$check( 'Sites' === ( $labels['siti'] ?? '' ) && 'Nuraghi' === ( $labels['nuraghi'] ?? '' ), 'nomi dei tipi nella lingua, ripiego sull\'italiano' );

// 6. Filtro per tipo
$filtered = $get( [ 'q' => 'bolotana', 'poi_type' => [ 'fonti' ] ] )->get_data();
$check( 1 === ( $filtered['total'] ?? null ) && [ 3 ] === array_column( (array) $filtered['items'], 'id' ), 'filtro per tipo' );
$check( 4 === array_sum( array_column( (array) ( $filtered['facets'][0]['values'] ?? [] ), 'count' ) ), 'la faccetta conta sui risultati del testo, non sul filtro scelto' );

// 6b. Filtri dei luoghi (oc:9000): faccette `list` della source con `field` taxonomyWheres, che il tema aggiunge dopo
// quella dei tipi; include, include_pattern, exclude ed exclude_pattern come per i percorsi (search-logic.js). Ogni
// faccetta conta senza il proprio filtro e con gli altri; OR dentro un filtro, AND fra i filtri; voci scelte a 0 restano
$check( [ 'Sardegna', 'Area G (Nord-EST)', 'ZNUG6', 'Nuoro', 'Bolotana' ] === ( $copy[0]['wheres'] ?? null ) && [ 'Sardegna', 'Area G (Nord-EST)', 'Nuoro', 'Bolotana' ] === ( $copy[1]['wheres'] ?? null ) && [] === ( $copy[3]['wheres'] ?? null ), 'luoghi nella copia (anche da stringa JSON): ' . wp_json_encode( array_column( (array) $copy, 'wheres' ) ) );
$luoghi = function ( array $sources ): array {
	// Al posto delle faccette dopo quella dei tipi, comprese quelle che il tema attivo aggiunge
	$sources['pois']['facets'] = array_merge(
		array_slice( (array) $sources['pois']['facets'], 0, 1 ),
		[
			[ 'key' => 'area', 'label' => 'Area', 'type' => 'list', 'field' => 'taxonomyWheres', 'include_pattern' => '^Area ' ],
			[ 'key' => 'provincia', 'label' => 'Provincia', 'type' => 'list', 'field' => 'taxonomyWheres', 'include' => [ 'Nuoro', 'Oristano' ] ],
			[ 'key' => 'comune', 'label' => 'Comune', 'type' => 'list', 'field' => 'taxonomyWheres', 'exclude' => [ 'Sardegna', 'Nuoro', 'Oristano' ], 'exclude_pattern' => '^(Area |Z[A-Z]{3}[0-9]+$)' ],
		]
	);
	return $sources;
};
add_filter( 'wp_package_search_sources', $luoghi, 99 );
$voci = function ( array $data, string $key ): array {
	foreach ( (array) ( $data['facets'] ?? [] ) as $facet ) {
		if ( $key === ( $facet['key'] ?? '' ) ) {
			return array_map( fn( $v ) => [ $v['id'], $v['count'], $v['selected'] ], (array) $facet['values'] );
		}
	}
	return [ 'assente' ];
};
$all = $get()->get_data();
$check( [ 'poi-type', 'area', 'provincia', 'comune' ] === array_column( (array) ( $all['facets'] ?? [] ), 'key' ), 'faccette: tipi e poi i luoghi, nell\'ordine della source: ' . wp_json_encode( array_column( (array) ( $all['facets'] ?? [] ), 'key' ) ) );
$check( [ [ 'Area G (Nord-EST)', 2, false ], [ 'Area T (Centrale)', 1, false ] ] === $voci( $all, 'area' ), 'area: ' . wp_json_encode( $voci( $all, 'area' ) ) );
$check( [ [ 'Nuoro', 2, false ], [ 'Oristano', 1, false ] ] === $voci( $all, 'provincia' ), 'provincia: ' . wp_json_encode( $voci( $all, 'provincia' ) ) );
$check( [ [ 'Bolotana', 2, false ], [ 'Ghilarza', 1, false ] ] === $voci( $all, 'comune' ), 'comune senza regione, aree, settori e province: ' . wp_json_encode( $voci( $all, 'comune' ) ) );
$nuoro = $get( [ 'filter' => [ 'provincia' => [ 'Nuoro' ] ] ] )->get_data();
$check( 2 === ( $nuoro['total'] ?? null ) && [ 2, 1 ] === array_column( (array) ( $nuoro['items'] ?? [] ), 'id' ), 'provincia Nuoro: due POI' );
$check( [ [ 'Nuoro', 2, true ], [ 'Oristano', 1, false ] ] === $voci( $nuoro, 'provincia' ), 'provincia Nuoro: la provincia conta senza il proprio filtro ' . wp_json_encode( $voci( $nuoro, 'provincia' ) ) );
$check( [ [ 'siti', 2, false ], [ 'nuraghi', 1, false ] ] === $voci( $nuoro, 'poi-type' ), 'provincia Nuoro: i tipi contano con la provincia ' . wp_json_encode( $voci( $nuoro, 'poi-type' ) ) );
$check( [ [ 'Bolotana', 2, false ] ] === $voci( $nuoro, 'comune' ), 'provincia Nuoro: solo i comuni di Nuoro ' . wp_json_encode( $voci( $nuoro, 'comune' ) ) );
$both = $get( [ 'filter' => [ 'provincia' => [ 'Nuoro', 'Oristano' ] ] ] )->get_data();
$check( 3 === ( $both['total'] ?? null ), 'due province: OR dentro il filtro' );
$none = $get( [ 'poi_type' => [ 'fonti' ], 'filter' => [ 'provincia' => [ 'Nuoro' ] ] ] )->get_data();
$check( 0 === ( $none['total'] ?? null ) && [ [ 'Oristano', 1, false ], [ 'Nuoro', 0, true ] ] === $voci( $none, 'provincia' ), 'fonti e Nuoro: nessun POI, Nuoro scelta resta a 0 ' . wp_json_encode( $voci( $none, 'provincia' ) ) );
$odd = $get( [ 'filter' => [ 'inventato' => [ 'x' ], 'comune' => 'Bolotana', 'area' => [ [ 'a' ] ] ] ] );
$check( 200 === $odd->get_status() && 4 === ( $odd->get_data()['total'] ?? null ), 'filtri sconosciuti o non liste: ignorati' );
$check( 0 === ( $get( [ 'filter' => [ 'comune' => [ 'Sardegna' ] ] ] )->get_data()['total'] ?? null ), 'un valore che la faccetta esclude non trova nulla, come per i percorsi' );
remove_filter( 'wp_package_search_sources', $luoghi, 99 );
// Copia senza i luoghi (scritta da una versione di prima): risponde, e la ricostruzione che li aggiunge parte subito
$senza = array_map( fn( $poi ) => array_diff_key( $poi, [ 'wheres' => true ] ), (array) $copy );
update_option( 'wmwp_poi_search_copy', $senza, false );
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
delete_option( WMWP_DETAIL_REBUILT_AT );
$old  = $get();
$next = wp_next_scheduled( WMWP_DETAIL_CRON_HOOK );
$check( 200 === $old->get_status() && 4 === ( $old->get_data()['total'] ?? null ) && false !== $next && $next <= time() + MINUTE_IN_SECONDS, 'copia senza luoghi: 200 e ricostruzione programmata' );
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
// Copia della 0.5.0, senza il punto dei POI (review interna di oc:9018): risponde, e la ricostruzione che lo aggiunge,
// con le aree dei POI, parte subito invece che al giro orario
$senza_punto = array_map( fn( $poi ) => array_diff_key( $poi, [ 'point' => true ] ), (array) $copy );
update_option( 'wmwp_poi_search_copy', $senza_punto, false );
delete_option( WMWP_DETAIL_REBUILT_AT );
$old  = $get();
$next = wp_next_scheduled( WMWP_DETAIL_CRON_HOOK );
$check( 200 === $old->get_status() && false !== $next && $next <= time() + MINUTE_IN_SECONDS, 'copia senza il punto: 200 e ricostruzione programmata' );
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
update_option( 'wmwp_poi_search_copy', $copy, false );

// 7. q lungo: accettato e troncato a 200
$long = $get( [ 'q' => str_repeat( 'a', 500 ) ] );
$check( 200 === $long->get_status(), 'q di 500 caratteri accettato' );
$long_hit = $get( [ 'q' => 'nuraghe' . str_repeat( ' ', 300 ) . 'zzz' ] )->get_data();
$check( 0 < ( $long_hit['total'] ?? 0 ), 'q oltre la soglia: si cerca la parte entro i 200 caratteri' );
// Parametri non scalari (dall'indirizzo, q[]=x): valgono come assenti, senza avvisi PHP
$errors = [];
set_error_handler( function ( $no, $msg ) use ( &$errors ) { $errors[] = $msg; return true; } );
$odd = $get( [ 'q' => [ 'x' ], 'lang' => [ 'it' ], 'poi_type' => [ [ 'a' => 'b' ] ], 'page' => [ 2 ] ] );
restore_error_handler();
$check( 200 === $odd->get_status() && [] === $errors, 'parametri non scalari senza avvisi: ' . implode( '; ', $errors ) );
$check( 200 === strlen( wmwp_poi_search_text( str_repeat( 'a', 500 ) ) ), 'q troncato a 200 caratteri' );

// 8. per_page limitato a 48; senza testo tutti i POI
$many = [];
for ( $i = 1; $i <= 60; $i++ ) {
	$many[] = $poi( 100 + $i, [ 'it' => 'Punto ' . $i ], '<p>x</p>', [] );
}
$shard['features'] = $many;
$rebuild();
$page = $get( [ 'per_page' => 100 ] )->get_data();
$check( 48 === count( $page['items'] ?? [] ) && 60 === ( $page['total'] ?? null ), 'per_page 100 → 48' );
$page2 = $get( [ 'per_page' => 48, 'page' => 2 ] )->get_data();
$check( 12 === count( $page2['items'] ?? [] ), 'seconda pagina' );
// Una pagina enorme (anche dall'indirizzo della ricerca, ?pagina=) non manda in errore la rotta (review finale, I2)
$huge = $get( [ 'per_page' => 48, 'page' => '99999999999999999999' ] );
$check( 200 === $huge->get_status() && [] === ( $huge->get_data()['items'] ?? null ) && 60 === ( $huge->get_data()['total'] ?? null ), 'pagina enorme: 200 senza risultati' );

// 8b. Tipi dal config.json (oc:9018): il tema dà la faccetta dei tipi completata da MAP.filters (include, order, labels,
// lang_labels): solo i tipi pubblicati, nell'ordine e con i nomi del config.json, il nome del filtro del tema
$shard['features'] = $features;
$rebuild();
$from_config = function ( $sources ) {
	$sources['pois']['facets'][0] = [
		'key'         => WMWP_POI_SEARCH_FACET,
		'label'       => 'Tipi',
		'type'        => 'terms',
		'include'     => [ 'fonti', 'siti' ],
		'order'       => [ 'fonti', 'siti' ],
		'labels'      => [ 'siti' => 'Siti dal config' ],
		'lang_labels' => [ 'siti' => 'it' ],
	];
	return $sources;
};
add_filter( 'wp_package_search_sources', $from_config, 99 );
$typed  = $get( [ 'lang' => 'en' ] )->get_data();
$types  = $typed['facets'][0] ?? [];
$ids    = array_column( (array) ( $types['values'] ?? [] ), 'id' );
$sites  = array_values( array_filter( (array) ( $types['values'] ?? [] ), fn( $v ) => 'siti' === $v['id'] ) )[0] ?? [];
$check( [ 'fonti', 'siti' ] === $ids, 'tipi dal config.json: solo quelli pubblicati, nel loro ordine (' . implode( ',', $ids ) . ')' );
$check( 'Siti dal config' === ( $sites['label'] ?? '' ) && 'it' === ( $sites['lang'] ?? '' ), 'tipi dal config.json: nome e lingua del config' );
$check( 'Tipi' === ( $types['label'] ?? '' ), 'tipi dal config.json: nome del filtro dalla faccetta' );
remove_filter( 'wp_package_search_sources', $from_config, 99 );

// 9. Un'app senza POI ha una copia vuota: «0 risultati», non «non disponibile» (review interna oc:9000)
$shard['features'] = [];
$rebuild();
$empty = $get( [ 'q' => 'nuraghe' ] );
$check( [] === get_option( 'wmwp_poi_search_copy', null ) && 200 === $empty->get_status() && 0 === ( $empty->get_data()['total'] ?? null ), 'app senza POI: copia vuota e 200' );

remove_filter( 'pre_http_request', $fake, 10 );
delete_option( 'wmwp_detail_index_rejects' );
if ( null !== $theme_sources ) {
	$GLOBALS['wp_filter']['wp_package_search_sources'] = $theme_sources;
}

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
