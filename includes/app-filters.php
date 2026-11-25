<?php
/**
 * Faccette della ricerca costruite dal config.json dell'app (oc:9018), come le legge la webapp: il tema dice quali
 * filtri vuole, il plugin ci mette valori, nomi e ordine dello shard.
 *
 * - `{ key, from_config: 'activities'|'layers'|'poi_types' }`: opzioni di `MAP.filters`, nell'ordine dell'array, solo
 *   quelle pubblicate (`include`), con il nome nella lingua della pagina (`labels`) e la lingua del nome quando è
 *   un'altra (`lang_labels`). I tipi dei POI perdono il prefisso `poi_type_`, come fa wm-core
 *   (`withPoiTypeIdentifiers`): nei POI l'identifier non lo ha. La chiave `poi_type`, copia di `poi_types` per le app
 *   vecchie, non si usa.
 * - `{ key_prefix?, from_families: '-difficulty', exclude?, order? }`: una faccetta per famiglia di `LABELS.taxonomies`
 *   con quel suffisso, con il nome della famiglia; `order` è l'ordine di ripiego del tema per famiglia, finché lo
 *   shard non pubblica il suo.
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Chiave di MAP.filters → campo dei risultati che filtra (come nelle app). */
const WMWP_APP_FILTER_FIELDS = [
	'activities' => 'taxonomyActivities',
	'layers'     => 'layers',
	'poi_types'  => 'poi_type',
];

/** Prefisso degli identifier dei tipi dei POI nel config.json (non nei POI). */
const WMWP_APP_POI_TYPE_PREFIX = 'poi_type_';
/**
 * Parametro dell'indirizzo con le aree scelte di tutte le faccette degli overlay (`?layer=3,50`), come le app. Fisso: lo
 * legge anche la rotta dei POI (review interna di oc:9018).
 */
const WMWP_APP_AREA_PARAM = 'layer';

/**
 * Nome nella lingua della pagina, poi in italiano, poi il primo non vuoto (come `wmtrans` di wm-core); `lang` è la
 * lingua del nome solo se diversa da quella della pagina.
 *
 * @param mixed $names Nomi per lingua.
 * @return array{text: string, lang: ?string}
 */
function wmwp_app_label( $names, string $lang ): array {
	$text  = wmwp_text( is_array( $names ) ? $names : [], $lang );
	$short = strtolower( substr( $lang, 0, 2 ) );
	$from  = null !== $text['lang'] ? strtolower( substr( (string) $text['lang'], 0, 2 ) ) : null;
	return [
		'text' => trim( (string) $text['text'] ),
		'lang' => null !== $from && $from !== $short ? $from : null,
	];
}

/**
 * Faccetta slider da un filtro `type: slider` di `MAP.filters` (oc:9003), come la disegna wm-core: chiave del filtro
 * (es. `track_distance`, anche nome del parametro dell'indirizzo), campo dell'indice (`identifier`), limiti, passo
 * (`steps`) e unità. Null se mancano limiti o passo numerici, o se l'intervallo è vuoto: il config.json può avere un
 * filtro acceso in Nova senza valori.
 *
 * @param array<string, mixed> $filter Filtro di `MAP.filters`.
 * @param array<string, mixed> $facet  Faccetta del tema (`from_config`, eventuale `label`).
 * @return array<string, mixed>|null
 */
