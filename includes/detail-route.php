<?php
/**
 * Pagine di dettaglio (oc:8969): indirizzi, risoluzione e redirect.
 *
 * Una rewrite per le sole basi in uso (quelle dell'indice) passa `<base>/<slug>` nella query var
 * `wmwp_detail_path`; l'indirizzo per id `?wmwp_detail=<track|poi>&wmwp_id=<id>` (link della ricerca) manda con un 302
 * all'indirizzo del dettaglio nella lingua della richiesta: temporaneo, perché la destinazione cambia con una rinomina,
 * un cambio di base o un reset degli id. Con WPML in modalità directory il prefisso di lingua
 * (`/en/`) lo toglie WPML prima delle regole.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_DETAIL_RULES_SIGNATURE = 'wmwp_detail_rules_signature';
// Nomi che WordPress usa negli indirizzi: non possono essere basi, né slug di un dettaglio sotto una base (sono feed,
// embed e pagine dei commenti della pagina base, che restano a WordPress)
const WMWP_DETAIL_RESERVED_BASES = [ 'feed', 'page', 'search', 'author', 'embed', 'comments', 'trackback', 'wp-admin', 'wp-json', 'wp-content', 'wp-includes' ];
const WMWP_DETAIL_RESERVED_SLUGS = '(?:feed|rdf|rss|rss2|atom|embed|trackback|comment-page-[0-9]+)';

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
	$bases = get_option( WMWP_DETAIL_BASES_OPTION, null );
	if ( ! is_array( $bases ) ) {
		// Opzione mai scritta (installazione nuova o aggiornamento del plugin): una volta sola dall'indice
		$index = wmwp_detail_index();
		$bases = null === $index ? [] : wmwp_detail_index_bases( $index );
		if ( null !== $index ) {
			update_option( WMWP_DETAIL_BASES_OPTION, $bases, true );
		}
	}
	return array_values( array_diff( array_map( 'strval', $bases ), $taken, WMWP_DETAIL_RESERVED_BASES ) );
}

/**
 * Espressione della regola di rewrite dei dettagli per le basi date: `<base>/<slug>`, tranne gli slug riservati di
 * WordPress (WMWP_DETAIL_RESERVED_SLUGS). Lo slug è il secondo gruppo.
 *
 * @param list<string> $bases
 */
function wmwp_detail_rewrite_regex( array $bases ): string {
	$pattern = implode(
		'|',
		array_map(
			function ( string $base ): string {
				return preg_quote( $base, '#' );
			},
			$bases
		)
	);
	return '^(' . $pattern . ')/(?!' . WMWP_DETAIL_RESERVED_SLUGS . '/?$)([^/]+)/?$';
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
 * Indirizzo del dettaglio per id, per il link della ricerca, che legge Elasticsearch dal vivo e quindi può mostrare un
 * elemento che l'indice in cache non ha ancora, o un id che dopo un reset dello shard è passato a un altro elemento.
 * Se l'id non è nell'indice, o la chiave stabile del suo JSON non è quella dell'indice, l'indice si ricostruisce una
 * volta (se permesso) e si riprova; se ancora non torna, ''. Il JSON letto qui resta nella cache breve e serve alla
 * pagina subito dopo. Con la fonte che non risponde si manda all'indirizzo dell'indice senza controllo.
 */
function wmwp_detail_url_by_id( string $type, int $id, string $lang ): string {
	if ( $id <= 0 || ! in_array( $type, WMWP_DETAIL_TYPES, true ) ) {
		return '';
	}
	$check = function () use ( $type, $id, $lang ): ?string {
		$index = wmwp_detail_index();
		$item  = $index['items'][ $type ][ $id ] ?? null;
		if ( ! isset( $index['urls'][ $type ][ $id ] ) ) {
			return null;
		}
		$known = (string) ( $item['source'] ?? '' );
		if ( '' !== $known ) {
			$feature = wmwp_detail_fetch( $type, $id );
			if ( is_wp_error( $feature ) && 'wmwp_detail_not_found' === $feature->get_error_code() ) {
				return null;
			}
			if ( ! is_wp_error( $feature ) ) {
				$source = wmwp_detail_source( $type, (array) $feature['properties'] );
				if ( '' !== $source && $source !== $known ) {
					return null;
				}
			}
		}
		return wmwp_detail_url( $type, $id, $lang );
	};
	$url = $check();
	if ( null === $url && wmwp_detail_rebuild_allowed() && null !== wmwp_detail_index( true ) ) {
		$url = $check();
	}
	return (string) $url;
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
	// arrivasse la traduzione del nome), se un solo elemento ce l'ha. Solo fra i tipi che stanno sotto la base chiesta,
	// quando l'indice ne ha: l'indirizzo vecchio di una traccia non deve portare a un POI con lo stesso slug
	[ $base, $slug ] = wmwp_detail_split_path( $path );
	$types           = [];
	foreach ( $index['paths'] as $paths ) {
		foreach ( $paths as $candidate => $item ) {
			if ( 0 === strpos( $candidate, $base . '/' ) ) {
				$types[ $item[0] ] = true;
			}
		}
	}
	$matches = [];
	foreach ( array_unique( array_merge( [ $lang ], array_keys( $index['paths'] ) ) ) as $code ) {
		foreach ( (array) ( $index['paths'][ $code ] ?? [] ) as $candidate => $item ) {
			if ( wmwp_detail_split_path( $candidate )[1] === $slug && ( ! $types || isset( $types[ $item[0] ] ) ) ) {
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
			'status'   => 301,
			'type'     => $matches[0][0],
			'id'       => (int) $matches[0][1],
			// Trovato per lo slug: può essere l'indice vecchio, il chiamante prova a ricostruirlo
			'fallback' => true,
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
	$path  = wmwp_detail_normalize_path( $path );
	$index = wmwp_detail_index();
	if ( null === $index ) {
		return [ 'status' => 503 ];
	}
	$result = wmwp_detail_find( $index, $lang, $path );
	// Anche il risultato del ripiego per slug può venire dall'indice vecchio (traccia che ha cambiato base): si ricostruisce
	if ( ( 404 === $result['status'] || ! empty( $result['fallback'] ) ) && wmwp_detail_rebuild_allowed() ) {
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
			add_rewrite_rule( wmwp_detail_rewrite_regex( $bases ), 'index.php?wmwp_detail_path=$matches[1]/$matches[2]', 'top' );
		}
		// Anche la regola stessa: una regola cambiata nel plugin rigenera le regole senza cambiare versione
		$signature = md5( wp_json_encode( [ $bases ? wmwp_detail_rewrite_regex( $bases ) : '', WMWP_VERSION ] ) );
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
			$url = wmwp_detail_url_by_id( $type, absint( get_query_var( 'wmwp_id' ) ), $lang );
			if ( '' !== $url ) {
				wp_safe_redirect( $url, 302 );
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
			'path' => wmwp_detail_normalize_path( $path ),
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
