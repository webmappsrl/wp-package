<?php
/**
 * Pagine di dettaglio (oc:8969): SEO con il solo core di WordPress, su qualsiasi tema.
 *
 * Il plugin stampa titolo, description, anteprima per i social (Open Graph) e canonical del dettaglio. Dove il tema o
 * un plugin SEO stampa già i suoi meta, sulle pagine di dettaglio passa a loro i dati (Impreza) o toglie i loro
 * (Yoast, Rank Math): un adattatore per ciascuno, attivo solo se c'è. Il filtro `wmwp_detail_print_meta` spegne la
 * stampa del plugin, per chi preferisce farla da sé con i dati di `wmwp_detail_meta`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Lunghezza massima della meta description, in caratteri (la soglia oltre cui i motori la tagliano)
const WMWP_DETAIL_DESCRIPTION_LENGTH = 160;

/**
 * Meta di un dettaglio.
 *
 * @param array<string, mixed> $detail wmwp_detail_current().
 * @return array{title: string, description: string, image: string, url: string, locale: string}
 */
function wmwp_detail_meta( array $detail ): array {
	$props = (array) ( $detail['feature']['properties'] ?? [] );
	$text  = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( wmwp_detail_text( $props['description'] ?? null, (string) $detail['lang'] ) ) ) );
	if ( mb_strlen( $text ) > WMWP_DETAIL_DESCRIPTION_LENGTH ) {
		$cut  = mb_substr( $text, 0, WMWP_DETAIL_DESCRIPTION_LENGTH );
		$text = rtrim( mb_substr( $cut, 0, (int) mb_strrpos( $cut, ' ' ) ), " ,.;:" ) . '…';
	}
	$image = $props['feature_image']['url'] ?? '';

	$meta = [
		'title'       => (string) $detail['name'],
		'description' => $text,
		'image'       => is_string( $image ) ? $image : '',
		'url'         => (string) $detail['url'],
		'locale'      => (string) get_locale(),
	];
	/**
	 * Meta del dettaglio: titolo, description, immagine, URL, locale.
	 *
	 * @param array<string, string> $meta
	 * @param array<string, mixed>  $detail
	 */
	return (array) apply_filters( 'wmwp_detail_meta', $meta, $detail );
}

/** Vero se il plugin deve stampare i meta da sé: filtro `wmwp_detail_print_meta`, e niente Impreza a farlo. */
function wmwp_detail_prints_meta(): bool {
	return (bool) apply_filters( 'wmwp_detail_print_meta', ! function_exists( 'us_output_meta_tags' ) );
}

// Titolo del documento: il nome del dettaglio, con il nome del sito come per le pagine
add_filter(
	'pre_get_document_title',
	function ( $title ) {
		$detail = wmwp_detail_current();
		if ( ! $detail ) {
			return $title;
		}
		return wmwp_detail_meta( $detail )['title'] . ' – ' . get_bloginfo( 'name', 'display' );
	},
	20
);

// Canonical e meta stampati dal plugin; quella del core vale solo per i post
add_action(
	'wp_head',
	function () {
		$detail = wmwp_detail_current();
		if ( ! $detail ) {
			return;
		}
		remove_action( 'wp_head', 'rel_canonical' );
		if ( ! wmwp_detail_prints_meta() ) {
			return;
		}
		$meta = wmwp_detail_meta( $detail );
		if ( '' !== $meta['url'] ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $meta['url'] ) );
		}
		$tags = [
			'description'    => $meta['description'],
			'og:type'        => 'website',
			'og:title'       => $meta['title'],
			'og:description' => $meta['description'],
			'og:url'         => $meta['url'],
			'og:image'       => $meta['image'],
			'og:locale'      => $meta['locale'],
			'og:site_name'   => get_bloginfo( 'name', 'display' ),
		];
		foreach ( $tags as $name => $content ) {
			if ( '' === (string) $content ) {
				continue;
			}
			$attribute = 0 === strpos( $name, 'og:' ) ? 'property' : 'name';
			printf( '<meta %s="%s" content="%s">' . "\n", $attribute, esc_attr( $name ), esc_attr( (string) $content ) );
		}
	},
	1
);

// Adattatore Impreza: i suoi meta e la sua canonical, con i dati del dettaglio al posto di quelli della pagina madre
add_filter(
	'us_meta_tags',
	function ( $tags ) {
		$detail = wmwp_detail_current();
		if ( ! $detail || ! is_array( $tags ) ) {
			return $tags;
		}
		$meta = wmwp_detail_meta( $detail );
		foreach ( [ 'description', 'og:description' ] as $key ) {
			unset( $tags[ $key ] );
			if ( '' !== $meta['description'] ) {
				$tags[ $key ] = $meta['description'];
			}
		}
		$tags['og:title'] = $meta['title'];
		$tags['og:url']   = $meta['url'];
		$tags['og:type']  = 'website';
		unset( $tags['og:image'] );
		if ( '' !== $meta['image'] ) {
			$tags['og:image'] = $meta['image'];
		}
		return $tags;
	}
);
add_filter(
	'us_rel_canonical',
	function ( $html ) {
		$detail = wmwp_detail_current();
		if ( ! $detail || '' === (string) $detail['url'] ) {
			return $html;
		}
		return '<link rel="canonical" href="' . esc_url( (string) $detail['url'] ) . '" />' . "\n";
	}
);

// Adattatori Yoast e Rank Math: sulle pagine di dettaglio i loro meta parlerebbero della pagina madre
add_action(
	'template_redirect',
	function () {
		if ( ! wmwp_detail_current() ) {
			return;
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			add_filter( 'wpseo_frontend_presenters', '__return_empty_array' );
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			remove_all_actions( 'rank_math/head' );
		}
	},
	3
);
