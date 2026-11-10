<?php
/**
 * Mappa del plugin (oc:8952): il componente FeatureCollectionMap di wm-package compilato in `assets/dist/` (come si
 * ricompila: map/README.md). Un componente solo per tutto il sito:
 * - la ricerca lo carica al primo passaggio alla vista «Mappa» (assets/js/search.js, `map` nella configurazione);
 * - lo shortcode [wmwp_map] lo mette in una pagina, con le tracce per id o un GeoJSON;
 * - un altro script lo usa con `window.wmWpMap.mount()` dopo aver accodato `wmwp-map` (README, «Mappa»).
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_MAP_SHORTCODE = 'wmwp_map';
const WMWP_MAP_CONFIG_CACHE_PREFIX = 'wmwp_map_config_';
// Le tile Webmapp (api.webmapp.it/tiles) arrivano allo zoom 16: il 17 risponde 404 (verificato il 10/11 su 10 punti
// della Sardegna, il 16 c'era su tutti). Oltre, OpenLayers ingrandisce le tile del 16
const WMWP_WEBMAPP_TILES_MAX_ZOOM = 16;

/** Indirizzo del config.json dell'app configurata, la stessa configurazione che legge la webapp ('' senza shard o app). */
function wmwp_app_config_url(): string {
	$origin = wmwp_configured_api_urls()['origin'];
	$app_id = (int) get_option( WMWP_OPTION_APP_ID, 0 );
	return ( '' !== $origin && $app_id > 0 ) ? $origin . '/api/app/webmapp/' . $app_id . '/config.json' : '';
}

/**
 * Carta di base dal config.json di un'app: la prima voce di `MAP.tiles` (`[{ "webmapp": "https://…/{z}/{x}/{y}.png" }]`),
 * che è anche la prima carta della webapp. Le chiavi sono quelle che legge la mappa (`window.wmWpMap`, `tiles`):
 * `url`, `maxZoom` (ultimo zoom delle tile), `attributions`, `viewMaxZoom` (`MAP.maxZoom` dell'app, se c'è).
 * La carta Webmapp ha l'attribuzione della webapp, «© Webmapp © OpenStreetMap»; per le altre la mette il tema
 * (`wp_package_map_tiles`). Un array vuoto vuol dire: resta OpenStreetMap, la carta del componente.
 *
 * @param array $config config.json decodificato.
 */
function wmwp_parse_map_tiles( array $config ): array {
	$map   = isset( $config['MAP'] ) && is_array( $config['MAP'] ) ? $config['MAP'] : [];
	$list  = isset( $map['tiles'] ) && is_array( $map['tiles'] ) ? $map['tiles'] : [];
	$first = $list ? reset( $list ) : null;
	if ( ! is_array( $first ) || ! $first ) {
		return [];
	}
	$name = (string) array_key_first( $first );
	$url  = is_string( $first[ $name ] ) ? trim( $first[ $name ] ) : '';
	// Niente esc_url_raw: toglierebbe le graffe di {z}/{x}/{y}. L'indirizzo va al JS dentro wp_json_encode
	if ( ! preg_match( '#^https?://[^\s"\'<>]+$#i', $url ) ) {
		return [];
	}
	$tiles = [
		'name'         => $name,
		'url'          => $url,
		'maxZoom'      => 19,
		'attributions' => '',
	];
	if ( 'webmapp' === $name ) {
		$tiles['maxZoom']      = WMWP_WEBMAPP_TILES_MAX_ZOOM;
		$tiles['attributions'] = '© Webmapp © OpenStreetMap';
	}
	if ( isset( $map['maxZoom'] ) && is_numeric( $map['maxZoom'] ) ) {
		$tiles['viewMaxZoom'] = (int) $map['maxZoom'];
	}
	return $tiles;
}

/**
 * Colori dei layer dal config.json di un'app (`MAP.layers[].style.color`), nell'ordine dell'app: sono i colori con cui
 * la webapp disegna le tracce di ogni layer (per Forestas Sentieri `#de1b0d`, Itinerari `#608d0d`, Cammini
 * `#1a416f`, Sentieri Accessibili `#e100ff`). Una traccia prende il colore del primo layer colorato a cui appartiene
 * (assets/js/track-map.js). Valgono solo colori esadecimali o rgb()/rgba().
 *
 * @param array $config config.json decodificato.
 * @return list<array{id: int, color: string}>
 */
function wmwp_parse_map_layer_colors( array $config ): array {
	$layers = isset( $config['MAP']['layers'] ) && is_array( $config['MAP']['layers'] ) ? $config['MAP']['layers'] : [];
	$colors = [];
	foreach ( $layers as $layer ) {
		$color = is_array( $layer ) && isset( $layer['style']['color'] ) && is_string( $layer['style']['color'] ) ? trim( $layer['style']['color'] ) : '';
		if ( isset( $layer['id'] ) && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*(,\s*[\d.]+\s*)?\))$/i', $color ) ) {
			$colors[] = [
				'id'    => (int) $layer['id'],
				'color' => $color,
			];
		}
	}
	return $colors;
}

/**
 * Carta di base e colori dei layer dell'app configurata, letti dal suo config.json e tenuti in cache un giorno (un'ora
 * se lo shard non risponde, così una pagina non aspetta ogni volta il timeout).
 *
 * @return array{tiles: array, layer_colors: array}
 */
