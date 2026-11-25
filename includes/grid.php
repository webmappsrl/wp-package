<?php
/**
 * Griglie di percorsi (oc:9003): ricerca sulla copia delle tracce (includes/tracks-copy.php) e rotta `wmwp/v1/tracks`.
 *
 * Le regole sono quelle di assets/js/search-logic.js, rifatte in PHP perché la griglia lavora sul server: valori di una
 * faccetta (`terms`, `list`, `missing`, `groups`, include/exclude), OR dentro un filtro e AND fra filtri, ogni faccetta
 * contata senza il proprio filtro, slider con gli estremi aperti. Chi cambia una regola là la cambia anche qui: i test
 * tests/php/tracks-route.php e tests/js/search-logic.test.js provano gli stessi casi.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_GRID_PER_MAX  = 48;
const WMWP_GRID_PAGE_MAX = 10000;
// Testo cercato in una griglia (oc:9003): gli id li dà l'API dello shard, come nella ricerca unica; in cache per testo
const WMWP_GRID_TEXT_TTL         = 10 * MINUTE_IN_SECONDS;
const WMWP_GRID_TEXT_CACHE_GROUP = 'wmwp_grid_text';
const WMWP_GRID_TEXT_MAX         = 200;
const WMWP_GRID_TEXT_TIMEOUT     = 8;

/** Valore di un campo con il percorso a punti («terms.hiking-difficulty»). */
function wmwp_grid_field( array $track, string $path ) {
	$value = $track;
	foreach ( explode( '.', $path ) as $key ) {
		if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
			return null;
		}
		$value = $value[ $key ];
	}
	return $value;
}

/** Nome nella lingua, poi in italiano (come localized() di search-logic.js). */
function wmwp_grid_localized( $names, string $lang ): string {
	if ( ! is_array( $names ) ) {
		return '';
	}
	return (string) ( ( $names[ $lang ] ?? '' ) ?: ( $names['it'] ?? '' ) );
}

/** Lingua da dare a un testo con `lang`: quella del testo se è diversa da quella della pagina, '' altrimenti. */
function wmwp_grid_lang_for( string $text_lang, string $page_lang ): string {
	$text = strtolower( substr( $text_lang, 0, 2 ) );
	return '' !== $text && strtolower( substr( $page_lang, 0, 2 ) ) !== $text ? $text : '';
}

/** include / include_pattern / exclude / exclude_pattern di una faccetta. */
function wmwp_grid_keep_value( array $facet, string $id ): bool {
	if ( isset( $facet['include'] ) && ! in_array( $id, array_map( 'strval', (array) $facet['include'] ), true ) ) {
		return false;
	}
	if ( ! empty( $facet['include_pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', $facet['include_pattern'] ) . '/u', $id ) ) {
		return false;
	}
	if ( isset( $facet['exclude'] ) && in_array( $id, array_map( 'strval', (array) $facet['exclude'] ), true ) ) {
		return false;
	}
	if ( ! empty( $facet['exclude_pattern'] ) && preg_match( '/' . str_replace( '/', '\/', $facet['exclude_pattern'] ) . '/u', $id ) ) {
		return false;
	}
	return true;
}

/**
 * Gruppi di una faccetta: più valori sotto una voce; un valore fuori dai gruppi resta dopo, con il suo nome.
 *
 * @param list<array<string, string>> $values Valori.
 * @param array<int, array<string, mixed>> $groups Gruppi del tema.
 * @return list<array<string, string>>
 */
function wmwp_grid_group_values( array $values, array $groups ): array {
	$out     = [];
	$grouped = [];
	$ids     = array_column( $values, 'id' );
	foreach ( $groups as $group ) {
		$members = array_map( 'strval', (array) ( $group['values'] ?? [] ) );
		foreach ( $members as $member ) {
			$grouped[ $member ] = true;
		}
		if ( array_intersect( $members, $ids ) ) {
			$out[] = [
				'id'    => (string) $group['id'],
				'label' => (string) ( $group['label'] ?? $group['id'] ),
			];
		}
	}
	foreach ( $values as $value ) {
		if ( ! isset( $grouped[ $value['id'] ] ) ) {
			$out[] = $value;
		}
	}
	return $out;
}

/** Il campo ha almeno un valore utile (per i termini un oggetto con l'identifier). */
function wmwp_grid_has_value( $raw, string $type ): bool {
	if ( ! is_array( $raw ) ) {
		return false;
	}
	foreach ( $raw as $item ) {
		if ( 'terms' === $type ? is_array( $item ) && ! empty( $item['identifier'] ) : null !== $item && '' !== $item ) {
			return true;
		}
	}
	return false;
}

/**
 * Valori di una faccetta in una traccia (facetValues() di search-logic.js).
 *
 * @return list<array{id: string, label: string, lang?: string}>
 */
