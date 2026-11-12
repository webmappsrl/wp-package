<?php
/**
 * Pagine di dettaglio (oc:8969): lettura del JSON, controllo dell'elemento e template.
 *
 * Il JSON si legge dallo shard a ogni richiesta (`api/ec/track/<id>`, `api/ec/poi/<id>`): nessuna cache, i dati sono
 * sempre quelli del backend. Prima di stampare si controlla che l'id porti ancora all'elemento chiesto (dopo un reset
 * dello shard gli id cambiano): per le tracce lo slug del nome, per i POI, il cui dettaglio non ha il nome, l'id della
 * fonte (`out_source_feature_id`). Il template si cerca prima nel tema (`wp-package/<tipo>.php`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON del dettaglio dallo shard (una Feature), o WP_Error se lo shard non risponde.
 *
 * @return array<string, mixed>|WP_Error
 */
function wmwp_detail_fetch( string $type, int $id, bool $fresh = false ) {
	$urls   = wmwp_configured_api_urls();
	$origin = $urls['origin'];
	if ( '' === $origin || ! in_array( $type, WMWP_DETAIL_TYPES, true ) ) {
		return new WP_Error( 'wmwp_detail_config', 'Shard non configurato' );
	}
	/**
	 * Durata in secondi della cache breve del JSON (predefinita 60): per questo tempo il dettaglio non si richiede, e
	 * le visite e i crawler sulla stessa pagina non chiamano niente. 0 spegne la cache.
	 *
	 * @param int $ttl
	 */
	$ttl    = (int) apply_filters( 'wmwp_detail_cache_ttl', MINUTE_IN_SECONDS );
	$key    = 'wmwp_detail_json_' . $type . '_' . $id;
	$cached = $ttl > 0 ? get_transient( $key ) : false;
	$cached = is_array( $cached ) && is_array( $cached['feature'] ?? null ) ? $cached : null;
	if ( null !== $cached && ! $fresh && time() - (int) ( $cached['checked'] ?? 0 ) < $ttl ) {
		return $cached['feature'];
	}

	// La traccia dal file che lo shard pubblica su AWS, lo stesso che restituisce l'API; il POI un file non lo ha
	$entry = null;
	if ( 'track' === $type && '' !== $urls['aws_api'] ) {
		$entry = wmwp_detail_fetch_file( $urls['aws_api'] . '/tracks/' . $id . '.json', $cached );
	}
	if ( null === $entry ) {
		$feature = wmwp_detail_get_json( $origin . '/api/ec/' . $type . '/' . $id, 5 );
		$entry   = wmwp_detail_is_feature( $feature ) ? [ 'feature' => $feature ] : null;
	}
	if ( null === $entry ) {
		return new WP_Error( 'wmwp_detail_unavailable', 'Dettaglio non disponibile' );
	}
	if ( $ttl > 0 ) {
		// Il JSON resta un giorno: dopo la cache breve si richiede con ETag/Last-Modified, e con il 304 si riusa
		$entry['checked'] = time();
		set_transient( $key, $entry, DAY_IN_SECONDS );
	}
	return $entry['feature'];
}

/**
 * Feature GeoJSON con le proprietà, la forma minima di un dettaglio.
 *
 * @param mixed $feature
 */
function wmwp_detail_is_feature( $feature ): bool {
	return is_array( $feature ) && isset( $feature['properties'] ) && is_array( $feature['properties'] );
}

/**
 * File del dettaglio pubblicato dallo shard all'indirizzo `awsApi` di `environment.ts` (S3 o MinIO, per il plugin è lo
 * stesso), con la richiesta condizionale quando
 * c'è già una copia: `If-None-Match` con l'ETag, `If-Modified-Since` con la data. Con il 304 torna la copia; null se il
 * file non c'è o non risponde, e allora si legge l'API dello shard.
 *
 * @param array<string, mixed>|null $cached Copia in cache: `feature`, `etag`, `modified`.
 * @return array{feature: array<string, mixed>, etag: string, modified: string}|null
 */
