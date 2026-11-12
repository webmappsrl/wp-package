<?php
/**
 * Pagine di dettaglio (oc:8969): lingue e sitemap.
 *
 * WPML stampa gli `hreflang` solo per i post (vuole un oggetto della query): sui dettagli li stampa il plugin, con
 * l'indirizzo dell'elemento in ogni lingua. Il selettore di lingua (`icl_ls_languages`) porta allo stesso elemento
 * nell'altra lingua, non alla pagina madre. La sitemap del core elenca tutti i dettagli, in ogni lingua, dall'indice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * hreflang di un dettaglio: tag della lingua (es. `en-US`) → URL, più `x-default` sulla lingua predefinita. Vuoto con
 * una lingua sola.
 *
 * @param array<string, mixed> $detail Almeno `urls` (codice WPML → URL).
 * @return array<string, string>
 */
function wmwp_detail_hreflangs( array $detail ): array {
	$urls = array_filter( (array) ( $detail['urls'] ?? [] ) );
	if ( count( $urls ) < 2 ) {
		return [];
	}
	$active = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
	$links  = [];
	foreach ( $urls as $code => $url ) {
		$tag                 = is_array( $active ) && ! empty( $active[ $code ]['tag'] ) ? (string) $active[ $code ]['tag'] : (string) $code;
		$links[ $tag ] = (string) $url;
	}
	$default = (string) apply_filters( 'wpml_default_language', array_key_first( $urls ) );
	if ( isset( $urls[ $default ] ) ) {
		$links['x-default'] = (string) $urls[ $default ];
	}
	return $links;
}

add_action(
	'wp_head',
	function () {
		$detail = wmwp_detail_current();
		if ( ! $detail ) {
			return;
		}
		foreach ( wmwp_detail_hreflangs( $detail ) as $tag => $url ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s">' . "\n", esc_attr( $tag ), esc_url( $url ) );
		}
	},
	2
);

/**
 * Indirizzi per lingua del dettaglio della richiesta. WPML calcola il selettore di lingua anche prima che il plugin
 * carichi il dettaglio (e la testata usa quel calcolo): in quel caso bastano il percorso della richiesta e l'indice,
 * senza leggere il JSON.
 *
 * @return array<string, string>
 */
function wmwp_detail_request_urls(): array {
	$detail = wmwp_detail_current();
	if ( $detail ) {
		return (array) $detail['urls'];
	}
	$path = (string) get_query_var( 'wmwp_detail_path' );
	$item = '' !== $path ? wmwp_detail_find_any( trim( strtolower( $path ), '/' ) ) : null;
	if ( null === $item ) {
		return [];
	}
	$urls = [];
	foreach ( wmwp_detail_languages() as $code ) {
		$url = wmwp_detail_url( $item[0], (int) $item[1], $code );
		if ( '' !== $url ) {
			$urls[ $code ] = $url;
		}
	}
	return $urls;
}

/**
 * Elemento di un percorso in una qualsiasi lingua dell'indice, senza ricostruirlo; null se non c'è.
 *
 * @return array{0: string, 1: int}|null
 */
function wmwp_detail_find_any( string $path ): ?array {
	$index = wmwp_detail_index();
	foreach ( (array) ( $index['paths'] ?? [] ) as $paths ) {
		if ( isset( $paths[ $path ] ) ) {
			return $paths[ $path ];
		}
	}
	return null;
}

// Selettore di lingua di WPML: lo stesso dettaglio nell'altra lingua
add_filter(
	'icl_ls_languages',
	function ( $languages ) {
		// wmwp_detail_url() passa da wpml_permalink, che può richiamare il selettore: niente rientro
		static $busy = false;
		if ( ! is_array( $languages ) || $busy ) {
			return $languages;
		}
		$busy = true;
		$urls = wmwp_detail_request_urls();
		$busy = false;
		foreach ( $languages as $code => $language ) {
			if ( ! empty( $urls[ $code ] ) ) {
				$languages[ $code ]['url'] = $urls[ $code ];
			}
		}
		return $languages;
	}
);

add_action(
	'init',
	function () {
		if ( ! class_exists( 'WP_Sitemaps_Provider' ) || ! function_exists( 'wp_register_sitemap_provider' ) ) {
			return;
		}
		require_once WMWP_PATH . 'includes/class-wmwp-detail-sitemap-provider.php';
		wp_register_sitemap_provider( WMWP_Detail_Sitemap_Provider::NAME, new WMWP_Detail_Sitemap_Provider() );
	}
);
