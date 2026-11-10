<?php
/**
 * Ricerca unica (oc:8952): shortcode, registro delle source, parametri dell'indirizzo e template.
 *
 * Il PHP stampa solo lo scheletro della pagina e i `<template>` di card e righe: risultati e filtri li
 * disegna il JS (assets/js/search.js), che chiede i dati alle source registrate. Un primo disegno PHP dei
 * risultati raddoppierebbe il download della risposta dell'API, che il browser scarica comunque per contare
 * i filtri.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_SEARCH_SHORTCODE = 'wmwp_search';

/**
 * Query var pubbliche di WordPress (`s`, `p`, `page`, `paged`, `name`, `tag`, `year`…, più i tipi di post e le
 * tassonomie registrati): un parametro della ricerca con uno di questi nomi lo legge anche WordPress. Con `?page=2`
 * su una pagina il redirect canonico toglie il parametro e la ricerca torna alla pagina 1 (review interna, 10/11).
 *
 * @return string[]
 */
function wmwp_reserved_query_vars(): array {
	global $wp;
	return ( $wp instanceof WP ) ? (array) $wp->public_query_vars : [ 's', 'p', 'page', 'paged', 'name', 'tag', 'year' ];
}

/**
 * Nomi dei parametri dell'indirizzo: testo, tab, pagina e vista (`view` è il nome del parametro, `map` il valore
 * della vista mappa: «?view=map»). Il tema li cambia con il filter `wp_package_search_params`; i filtri usano la
 * `key` di ogni filtro. Un nome vuoto, già usato da un altro parametro o che è una query var di WordPress torna al
 * predefinito, con un avviso `_doing_it_wrong` (visibile con WP_DEBUG).
 *
 * @return array{q: string, tab: string, page: string, view: string, map: string}
 */
function wmwp_search_params(): array {
	$defaults = [
		'q'    => 'q',
		'tab'  => 'tab',
		'page' => 'pg',
		'view' => 'view',
		'map'  => 'map',
	];
	$params   = array_merge( $defaults, array_intersect_key( array_map( 'strval', (array) apply_filters( 'wp_package_search_params', $defaults ) ), $defaults ) );
	$reserved = wmwp_reserved_query_vars();
	$used     = [];
	foreach ( [ 'q', 'tab', 'page', 'view' ] as $key ) {
		$name = trim( $params[ $key ] );
		if ( '' === $name || in_array( $name, $reserved, true ) || in_array( $name, $used, true ) ) {
			_doing_it_wrong(
				'wp_package_search_params',
				/* translators: 1: parameter name chosen by the theme, 2: parameter key, 3: default name used instead */
				esc_html( sprintf( __( '«%1$s» cannot be the name of the «%2$s» parameter (empty, already used, or a WordPress query var): «%3$s» is used instead.', 'wp-package' ), $name, $key, $defaults[ $key ] ) ),
				'0.1.0'
			);
			$name = $defaults[ $key ];
		}
		$params[ $key ] = $name;
		$used[]         = $name;
	}
	return $params;
}

/**
 * Chiavi dei filtri di una source che non possono stare nell'indirizzo così come sono (query var di WordPress o nome
 * di un parametro della ricerca): prendono il prefisso `f_`, anche nel `meta_facet`, con un avviso `_doing_it_wrong`.
 */
function wmwp_safe_facet_keys( array $source ): array {
	$taken = array_merge( wmwp_reserved_query_vars(), array_values( array_intersect_key( wmwp_search_params(), array_flip( [ 'q', 'tab', 'page', 'view' ] ) ) ) );
	foreach ( (array) ( $source['facets'] ?? [] ) as $i => $facet ) {
		$key = isset( $facet['key'] ) ? (string) $facet['key'] : '';
		if ( '' === $key || ! in_array( $key, $taken, true ) ) {
			continue;
		}
		_doing_it_wrong(
			'wp_package_search_sources',
			/* translators: 1: filter key chosen by the theme, 2: key used instead */
			esc_html( sprintf( __( 'The filter key «%1$s» is a WordPress query var or a search parameter: «%2$s» is used instead.', 'wp-package' ), $key, 'f_' . $key ) ),
			'0.1.0'
		);
		$source['facets'][ $i ]['key'] = 'f_' . $key;
		if ( isset( $source['meta_facet'] ) && $key === $source['meta_facet'] ) {
			$source['meta_facet'] = 'f_' . $key;
		}
	}
	return $source;
}

/**
 * Source della ricerca, nell'ordine dei gruppi del tab «Tutti». Il plugin registra `tracks` (percorsi
 * dall'API Elasticsearch dello shard); il tema ne cambia le chiavi o ne aggiunge altre con il filter
 * `wp_package_search_sources`. Chiavi comuni: id, slug (valore del tab nell'indirizzo), label, nouns
 * (`one`/`other` con %s per il numero), preview, per_page, script (handle JS che registra la source),
 * layout (`cards` o `rows`), search_label, placeholder; più quelle proprie di ogni source.
 *
 * @return array<string, array<string, mixed>>
 */