function wmwp_grid_facet_values( array $track, array $facet, string $lang ): array {
	if ( ! empty( $facet['disabled'] ) || empty( $facet['field'] ) ) {
		return [];
	}
	$type   = (string) ( $facet['type'] ?? 'list' );
	$raw    = wmwp_grid_field( $track, (string) $facet['field'] );
	$labels = (array) ( $facet['labels'] ?? [] );
	if ( ! empty( $facet['missing'] ) && ! wmwp_grid_has_value( $raw, $type ) ) {
		$id      = (string) $facet['missing'];
		$missing = [
			[
				'id'    => $id,
				'label' => trim( (string) ( $labels[ $id ] ?? $id ) ),
			],
		];
		return ! empty( $facet['groups'] ) ? wmwp_grid_group_values( $missing, (array) $facet['groups'] ) : $missing;
	}
	if ( ! is_array( $raw ) ) {
		return [];
	}
	$result = [];
	foreach ( $raw as $item ) {
		$term_lang = '';
		if ( 'terms' === $type ) {
			if ( ! is_array( $item ) || empty( $item['identifier'] ) ) {
				continue;
			}
			$id    = (string) $item['identifier'];
			$names = $item['name'] ?? null;
			$label = wmwp_grid_localized( $names, $lang );
			if ( is_array( $names ) && empty( $names[ $lang ] ) && ! empty( $names['it'] ) ) {
				$term_lang = 'it';
			}
		} else {
			if ( null === $item || '' === $item || is_array( $item ) ) {
				continue;
			}
			$id    = trim( (string) $item );
			$label = wmwp_grid_localized( $track['taxonomyIcons'][ $id ]['label'] ?? null, $lang );
		}
		if ( ! empty( $facet['exclude_prefix'] ) && 0 === strpos( $id, (string) $facet['exclude_prefix'] ) ) {
			continue;
		}
		if ( ! wmwp_grid_keep_value( $facet, $id ) ) {
			continue;
		}
		$value = [
			'id'    => $id,
			'label' => trim( (string) ( ( $labels[ $id ] ?? '' ) ?: ( $label ?: $id ) ) ),
		];
		if ( isset( $labels[ $id ] ) && ! empty( $facet['lang_labels'][ $id ] ) ) {
			$value['lang'] = (string) $facet['lang_labels'][ $id ];
		} elseif ( ! isset( $labels[ $id ] ) && '' !== wmwp_grid_lang_for( $term_lang, $lang ) ) {
			$value['lang'] = wmwp_grid_lang_for( $term_lang, $lang );
		}
		$result[] = $value;
	}
	return ! empty( $facet['groups'] ) ? wmwp_grid_group_values( $result, (array) $facet['groups'] ) : $result;
}

/** Ultimo valore che un range con quel passo raggiunge (lastStep() di search-logic.js). */
function wmwp_grid_last_step( array $facet ): float {
	$min  = (float) ( $facet['min'] ?? 0 );
	$max  = (float) ( $facet['max'] ?? 0 );
	$step = (float) ( $facet['step'] ?? 0 );
	if ( $step <= 0 || $max <= $min ) {
		return $max;
	}
	return round( $min + floor( ( $max - $min ) / $step + 1e-9 ) * $step, 6 );
}

/**
 * Limiti di uno slider dal valore dell'indirizzo («<min>-<max>»), con gli estremi aperti (sliderBounds()).
 *
 * @return array{min: ?float, max: ?float}
 */
function wmwp_grid_slider_bounds( array $facet, string $value ): array {
	$open = [
		'min' => null,
		'max' => null,
	];
	if ( ! preg_match( '/^\s*(\d+(?:[.,]\d+)?)?\s*-\s*(\d+(?:[.,]\d+)?)?\s*$/', $value, $match ) ) {
		return $open;
	}
	$low  = isset( $match[1] ) && '' !== $match[1] ? (float) str_replace( ',', '.', $match[1] ) : null;
	$high = isset( $match[2] ) && '' !== $match[2] ? (float) str_replace( ',', '.', $match[2] ) : null;
	// Un intervallo rovesciato scritto a mano («10-4») vale in ordine
	if ( null !== $low && null !== $high && $low > $high ) {
		[ $low, $high ] = [ $high, $low ];
	}
	return [
		'min' => null !== $low && $low > (float) $facet['min'] ? $low : null,
		'max' => null !== $high && $high < wmwp_grid_last_step( $facet ) ? $high : null,
	];
}

/** Valore di uno slider nell'indirizzo dai suoi limiti: «<min>-<max>» con un lato vuoto se aperto, '' senza limiti. */
function wmwp_grid_slider_value( array $facet, string $value ): string {
	$bounds = wmwp_grid_slider_bounds( $facet, $value );
	if ( null === $bounds['min'] && null === $bounds['max'] ) {
		return '';
	}
	$text = fn( ?float $n ): string => null === $n ? '' : rtrim( rtrim( number_format( $n, 6, '.', '' ), '0' ), '.' );
	return $text( $bounds['min'] ) . '-' . $text( $bounds['max'] );
}

/** La traccia passa il filtro di una faccetta (OR fra i valori scelti; slider con gli estremi aperti). */
function wmwp_grid_matches( array $track, array $facet, array $chosen, string $lang ): bool {
	if ( 'slider' === ( $facet['type'] ?? '' ) ) {
		$bounds = wmwp_grid_slider_bounds( $facet, (string) ( $chosen[0] ?? '' ) );
		if ( null === $bounds['min'] && null === $bounds['max'] ) {
			return true;
		}
		$value = (float) ( wmwp_grid_field( $track, (string) $facet['field'] ) ?? 0 );
		return $value > 0 && ( null === $bounds['min'] || $value >= $bounds['min'] ) && ( null === $bounds['max'] || $value <= $bounds['max'] );
	}
	if ( ! $chosen ) {
		return true;
	}
	return (bool) array_intersect( array_column( wmwp_grid_facet_values( $track, $facet, $lang ), 'id' ), $chosen );
}

/**
 * Tracce che passano tutti i filtri, tranne quello di `$skip` (per i suoi conteggi).
 *
 * @param list<array<string, mixed>>     $tracks   Tracce.
 * @param list<array<string, mixed>>     $facets   Faccette.
 * @param array<string, list<string>>    $selected Valori scelti per chiave.
 * @return list<array<string, mixed>>
 */
function wmwp_grid_filter( array $tracks, array $facets, array $selected, string $lang, string $skip = '' ): array {
	return array_values(
		array_filter(
			$tracks,
			function ( array $track ) use ( $facets, $selected, $lang, $skip ) {
				foreach ( $facets as $facet ) {
					$key = (string) ( $facet['key'] ?? '' );
					if ( $key === $skip || ! empty( $facet['disabled'] ) ) {
						continue;
					}
					if ( ! wmwp_grid_matches( $track, $facet, (array) ( $selected[ $key ] ?? [] ), $lang ) ) {
						return false;
					}
				}
				return true;
			}
		)
	);
}

