<?php
/**
 * Test (oc:9018): faccette costruite dal config.json dell'app, come le legge la webapp. Una faccetta del tema con
 * `from_config` prende opzioni, nomi e ordine da `MAP.filters` (solo le opzioni pubblicate, `include`); i tipi dei POI
 * senza il prefisso `poi_type_`, come wm-core; la chiave `poi_type` (copia per le app vecchie) non si usa. Una con
 * `from_families` diventa una faccetta per famiglia di `LABELS.taxonomies` con quel suffisso. Fixture: i config.json
 * di dev e UAT del 24/11, ridotti (tests/fixtures/config-*.json).
 * Si lancia con: wp eval-file tests/php/app-filters.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1. Rimette opzioni com'erano.
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

// Option vere rimesse com'erano alla fine, anche se il test si interrompe con un errore (un errore fatale di WordPress
// chiude il processo prima delle funzioni di shutdown: per questo il gestore delle eccezioni)
$saved = [];
foreach ( [ WMWP_OPTION_SHARD, WMWP_OPTION_APP_ID, WMWP_APP_CONFIG_OPTION, WMWP_APP_OVERLAYS_OPTION, WMWP_APP_OVERLAY_LAYERS_OPTION ] as $name ) {
	$saved[ $name ] = get_option( $name, null );
}
$restore = function () use ( $saved ) {
	foreach ( $saved as $name => $value ) {
		null === $value ? delete_option( $name ) : update_option( $name, $value, false );
	}
};
set_exception_handler(
	function ( Throwable $e ) use ( $restore ) {
		$restore();
		echo 'FAIL: errore nel test: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
		exit( 1 );
	}
);
update_option( WMWP_OPTION_SHARD, 'forestasdev' );
update_option( WMWP_OPTION_APP_ID, 1 );
$use_fixture = function ( string $env ) {
	update_option(
		WMWP_APP_CONFIG_OPTION,
		[
			'url'    => wmwp_app_config_url(),
			'config' => json_decode( (string) file_get_contents( WMWP_PATH . 'tests/fixtures/config-' . $env . '.json' ), true ),
		],
		false
	);
};

// 1. Attività su UAT: opzioni e ordine di MAP.filters, niente mtb (il config.json di UAT non lo ha)
$use_fixture( 'uat' );
$activities = wmwp_app_filter_facet(
	[
		'key'         => 'activity',
		'from_config' => 'activities',
	],
	'it'
);
$check( [ 'trekking', 'trail', 'horse', 'hiking', 'nordic-walking' ] === ( $activities['order'] ?? null ), 'attività: ordine di MAP.filters su UAT' );
$check( ( $activities['order'] ?? null ) === ( $activities['include'] ?? null ), 'attività: solo le opzioni pubblicate' );
$check( 'taxonomyActivities' === ( $activities['field'] ?? '' ) && 'list' === ( $activities['type'] ?? '' ), 'attività: campo taxonomyActivities, tipo list' );
$check( 'Attività' === ( $activities['label'] ?? '' ), 'attività: nome del filtro da MAP.filters' );
$check( 'activity' === ( $activities['key'] ?? '' ) && ! isset( $activities['from_config'] ), 'attività: la chiave del tema resta, from_config sparisce' );

// 2. Tipo percorso (layers): id dei layer come stringhe, nomi solo italiani marcati sulla pagina inglese
$layers = wmwp_app_filter_facet(
	[
		'key'         => 'type',
		'from_config' => 'layers',
	],
	'en'
);
$check( [ '11', '10', '8' ] === ( $layers['order'] ?? null ), 'layers: ordine di MAP.filters su UAT' );
$check( 'Sentieri' === ( $layers['labels']['11'] ?? '' ) && 'it' === ( $layers['lang_labels']['11'] ?? '' ), 'layers: nome italiano con lang it sulla pagina inglese' );
$check( 'Path type' === ( $layers['label'] ?? '' ) && 'layers' === ( $layers['field'] ?? '' ), 'layers: nome inglese del filtro, campo layers' );
// Review interna (C14): un nome del tema sostituisce quello del config.json, e con lui la lingua (il nome del tema è
// nella lingua della pagina), salvo che il tema la dica. Pagina tedesca: il nome del config.json ripiega sull'italiano
$check( 'it' === ( wmwp_app_filter_facet( [ 'key' => 'activity', 'from_config' => 'activities' ], 'de' )['label_lang'] ?? null ), 'nome del config.json in un\'altra lingua: lang it' );
$sentieri = wmwp_app_filter_facet( [ 'key' => 'activity', 'from_config' => 'activities', 'label' => 'Aktivitäten' ], 'de' );
$check( 'Aktivitäten' === ( $sentieri['label'] ?? '' ) && is_array( $sentieri ) && array_key_exists( 'label_lang', $sentieri ) && null === $sentieri['label_lang'], 'nome del tema: niente lang del nome del config.json' );
$con_lingua = wmwp_app_filter_facet( [ 'key' => 'activity', 'from_config' => 'activities', 'label' => 'Attività', 'label_lang' => 'it' ], 'de' );
$check( 'it' === ( $con_lingua['label_lang'] ?? null ), 'nome del tema con la sua lingua: resta' );

// 3. Tipi dei POI: senza prefisso, poi_type ignorata
$pois = wmwp_app_filter_facet(
	[
		'key'         => 'poi-type',
		'from_config' => 'poi_types',
	],
	'it'
);
$check( 28 === count( (array) ( $pois['order'] ?? [] ) ), 'tipi dei POI: 28 opzioni' );
$check( [] === array_filter( (array) ( $pois['order'] ?? [] ), fn( $id ) => 0 === strpos( $id, 'poi_type_' ) ), 'tipi dei POI: nessun prefisso poi_type_' );
$check( in_array( 'equipped-rest-area', (array) ( $pois['order'] ?? [] ), true ), 'tipi dei POI: equipped-rest-area senza prefisso' );
$check( null === wmwp_app_filter_facet( [ 'key' => 'x', 'from_config' => 'poi_type' ], 'it' ), 'la chiave poi_type non si usa' );
$check( null === wmwp_app_filter_facet( [ 'key' => 'x', 'from_config' => 'nessuna' ], 'it' ), 'una chiave che MAP.filters non ha → null' );

// 4. Famiglie della difficoltà sul dev: una faccetta per famiglia, inclusività esclusa
$use_fixture( 'dev' );
$families = wmwp_app_family_facets(
	[
		'key_prefix'    => '',
		'from_families' => '-difficulty',
		'exclude'       => [ 'inclusivity-difficulty' ],
		'order'         => [ 'hiking-difficulty' => [ 'hiking-difficulty-T', 'hiking-difficulty-E', 'hiking-difficulty-EE', 'hiking-difficulty-EEA' ] ],
	],
	'it'
);
$keys     = array_column( $families, 'key' );
$check( 6 === count( $families ), 'difficoltà: 6 famiglie, invece di ' . count( $families ) );
$check( ! in_array( 'inclusivity-difficulty', $keys, true ), 'difficoltà: inclusività esclusa' );
$hiking = $families[ array_search( 'hiking-difficulty', $keys, true ) ] ?? [];
$check( 'Difficoltà escursionistica' === ( $hiking['label'] ?? '' ) && 'terms.hiking-difficulty' === ( $hiking['field'] ?? '' ) && 'terms' === ( $hiking['type'] ?? '' ), 'difficoltà escursionistica: nome, campo e tipo' );
$check( 'hiking-difficulty-T' === ( $hiking['order'][0] ?? '' ), 'difficoltà escursionistica: ordine di ripiego del tema' );
$check( array_key_exists( 'label_lang', $hiking ) && null === $hiking['label_lang'], 'nome della famiglia sulla pagina italiana: nessun lang' );
$english = wmwp_app_family_facets( [ 'from_families' => '-difficulty' ], 'en' );
$check( 'it' === ( $english[0]['label_lang'] ?? '' ), 'nome della famiglia solo italiano: lang it sulla pagina inglese' );

// 5. Senza config.json: nessuna faccetta, nessun errore
delete_option( WMWP_APP_CONFIG_OPTION );
$check( null === wmwp_app_filter_facet( [ 'key' => 'activity', 'from_config' => 'activities' ], 'it' ), 'senza config.json: null' );
$check( [] === wmwp_app_family_facets( [ 'from_families' => '-difficulty' ], 'it' ), 'senza config.json: nessuna famiglia' );

// 6. wmwp_search_sources() espande le faccette del tema
$use_fixture( 'uat' );
$theme = function ( $sources ) {
	$sources['tracks']['facets'] = [
		[
			'key'         => 'activity',
			'from_config' => 'activities',
		],
		[
			'key'   => 'mine',
			'label' => 'Mio',
			'type'  => 'list',
			'field' => 'x',
		],
		[
			'from_families' => '-difficulty',
			'exclude'       => [ 'inclusivity-difficulty' ],
		],
		[
			'key'         => 'gone',
			'from_config' => 'nessuna',
		],
	];
	return $sources;
};
add_filter( 'wp_package_search_sources', $theme, 99 );
$facets = wmwp_search_sources()['tracks']['facets'];
remove_filter( 'wp_package_search_sources', $theme, 99 );
$check( 'activity' === ( $facets[0]['key'] ?? '' ) && isset( $facets[0]['labels'] ), 'sources: from_config espansa al suo posto' );
$check( 'mine' === ( $facets[1]['key'] ?? '' ) && 'Mio' === ( $facets[1]['label'] ?? '' ), 'sources: una faccetta del tema resta com\'è' );
$check( 'hiking-difficulty' === ( $facets[2]['key'] ?? '' ), 'sources: from_families espansa al suo posto' );
$check( ! in_array( 'gone', array_column( $facets, 'key' ), true ), 'sources: una from_config senza dati sparisce' );
$check( array_values( $facets ) === $facets, 'sources: lista con chiavi sequenziali' );

// 7. Filtri di luogo dagli overlay: una faccetta per overlay con aree, sul campo layers, nel parametro condiviso layer
// I filtri leggono l'option piccola con gli id: senza, li ricavano dalla copia con le geometrie, quella del test
delete_option( WMWP_APP_OVERLAY_LAYERS_OPTION );
update_option(
	WMWP_APP_CONFIG_OPTION,
	[
		'url'    => wmwp_app_config_url(),
		'config' => [
			'MAP' => [
				'layers'   => [ [ 'id' => 3, 'name' => [ 'it' => 'Area D' ] ], [ 'id' => 50, 'name' => [ 'it' => 'Supramontes' ] ] ],
				'controls' => [
					'overlays' => [
						[ 'label' => [ 'it' => 'Dettagli Mappa' ], 'type' => 'title' ],
						[ 'label' => [ 'it' => 'Aree geografiche', 'en' => 'Geographical areas' ], 'type' => 'button', 'url' => 'http://shard.test/fc/2.geojson' ],
						[ 'label' => [ 'it' => 'Aree Catastali' ], 'type' => 'button', 'url' => 'http://shard.test/fc/1.geojson' ],
					],
				],
			],
		],
	],
	false
);
$square = [ 'type' => 'Polygon', 'coordinates' => [ [ [ 0, 0 ], [ 1, 0 ], [ 1, 1 ], [ 0, 0 ] ] ] ];
update_option(
	WMWP_APP_OVERLAYS_OPTION,
	[
		'http://shard.test/fc/2.geojson' => [ 'areas' => [ [ 'layer_id' => 50, 'geometry' => $square, 'bbox' => [ 0, 0, 1, 1 ] ] ] ],
		'http://shard.test/fc/1.geojson' => [ 'areas' => [ [ 'layer_id' => 3, 'geometry' => $square, 'bbox' => [ 0, 0, 1, 1 ] ] ] ],
	],
	false
);
$places = wmwp_app_expand_facets( [ [ 'from_overlays' => true ] ], 'en' );
$check( [ 'area-1', 'area-2' ] === array_column( $places, 'key' ), 'overlay: una faccetta per overlay, chiave dalla posizione' );
$check( 'Geographical areas' === ( $places[0]['label'] ?? '' ) && 'layers' === ( $places[0]['field'] ?? '' ) && 'layer' === ( $places[0]['param'] ?? '' ), 'overlay: nome, campo layers, parametro layer' );
$check( [ '50' ] === ( $places[0]['ids'] ?? null ) && [ '50' ] === ( $places[0]['include'] ?? null ) && 'Supramontes' === ( $places[0]['labels']['50'] ?? '' ), 'overlay: id, include e nomi delle aree' );
$check( 'it' === ( $places[1]['label_lang'] ?? '' ) && 'it' === ( $places[1]['lang_labels']['3'] ?? '' ), 'overlay: nomi solo italiani marcati' );
// Il parametro delle aree è fisso (`layer`): lo legge anche la rotta dei POI (review interna, C9)
$check( [ 'layer' ] === array_unique( array_column( wmwp_app_expand_facets( [ [ 'from_overlays' => true, 'param' => 'zona' ] ], 'en' ), 'param' ) ), 'overlay: param del tema ignorato' );

// Slider di MAP.filters (oc:9003): chiave del filtro, campo dell'indice, limiti, passo e unità dal config.json
$use_fixture( 'uat' );
$distance = wmwp_app_filter_facet( [ 'from_config' => 'track_distance' ], 'it' );
$check( 'slider' === ( $distance['type'] ?? '' ) && 'track_distance' === ( $distance['key'] ?? '' ) && 'distance' === ( $distance['field'] ?? '' ), 'slider: tipo, chiave e campo' );
$check( 1.0 === ( $distance['min'] ?? null ) && 30.0 === ( $distance['max'] ?? null ) && 3.0 === ( $distance['step'] ?? null ) && 'km' === ( $distance['units'] ?? '' ), 'slider: limiti, passo e unità' );
$check( 'Lunghezza del sentiero' === ( $distance['label'] ?? '' ), 'slider: nome italiano' );
$duration = wmwp_app_filter_facet( [ 'from_config' => 'track_duration' ], 'en' );
$check( 'Travel time' === ( $duration['label'] ?? '' ) && 'duration_forward' === ( $duration['field'] ?? '' ) && 30.0 === ( $duration['step'] ?? null ), 'slider: nome inglese, campo e passo del tempo' );
$broken = wmwp_app_config();
$broken['MAP']['filters']['track_distance']['min'] = '';
update_option( WMWP_APP_CONFIG_OPTION, [ 'url' => wmwp_app_config_url(), 'config' => $broken ], false );
$check( null === wmwp_app_filter_facet( [ 'from_config' => 'track_distance' ], 'it' ), 'slider senza minimo: nessuna faccetta' );
$check( [] === wmwp_app_expand_facets( [ [ 'from_config' => 'track_distance' ] ], 'it' ), 'slider senza minimo: sparisce dalle faccette' );

$restore();


if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
