<?php
/**
 * Test (oc:8952): carta di base e colori dei layer della mappa vengono dal config.json dell'app configurata (prima voce
 * di MAP.tiles, `style.color` di MAP.layers), dalla copia scaricata da WP-Cron (oc:9018); senza copia resta
 * OpenStreetMap e nessun colore. Le risposte dello shard sono finte (pre_http_request). Si lancia con:
 * wp eval-file tests/php/map-tiles.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1. Rimette opzioni e transient com'erano.
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

// 1. Funzione pura: dal config.json alla carta
$webmapp = wmwp_parse_map_tiles(
	[
		'MAP' => [
			'maxZoom' => 17,
			'tiles'   => [
				[ 'webmapp' => 'https://api.webmapp.it/tiles/{z}/{x}/{y}.png' ],
				[ 'satellite' => 'https://api.maptiler.com/tiles/satellite/{z}/{x}/{y}.jpg?key=x' ],
			],
		],
	]
);
$check( 'https://api.webmapp.it/tiles/{z}/{x}/{y}.png' === ( $webmapp['url'] ?? '' ), 'la prima carta è la Webmapp, con {z}/{x}/{y} intatti' );
$check( 16 === ( $webmapp['maxZoom'] ?? null ), 'le tile Webmapp arrivano allo zoom 16' );
$check( 17 === ( $webmapp['viewMaxZoom'] ?? null ), 'la vista si ferma al maxZoom dell\'app' );
$check( '© Webmapp © OpenStreetMap' === ( $webmapp['attributions'] ?? '' ), 'attribuzione della carta Webmapp come nella webapp' );

$other = wmwp_parse_map_tiles( [ 'MAP' => [ 'tiles' => [ [ 'osm' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ] ] ] ] );
$check( 19 === ( $other['maxZoom'] ?? null ) && '' === ( $other['attributions'] ?? null ) && ! isset( $other['viewMaxZoom'] ), 'un\'altra carta: zoom 19, attribuzione dal tema' );
$check( [] === wmwp_parse_map_tiles( [ 'MAP' => [ 'tiles' => [] ] ] ), 'senza carte, niente' );
$check( [] === wmwp_parse_map_tiles( [ 'MAP' => [ 'tiles' => [ [ 'x' => 'javascript:alert(1)' ] ] ] ] ), 'un indirizzo non http(s) si scarta' );
$check( [] === wmwp_parse_map_tiles( [] ), 'config.json senza MAP' );

// Colori dei layer, nell'ordine dell'app; senza colore o con un colore non valido il layer non c'è
$colors = wmwp_parse_map_layer_colors(
	[
		'MAP' => [
			'layers' => [
				[ 'id' => 6, 'style' => [ 'color' => null ] ],
				[ 'id' => 8, 'style' => [ 'color' => '#1a416f' ] ],
				[ 'id' => 9, 'style' => [ 'color' => 'rgba(225, 0, 255, 1)' ] ],
				[ 'id' => 10, 'style' => [ 'color' => 'url(javascript:x)' ] ],
				[ 'id' => 11, 'style' => [ 'color' => '#DE1B0D' ] ],
			],
		],
	]
);
$check(
	[ [ 'id' => 8, 'color' => '#1a416f' ], [ 'id' => 9, 'color' => 'rgba(225, 0, 255, 1)' ], [ 'id' => 11, 'color' => '#DE1B0D' ] ] === $colors,
	'colori dei layer nell\'ordine dell\'app, solo quelli validi'
);
$check( [] === wmwp_parse_map_layer_colors( [ 'MAP' => [] ] ), 'senza layer, nessun colore' );

// 2. Lettura dallo shard configurato, con la cache
$saved_shard = get_option( WMWP_OPTION_SHARD );
$saved_app   = get_option( WMWP_OPTION_APP_ID );
update_option( WMWP_OPTION_SHARD, 'forestasdev' );
update_option( WMWP_OPTION_APP_ID, 1 );
// Il browser e il server WordPress raggiungono lo shard da due indirizzi (es. WordPress in Docker): il config.json lo
// legge il server, quindi va chiesto all'indirizzo di wmwp_server_origin
$browser_urls  = function ( $urls ) {
	$urls['origin'] = 'http://browser.test';
	return $urls;
};
$server_origin = fn() => 'http://server.test';
add_filter( 'wmwp_api_urls', $browser_urls, 99 );
add_filter( 'wmwp_server_origin', $server_origin, 99 );
$calls    = 0;
$answer   = 'ok';
$last_url = '';
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$calls, &$answer, &$last_url ) {
		if ( false === strpos( $url, '/api/app/webmapp/1/config.json' ) ) {
			return $pre;
		}
		++$calls;
		$last_url = $url;
		if ( 'down' === $answer ) {
			return new WP_Error( 'wmwp_test_down', 'shard non risponde (test)' );
		}
		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'MAP' => [
						'maxZoom' => 17,
						'tiles'   => [ [ 'webmapp' => 'https://api.webmapp.it/tiles/{z}/{x}/{y}.png' ] ],
						'layers'  => [ [ 'id' => 11, 'style' => [ 'color' => '#de1b0d' ] ] ],
					],
				]
			),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
		];
	},
	10,
	3
);
// La copia la scarica il cron (oc:9018): la pagina non chiama mai lo shard
$saved_copy   = get_option( WMWP_APP_CONFIG_OPTION, null );
$saved_failed = get_option( WMWP_APP_CONFIG_FAILED_OPTION, null );
delete_option( WMWP_APP_CONFIG_OPTION );
$check( [] === wmwp_map_tiles() && 0 === $calls, 'senza copia: nessuna carta e nessuna richiesta nella pagina' );
wmwp_app_config_refresh();
$first  = wmwp_map_tiles();
$second = wmwp_map_tiles();
$layers = wmwp_map_layer_colors();
$check( 1 === $calls, "una richiesta sola al config.json, dal cron, invece di {$calls}" );
$check( 'http://server.test/api/app/webmapp/1/config.json' === $last_url, "config.json chiesto all'indirizzo del server, non a {$last_url}" );
$check( [ [ 'id' => 11, 'color' => '#de1b0d' ] ] === $layers, 'colori dei layer letti dallo shard' );
$check( 'https://api.webmapp.it/tiles/{z}/{x}/{y}.png' === ( $first['url'] ?? '' ) && $first === $second, 'carta letta dalla copia' );

$answer = 'down';
$check( false === wmwp_app_config_refresh(), 'shard che non risponde: il cron lo dice' );
$check( 'https://api.webmapp.it/tiles/{z}/{x}/{y}.png' === ( wmwp_map_tiles()['url'] ?? '' ), "shard che non risponde: resta l'ultima carta buona" );
delete_option( WMWP_APP_CONFIG_OPTION );
$check( [] === wmwp_map_tiles(), 'senza nessuna copia: nessuna carta, resta OpenStreetMap' );
$check( [] === wmwp_map_layer_colors(), 'senza nessuna copia: nessun colore dei layer' );

add_filter( 'wp_package_map_tiles', fn() => [ 'url' => 'https://tema.example/{z}/{x}/{y}.png' ] );
$check( 'https://tema.example/{z}/{x}/{y}.png' === ( wmwp_map_tiles()['url'] ?? '' ), 'il tema cambia la carta con wp_package_map_tiles' );
add_filter( 'wp_package_map_layer_colors', fn() => [ [ 'id' => 1, 'color' => '#000000' ] ] );
$check( [ [ 'id' => 1, 'color' => '#000000' ] ] === wmwp_map_layer_colors(), 'il tema cambia i colori con wp_package_map_layer_colors' );

if ( null === $saved_copy ) {
	delete_option( WMWP_APP_CONFIG_OPTION );
} else {
	update_option( WMWP_APP_CONFIG_OPTION, $saved_copy, false );
}
// Il download fallito del test segna l'ora del fallimento (oc:9018): rimessa com'era
null === $saved_failed ? delete_option( WMWP_APP_CONFIG_FAILED_OPTION ) : update_option( WMWP_APP_CONFIG_FAILED_OPTION, $saved_failed, false );
remove_filter( 'wmwp_api_urls', $browser_urls, 99 );
remove_filter( 'wmwp_server_origin', $server_origin, 99 );
update_option( WMWP_OPTION_SHARD, $saved_shard );
update_option( WMWP_OPTION_APP_ID, $saved_app );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