/**
 * Faccette con i conteggi (computeFacets() di search-logic.js): ogni faccetta contata senza il proprio filtro; un valore
 * scelto senza risultati resta con 0; ordine del tema, poi per numero e nome. Uno slider dice se è disponibile.
 *
 * @return list<array<string, mixed>>
 */
function wmwp_grid_compute_facets( array $tracks, array $facets, array $selected, string $lang ): array {
	$views = [];
	foreach ( $facets as $facet ) {
		$key    = (string) ( $facet['key'] ?? '' );
		$chosen = array_map( 'strval', (array) ( $selected[ $key ] ?? [] ) );
		$others = empty( $facet['disabled'] ) ? wmwp_grid_filter( $tracks, $facets, $selected, $lang, $key ) : [];
		if ( 'slider' === ( $facet['type'] ?? '' ) ) {
			$available = false;
			foreach ( $others as $track ) {
				if ( (float) ( wmwp_grid_field( $track, (string) $facet['field'] ) ?? 0 ) > 0 ) {
					$available = true;
					break;
				}
			}
			$views[] = [
				'key'       => $key,
				'label'     => (string) ( $facet['label'] ?? $key ),
				'type'      => 'slider',
				'disabled'  => ! empty( $facet['disabled'] ),
				'min'       => (float) $facet['min'],
				'max'       => (float) $facet['max'],
				'step'      => (float) $facet['step'],
				'units'     => (string) ( $facet['units'] ?? '' ),
				'value'     => wmwp_grid_slider_value( $facet, (string) ( $chosen[0] ?? '' ) ),
				'available' => $available,
			];
			continue;
		}
		$counts = [];
		$labels = [];
		$langs  = [];
		foreach ( $others as $track ) {
			$seen = [];
			foreach ( wmwp_grid_facet_values( $track, $facet, $lang ) as $value ) {
				if ( isset( $seen[ $value['id'] ] ) ) {
					continue;
				}
				$seen[ $value['id'] ]     = true;
				$counts[ $value['id'] ]   = ( $counts[ $value['id'] ] ?? 0 ) + 1;
				$labels[ $value['id'] ]   = $value['label'];
				if ( isset( $value['lang'] ) ) {
					$langs[ $value['id'] ] = $value['lang'];
				}
			}
		}
		foreach ( $chosen as $id ) {
			if ( isset( $counts[ $id ] ) ) {
				continue;
			}
			$counts[ $id ] = 0;
			$labels[ $id ] = $id;
			foreach ( $tracks as $track ) {
				foreach ( wmwp_grid_facet_values( $track, $facet, $lang ) as $value ) {
					if ( $value['id'] === $id ) {
						$labels[ $id ] = $value['label'];
						if ( isset( $value['lang'] ) ) {
							$langs[ $id ] = $value['lang'];
						}
						break 2;
					}
				}
			}
			if ( $labels[ $id ] === $id ) {
				$group = array_values( array_filter( (array) ( $facet['groups'] ?? [] ), fn( $g ) => (string) ( $g['id'] ?? '' ) === $id ) );
				$labels[ $id ] = (string) ( $facet['labels'][ $id ] ?? ( $group[0]['label'] ?? $id ) );
			}
		}
		$order  = array_map( 'strval', (array) ( $facet['order'] ?? [] ) );
		$values = [];
		foreach ( $counts as $id => $count ) {
			$value = [
				'id'       => (string) $id,
				'label'    => $labels[ $id ],
				'count'    => $count,
				'selected' => in_array( (string) $id, $chosen, true ),
			];
			if ( isset( $langs[ $id ] ) ) {
				$value['lang'] = $langs[ $id ];
			}
			$values[] = $value;
		}
		usort(
			$values,
			function ( array $a, array $b ) use ( $order ): int {
				$ia = array_search( $a['id'], $order, true );
				$ib = array_search( $b['id'], $order, true );
				if ( false !== $ia || false !== $ib ) {
					return ( false === $ia ? PHP_INT_MAX : $ia ) <=> ( false === $ib ? PHP_INT_MAX : $ib );
				}
				return $b['count'] <=> $a['count'] ?: strcmp( $a['label'], $b['label'] );
			}
		);
		$views[] = [
			'key'      => $key,
			'label'    => (string) ( $facet['label'] ?? $key ),
			'disabled' => ! empty( $facet['disabled'] ),
			'values'   => $values,
		];
	}
	return $views;
}

/**
 * Testo per l'API dello shard, come cleanQuery() di search-logic.js: i caratteri della sintassi di Lucene diventano
 * spazi, AND/OR/NOT in minuscolo, il trattino dentro una parola si scrive «\-» (codici come «B-401»), quello fra due
 * parole si toglie.
 */
function wmwp_grid_clean_query( string $text ): string {
	$text  = preg_replace( '#[+=&|><!(){}\[\]^"~*?:\\\\/]#u', ' ', mb_substr( $text, 0, WMWP_GRID_TEXT_MAX, 'UTF-8' ) );
	$text  = preg_replace_callback( '/\b(AND|OR|NOT)\b/', fn( $m ) => strtolower( $m[1] ), (string) $text );
	$words = array_filter( preg_split( '/\s+/u', (string) $text ), fn( $word ) => '' !== $word && ! preg_match( '/^-+$/', $word ) );
	return str_replace( '-', '\\-', implode( ' ', $words ) );
}

/** Testo per il confronto, come normalize() di search-logic.js: minuscolo, senza accenti, lettere e numeri. */
function wmwp_grid_normalize( string $text ): string {
	return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $text ) ) ) );
}

