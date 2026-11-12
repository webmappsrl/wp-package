<?php
/**
 * Pagine di dettaglio (oc:8969): indirizzi, risoluzione e redirect.
 *
 * Una rewrite per le sole basi in uso (quelle dell'indice) passa `<base>/<slug>` nella query var
 * `wmwp_detail_path`; l'indirizzo per id `?wmwp_detail=<track|poi>&wmwp_id=<id>` (link della ricerca) manda con un 301
 * all'indirizzo del dettaglio nella lingua della richiesta. Con WPML in modalità directory il prefisso di lingua
 * (`/en/`) lo toglie WPML prima delle regole.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_DETAIL_RULES_SIGNATURE = 'wmwp_detail_rules_signature';

/** Lingua della richiesta: quella corrente di WPML, o la prima delle lingue dei dettagli. */
function wmwp_detail_current_language(): string {
	$lang = apply_filters( 'wpml_current_language', null );
	if ( is_string( $lang ) && '' !== $lang ) {
		return $lang;
	}
	return wmwp_detail_languages()[0];
}

/**
 * Basi in uso, in tutte le lingue.
 *
 * @return list<string>
 */
function wmwp_detail_bases(): array {
	// Slug di rewrite dei tipi di contenuto e delle tassonomie pubbliche: una base uguale ne ruberebbe gli indirizzi
	$taken = [];
	foreach ( array_merge( get_post_types( [ 'public' => true ], 'objects' ), get_taxonomies( [ 'public' => true ], 'objects' ) ) as $object ) {
		if ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
			$taken[] = trim( (string) $object->rewrite['slug'], '/' );
		}
	}
	$bases = get_option( 'wmwp_detail_bases', null );
	if ( ! is_array( $bases ) ) {
		// Opzione mai scritta (installazione nuova o aggiornamento del plugin): una volta sola dall'indice
		$index = wmwp_detail_index();
		$bases = null === $index ? [] : wmwp_detail_index_bases( $index );
		if ( null !== $index ) {
			update_option( 'wmwp_detail_bases', $bases, true );
		}
	}
	return array_values( array_diff( array_map( 'strval', $bases ), $taken ) );
}

/**
 * URL assoluto del dettaglio di un elemento in una lingua, '' se l'elemento non è nell'indice.
 */
function wmwp_detail_url( string $type, int $id, string $lang ): string {
	$index = wmwp_detail_index();
	$path  = $index['urls'][ $type ][ $id ][ $lang ] ?? '';
	if ( '' === $path ) {
		return '';
	}
	// La home senza filtri di lingua: il prefisso lo mette WPML per la lingua chiesta
	$url = untrailingslashit( (string) get_option( 'home' ) ) . '/' . $path . '/';
	return (string) apply_filters( 'wpml_permalink', $url, $lang );
}

/**
 * Elemento di un percorso nell'indice, con la risposta da dare.
 *
 * @return array{status: int, type?: string, id?: int, location?: string}
 */
function wmwp_detail_find( array $index, string $lang, string $path ): array {
	$found = $index['paths'][ $lang ][ $path ] ?? null;
	if ( null !== $found ) {
		return [
			'status' => 200,
			'type'   => $found[0],
			'id'     => (int) $found[1],
		];
	}
	// Lo stesso percorso in un'altra lingua: l'elemento esiste, va mandato al suo indirizzo in questa lingua
	foreach ( $index['paths'] as $other => $paths ) {
		if ( $other !== $lang && isset( $paths[ $path ] ) ) {
			return [
				'status' => 301,
				'type'   => $paths[ $path ][0],
				'id'     => (int) $paths[ $path ][1],
			];
		}
	}
	// Lo stesso slug sotto un'altra base (la traccia ha cambiato route-type) o in un'altra lingua (indirizzo di prima che
	// arrivasse la traduzione del nome), se un solo elemento ce l'ha
	$slug    = substr( $path, (int) strrpos( $path, '/' ) );
	$matches = [];
	foreach ( array_unique( array_merge( [ $lang ], array_keys( $index['paths'] ) ) ) as $code ) {
		foreach ( (array) ( $index['paths'][ $code ] ?? [] ) as $candidate => $item ) {
			if ( substr( $candidate, -strlen( $slug ) ) === $slug ) {
				$matches[ $item[0] . ':' . $item[1] ] = $item;
			}
		}
		if ( $matches ) {
			break;
		}
	}
	$matches = array_values( $matches );
	if ( 1 === count( $matches ) ) {
		return [
			'status' => 301,
			'type'   => $matches[0][0],
			'id'     => (int) $matches[0][1],
		];
	}
	return [ 'status' => 404 ];
}

