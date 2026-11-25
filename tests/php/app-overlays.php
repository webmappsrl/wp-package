<?php
/**
 * Test (oc:9018): aree degli overlay del config.json e appartenenza dei POI. Il cron scarica il geojson di ogni overlay
 * con richiesta condizionale e tiene solo le aree con `layer_id`; wmwp_app_overlays() dà gli overlay con almeno un'area
 * il cui `layer_id` sta in MAP.layers (un'area di Geohub, 206, si salta). wmwp_point_in_geometry() regge buchi e
 * MultiPolygon. L'appartenenza dei POI segue la copia dei POI: id nuovi, appartenenza nuova. La rotta wmwp/v1/pois
 * filtra con `layer[]`. Fixture: tests/fixtures/overlay-areas.geojson e overlay-plain.geojson (coordinate finte).
 * Si lancia con: wp eval-file tests/php/app-overlays.php
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

$saved = [];
foreach ( [ WMWP_OPTION_SHARD, WMWP_OPTION_APP_ID, WMWP_APP_CONFIG_OPTION, WMWP_APP_OVERLAYS_OPTION, WMWP_APP_OVERLAY_LAYERS_OPTION, WMWP_POI_LAYERS_OPTION, WMWP_POI_SEARCH_OPTION ] as $name ) {
	$saved[ $name ] = get_option( $name, null );
}
// Option vere rimesse com'erano alla fine, anche se il test si interrompe con un errore (un errore fatale di WordPress
// chiude il processo prima delle funzioni di shutdown: per questo il gestore delle eccezioni)
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
delete_option( WMWP_APP_OVERLAYS_OPTION );
delete_option( WMWP_APP_OVERLAY_LAYERS_OPTION );
delete_option( WMWP_POI_LAYERS_OPTION );
update_option(
	WMWP_APP_CONFIG_OPTION,
	[
		'url'    => wmwp_app_config_url(),
		'config' => [
			'MAP' => [
				'layers'   => [
					[ 'id' => 3, 'name' => [ 'it' => 'Area D' ] ],
					[ 'id' => 50, 'name' => [ 'it' => 'Supramontes', 'en' => 'Supramontes' ] ],
				],
				'controls' => [
					'overlays' => [
						[ 'label' => [ 'it' => 'Dettagli Mappa' ], 'type' => 'title' ],
						[ 'label' => [ 'it' => 'Aree Catastali' ], 'type' => 'button', 'url' => 'http://shard.test/fc/1.geojson' ],
						[ 'label' => [ 'it' => 'Aree no caccia', 'en' => 'Hunting areas' ], 'type' => 'button', 'url' => 'http://shard.test/fc/5.geojson' ],
					],
				],
			],
		],
	],
	false
);

$downloads = [];
$headers   = [];
$fake      = function ( $pre, $args, $url ) use ( &$downloads, &$headers ) {
	$files = [
		'http://shard.test/fc/1.geojson' => 'overlay-areas.geojson',
		'http://shard.test/fc/5.geojson' => 'overlay-plain.geojson',
	];
	if ( ! isset( $files[ $url ] ) ) {
		return $pre;
	}
	$headers[ $url ] = (array) ( $args['headers'] ?? [] );
	if ( '"' . $files[ $url ] . '"' === ( $headers[ $url ]['If-None-Match'] ?? '' ) ) {
		return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 304, 'message' => 'Not Modified' ], 'cookies' => [] ];
	}
	$downloads[ $url ] = ( $downloads[ $url ] ?? 0 ) + 1;
	return [
		'headers'  => [ 'etag' => '"' . $files[ $url ] . '"' ],
		'body'     => (string) file_get_contents( WMWP_PATH . 'tests/fixtures/' . $files[ $url ] ),
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'cookies'  => [],
	];
};
add_filter( 'pre_http_request', $fake, 10, 3 );

// 1. Punto nel poligono
$hole  = [ 'type' => 'Polygon', 'coordinates' => [ [ [ 0, 0 ], [ 10, 0 ], [ 10, 10 ], [ 0, 10 ], [ 0, 0 ] ], [ [ 4, 4 ], [ 6, 4 ], [ 6, 6 ], [ 4, 6 ], [ 4, 4 ] ] ] ];
$multi = [ 'type' => 'MultiPolygon', 'coordinates' => [ [ [ [ 40, 0 ], [ 45, 0 ], [ 45, 5 ], [ 40, 5 ], [ 40, 0 ] ] ], [ [ [ 50, 0 ], [ 55, 0 ], [ 55, 5 ], [ 50, 5 ], [ 50, 0 ] ] ] ] ];
$check( true === wmwp_point_in_geometry( 2, 2, $hole ), 'punto dentro' );
$check( false === wmwp_point_in_geometry( 5, 5, $hole ), 'punto nel buco' );
$check( false === wmwp_point_in_geometry( 15, 5, $hole ), 'punto fuori' );
$check( true === wmwp_point_in_geometry( 52, 2, $multi ), 'MultiPolygon, seconda parte' );
$check( false === wmwp_point_in_geometry( 47, 2, $multi ), 'MultiPolygon, fra le due parti' );
$check( false === wmwp_point_in_geometry( 1, 1, [ 'type' => 'Point', 'coordinates' => [ 1, 1 ] ] ), 'geometria che non è un poligono' );

// 2. Download: due overlay, uno solo con aree utili
$check( true === wmwp_app_overlays_refresh(), 'download degli overlay riuscito' );
$overlays = wmwp_app_overlays( 'it' );
$check( 1 === count( $overlays ), 'un overlay con aree utili, invece di ' . count( $overlays ) );
$cadastral = $overlays[0] ?? [];
$check( 'Aree Catastali' === ( $cadastral['label'] ?? '' ), 'nome dell\'overlay' );
$check( [ 3, 50 ] === array_column( (array) ( $cadastral['areas'] ?? [] ), 'layer_id' ), 'aree con layer_id in MAP.layers (206 saltata, senza layer_id saltata)' );
$check( 'Area D' === ( $cadastral['areas'][0]['label'] ?? '' ), 'nome dell\'area da MAP.layers' );
$english = wmwp_app_overlays( 'en' );
$check( 'it' === ( $english[0]['label_lang'] ?? '' ) && 'it' === ( $english[0]['areas'][0]['label_lang'] ?? '' ), 'nomi solo italiani marcati sulla pagina inglese' );
$check( '1' === ( $cadastral['id'] ?? '' ), 'id dell\'overlay: la sua posizione nel config.json' );
// Review interna (C5): la copia con le geometrie tiene solo le aree dei layer di MAP.layers, e i filtri leggono un'option
// piccola con gli id, senza deserializzare le geometrie (MB) a ogni pagina
$stored_ids = array_column( (array) ( get_option( WMWP_APP_OVERLAYS_OPTION )['http://shard.test/fc/1.geojson']['areas'] ?? [] ), 'layer_id' );
$check( [ 3, 50 ] === $stored_ids, 'copia delle geometrie senza l\'area 206, fuori da MAP.layers (' . implode( ',', $stored_ids ) . ')' );
$check( [ 'http://shard.test/fc/1.geojson' => [ 3, 50 ], 'http://shard.test/fc/5.geojson' => [] ] === get_option( WMWP_APP_OVERLAY_LAYERS_OPTION ), 'option piccola: id delle aree per overlay' );
$letture = 0;
$conta   = function ( $pre ) use ( &$letture ) {
	++$letture;
	return $pre;
};
add_filter( 'pre_option_' . WMWP_APP_OVERLAYS_OPTION, $conta );
wmwp_app_overlays( 'it' );
remove_filter( 'pre_option_' . WMWP_APP_OVERLAYS_OPTION, $conta );
$check( 0 === $letture, "i filtri non leggono le geometrie, invece {$letture} letture" );

// 3. Secondo giro: richiesta condizionale, nessun nuovo download, l'overlay senza layer_id resta escluso
wmwp_app_overlays_refresh();
$check( 1 === ( $downloads['http://shard.test/fc/1.geojson'] ?? 0 ) && 1 === ( $downloads['http://shard.test/fc/5.geojson'] ?? 0 ), 'secondo giro: 304, nessun nuovo download' );
$check( '"overlay-plain.geojson"' === ( $headers['http://shard.test/fc/5.geojson']['If-None-Match'] ?? '' ), 'secondo giro: richiesta condizionale anche per l\'overlay senza layer_id' );
$check( 1 === count( wmwp_app_overlays( 'it' ) ), 'secondo giro: sempre un overlay' );

// 4. Appartenenza dei POI dalla copia, e copia con id nuovi
$row  = fn( int $id, ?array $point ) => [
	'id'         => $id,
	'name'       => [ 'it' => 'POI ' . $id ],
	'searchable' => [ 'it' => 'poi ' . $id ],
	'excerpt'    => [],
	'image'      => null,
	'types'      => [],
	'wheres'     => [],
	'point'      => $point,
];
update_option( WMWP_POI_SEARCH_OPTION, [ $row( 1, [ 2, 2 ] ), $row( 2, [ 52, 2 ] ), $row( 3, [ 5, 5 ] ), $row( 4, null ) ], false );
wmwp_poi_layers_compute();
$layers = wmwp_poi_layers();
$check( [ 3 ] === ( $layers[1] ?? null ) && [ 50 ] === ( $layers[2] ?? null ), 'POI 1 nell\'Area D, POI 2 nei Supramontes' );
$check( ! isset( $layers[3] ) && ! isset( $layers[4] ), 'POI nel buco e POI senza punto: nessuna area' );
// La copia nuova arriva dal giro dell'indice: wmwp_poi_search_commit() la scrive e ricalcola l'appartenenza
wmwp_poi_search_pending( null, true );
wmwp_poi_search_pending( [ [ 'type' => 'Feature', 'geometry' => [ 'type' => 'Point', 'coordinates' => [ 2, 2 ] ], 'properties' => [ 'id' => 7, 'name' => [ 'it' => 'POI 7' ] ] ], [ 'type' => 'Feature', 'geometry' => [ 'type' => 'Point', 'coordinates' => [ 52, 2 ] ], 'properties' => [ 'id' => 8, 'name' => [ 'it' => 'POI 8' ] ] ] ] );
wmwp_poi_search_commit();
$layers = wmwp_poi_layers();
$check( isset( $layers[7], $layers[8] ) && ! isset( $layers[1] ) && ! isset( $layers[2] ), 'copia con id nuovi: appartenenza con gli id nuovi' );

// 5. Rotta dei POI con layer[]: faccetta del tema sul campo layers
update_option( WMWP_POI_SEARCH_OPTION, [ $row( 1, [ 2, 2 ] ), $row( 2, [ 52, 2 ] ), $row( 3, [ 5, 5 ] ) ], false );
wmwp_poi_layers_compute();
$theme = function ( $sources ) {
	$sources['pois']['facets'][] = [
		'key'    => 'area-1',
		'label'  => 'Aree Catastali',
		'type'   => 'list',
		'field'  => 'layers',
		'param'  => 'layer',
		'ids'    => [ '3', '50' ],
		'labels' => [ '3' => 'Area D', '50' => 'Supramontes' ],
		'order'  => [ '3', '50' ],
	];
	return $sources;
};
add_filter( 'wp_package_search_sources', $theme, 99 );
$request = new WP_REST_Request( 'GET', '/wmwp/v1/pois' );
$request->set_param( 'layer', [ '3', '999' ] );
$data  = rest_do_request( $request )->get_data();
$ids   = array_column( (array) ( $data['items'] ?? [] ), 'id' );
$check( [ 1 ] === $ids, 'layer[]=3 → solo il POI dell\'Area D (' . implode( ',', $ids ) . ')' );
$areas = array_values( array_filter( (array) ( $data['facets'] ?? [] ), fn( $f ) => 'area-1' === $f['key'] ) )[0] ?? [];
$check( [ '3', '50' ] === array_column( (array) ( $areas['values'] ?? [] ), 'id' ), 'faccetta delle aree: Area D (scelta) e Supramontes' );
$check( 'Area D' === ( $areas['values'][0]['label'] ?? '' ) && true === ( $areas['values'][0]['selected'] ?? null ), 'faccetta delle aree: nome e scelta' );
remove_filter( 'wp_package_search_sources', $theme, 99 );

remove_filter( 'pre_http_request', $fake, 10 );
$restore();

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