function wmwp_search_sources(): array {
	$defaults = [
		'tracks' => [
			'label'           => __( 'Trails', 'wp-package' ),
			'nouns'           => [
				/* translators: %s: number of trails, singular */
				'one'   => __( '%s trail', 'wp-package' ),
				/* translators: %s: number of trails, plural */
				'other' => __( '%s trails', 'wp-package' ),
			],
			'script'          => 'wmwp-source-tracks',
			'layout'          => 'rows',
			'search_label'    => __( 'Search trails', 'wp-package' ),
			'endpoint'        => wmwp_elastic_url(),
			'app'             => wmwp_app_param(),
			'facets'          => [],
			'link_template'   => '',
			'badge_field'     => '',
			'meta_facet'      => '',
			// Selezione e scarico di più percorsi: URL di `api/ec/track/multiple` ('' = niente selezione), nome di
			// base del file, testo del numero di percorsi scelti e nome della barra per gli screen reader
			'download_url'    => wmwp_tracks_download_url(),
			'download_name'   => 'tracks',
			'selected'        => [
				/* translators: %s: number of selected trails, singular */
				'one'   => __( '%s trail selected', 'wp-package' ),
				/* translators: %s: number of selected trails, plural */
				'other' => __( '%s trails selected', 'wp-package' ),
			],
			'selection_label' => __( 'Selected trails', 'wp-package' ),
		],
	];
	$sources    = (array) apply_filters( 'wp_package_search_sources', $defaults );
	$normalized = [];
	foreach ( $sources as $id => $source ) {
		if ( ! is_array( $source ) ) {
			continue;
		}
		$id                = (string) ( $source['id'] ?? $id );
		$normalized[ $id ] = array_merge(
			[
				'id'           => $id,
				'slug'         => $id,
				'label'        => $id,
				'nouns'        => [
					'one'   => '%s',
					'other' => '%s',
				],
				'preview'      => 4,
				'per_page'     => 12,
				'script'       => '',
				'layout'       => 'cards',
				'search_label' => '',
				'placeholder'  => '',
				'facets'       => [],
			],
			$source,
			[ 'id' => $id ]
		);
		$normalized[ $id ] = wmwp_safe_facet_keys( $normalized[ $id ] );
	}
	return $normalized;
}

/**
 * Percorso di un template: prima `wp-package/<nome>` nel tema (child, poi padre), poi quello del plugin
 * (schema di WooCommerce).
 */
function wmwp_locate_template( string $name ): string {
	$theme = locate_template( 'wp-package/' . $name );
	return $theme ? $theme : WMWP_PATH . 'templates/' . $name;
}

/** Lingua della pagina in due lettere («it», «en»), per i nomi tradotti dell'API e il formato dei numeri. */
function wmwp_current_lang(): string {
	return substr( determine_locale(), 0, 2 );
}

/**
 * Versione di un file del plugin per l'indirizzo («?ver=»): la versione del plugin più la data di modifica del
 * file. Così un file cambiato non resta nella cache dei browser anche se la versione del plugin è la stessa.
 */
function wmwp_asset_version( string $file ): string {
	$time = filemtime( WMWP_PATH . $file );
	return WMWP_VERSION . ( $time ? '.' . $time : '' );
}

/** Registra e accoda gli script della ricerca, con la configurazione per il JS. */
function wmwp_enqueue_search_assets( array $sources, array $params ): void {
	$args = [ 'in_footer' => true ];
	wp_register_script( 'wmwp-search-logic', WMWP_URL . 'assets/js/search-logic.js', [], wmwp_asset_version( 'assets/js/search-logic.js' ), $args );
	wp_register_script( 'wmwp-search', WMWP_URL . 'assets/js/search.js', [ 'wmwp-search-logic', 'wp-i18n' ], wmwp_asset_version( 'assets/js/search.js' ), $args );
	wp_register_script( 'wmwp-track-export', WMWP_URL . 'assets/js/track-export.js', [], wmwp_asset_version( 'assets/js/track-export.js' ), $args );
	wp_register_script( 'wmwp-source-tracks', WMWP_URL . 'assets/js/source-tracks.js', [ 'wmwp-search', 'wmwp-track-export', 'wmwp-track-api', 'wmwp-track-map' ], wmwp_asset_version( 'assets/js/source-tracks.js' ), $args );

	$config = [
		'params'  => $params,
		'sources' => array_values( $sources ),
		'lang'    => wmwp_current_lang(),
		// La mappa pesa 800 KB: la pagina la carica solo al primo passaggio alla vista «Mappa». Carta di base e colori
		// dei layer sono quelli dell'app (wmwp_app_map_config)
		'map'     => wmwp_map_assets() + [
			'tiles'        => wmwp_map_tiles(),
			'layer_colors' => wmwp_map_layer_colors(),
		],
	];
	wp_set_script_translations( 'wmwp-search', 'wp-package', WMWP_PATH . 'languages' );
	wp_set_script_translations( 'wmwp-source-tracks', 'wp-package', WMWP_PATH . 'languages' );
	wp_add_inline_script( 'wmwp-search', 'window.wmWpSearchConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	wp_enqueue_script( 'wmwp-search' );
	foreach ( $sources as $source ) {
		if ( $source['script'] && wp_script_is( $source['script'], 'registered' ) ) {
			wp_enqueue_script( $source['script'] );
		}
	}
}

/** Shortcode [wmwp_search]: scheletro della pagina e template di card e righe. */
function wmwp_render_search(): string {
	$sources = wmwp_search_sources();
	$params  = wmwp_search_params();
	wmwp_enqueue_search_assets( $sources, $params );

	$wmwp = [
		'sources' => $sources,
		'params'  => $params,
	];
	ob_start();
	include wmwp_locate_template( 'search.php' );
	echo '<template id="wmwp-card">';
	include wmwp_locate_template( 'card.php' );
	echo '</template><template id="wmwp-row">';
	include wmwp_locate_template( 'row.php' );
	echo '</template>';
	return (string) ob_get_clean();
}
add_shortcode( WMWP_SEARCH_SHORTCODE, 'wmwp_render_search' );
