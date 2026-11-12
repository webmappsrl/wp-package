<?php
/**
 * Pagine di dettaglio (oc:8969): la base degli indirizzi.
 *
 * Per tracce e POI, separatamente, la base viene dal tipo (il nome del termine `route-type` o `poi-type` nella lingua
 * della pagina, con ripiego `track`/`poi`, come faceva wp-geohub con `/track/`) oppure da una pagina di WordPress
 * scelta nelle impostazioni (es. i POI sotto `/punti-di-interesse/`). Un tema può imporre le opzioni con
 * `pre_option_<nome>`, senza salvare id di pagine nel database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_DETAIL_TYPES = [ 'track', 'poi' ];

/** Nome dell'opzione dell'interruttore «Indirizzo sotto una pagina» per un tipo (`track` o `poi`). */
function wmwp_detail_use_page_option( string $type ): string {
	return 'wmwp_detail_' . ( 'poi' === $type ? 'pois' : 'tracks' ) . '_use_page';
}

/** Nome dell'opzione con l'id della pagina scelta per un tipo. */
function wmwp_detail_page_option( string $type ): string {
	return 'wmwp_detail_' . ( 'poi' === $type ? 'pois' : 'tracks' ) . '_page';
}

/** Pagina pubblicata scelta come base per un tipo, 0 se l'interruttore è spento o la pagina non c'è. */
function wmwp_detail_base_page( string $type ): int {
	if ( ! (bool) get_option( wmwp_detail_use_page_option( $type ), false ) ) {
		return 0;
	}
	$page = (int) get_option( wmwp_detail_page_option( $type ), 0 );
	return ( $page > 0 && 'publish' === get_post_status( $page ) ) ? $page : 0;
}

/**
 * Base dell'indirizzo di un elemento in una lingua.
 *
 * @param string                     $type `track` o `poi`.
 * @param array<string, mixed>|null $kind Nomi per lingua del tipo dell'elemento, null se non ce l'ha.
 */
function wmwp_detail_base( string $type, ?array $kind, string $lang ): string {
	$page = wmwp_detail_base_page( $type );
	if ( $page > 0 ) {
		// La pagina tradotta in quella lingua, se WPML ce l'ha; altrimenti la pagina stessa
		$translated = (int) apply_filters( 'wpml_object_id', $page, 'page', true, $lang );
		$uri        = (string) get_page_uri( $translated > 0 ? $translated : $page );
		if ( '' !== $uri ) {
			return trim( $uri, '/' );
		}
	}
	$name = null === $kind ? '' : sanitize_title( wmwp_detail_text( $kind, $lang ) );
	return '' !== $name ? $name : ( 'poi' === $type ? 'poi' : 'track' );
}

// Una pagina base salvata (rinominata, spostata): l'impronta cambia e l'indice si ricostruisce subito
add_action(
	'post_updated',
	function ( int $post_id ) {
		foreach ( WMWP_DETAIL_TYPES as $type ) {
			if ( $post_id === (int) get_option( wmwp_detail_page_option( $type ), 0 ) ) {
				wmwp_detail_index();
				return;
			}
		}
	}
);