/**
 * Id delle tracce che l'API dello shard trova per un testo già ripulito (la stessa chiamata della ricerca unica). Cache
 * per testo nell'object cache: 10 minuti con un object cache esterno, altrimenti la sola richiesta. Mai nei transient:
 * la rotta è pubblica e ogni testo a caso scriverebbe righe in wp_options. Null se lo shard non risponde: la griglia lo
 * dice, non mostra «0 risultati».
 *
 * @return list<int>|null
 */
function wmwp_grid_text_ids( string $clean ): ?array {
	$key    = md5( wmwp_app_param() . '|' . $clean );
	$cached = wp_cache_get( $key, WMWP_GRID_TEXT_CACHE_GROUP );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$data = wmwp_detail_get_json( add_query_arg( [ 'app' => wmwp_app_param(), 'query' => rawurlencode( $clean ) ], wmwp_elastic_url() ), WMWP_GRID_TEXT_TIMEOUT );
	if ( ! is_array( $data ) || ! empty( $data['timed_out'] ) ) {
		return null;
	}
	$hits = $data['hits'] ?? [];
	$hits = isset( $hits['hits'] ) && is_array( $hits['hits'] ) ? $hits['hits'] : $hits;
	$ids  = [];
	foreach ( (array) $hits as $hit ) {
		$id = (int) ( ( is_array( $hit ) && isset( $hit['_source'] ) ? $hit['_source']['id'] ?? 0 : ( $hit['id'] ?? 0 ) ) );
		if ( $id > 0 ) {
			$ids[] = $id;
		}
	}
	wp_cache_set( $key, $ids, WMWP_GRID_TEXT_CACHE_GROUP, WMWP_GRID_TEXT_TTL );
	return $ids;
}

/**
 * Pertinenza come rankByText() di search-logic.js: prima chi ha il testo nel nome, poi chi ne ha tutte le parole, poi
 * gli altri; a parità l'ordine della copia.
 *
 * @param list<array<string, mixed>> $tracks Tracce.
 * @return list<array<string, mixed>>
 */
function wmwp_grid_rank_by_text( array $tracks, string $raw, string $lang ): array {
	$phrase = wmwp_grid_normalize( $raw );
	if ( '' === $phrase ) {
		return $tracks;
	}
	$words  = explode( ' ', $phrase );
	$scored = [];
	foreach ( array_values( $tracks ) as $index => $track ) {
		$names    = $track['name'] ?? null;
		$name     = wmwp_grid_normalize( wmwp_grid_localized( $names, $lang ) ) . ' ' . wmwp_grid_normalize( is_array( $names ) ? (string) ( $names['it'] ?? '' ) : (string) $names );
		$score    = false !== strpos( $name, $phrase ) ? 2 : ( count( array_filter( $words, fn( $word ) => false !== strpos( $name, $word ) ) ) === count( $words ) ? 1 : 0 );
		$scored[] = [ $score, $index, $track ];
	}
	usort( $scored, fn( array $a, array $b ): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1] );
	return array_column( $scored, 2 );
}

/**
 * Ricerca di una griglia sulla copia: layer fisso (0 = nessuna traccia, mai tutte), filtri, conteggi e pagina.
 *
 * @param list<array<string, mixed>>  $copy    Copia delle tracce.
 * @param list<array<string, mixed>>  $facets  Faccette già completate dal config.json.
 * @param array<string, list<string>> $filters Valori scelti per chiave.
 * @param list<int>|null              $text_ids Id trovati dall'API per il testo cercato; null senza testo.
 * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, facets: list<array<string, mixed>>}
 */
function wmwp_tracks_query( array $copy, int $layer, array $facets, array $filters, string $lang, int $page, int $per_page, ?array $text_ids = null, string $raw_text = '' ): array {
	$tracks = $layer > 0 ? array_values( array_filter( $copy, fn( array $track ) => in_array( $layer, array_map( 'intval', (array) ( $track['layers'] ?? [] ) ), true ) ) ) : [];
	// Con un testo: solo le tracce che l'API dello shard trova, come nella ricerca unica (anche i conteggi), per pertinenza
	if ( null !== $text_ids ) {
		$found  = array_flip( array_map( 'intval', $text_ids ) );
		$tracks = wmwp_grid_rank_by_text( array_values( array_filter( $tracks, fn( array $track ) => isset( $found[ (int) $track['id'] ] ) ) ), $raw_text, $lang );
	}
	$matching = wmwp_grid_filter( $tracks, $facets, $filters, $lang );
	$per_page = max( 1, min( WMWP_GRID_PER_MAX, $per_page ) );
	$pages    = max( 1, (int) ceil( count( $matching ) / $per_page ) );
	$current  = min( max( 1, $page ), $pages );
	return [
		'items'  => array_slice( $matching, ( $current - 1 ) * $per_page, $per_page ),
		'total'  => count( $matching ),
		'page'   => $current,
		'pages'  => $pages,
		'facets' => wmwp_grid_compute_facets( $tracks, $facets, $filters, $lang ),
	];
}

/**
 * Filtri dai parametri dell'indirizzo: la chiave di ogni faccetta, valori separati da virgola (una faccetta con un
 * parametro condiviso, come le aree in `layer`, prende solo i suoi `ids`).
 *
 * @param array<string, mixed>       $params Parametri.
 * @param list<array<string, mixed>> $facets Faccette.
 * @return array<string, list<string>>
 */