/**
 * Risolve un percorso `<base>/<slug>` nella lingua della richiesta. Se non si trova, l'indice si ricostruisce una
 * volta (al massimo ogni 5 minuti: una traccia appena pubblicata è subito raggiungibile, i bot non pesano) e si
 * riprova. 503 se non c'è nessun indice (shard mai raggiunto).
 *
 * @return array{status: int, type?: string, id?: int, location?: string}
 */
function wmwp_detail_resolve( string $lang, string $path ): array {
	$path  = trim( strtolower( $path ), '/' );
	$index = wmwp_detail_index();
	if ( null === $index ) {
		return [ 'status' => 503 ];
	}
	$result = wmwp_detail_find( $index, $lang, $path );
	if ( 404 === $result['status'] && wmwp_detail_rebuild_allowed() ) {
		$index  = wmwp_detail_index( true ) ?? $index;
		$result = wmwp_detail_find( $index, $lang, $path );
	}
	if ( 301 === $result['status'] ) {
		$result['location'] = wmwp_detail_url( $result['type'], $result['id'], $lang );
		if ( '' === $result['location'] ) {
			return [ 'status' => 404 ];
		}
	}
	return $result;
}

add_filter(
	'query_vars',
	function ( array $vars ): array {
		return array_merge( $vars, [ 'wmwp_detail_path', 'wmwp_detail', 'wmwp_id' ] );
	}
);

// Regole per le basi in uso; si rigenerano (flush) solo quando cambia l'elenco delle basi o la versione del plugin
add_action(
	'init',
	function () {
		$bases = wmwp_detail_bases();
		if ( $bases ) {
			$pattern = implode(
				'|',
				array_map(
					function ( string $base ): string {
						return preg_quote( $base, '#' );
					},
					$bases
				)
			);
			add_rewrite_rule( '^(' . $pattern . ')/([^/]+)/?$', 'index.php?wmwp_detail_path=$matches[1]/$matches[2]', 'top' );
		}
		$signature = md5( wp_json_encode( [ $bases, WMWP_VERSION ] ) );
		if ( get_option( WMWP_DETAIL_RULES_SIGNATURE ) !== $signature ) {
			add_action(
				'wp_loaded',
				function () use ( $signature ) {
					flush_rewrite_rules( false );
					update_option( WMWP_DETAIL_RULES_SIGNATURE, $signature, false );
				}
			);
		}
	},
	20
);

// Una pagina vera con lo stesso percorso (es. una figlia della pagina usata come base) la serve WordPress
add_filter(
	'request',
	function ( array $vars ): array {
		if ( isset( $vars['wmwp_detail_path'] ) && get_page_by_path( (string) $vars['wmwp_detail_path'] ) ) {
			$vars['pagename'] = (string) $vars['wmwp_detail_path'];
			unset( $vars['wmwp_detail_path'] );
		}
		return $vars;
	}
);

// Redirect dell'indirizzo per id e risoluzione del percorso, prima del redirect canonico del core
add_action(
	'template_redirect',
	function () {
		global $wp_query;
		$lang = wmwp_detail_current_language();

		$type = (string) get_query_var( 'wmwp_detail' );
		if ( '' !== $type ) {
			$url = in_array( $type, WMWP_DETAIL_TYPES, true ) ? wmwp_detail_url( $type, absint( get_query_var( 'wmwp_id' ) ), $lang ) : '';
			if ( '' !== $url ) {
				wp_safe_redirect( $url, 301 );
				exit;
			}
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$path = (string) get_query_var( 'wmwp_detail_path' );
		if ( '' === $path ) {
			return;
		}
		$result = wmwp_detail_resolve( $lang, $path );
		if ( 301 === $result['status'] ) {
			wp_safe_redirect( $result['location'], 301 );
			exit;
		}
		if ( 404 === $result['status'] ) {
			$GLOBALS['wmwp_detail_resolved'] = $result;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		$GLOBALS['wmwp_detail_resolved'] = $result + [
			'lang' => $lang,
			'path' => trim( strtolower( $path ), '/' ),
		];
	},
	1
);

/** Home del sito nella lingua corrente (con WPML in modalità directory: `/en/`). */
function wmwp_detail_home(): string {
	return trailingslashit( (string) apply_filters( 'wpml_home_url', home_url( '/' ) ) );
}

/**
 * Indirizzo per id dei dettagli di un tipo, con il segnaposto `{id}`: è il `link_template` predefinito della ricerca.
 * Il 301 lo manda all'indirizzo con base e slug nella lingua della pagina.
 */
function wmwp_detail_link_template( string $type ): string {
	return add_query_arg(
		[
			'wmwp_detail' => $type,
			'wmwp_id'     => '{id}',
		],
		wmwp_detail_home()
	);
}