function wmwp_app_slider_facet( string $name, array $filter, array $facet, string $lang ): ?array {
	$field = trim( (string) ( $filter['identifier'] ?? '' ) );
	$min   = $filter['min'] ?? null;
	$max   = $filter['max'] ?? null;
	$step  = $filter['steps'] ?? null;
	if ( '' === $field || ! is_numeric( $min ) || ! is_numeric( $max ) || ! is_numeric( $step ) || (float) $step <= 0 || (float) $max <= (float) $min ) {
		return null;
	}
	$title = wmwp_app_label( $filter['name'] ?? [], $lang );
	unset( $facet['from_config'] );
	if ( isset( $facet['label'] ) && ! array_key_exists( 'label_lang', $facet ) ) {
		$facet['label_lang'] = null;
	}
	return array_merge(
		[
			'key'        => $name,
			'label'      => '' !== $title['text'] ? $title['text'] : $name,
			'label_lang' => $title['lang'],
		],
		$facet,
		[
			'type'  => 'slider',
			'field' => $field,
			'min'   => (float) $min,
			'max'   => (float) $max,
			'step'  => (float) $step,
			'units' => (string) ( $filter['units'] ?? '' ),
		]
	);
}

/**
 * Faccetta del tema con `from_config` completata da `MAP.filters`; null se il config.json non c'è o non ha quel filtro.
 *
 * @param array<string, mixed> $facet Faccetta del tema.
 * @return array<string, mixed>|null
 */
function wmwp_app_filter_facet( array $facet, string $lang ): ?array {
	$name   = (string) ( $facet['from_config'] ?? '' );
	$config = wmwp_app_config();
	$filter = $config['MAP']['filters'][ $name ] ?? null;
	if ( is_array( $filter ) && 'slider' === ( $filter['type'] ?? '' ) ) {
		return wmwp_app_slider_facet( $name, $filter, $facet, $lang );
	}
	if ( ! isset( WMWP_APP_FILTER_FIELDS[ $name ] ) || ! is_array( $filter ) || 'select' !== ( $filter['type'] ?? '' ) ) {
		return null;
	}
	$order       = [];
	$labels      = [];
	$lang_labels = [];
	foreach ( (array) ( $filter['options'] ?? [] ) as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}
		$id = trim( (string) ( $option['identifier'] ?? $option['id'] ?? '' ) );
		if ( 'poi_types' === $name && 0 === strpos( $id, WMWP_APP_POI_TYPE_PREFIX ) ) {
			$id = substr( $id, strlen( WMWP_APP_POI_TYPE_PREFIX ) );
		}
		if ( '' === $id || in_array( $id, $order, true ) ) {
			continue;
		}
		$order[] = $id;
		$label   = wmwp_app_label( $option['name'] ?? [], $lang );
		if ( '' !== $label['text'] ) {
			$labels[ $id ] = $label['text'];
			if ( null !== $label['lang'] ) {
				$lang_labels[ $id ] = $label['lang'];
			}
		}
	}
	$title = wmwp_app_label( $filter['name'] ?? [], $lang );
	unset( $facet['from_config'] );
	// Un nome del tema sostituisce quello del config.json e la sua lingua: è nella lingua della pagina, se il tema non
	// dice altro (`label_lang`)
	if ( isset( $facet['label'] ) && ! array_key_exists( 'label_lang', $facet ) ) {
		$facet['label_lang'] = null;
	}
	return array_merge(
		[
			'type'       => 'list',
			'field'      => WMWP_APP_FILTER_FIELDS[ $name ],
			'label'      => '' !== $title['text'] ? $title['text'] : (string) ( $facet['key'] ?? $name ),
			'label_lang' => $title['lang'],
		],
		$facet,
		[
			'include'     => $order,
			'order'       => $order,
			'labels'      => $labels,
			'lang_labels' => $lang_labels,
		]
	);
}

/**
 * Una faccetta per famiglia di `LABELS.taxonomies` con il suffisso `from_families` (esclusa `exclude`), nell'ordine del
 * config.json. Valore = identifier intero del termine: famiglie diverse non si mescolano.
 *
 * @param array<string, mixed> $facet Faccetta del tema.
 * @return list<array<string, mixed>>
 */