function wmwp_app_map_config(): array {
	$empty = [
		'tiles'        => [],
		'layer_colors' => [],
	];
	$url   = wmwp_app_config_url();
	if ( '' === $url ) {
		return $empty;
	}
	$key    = WMWP_MAP_CONFIG_CACHE_PREFIX . md5( $url );
	$cached = get_transient( $key );
	if ( is_array( $cached ) && isset( $cached['tiles'], $cached['layer_colors'] ) ) {
		return $cached;
	}
	$response = wp_remote_get( $url, [ 'timeout' => 5 ] );
	$config   = ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) )
		? json_decode( wp_remote_retrieve_body( $response ), true )
		: null;
	if ( ! is_array( $config ) ) {
		error_log( 'WP Package: config.json dell\'app non disponibile da ' . $url . ', la mappa usa OpenStreetMap' );
		set_transient( $key, $empty, HOUR_IN_SECONDS );
		return $empty;
	}
	$map = [
		'tiles'        => wmwp_parse_map_tiles( $config ),
		'layer_colors' => wmwp_parse_map_layer_colors( $config ),
	];
	set_transient( $key, $map, DAY_IN_SECONDS );
	return $map;
}

/** Carta di base della mappa (wmwp_parse_map_tiles); il tema la cambia con il filter `wp_package_map_tiles`. */
function wmwp_map_tiles(): array {
	return (array) apply_filters( 'wp_package_map_tiles', wmwp_app_map_config()['tiles'] );
}

/** Colori dei layer (wmwp_parse_map_layer_colors); il tema li cambia con il filter `wp_package_map_layer_colors`. */
function wmwp_map_layer_colors(): array {
	return array_values( (array) apply_filters( 'wp_package_map_layer_colors', wmwp_app_map_config()['layer_colors'] ) );
}

/** Script e foglio di stile della mappa, con la versione per la cache: la ricerca li carica solo quando servono. */
function wmwp_map_assets(): array {
	return [
		'script' => add_query_arg( 'ver', wmwp_asset_version( 'assets/dist/wp-package-map.js' ), WMWP_URL . 'assets/dist/wp-package-map.js' ),
		'style'  => add_query_arg( 'ver', wmwp_asset_version( 'assets/dist/wp-package-map.css' ), WMWP_URL . 'assets/dist/wp-package-map.css' ),
	];
}

/**
 * Registra la mappa (`wmwp-map`, script e stile) e i due moduli delle tracce che usano anche ricerca e shortcode.
 * Non accoda niente: chi usa la mappa accoda `wmwp-map`.
 */
function wmwp_register_map_assets(): void {
	$args = [ 'in_footer' => true ];
	wp_register_script( 'wmwp-map', WMWP_URL . 'assets/dist/wp-package-map.js', [], wmwp_asset_version( 'assets/dist/wp-package-map.js' ), $args );
	wp_register_style( 'wmwp-map', WMWP_URL . 'assets/dist/wp-package-map.css', [], wmwp_asset_version( 'assets/dist/wp-package-map.css' ) );
	wp_register_script( 'wmwp-track-api', WMWP_URL . 'assets/js/track-api.js', [], wmwp_asset_version( 'assets/js/track-api.js' ), $args );
	wp_register_script( 'wmwp-track-map', WMWP_URL . 'assets/js/track-map.js', [], wmwp_asset_version( 'assets/js/track-map.js' ), $args );
	wp_register_script( 'wmwp-map-embed', WMWP_URL . 'assets/js/map-embed.js', [ 'wmwp-map', 'wmwp-track-api', 'wmwp-track-map', 'wp-i18n' ], wmwp_asset_version( 'assets/js/map-embed.js' ), $args );
	wp_set_script_translations( 'wmwp-map-embed', 'wp-package', WMWP_PATH . 'languages' );
}
add_action( 'wp_enqueue_scripts', 'wmwp_register_map_assets', 5 );

/**
 * Shortcode [wmwp_map]: una mappa nella pagina.
 * - `tracks`: id delle tracce dello shard, separati da virgole (es. il dettaglio di un percorso, o i percorsi di un
 *   layer), lette da `api/ec/track/multiple`;
 * - `geojson`: in alternativa, l'indirizzo di un GeoJSON (es. `api/ec/poi/<id>.geojson` per un punto di interesse);
 * - `height`: altezza in pixel (420); `label`: nome della mappa per gli screen reader («Map»).
 * Senza tracce né GeoJSON, o con le tracce ma senza shard configurato, non stampa niente.
 *
 * @param array|string $atts Attributi dello shortcode.
 */
function wmwp_render_map( $atts ): string {
	$atts    = shortcode_atts(
		[
			'tracks'  => '',
			'geojson' => '',
			'height'  => '420',
			'label'   => '',
		],
		$atts,
		WMWP_MAP_SHORTCODE
	);
	$tracks  = array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['tracks'] ) ) ) );
	$options = [ 'height' => max( 200, absint( $atts['height'] ) ) ];
	if ( $tracks && '' !== wmwp_tracks_download_url() ) {
		$options['tracks']       = $tracks;
		$options['download_url'] = wmwp_tracks_download_url();
	} elseif ( '' !== trim( (string) $atts['geojson'] ) ) {
		$options['geojson'] = esc_url_raw( trim( (string) $atts['geojson'] ) );
	} else {
		return '';
	}
	$tiles = wmwp_map_tiles();
	if ( $tiles ) {
		$options['tiles'] = $tiles;
	}
	$options['layer_colors'] = wmwp_map_layer_colors();
	wp_enqueue_style( 'wmwp-map' );
	wp_enqueue_script( 'wmwp-map-embed' );
	$label = '' !== trim( (string) $atts['label'] ) ? (string) $atts['label'] : __( 'Map', 'wp-package' );
	return sprintf(
		'<div class="wmwp-map-embed" role="region" aria-label="%1$s" data-wmwp-map="%2$s"></div>',
		esc_attr( $label ),
		esc_attr( (string) wp_json_encode( $options ) )
	);
}
add_shortcode( WMWP_MAP_SHORTCODE, 'wmwp_render_map' );