function wmwp_detail_fetch_file( string $url, ?array $cached ): ?array {
	$headers = [];
	if ( null !== $cached && '' !== (string) ( $cached['etag'] ?? '' ) ) {
		$headers['If-None-Match'] = (string) $cached['etag'];
	} elseif ( null !== $cached && '' !== (string) ( $cached['modified'] ?? '' ) ) {
		$headers['If-Modified-Since'] = (string) $cached['modified'];
	}
	$response = wp_remote_get(
		$url,
		[
			'timeout' => 5,
			'headers' => $headers,
		]
	);
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 304 === $code && null !== $cached && $headers ) {
		return [
			'feature'  => $cached['feature'],
			'etag'     => (string) ( $cached['etag'] ?? '' ),
			'modified' => (string) ( $cached['modified'] ?? '' ),
		];
	}
	$feature = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
	if ( ! wmwp_detail_is_feature( $feature ) ) {
		return null;
	}
	return [
		'feature'  => $feature,
		'etag'     => (string) wp_remote_retrieve_header( $response, 'etag' ),
		'modified' => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
	];
}

/**
 * L'id porta ancora all'elemento chiesto? Con il nome nel JSON, lo slug richiesto deve essere quello del nome (con o
 * senza `-<id>` dei doppioni); senza nome, l'id della fonte deve essere quello dell'indice.
 *
 * @param array<string, mixed>      $resolved Risultato di wmwp_detail_resolve() con lang e path.
 * @param array<string, mixed>      $feature  JSON del dettaglio.
 * @param array<string, mixed>|null $item     Elemento dell'indice (`items`).
 */
function wmwp_detail_matches( array $resolved, array $feature, ?array $item ): bool {
	$props     = $feature['properties'];
	$requested = substr( (string) $resolved['path'], (int) strrpos( (string) $resolved['path'], '/' ) + 1 );
	$name      = wmwp_detail_text( $props['name'] ?? null, (string) $resolved['lang'] );
	if ( '' !== $name ) {
		$expected = wmwp_detail_slug( $name, (int) $resolved['id'] );
		// Con -<id> dei doppioni, e un numero in più se anche quello era preso
		return $requested === $expected || $requested === $expected . '-' . (int) $resolved['id'] || 0 === strpos( $requested, $expected . '-' . (int) $resolved['id'] . '-' );
	}
	$source = isset( $props['out_source_feature_id'] ) ? (string) $props['out_source_feature_id'] : '';
	$known  = (string) ( $item['source'] ?? '' );
	return '' === $source || '' === $known || $source === $known;
}

/**
 * Dettaglio di un elemento risolto: il JSON e quello che serve al template. Null se l'id non porta più
 * all'elemento chiesto (anche dopo una ricostruzione dell'indice): la pagina risponde 404, mai con un altro elemento.
 *
 * @param array<string, mixed> $resolved Risultato di wmwp_detail_resolve() con status 200, lang e path.
 * @return array<string, mixed>|WP_Error|null
 */
function wmwp_detail_load( array $resolved ) {
	$feature = wmwp_detail_fetch( (string) $resolved['type'], (int) $resolved['id'] );
	if ( is_wp_error( $feature ) ) {
		return $feature;
	}
	$index = wmwp_detail_index();
	$item  = $index['items'][ $resolved['type'] ][ (int) $resolved['id'] ] ?? null;

	if ( ! wmwp_detail_matches( $resolved, $feature, $item ) ) {
		// L'indice è vecchio (reset dello shard, rinomina): una ricostruzione e un nuovo tentativo, una volta sola
		// Prima il JSON fresco: in cache può esserci la versione di prima di una rinomina
		$fresh = wmwp_detail_fetch( (string) $resolved['type'], (int) $resolved['id'], true );
		if ( ! is_wp_error( $fresh ) && wmwp_detail_matches( $resolved, $fresh, $item ) ) {
			$feature = $fresh;
		} else {
			if ( ! wmwp_detail_rebuild_allowed() || null === wmwp_detail_index( true ) ) {
				return null;
			}
			$again = wmwp_detail_resolve( (string) $resolved['lang'], (string) $resolved['path'] );
			if ( 200 !== $again['status'] ) {
				return null;
			}
			$again += [
				'lang' => $resolved['lang'],
				'path' => $resolved['path'],
			];
			$feature = wmwp_detail_fetch( (string) $again['type'], (int) $again['id'], true );
			if ( is_wp_error( $feature ) ) {
				return $feature;
			}
			$index    = wmwp_detail_index();
			$item     = $index['items'][ $again['type'] ][ (int) $again['id'] ] ?? null;
			$resolved = $again;
			if ( ! wmwp_detail_matches( $resolved, $feature, $item ) ) {
				return null;
			}
		}
	}

	$lang = (string) $resolved['lang'];
	$name = wmwp_detail_text( $feature['properties']['name'] ?? null, $lang );
	if ( '' === $name ) {
		$name = wmwp_detail_text( $item['names'] ?? null, $lang );
	}
	$urls = [];
	foreach ( (array) ( $index['langs'] ?? [] ) as $code ) {
		$url = wmwp_detail_url( (string) $resolved['type'], (int) $resolved['id'], (string) $code );
		if ( '' !== $url ) {
			$urls[ $code ] = $url;
		}
	}

	return [
		'type'    => (string) $resolved['type'],
		'id'      => (int) $resolved['id'],
		'lang'    => $lang,
		'path'    => (string) $resolved['path'],
		'name'    => $name,
		'url'     => $urls[ $lang ] ?? '',
		'urls'    => $urls,
		'feature' => $feature,
	];
}