function wmwp_grid_filters_from_params( array $params, array $facets ): array {
	$filters = [];
	foreach ( $facets as $facet ) {
		$key   = (string) ( $facet['key'] ?? '' );
		$param = (string) ( $facet['param'] ?? $key );
		$raw   = $params[ $param ] ?? null;
		if ( '' === $key || ! is_scalar( $raw ) ) {
			continue;
		}
		$list = array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'strlen' ) );
		if ( ! empty( $facet['param'] ) ) {
			$list = array_values( array_intersect( $list, array_map( 'strval', (array) ( $facet['ids'] ?? [] ) ) ) );
		}
		// Uno slider senza limiti validi non è un filtro (nessun filtro attivo «abc»); i limiti vanno in ordine
		if ( 'slider' === ( $facet['type'] ?? '' ) ) {
			$value = wmwp_grid_slider_value( $facet, (string) ( $list[0] ?? '' ) );
			$list  = '' === $value ? [] : [ $value ];
		}
		if ( $list ) {
			$filters[ $key ] = $list;
		}
	}
	return $filters;
}

/**
 * Configurazione di una griglia dal tema (filter `wmwp_grid_config`): `type` (`tracks` o `pois`), `layer`, `facets`,
 * `per_page`, `nouns`, `title`. Null se il tema non la dà.
 *
 * @return array<string, mixed>|null
 */
function wmwp_grid_config( string $id ): ?array {
	$config = apply_filters( 'wmwp_grid_config', null, $id );
	return is_array( $config ) ? $config : null;
}

/**
 * Risultato di una griglia di percorsi per la lingua e i parametri dati: quello della rotta e della prima pagina
 * disegnata sul server. Null senza copia (la rotta risponde 503).
 *
 * @param array<string, mixed> $config Configurazione della griglia.
 * @param array<string, mixed> $params Parametri dell'indirizzo.
 * @return array<string, mixed>|WP_Error|null Null senza copia, WP_Error se il testo non si può cercare.
 */
function wmwp_grid_tracks_result( array $config, array $params, string $lang ) {
	$copy = wmwp_tracks_copy();
	if ( null === $copy ) {
		return null;
	}
	$raw      = trim( (string) ( $params[ wmwp_search_params()['q'] ] ?? '' ) );
	$clean    = wmwp_grid_clean_query( $raw );
	$text_ids = null;
	if ( '' !== $clean ) {
		$text_ids = wmwp_grid_text_ids( $clean );
		if ( null === $text_ids ) {
			return new WP_Error( 'wmwp_grid_text_unavailable', 'Text search is not available at the moment.', [ 'status' => 503 ] );
		}
	}
	$facets = wmwp_app_expand_facets( (array) ( $config['facets'] ?? [] ), $lang );
	$result = wmwp_tracks_query(
		$copy,
		(int) ( $config['layer'] ?? 0 ),
		$facets,
		wmwp_grid_filters_from_params( $params, $facets ),
		$lang,
		min( WMWP_GRID_PAGE_MAX, max( 1, (int) ( $params['pg'] ?? 1 ) ) ),
		(int) ( $config['per_page'] ?? 9 ),
		$text_ids,
		$raw
	);
	$result['items'] = array_map( fn( array $track ) => wmwp_grid_track_card( $track, $lang ), $result['items'] );
	return $result;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wmwp/v1',
			'/tracks',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => [
					'grid' => [
						'type'     => 'string',
						'required' => true,
					],
					'lang' => [
						'type'    => 'string',
						'default' => 'it',
					],
				],
				'callback'            => 'wmwp_grid_tracks_rest',
			]
		);
	}
);

/** Rotta `wmwp/v1/tracks`: la griglia `grid` del tema con i parametri della richiesta; 503 senza copia. */
function wmwp_grid_tracks_rest( WP_REST_Request $request ) {
	$lang   = strtolower( substr( sanitize_key( (string) $request->get_param( 'lang' ) ), 0, 2 ) ) ?: 'it';
	$copy   = wmwp_tracks_copy();
	$config = wmwp_grid_config( (string) $request->get_param( 'grid' ) );
	if ( null === $copy ) {
		return new WP_Error( 'wmwp_tracks_unavailable', 'Trails are not available at the moment.', [ 'status' => 503 ] );
	}
	if ( null === $config || 'tracks' !== ( $config['type'] ?? 'tracks' ) ) {
		return new WP_Error( 'wmwp_grid_unknown', 'Unknown grid.', [ 'status' => 404 ] );
	}
	$params = array_map( fn( $value ) => is_scalar( $value ) ? (string) $value : '', $request->get_query_params() );
	$result = wmwp_grid_tracks_result( $config, $params, $lang );
	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}

/** Numero nel formato della lingua (virgola decimale in italiano), come Intl.NumberFormat della ricerca. */
function wmwp_grid_number( float $number, int $decimals, string $lang ): string {
	return 'it' === $lang ? number_format( $number, $decimals, ',', '.' ) : number_format( $number, $decimals, '.', ',' );
}

/** Km, metri e durata come formatKm(), formatMeters() e formatDuration() di search-logic.js; '' senza valore. */
function wmwp_grid_format_km( float $km, string $lang ): string {
	return $km > 0 ? wmwp_grid_number( $km, 1, $lang ) . ' km' : '';
}

function wmwp_grid_format_meters( float $meters, string $lang ): string {
	return $meters > 0 ? wmwp_grid_number( $meters, 0, $lang ) . ' m' : '';
}

function wmwp_grid_format_duration( float $minutes ): string {
	if ( $minutes <= 0 ) {
		return '';
	}
	$total = (int) round( $minutes );
	$hours = intdiv( $total, 60 );
	return ( $hours > 0 ? $hours . ' h ' : '' ) . ( $total % 60 ) . ' min';
}

/**
 * Card di una traccia come toCard() di source-tracks.js, con le chiavi della source `tracks` della ricerca (tipo,
 * stato, comune, indirizzo per id): la griglia e la ricerca mostrano la stessa card.
 *
 * @return array<string, mixed>
 */