function wmwp_app_family_facets( array $facet, string $lang ): array {
	$suffix   = (string) ( $facet['from_families'] ?? '' );
	$config   = wmwp_app_config();
	$families = $config['LABELS']['taxonomies'] ?? null;
	if ( '' === $suffix || ! is_array( $families ) ) {
		return [];
	}
	$exclude = array_map( 'strval', (array) ( $facet['exclude'] ?? [] ) );
	$orders  = (array) ( $facet['order'] ?? [] );
	$prefix  = (string) ( $facet['key_prefix'] ?? '' );
	$out     = [];
	foreach ( $families as $family => $info ) {
		$family = (string) $family;
		if ( substr( $family, -strlen( $suffix ) ) !== $suffix || in_array( $family, $exclude, true ) ) {
			continue;
		}
		$title = wmwp_app_label( is_array( $info ) ? ( $info['labels'] ?? $info['name'] ?? [] ) : [], $lang );
		$out[] = [
			'key'        => $prefix . $family,
			'label'      => '' !== $title['text'] ? $title['text'] : $family,
			'label_lang' => $title['lang'],
			'type'       => 'terms',
			'field'      => 'terms.' . $family,
			'order'      => array_values( array_map( 'strval', (array) ( $orders[ $family ] ?? [] ) ) ),
		];
	}
	return $out;
}

/**
 * Una faccetta per overlay del config.json con aree (wmwp_app_overlays()): campo `layers`, scelte nel parametro
 * condiviso WMWP_APP_AREA_PARAM (`layer`, come le app), `ids` le aree dell'overlay. Chiave `<key_prefix><posizione>`.
 *
 * @param array<string, mixed> $facet Faccetta del tema (`from_overlays`, `key_prefix`).
 * @return list<array<string, mixed>>
 */
function wmwp_app_overlay_facets( array $facet, string $lang ): array {
	$prefix = (string) ( $facet['key_prefix'] ?? 'area-' );
	$out    = [];
	foreach ( wmwp_app_overlays( $lang ) as $overlay ) {
		$ids         = [];
		$labels      = [];
		$lang_labels = [];
		foreach ( $overlay['areas'] as $area ) {
			$id            = (string) $area['layer_id'];
			$ids[]         = $id;
			$labels[ $id ] = $area['label'];
			if ( null !== $area['label_lang'] ) {
				$lang_labels[ $id ] = $area['label_lang'];
			}
		}
		$out[] = [
			'key'         => $prefix . $overlay['id'],
			'label'       => $overlay['label'],
			'label_lang'  => $overlay['label_lang'],
			'type'        => 'list',
			'field'       => 'layers',
			'param'       => WMWP_APP_AREA_PARAM,
			'ids'         => $ids,
			'include'     => $ids,
			'order'       => $ids,
			'labels'      => $labels,
			'lang_labels' => $lang_labels,
		];
	}
	return $out;
}

/**
 * Faccette di una source con quelle del tema completate dal config.json: `from_config`, `from_families` e
 * `from_overlays` al loro posto, le altre com'erano. Una `from_config` senza dati sparisce.
 *
 * @param list<array<string, mixed>> $facets Faccette del tema.
 * @return list<array<string, mixed>>
 */
function wmwp_app_expand_facets( array $facets, string $lang ): array {
	$out = [];
	foreach ( $facets as $facet ) {
		if ( ! is_array( $facet ) ) {
			continue;
		}
		if ( isset( $facet['from_config'] ) ) {
			$built = wmwp_app_filter_facet( $facet, $lang );
			if ( null !== $built ) {
				$out[] = $built;
			}
			continue;
		}
		if ( ! empty( $facet['from_overlays'] ) ) {
			foreach ( wmwp_app_overlay_facets( $facet, $lang ) as $built ) {
				$out[] = $built;
			}
			continue;
		}
		if ( isset( $facet['from_families'] ) ) {
			foreach ( wmwp_app_family_facets( $facet, $lang ) as $built ) {
				$out[] = $built;
			}
			continue;
		}
		$out[] = $facet;
	}
	return $out;
}