/**
 * Dettaglio della richiesta in corso, null se non è una pagina di dettaglio. È la variabile `$detail` dei template.
 *
 * @return array<string, mixed>|null
 */
function wmwp_detail_current(): ?array {
	return isset( $GLOBALS['wmwp_detail'] ) && is_array( $GLOBALS['wmwp_detail'] ) ? $GLOBALS['wmwp_detail'] : null;
}

/** Vero se la richiesta è un dettaglio che lo shard non ha potuto dare (pagina 503). */
function wmwp_detail_unavailable(): bool {
	return ! empty( $GLOBALS['wmwp_detail_unavailable'] );
}

// Dopo la risoluzione (priorità 1): si legge il JSON e si decide 200, 404 o 503
add_action(
	'template_redirect',
	function () {
		global $wp_query;
		$resolved = $GLOBALS['wmwp_detail_resolved'] ?? null;
		if ( is_array( $resolved ) && 404 === (int) $resolved['status'] ) {
			// Già 404 per la rotta: niente da leggere
			return;
		}
		if ( is_array( $resolved ) && 200 !== (int) $resolved['status'] ) {
			$resolved = null;
		} elseif ( ! is_array( $resolved ) ) {
			if ( isset( $GLOBALS['wmwp_detail_resolved'] ) || '' === (string) get_query_var( 'wmwp_detail_path' ) ) {
				return;
			}
			// Nessun indice (shard mai raggiunto): la pagina non è disponibile, non inesistente
			$resolved = null;
		}
		$detail = null === $resolved ? new WP_Error( 'wmwp_detail_unavailable', '' ) : wmwp_detail_load( $resolved );
		if ( is_wp_error( $detail ) ) {
			$GLOBALS['wmwp_detail_unavailable'] = true;
			$wp_query->is_404                    = false;
			status_header( 503 );
			header( 'Retry-After: 120' );
			nocache_headers();
			return;
		}
		if ( null === $detail ) {
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		$GLOBALS['wmwp_detail'] = $detail;
		$wp_query->is_404       = false;
		$wp_query->is_home      = false;
		status_header( 200 );
	},
	2
);

add_filter(
	'pre_handle_404',
	function ( $handled ) {
		return ( wmwp_detail_current() || wmwp_detail_unavailable() ) ? true : $handled;
	}
);

add_filter(
	'template_include',
	function ( string $template ): string {
		$detail = wmwp_detail_current();
		if ( $detail ) {
			return wmwp_locate_template( $detail['type'] . '.php' );
		}
		return wmwp_detail_unavailable() ? wmwp_locate_template( 'detail-unavailable.php' ) : $template;
	},
	99
);

add_filter(
	'body_class',
	function ( array $classes ): array {
		$detail = wmwp_detail_current();
		if ( $detail ) {
			$classes[] = 'wmwp-detail';
			$classes[] = 'wmwp-detail-' . $detail['type'];
		}
		return $classes;
	}
);

/**
 * Apertura della pagina di un template del plugin, su qualsiasi tema: `get_header()` per i temi classici; per i temi
 * a blocchi, che non hanno `header.php`, il documento e la parte `header` del tema.
 */
function wmwp_template_header(): void {
	if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
		get_header();
		return;
	}
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php
	block_template_part( 'header' );
}

/** Chiusura della pagina, speculare a wmwp_template_header(). */
function wmwp_template_footer(): void {
	if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
		get_footer();
		return;
	}
	block_template_part( 'footer' );
	?>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
}