function wmwp_grid_track_card( array $track, string $lang ): array {
	$source = wmwp_search_sources()['tracks'] ?? [];
	$labels = (array) ( $source['term_labels'] ?? [] );
	$term   = function ( ?array $item ) use ( $lang, $labels ): string {
		if ( ! $item ) {
			return '';
		}
		$id = (string) ( $item['identifier'] ?? '' );
		return trim( (string) ( ( '' !== $id && isset( $labels[ $id ] ) ) ? $labels[ $id ] : ( wmwp_grid_localized( $item['name'] ?? null, $lang ) ?: $id ) ) );
	};
	$kind_list = ! empty( $source['kind_field'] ) ? wmwp_grid_field( $track, (string) $source['kind_field'] ) : null;
	$kind      = is_array( $kind_list ) && isset( $kind_list[0] ) && is_array( $kind_list[0] ) ? $kind_list[0] : null;
	$badge     = null;
	if ( ! empty( $source['badge_field'] ) ) {
		$list  = wmwp_grid_field( $track, (string) $source['badge_field'] );
		$first = is_array( $list ) && isset( $list[0]['identifier'] ) ? $list[0] : null;
		if ( $first && ! in_array( (string) $first['identifier'], (array) ( $source['badge_exclude'] ?? [] ), true ) ) {
			$badge = [
				'key'   => (string) $first['identifier'],
				'label' => $term( $first ),
			];
		}
	}
	$last = '';
	if ( ! empty( $source['meta_last_field'] ) ) {
		$list = wmwp_grid_field( $track, (string) $source['meta_last_field'] );
		$last = is_array( $list ) && $list ? trim( (string) end( $list ) ) : '';
	}
	$ref    = trim( (string) ( $track['ref'] ?? '' ) );
	$url    = ! empty( $source['link_template'] ) ? str_replace( [ '{id}', '{ref}' ], [ rawurlencode( (string) $track['id'] ), rawurlencode( $ref ) ], (string) $source['link_template'] ) : null;
	$names  = $track['name'] ?? null;
	$ascent = wmwp_grid_format_meters( (float) ( $track['ascent'] ?? 0 ), $lang );
	return [
		'id'        => (int) $track['id'],
		'title'     => trim( wmwp_grid_localized( $names, $lang ) ),
		'titleLang' => is_array( $names ) && empty( $names[ $lang ] ) && ! empty( $names['it'] ) ? wmwp_grid_lang_for( 'it', $lang ) : '',
		'url'       => $url,
		'typeKey'   => $kind ? (string) ( $kind['identifier'] ?? '' ) : '',
		'typeLabel' => $term( $kind ),
		'image'     => ! empty( $track['feature_image'] ) ? [
			'src' => (string) $track['feature_image'],
			'alt' => '',
		] : null,
		'code'      => '' !== $ref ? $ref : null,
		'meta'      => [ $last, wmwp_grid_format_km( (float) ( $track['distance'] ?? 0 ), $lang ), '' !== $ascent ? '+' . $ascent : '', wmwp_grid_format_duration( (float) ( $track['duration_forward'] ?? 0 ) ) ],
		'badge'     => $badge,
	];
}

/**
 * Una griglia sola per pagina: configurazione e parametri della ricerca sono unici (window.wmWpSearchConfig). Con
 * `$set` false si azzera (test).
 */
function wmwp_grid_rendered( ?bool $set = null ): bool {
	static $rendered = false;
	if ( null !== $set ) {
		$rendered = $set;
	}
	return $rendered;
}

/** Messaggio al posto della griglia (dati non disponibili): mai tutte le tracce. */
function wmwp_grid_message( string $type ): string {
	$text = 'pois' === $type ? __( 'Points of interest are not available right now.', 'wp-package' ) : __( 'Trails are not available at the moment.', 'wp-package' );
	return '<p class="wmwp-grid__message">' . esc_html( $text ) . '</p>';
}

/** Numero dei risultati come countLabel() di search-logic.js («27 sentieri»). */
function wmwp_grid_count_text( int $count, array $nouns, string $lang ): string {
	$form = 1 === $count ? ( $nouns['one'] ?? '%s' ) : ( $nouns['other'] ?? '%s' );
	return str_replace( '%s', wmwp_grid_number( (float) $count, 0, $lang ), (string) $form );
}

/**
 * Card di un risultato dal template `card.php` (sovrascrivibile dal tema), riempita come fill() di search.js: la prima
 * pagina disegnata sul server è uguale a quella che il JS ridisegna.
 *
 * @param array<string, mixed> $item Card (wmwp_grid_track_card() o la rotta dei POI).
 */
