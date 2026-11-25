<?php
/**
 * Aree degli overlay del config.json e appartenenza dei POI (oc:9018), come i filtri delle aree della webapp.
 *
 * Ogni voce di `MAP.controls.overlays` (tranne i titoli) ha l'indirizzo di un geojson: le sue aree con `layer_id` sono
 * le opzioni di un filtro di luogo, con il nome del layer da `MAP.layers`. Un percorso sta in un'area se il suo
 * `layers` contiene il `layer_id`; un POI se il suo punto cade nel poligono (Giuseppe, scrum del 23/11: «le geometrie
 * per fare il filtro le devi avere a priori»).
 *
 * I geojson si scaricano solo da WP-Cron, dopo il config.json (`WMWP_APP_CONFIG_CRON_HOOK`), con richiesta condizionale:
 * l'indirizzo non cambia quando il file si rigenera, e un file non cambiato non si riscarica (sul dev «Aree no caccia»
 * pesa 5,3 MB). Si tengono solo le aree con un `layer_id` di `MAP.layers` (su UAT le 39 aree di Geohub non lo sono),
 * con il loro riquadro; un overlay senza aree utili si ricorda come tale finché il suo file o `MAP.layers` non cambiano.
 * Le geometrie (MB) le legge solo il cron: i filtri delle pagine leggono un'option piccola con gli id delle aree
 * (`WMWP_APP_OVERLAY_LAYERS_OPTION`). L'appartenenza dei POI si ricalcola quando cambiano le aree o la copia dei
 * POI (gli id cambiano: su UAT il reset notturno li fa ripartire).
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_APP_OVERLAYS_OPTION       = 'wmwp_app_overlays_v1';
const WMWP_APP_OVERLAY_LAYERS_OPTION = 'wmwp_app_overlay_layers_v1';
const WMWP_POI_LAYERS_OPTION         = 'wmwp_poi_layers_v1';
const WMWP_APP_OVERLAYS_TIMEOUT = 30;

/**
 * Indirizzi dei geojson degli overlay del config.json, per posizione nell'elenco (i titoli non hanno indirizzo).
 *
 * @return array<int, string>
 */
function wmwp_app_overlay_urls( array $config ): array {
	$urls = [];
	foreach ( (array) ( $config['MAP']['controls']['overlays'] ?? [] ) as $index => $overlay ) {
		$url = is_array( $overlay ) ? (string) ( $overlay['url'] ?? '' ) : '';
		if ( 'title' !== ( $overlay['type'] ?? '' ) && 1 === preg_match( '#^https?://#i', $url ) ) {
			$urls[ (int) $index ] = $url;
		}
	}
	return $urls;
}

/**
 * Riquadro di una geometria: [ minLon, minLat, maxLon, maxLat ], null se non ha coordinate.
 *
 * @return array{0: float, 1: float, 2: float, 3: float}|null
 */
function wmwp_geometry_bbox( array $geometry ): ?array {
	$box   = null;
	$walk  = function ( $coords ) use ( &$walk, &$box ) {
		if ( is_array( $coords ) && isset( $coords[0], $coords[1] ) && is_numeric( $coords[0] ) && is_numeric( $coords[1] ) ) {
			$x   = (float) $coords[0];
			$y   = (float) $coords[1];
			$box = null === $box ? [ $x, $y, $x, $y ] : [ min( $box[0], $x ), min( $box[1], $y ), max( $box[2], $x ), max( $box[3], $y ) ];
			return;
		}
		foreach ( is_array( $coords ) ? $coords : [] as $item ) {
			$walk( $item );
		}
	};
	$walk( $geometry['coordinates'] ?? [] );
	return $box;
}

/** Vero se il punto (lon, lat) sta nel Polygon o MultiPolygon, fuori dai suoi buchi. */
function wmwp_point_in_geometry( float $lon, float $lat, array $geometry ): bool {
	$type     = (string) ( $geometry['type'] ?? '' );
	$polygons = 'Polygon' === $type ? [ $geometry['coordinates'] ?? [] ] : ( 'MultiPolygon' === $type ? (array) ( $geometry['coordinates'] ?? [] ) : [] );
	foreach ( $polygons as $rings ) {
		$inside = false;
		foreach ( array_values( (array) $rings ) as $i => $ring ) {
			$in = wmwp_point_in_ring( $lon, $lat, (array) $ring );
			if ( 0 === $i ) {
				$inside = $in;
				if ( ! $in ) {
					break;
				}
			} elseif ( $in ) {
				$inside = false;
				break;
			}
		}
		if ( $inside ) {
			return true;
		}
	}
	return false;
}

/** Ray casting su un anello: vero se il punto è dentro. */
function wmwp_point_in_ring( float $lon, float $lat, array $ring ): bool {
	$inside = false;
	$count  = count( $ring );
	for ( $i = 0, $j = $count - 1; $i < $count; $j = $i++ ) {
		$xi = (float) ( $ring[ $i ][0] ?? 0 );
		$yi = (float) ( $ring[ $i ][1] ?? 0 );
		$xj = (float) ( $ring[ $j ][0] ?? 0 );
		$yj = (float) ( $ring[ $j ][1] ?? 0 );
		if ( ( $yi > $lat ) !== ( $yj > $lat ) && $lon < ( $xj - $xi ) * ( $lat - $yi ) / ( $yj - $yi ) + $xi ) {
			$inside = ! $inside;
		}
	}
	return $inside;
}