function wmwp_grid_card_html( array $item, string $lang ): string {
	ob_start();
	include wmwp_locate_template( 'card.php' );
	$html = (string) ob_get_clean();
	$doc  = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div id="wmwp-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	$xpath = new DOMXPath( $doc );
	$part  = fn( string $name ) => $xpath->query( '//*[@data-wmwp="' . $name . '"]' )->item( 0 );
	$node  = $xpath->query( '//*[@id="wmwp-root"]/*' )->item( 0 );
	if ( ! $node instanceof DOMElement ) {
		return '';
	}
	if ( ! empty( $item['typeKey'] ) ) {
		$node->setAttribute( 'class', trim( $node->getAttribute( 'class' ) . ' wmwp-card--type-' . sanitize_html_class( (string) $item['typeKey'] ) ) );
	}
	$remove = function ( $element ) {
		if ( $element && $element->parentNode ) {
			$element->parentNode->removeChild( $element );
		}
	};
	$remove( $part( 'select' ) );
	$link = $part( 'link' );
	if ( $link instanceof DOMElement && ! empty( $item['url'] ) ) {
		// esc_url_raw: l'escape dell'attributo lo fa DOMDocument (con esc_url `&` diventerebbe `&amp;#038;`)
		$link->setAttribute( 'href', esc_url_raw( (string) $item['url'] ) );
	}
	$image = $part( 'image' );
	if ( $image instanceof DOMElement ) {
		if ( ! empty( $item['image']['src'] ) ) {
			$image->setAttribute( 'src', esc_url_raw( (string) $item['image']['src'] ) );
			$image->setAttribute( 'alt', (string) ( $item['image']['alt'] ?? '' ) );
		} else {
			$media = $xpath->query( 'ancestor::*[contains(concat(" ", normalize-space(@class), " "), " wmwp-card__media ")]', $image )->item( 0 );
			$remove( $media ? $media : $image );
		}
	}
	$texts = [
		'type'  => [ (string) ( $item['typeLabel'] ?? '' ), '' ],
		'code'  => [ (string) ( $item['code'] ?? '' ), '' ],
		'title' => [ (string) ( $item['title'] ?? '' ), (string) ( $item['titleLang'] ?? '' ) ],
		'meta'  => [ implode( ' · ', array_filter( array_map( 'strval', (array) ( $item['meta'] ?? [] ) ) ) ), (string) ( $item['metaLang'] ?? '' ) ],
	];
	foreach ( $texts as $name => [ $text, $text_lang ] ) {
		$element = $part( $name );
		if ( ! $element instanceof DOMElement ) {
			continue;
		}
		if ( '' === $text ) {
			$remove( $element );
			continue;
		}
		$element->textContent = $text;
		$attr                 = wmwp_grid_lang_for( $text_lang, $lang );
		if ( '' !== $attr ) {
			$element->setAttribute( 'lang', $attr );
		}
	}
	$badge = $part( 'badge' );
	if ( $badge instanceof DOMElement ) {
		if ( ! empty( $item['badge']['label'] ) ) {
			$badge->textContent = (string) $item['badge']['label'];
			$badge->setAttribute( 'class', trim( $badge->getAttribute( 'class' ) . ' wmwp-badge--' . sanitize_html_class( (string) $item['badge']['key'] ) ) );
		} else {
			$remove( $badge );
		}
	}
	return (string) $doc->saveHTML( $node );
}

/** Link alle pagine per chi non ha JavaScript (e per i crawler): il JS li sostituisce con i suoi pulsanti. */
function wmwp_grid_pager_html( int $page, int $pages, string $param ): string {
	if ( $pages <= 1 ) {
		return '';
	}
	// La stessa finestra di renderPager() di search.js (5 pagine attorno alla corrente, più precedente e successiva): il JS
	// la ridisegna senza che i link cambino di posto
	$url   = fn( int $number ): string => esc_url( 1 === $number ? remove_query_arg( $param ) : add_query_arg( $param, $number ) );
	$links = [];
	if ( $page > 1 ) {
		$links[] = '<a class="wmwp-search__page" href="' . $url( $page - 1 ) . '" aria-label="' . esc_attr__( 'Previous page', 'wp-package' ) . '">‹</a>';
	}
	$first = max( 1, min( $page - 2, $pages - 4 ) );
	$last  = min( $pages, $first + 4 );
	for ( $number = $first; $number <= $last; $number++ ) {
		$links[] = $number === $page
			? '<span class="wmwp-search__page is-current" aria-current="page">' . (int) $number . '</span>'
			: '<a class="wmwp-search__page" href="' . $url( $number ) . '">' . (int) $number . '</a>';
	}
	if ( $page < $pages ) {
		$links[] = '<a class="wmwp-search__page" href="' . $url( $page + 1 ) . '" aria-label="' . esc_attr__( 'Next page', 'wp-package' ) . '">›</a>';
	}
	return implode( '', $links );
}

/**
 * Shortcode [wmwp_tracks_grid id="…"]: griglia dei percorsi di un layer, configurata dal tema con il filter
 * `wmwp_grid_config`. È l'interfaccia della ricerca (search.js) in modalità griglia, con una source sola sulla rotta
 * `wmwp/v1/tracks`; la prima pagina arriva già dal server.
 *
 * @param array<string, string>|string $atts Attributi.
 */