/**
 * Aree con `layer_id` di un geojson decodificato, con il riquadro.
 *
 * @return list<array{layer_id: int, geometry: array, bbox: array}>
 */
function wmwp_app_overlay_areas_from( array $collection ): array {
	$areas = [];
	foreach ( (array) ( $collection['features'] ?? [] ) as $feature ) {
		$layer    = is_array( $feature ) ? ( $feature['properties']['layer_id'] ?? null ) : null;
		$geometry = is_array( $feature ) && is_array( $feature['geometry'] ?? null ) ? $feature['geometry'] : null;
		if ( ! is_numeric( $layer ) || null === $geometry || ! in_array( $geometry['type'] ?? '', [ 'Polygon', 'MultiPolygon' ], true ) ) {
			continue;
		}
		$bbox = wmwp_geometry_bbox( $geometry );
		if ( null !== $bbox ) {
			$areas[] = [
				'layer_id' => (int) $layer,
				'geometry' => $geometry,
				'bbox'     => $bbox,
			];
		}
	}
	return $areas;
}

/**
 * Scarica i geojson degli overlay (dal cron). Vero se tutti sono aggiornati; un errore lascia le aree di prima di
 * quell'overlay e lo scrive nel log.
 */
function wmwp_app_overlays_refresh(): bool {
	$config = wmwp_app_config();
	if ( null === $config ) {
		return false;
	}
	$stored  = (array) get_option( WMWP_APP_OVERLAYS_OPTION, [] );
	$out     = [];
	$ok      = true;
	$changed = false;
	// Si tengono solo le aree dei layer dell'app: se `MAP.layers` cambia, i file si riscaricano per intero
	$allowed = array_keys( wmwp_app_layer_names( $config ) );
	$layers  = md5( implode( ',', $allowed ) );
	foreach ( wmwp_app_overlay_urls( $config ) as $url ) {
		$prev    = is_array( $stored[ $url ] ?? null ) && $layers === ( $stored[ $url ]['layers'] ?? '' ) ? $stored[ $url ] : null;
		$headers = [];
		if ( '' !== (string) ( $prev['etag'] ?? '' ) ) {
			$headers['If-None-Match'] = (string) $prev['etag'];
		}
		if ( '' !== (string) ( $prev['last_modified'] ?? '' ) ) {
			$headers['If-Modified-Since'] = (string) $prev['last_modified'];
		}
		$response = wp_remote_get(
			wmwp_server_url( $url ),
			[
				'timeout' => WMWP_APP_OVERLAYS_TIMEOUT,
				'headers' => $headers,
			]
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code && null !== $prev ) {
			$out[ $url ] = $prev;
			continue;
		}
		$body = 200 === $code ? wp_remote_retrieve_body( $response ) : '';
		// Un file senza `layer_id` (sul dev «Aree no caccia», 5,3 MB) non si decodifica: non ha aree utili, e decodificarlo
		// costa decine di MB di memoria
		$collection = 200 === $code ? ( false === strpos( $body, '"layer_id"' ) ? [ 'features' => [] ] : json_decode( $body, true ) ) : null;
		$body       = '';
		if ( ! is_array( $collection ) ) {
			error_log( 'WP Package: geojson dell\'overlay non disponibile da ' . $url . ' (' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code ) . '), restano le aree di prima' );
			$ok = false;
			// Restano le aree di prima, anche se MAP.layers è cambiato: i filtri mostrano solo quelle dei layer dell'app
			if ( is_array( $stored[ $url ] ?? null ) ) {
				$out[ $url ] = $stored[ $url ];
			}
			continue;
		}
		// Il file grande non resta in memoria: si tengono solo le aree con un layer_id di MAP.layers
		$areas       = array_values( array_filter( wmwp_app_overlay_areas_from( $collection ), fn( array $area ): bool => in_array( (int) $area['layer_id'], $allowed, true ) ) );
		$collection  = null;
		$changed     = $changed || ( $stored[ $url ]['areas'] ?? null ) !== $areas;
		$out[ $url ] = [
			'etag'          => (string) wp_remote_retrieve_header( $response, 'etag' ),
			'last_modified' => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
			'layers'        => $layers,
			'areas'         => $areas,
		];
	}
	$changed = $changed || array_keys( $stored ) !== array_keys( $out );
	update_option( WMWP_APP_OVERLAYS_OPTION, $out, false );
	update_option( WMWP_APP_OVERLAY_LAYERS_OPTION, wmwp_app_overlay_layer_ids( $out ), false );
	if ( $changed ) {
		wmwp_poi_layers_compute();
	}
	return $ok;
}

/**
 * Nomi dei layer del config.json per id.
 *
 * @return array<int, mixed>
 */
function wmwp_app_layer_names( array $config ): array {
	$names = [];
	foreach ( (array) ( $config['MAP']['layers'] ?? [] ) as $layer ) {
		if ( is_array( $layer ) && is_numeric( $layer['id'] ?? null ) ) {
			$names[ (int) $layer['id'] ] = $layer['name'] ?? $layer['title'] ?? [];
		}
	}
	return $names;
}

/**
 * Id delle aree di ogni overlay (indirizzo → `layer_id`, senza doppioni), dalla copia con le geometrie.
 *
 * @param array<string, mixed> $overlays Copia degli overlay (WMWP_APP_OVERLAYS_OPTION).
 * @return array<string, list<int>>
 */
function wmwp_app_overlay_layer_ids( array $overlays ): array {
	$ids = [];
	foreach ( $overlays as $url => $overlay ) {
		$ids[ (string) $url ] = array_values( array_unique( array_map( 'intval', array_column( (array) ( $overlay['areas'] ?? [] ), 'layer_id' ) ) ) );
	}
	return $ids;
}

/**
 * Overlay con almeno un'area il cui `layer_id` sta in `MAP.layers`, nell'ordine del config.json. `id` è la posizione
 * dell'overlay nell'elenco; le aree hanno il nome del layer.
 *
 * @return list<array{id: string, label: string, label_lang: ?string, areas: list<array{layer_id: int, label: string, label_lang: ?string}>}>
 */
function wmwp_app_overlays( string $lang ): array {
	$config = wmwp_app_config();
	if ( null === $config ) {
		return [];
	}
	// L'option piccola degli id; prima del primo giro del cron della 0.6.0 c'è solo la copia con le geometrie
	$ids = get_option( WMWP_APP_OVERLAY_LAYERS_OPTION, null );
	if ( ! is_array( $ids ) ) {
		$ids = wmwp_app_overlay_layer_ids( (array) get_option( WMWP_APP_OVERLAYS_OPTION, [] ) );
	}
	$names = wmwp_app_layer_names( $config );
	$list  = (array) ( $config['MAP']['controls']['overlays'] ?? [] );
	$out   = [];
	foreach ( wmwp_app_overlay_urls( $config ) as $index => $url ) {
		$areas = [];
		$seen  = [];
		foreach ( (array) ( $ids[ $url ] ?? [] ) as $layer ) {
			$layer = (int) $layer;
			if ( ! array_key_exists( $layer, $names ) || isset( $seen[ $layer ] ) ) {
				continue;
			}
			$seen[ $layer ] = true;
			$label          = wmwp_app_label( $names[ $layer ], $lang );
			$areas[]        = [
				'layer_id'   => $layer,
				'label'      => '' !== $label['text'] ? $label['text'] : (string) $layer,
				'label_lang' => $label['lang'],
			];
		}
		if ( ! $areas ) {
			continue;
		}
		$title = wmwp_app_label( $list[ $index ]['label'] ?? [], $lang );
		$out[] = [
			'id'         => (string) $index,
			'label'      => $title['text'],
			'label_lang' => $title['lang'],
			'areas'      => $areas,
		];
	}
	return $out;
}

/** Ricalcola l'appartenenza dei POI della copia alle aree degli overlay. */
function wmwp_poi_layers_compute(): void {
	$areas = [];
	foreach ( (array) get_option( WMWP_APP_OVERLAYS_OPTION, [] ) as $overlay ) {
		foreach ( (array) ( $overlay['areas'] ?? [] ) as $area ) {
			$areas[] = $area;
		}
	}
	$layers = [];
	foreach ( (array) get_option( WMWP_POI_SEARCH_OPTION, [] ) as $poi ) {
		$point = is_array( $poi ) ? ( $poi['point'] ?? null ) : null;
		if ( ! is_array( $point ) || ! isset( $point[0], $point[1] ) ) {
			continue;
		}
		$lon = (float) $point[0];
		$lat = (float) $point[1];
		$in  = [];
		foreach ( $areas as $area ) {
			$box = $area['bbox'];
			if ( $lon < $box[0] || $lat < $box[1] || $lon > $box[2] || $lat > $box[3] ) {
				continue;
			}
			if ( wmwp_point_in_geometry( $lon, $lat, $area['geometry'] ) ) {
				$in[] = (int) $area['layer_id'];
			}
		}
		if ( $in ) {
			$layers[ (int) $poi['id'] ] = array_values( array_unique( $in ) );
		}
	}
	update_option( WMWP_POI_LAYERS_OPTION, $layers, false );
}

/**
 * Aree di ogni POI: id del POI → `layer_id` delle aree in cui cade.
 *
 * @return array<int, list<int>>
 */
function wmwp_poi_layers(): array {
	return (array) get_option( WMWP_POI_LAYERS_OPTION, [] );
}

// Dopo il config.json (priorità 10), nello stesso giro del cron
add_action( WMWP_APP_CONFIG_CRON_HOOK, 'wmwp_app_overlays_refresh', 20 );