function wmwp_render_tracks_grid( $atts ): string {
	$id = (string) ( shortcode_atts( [ 'id' => '' ], (array) $atts )['id'] );
	if ( wmwp_grid_rendered() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			trigger_error( 'wp-package: una griglia sola per pagina', E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return '';
	}
	wmwp_grid_rendered( true );
	$config = wmwp_grid_config( $id );
	$lang   = wmwp_current_lang();
	// Prima la copia: senza, anche il layer (che si ricava dalla copia) manca, e il rebuild va programmato subito invece di
	// aspettare il cron orario
	if ( null === wmwp_tracks_copy() ) {
		wmwp_detail_schedule_rebuild();
		return wmwp_grid_message( 'tracks' );
	}
	if ( null === $config || (int) ( $config['layer'] ?? 0 ) <= 0 ) {
		return wmwp_grid_message( 'tracks' );
	}
	$params = wmwp_search_params();
	$query  = array_map( fn( $value ) => is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$query['pg'] = $query[ $params['page'] ] ?? '1';
	$result      = wmwp_grid_tracks_result( $config, $query, $lang );
	if ( null === $result ) {
		wmwp_detail_schedule_rebuild();
		return wmwp_grid_message( 'tracks' );
	}
	// Testo che lo shard non sa cercare in questo momento: la prima pagina la disegna il JS, con il suo messaggio d'errore
	if ( is_wp_error( $result ) ) {
		$result = null;
	}
	$nouns   = (array) ( $config['nouns'] ?? [] );
	$sources = [
		[
			'id'       => 'grid',
			'slug'     => 'grid',
			'label'    => (string) ( $config['title'] ?? '' ),
			'nouns'    => $nouns,
			'per_page' => (int) ( $config['per_page'] ?? 9 ),
			'layout'   => 'cards',
			'script'   => 'wmwp-source-grid-tracks',
			'endpoint' => rest_url( 'wmwp/v1/tracks' ),
			'grid'     => $id,
			// Campo «cerca» della griglia (oc:9003): nome e suggerimento dal tema
			'search_label' => (string) ( $config['search_label'] ?? '' ),
			'placeholder'  => (string) ( $config['placeholder'] ?? '' ),
			'facets'   => wmwp_app_expand_facets( (array) ( $config['facets'] ?? [] ), $lang ),
		],
	];
	return wmwp_grid_render( $sources, $params, $result, $nouns, $lang );
}
add_shortcode( 'wmwp_tracks_grid', 'wmwp_render_tracks_grid' );

/**
 * Shortcode [wmwp_pois_grid id="…"]: griglia dei POI, con la source `pois` della ricerca (rotta `wmwp/v1/pois` e le sue
 * faccette: tipi e luoghi) in modalità griglia; il tema dà card per pagina e nomi del conteggio con `wmwp_grid_config`.
 *
 * @param array<string, string>|string $atts Attributi.
 */
function wmwp_render_pois_grid( $atts ): string {
	$id = (string) ( shortcode_atts( [ 'id' => '' ], (array) $atts )['id'] );
	if ( wmwp_grid_rendered() ) {
		return '';
	}
	wmwp_grid_rendered( true );
	$config = wmwp_grid_config( $id );
	$pois   = wmwp_search_sources()['pois'] ?? null;
	$lang   = wmwp_current_lang();
	if ( null === $config || ! is_array( $pois ) ) {
		return wmwp_grid_message( 'pois' );
	}
	$params   = wmwp_search_params();
	$per_page = max( 1, (int) ( $config['per_page'] ?? 12 ) );
	$nouns    = (array) ( $config['nouns'] ?? ( $pois['nouns'] ?? [] ) );
	$page     = min( WMWP_GRID_PAGE_MAX, max( 1, (int) ( $_GET[ $params['page'] ] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$text     = sanitize_text_field( wp_unslash( (string) ( $_GET[ $params['q'] ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$fetch    = function ( int $page ) use ( $lang, $per_page, $text ) {
		$request = new WP_REST_Request( 'GET', '/wmwp/v1/pois' );
		$request->set_query_params(
			array_filter(
				[
					'q'        => $text,
					'lang'     => $lang,
					'page'     => $page,
					'per_page' => $per_page,
				],
				fn( $value ) => '' !== $value
			)
		);
		return rest_do_request( $request );
	};
	$response = $fetch( $page );
	if ( $response->is_error() ) {
		return wmwp_grid_message( 'pois' );
	}
	$data  = (array) $response->get_data();
	$pages = max( 1, (int) ceil( (int) ( $data['total'] ?? 0 ) / $per_page ) );
	// Una pagina oltre l'ultima vale l'ultima, come per i percorsi
	if ( $page > $pages ) {
		$page     = $pages;
		$response = $fetch( $page );
		if ( $response->is_error() ) {
			return wmwp_grid_message( 'pois' );
		}
		$data = (array) $response->get_data();
	}
	$result = $data + [
		'page'  => $page,
		'pages' => $pages,
	];
	$has_filters = array_intersect_key( $_GET, array_flip( array_column( (array) ( $pois['facets'] ?? [] ), 'key' ) ) + [ 'layer' => 0 ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$source      = array_merge( $pois, [ 'slug' => 'grid', 'per_page' => $per_page, 'layout' => 'cards', 'nouns' => $nouns ], array_filter( [ 'search_label' => (string) ( $config['search_label'] ?? '' ), 'placeholder' => (string) ( $config['placeholder'] ?? '' ) ] ) );
	// Con un filtro nell'indirizzo il primo disegno lo fa il JS (la rotta riceve i filtri come la ricerca): il server
	// disegna solo la pagina senza filtri
	return wmwp_grid_render( [ $source ], $params, $has_filters ? null : $result, $nouns, $lang );
}
add_shortcode( 'wmwp_pois_grid', 'wmwp_render_pois_grid' );

/**
 * Interfaccia della ricerca in modalità griglia, con la prima pagina dal server (se c'è) e il risultato per il JS.
 *
 * @param list<array<string, mixed>>    $sources Source (una sola).
 * @param array<string, string>         $params  Parametri della ricerca.
 * @param array<string, mixed>|null     $result  Risultato iniziale.
 * @param array<string, string>         $nouns   Nomi del conteggio.
 */
function wmwp_grid_render( array $sources, array $params, ?array $result, array $nouns, string $lang ): string {
	wmwp_enqueue_search_assets(
		$sources,
		$params,
		[
			'grid'    => true,
			'initial' => $result,
		]
	);
	$wmwp = [
		'sources'      => $sources,
		'params'       => $params,
		'grid'         => true,
		'text'         => sanitize_text_field( wp_unslash( (string) ( $_GET[ $params['q'] ] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'search_label' => (string) ( $sources[0]['search_label'] ?? '' ) ?: null,
		// Suggerimento del campo già nell'HTML: senza JavaScript lo metterebbe solo search.js
		'placeholder'  => (string) ( $sources[0]['placeholder'] ?? '' ),
	];
	if ( null !== $result ) {
		$cards = array_map( fn( array $item ) => wmwp_grid_card_html( $item, $lang ), (array) $result['items'] );
		$wmwp['initial_html']   = '<ul class="wmwp-search__list wmwp-search__list--cards">' . implode( '', $cards ) . '</ul>';
		$wmwp['initial_status'] = wmwp_grid_count_text( (int) $result['total'], $nouns, $lang );
		$wmwp['initial_pager']  = wmwp_grid_pager_html( (int) $result['page'], (int) $result['pages'], $params['page'] );
	}
	ob_start();
	include wmwp_locate_template( 'search.php' );
	echo '<template id="wmwp-card">';
	include wmwp_locate_template( 'card.php' );
	echo '</template><template id="wmwp-row">';
	include wmwp_locate_template( 'row.php' );
	echo '</template>';
	return (string) ob_get_clean();
}
