<?php
/**
 * Sorgente «Punti di interesse» della ricerca unica (oc:9000).
 *
 * I POI vengono dallo stesso `pois.geojson` che l'indice dei dettagli scarica già: all'accettazione dell'indice
 * (`wmwp_detail_index_rebuild`) il plugin tiene una copia ridotta in un'opzione a parte (`wmwp_poi_search_copy`, senza
 * autoload), senza un secondo download né un cron nuovo. La rotta `GET wmwp/v1/pois` cerca lì, con pagine e faccetta dei
 * tipi calcolate sul server (un `pois.geojson` pesa 6 MB: il browser non lo scarica).
 *
 * Il tema può aggiungere alla source, dopo la faccetta dei tipi, faccette dei luoghi: `type` `list` con `field`
 * `taxonomyWheres` e le stesse chiavi `include`, `include_pattern`, `exclude`, `exclude_pattern` delle faccette dei
 * percorsi (search-logic.js), per dividere i luoghi in area, provincia, comune… La rotta le legge dalla source
 * (wmwp_search_sources()) e le riceve come `filter[<chiave>][]`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_POI_SEARCH_OPTION   = 'wmwp_poi_search_copy';
const WMWP_POI_SEARCH_FACET    = 'poi-type';
const WMWP_POI_SEARCH_MAX_Q    = 200;
const WMWP_POI_SEARCH_PER_MAX  = 48;
const WMWP_POI_SEARCH_PAGE_MAX = 10000;
const WMWP_POI_EXCERPT_LENGTH  = 160;

/** Testo per il confronto: minuscolo e senza accenti. */
function wmwp_poi_search_normalize( string $text ): string {
	return mb_strtolower( remove_accents( $text ), 'UTF-8' );
}

/** Testo cercato: ripulito dagli spazi ai lati e troncato a WMWP_POI_SEARCH_MAX_Q caratteri. */
function wmwp_poi_search_text( string $text ): string {
	return mb_substr( trim( $text ), 0, WMWP_POI_SEARCH_MAX_Q, 'UTF-8' );
}

/** Descrizione senza tag, con le entità decodificate e gli spazi compressi, nelle prime battute. */
function wmwp_poi_search_excerpt( string $html ): string {
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? '' );
	return mb_strlen( $text, 'UTF-8' ) > WMWP_POI_EXCERPT_LENGTH ? rtrim( mb_substr( $text, 0, WMWP_POI_EXCERPT_LENGTH, 'UTF-8' ) ) . '…' : $text;
}

/** Valore che lo shard può dare come array o come stringa JSON. */
function wmwp_poi_search_array( $value ): array {
	if ( is_string( $value ) ) {
		$value = json_decode( $value, true );
	}
	return is_array( $value ) ? $value : [];
}

/**
 * `searchable` di `pois.geojson` diviso per lingua. Lo shard lo scrive campo per campo (nome, descrizione… secondo
 * «POI Search In» in Nova) come oggetti senza virgolette, `{it:Testo,en:Text} {it:…}` (`EcPoi::searchableTranslations`).
 * Per ogni lingua il testo di ogni campo in quella lingua, con ripiego sull'italiano e poi sulla prima lingua del campo:
 * così su una pagina inglese un POI senza descrizione inglese si trova con quella italiana. Minuscolo, senza accenti e
 * senza i marcatori di lingua, che altrimenti farebbero trovare tutto cercando «it» o «en».
 *
 * Il formato non ha virgolette, quindi il testo può contenere graffe e «,xx:». Un campo finisce con la graffa seguita
 * dal campo successivo o dalla fine; dentro un campo separano le lingue solo `,<lingua>:` con le lingue del sito (e
 * italiano e inglese): «Partenza,da: Dorgali» resta testo italiano.
 *
 * @param array<string, mixed> $names Nome per lingua, cercato anche se `searchable` non lo comprende.
 * @return array<string, string> lingua → testo cercabile
 */
function wmwp_poi_search_by_lang( string $searchable, array $names ): array {
	$code   = '[a-z]{2}(?:-[a-z]{2,3})?';
	$known  = array_unique( array_merge( [ 'it', 'en' ], array_map( fn( $lang ) => strtolower( substr( $lang, 0, 2 ) ), wmwp_detail_languages() ) ) );
	$split  = '/,(' . implode( '|', array_map( fn( $lang ) => preg_quote( $lang, '/' ), $known ) ) . '):/u';
	$fields = [];
	preg_match_all( '/\{(' . $code . '):(.*?)\}(?=\s*\{' . $code . ':|\s*$)/us', $searchable, $groups, PREG_SET_ORDER );
	foreach ( $groups as $group ) {
		$parts = preg_split( $split, $group[2], -1, PREG_SPLIT_DELIM_CAPTURE );
		$field = [ $group[1] => (string) array_shift( $parts ) ];
		for ( $i = 0; $i + 1 < count( $parts ); $i += 2 ) {
			$field[ $parts[ $i ] ] = $parts[ $i + 1 ];
		}
		$fields[] = $field;
	}
	$name = array_filter( array_map( fn( $v ) => is_scalar( $v ) ? (string) $v : '', $names ), 'strlen' );
	if ( $name ) {
		$fields[] = $name;
	}
	$langs = [];
	foreach ( $fields as $field ) {
		$langs = array_merge( $langs, array_keys( $field ) );
	}
	$langs = array_values( array_unique( array_merge( [ 'it' ], $langs ) ) );
	$out   = [];
	foreach ( $langs as $lang ) {
		$parts = [];
		foreach ( $fields as $field ) {
			$parts[] = $field[ $lang ] ?? $field['it'] ?? reset( $field );
		}
		$out[ $lang ] = wmwp_poi_search_normalize( trim( html_entity_decode( implode( ' ', $parts ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
	return $out;
}

/**
 * Copia per la ricerca dalle feature di `pois.geojson`: id, nome e estratto per lingua, testo cercabile per lingua
 * (wmwp_poi_search_by_lang()), immagine, tipi e luoghi (`taxonomyWheres`, per le faccette dei luoghi). Un POI senza id
 * si salta.
 *
 * @param array<int, mixed> $features Feature di pois.geojson.
 * @return list<array{id: int, name: array, searchable: array<string, string>, excerpt: array, image: ?string, types: list<array>, wheres: list<string>}>
 */
function wmwp_poi_search_build( array $features ): array {
	$copy = [];
	foreach ( $features as $feature ) {
		$props = is_array( $feature ) ? (array) ( $feature['properties'] ?? [] ) : [];
		$id    = (int) ( $props['id'] ?? 0 );
		if ( $id <= 0 ) {
			continue;
		}
		$names = wmwp_poi_search_array( $props['name'] ?? [] );
		$names = [] === $names && is_string( $props['name'] ?? null ) ? [ 'it' => $props['name'] ] : $names;
		$descr = $props['description'] ?? [];
		$descr = is_string( $descr ) ? [ 'it' => $descr ] : wmwp_poi_search_array( $descr );
		$exc   = [];
		foreach ( $descr as $lang => $html ) {
			$excerpt = is_scalar( $html ) ? wmwp_poi_search_excerpt( (string) $html ) : '';
			if ( '' !== $excerpt ) {
				$exc[ $lang ] = $excerpt;
			}
		}
		$image = wmwp_poi_search_array( $props['feature_image'] ?? [] );
		$src   = $image['thumbnail'] ?? ( $image['sizes']['400x200'] ?? null );
		$types = [];
		foreach ( (array) ( wmwp_poi_search_array( $props['taxonomies'] ?? [] )['poi-type'] ?? [] ) as $term ) {
			if ( is_array( $term ) && '' !== (string) ( $term['identifier'] ?? '' ) ) {
				$types[] = [
					'identifier' => (string) $term['identifier'],
					'name'       => is_array( $term['name'] ?? null ) ? $term['name'] : [ 'it' => (string) ( $term['name'] ?? $term['identifier'] ) ],
				];
			}
		}
		$copy[] = [
			'id'         => $id,
			'name'       => $names,
			'searchable' => wmwp_poi_search_by_lang( is_scalar( $props['searchable'] ?? null ) ? (string) $props['searchable'] : '', $names ),
			'excerpt'    => $exc,
			'image'      => is_string( $src ) && '' !== $src ? $src : null,
			'types'      => $types,
			'wheres'     => array_values( array_unique( array_filter( array_map( fn( $where ) => is_scalar( $where ) ? trim( (string) $where ) : '', wmwp_poi_search_array( $props['taxonomyWheres'] ?? [] ) ), 'strlen' ) ) ),
		];
	}
	return $copy;
}

/**
 * Copia in attesa dell'accettazione dell'indice: `wmwp_detail_fetch_items()` la azzera all'inizio e la prepara dal
 * download di pois.geojson, `wmwp_detail_index_rebuild()` la scrive solo se l'indice nuovo viene accettato. Senza
 * argomenti la legge: null se non c'è (download non fatto o fallito), una lista, anche vuota, se lo shard ha risposto.
 *
 * @param array<int, mixed>|null $features Feature da preparare (anche nessuna: un'app senza POI ha una copia vuota).
 * @param bool                   $reset    Vero per azzerare la copia in attesa.
 * @return list<array>|null
 */
function wmwp_poi_search_pending( ?array $features = null, bool $reset = false ): ?array {
	static $pending = null;
	if ( $reset ) {
		$pending = null;
	} elseif ( null !== $features ) {
		$pending = wmwp_poi_search_build( $features );
	}
	return $pending;
}

/**
 * Scrive la copia in attesa (indice accettato), anche vuota: un'app senza POI risponde «0 risultati», non «non
 * disponibile». Un calo dei POI che fa scartare l'indice non arriva qui (controllo dell'indice sul calo sotto la metà).
 */
function wmwp_poi_search_commit(): void {
	$pending = wmwp_poi_search_pending();
	if ( null !== $pending ) {
		update_option( WMWP_POI_SEARCH_OPTION, $pending, false );
	}
}

/**
 * Programma la ricostruzione dell'indice, che riscrive la copia, il prima possibile: subito, o alla fine dell'attesa
 * fra due ricostruzioni (WMWP_DETAIL_REBUILD_INTERVAL), perché un evento girato prima la salterebbe. Una volta sola,
 * anche con molte richieste.
 */
function wmwp_poi_search_schedule_rebuild(): void {
	$when = max( time(), (int) get_option( WMWP_DETAIL_REBUILT_AT, 0 ) + WMWP_DETAIL_REBUILD_INTERVAL );
	$next = wp_next_scheduled( WMWP_DETAIL_CRON_HOOK );
	if ( false === $next || $next > $when + MINUTE_IN_SECONDS ) {
		wp_schedule_single_event( $when, WMWP_DETAIL_CRON_HOOK );
	}
}

/**
 * Faccette dei luoghi della source `pois` (`type` `list`, `field` `taxonomyWheres`), con la chiave che la source usa
 * nell'indirizzo.
 *
 * @return list<array<string, mixed>>
 */
function wmwp_poi_search_place_facets(): array {
	$facets = [];
	foreach ( (array) ( wmwp_search_sources()['pois']['facets'] ?? [] ) as $facet ) {
		if ( is_array( $facet ) && 'list' === ( $facet['type'] ?? '' ) && 'taxonomyWheres' === ( $facet['field'] ?? '' ) && '' !== (string) ( $facet['key'] ?? '' ) ) {
			$facets[] = $facet;
		}
	}
	return $facets;
}

/** Un luogo vale per la faccetta (include, include_pattern, exclude, exclude_pattern, come keepValue() di search-logic.js). */
function wmwp_poi_search_keep_place( array $facet, string $value ): bool {
	$match = fn( string $pattern ): bool => 1 === @preg_match( '#' . str_replace( '#', '\\#', $pattern ) . '#u', $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( isset( $facet['include'] ) && ! in_array( $value, (array) $facet['include'], true ) ) {
		return false;
	}
	if ( ! empty( $facet['include_pattern'] ) && ! $match( (string) $facet['include_pattern'] ) ) {
		return false;
	}
	if ( isset( $facet['exclude'] ) && in_array( $value, (array) $facet['exclude'], true ) ) {
		return false;
	}
	return ! ( ! empty( $facet['exclude_pattern'] ) && $match( (string) $facet['exclude_pattern'] ) );
}

add_action(
	'rest_api_init',
	function () {
		// I parametri arrivano dall'indirizzo: un valore che non è scalare (q[]=x) vale come assente, senza avvisi PHP
		$scalar = fn( $value, $default ) => is_scalar( $value ) ? $value : $default;
		register_rest_route(
			'wmwp/v1',
			'/pois',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'wmwp_poi_search_rest',
				'args'                => [
					'q'        => [
						'default'           => '',
						'sanitize_callback' => fn( $value ) => wmwp_poi_search_text( (string) $scalar( $value, '' ) ),
					],
					'page'     => [
						'default'           => 1,
						// Con un tetto: una pagina enorme farebbe un offset fuori dagli interi (errore fatale)
						'sanitize_callback' => fn( $value ) => min( WMWP_POI_SEARCH_PAGE_MAX, max( 1, (int) $scalar( $value, 1 ) ) ),
					],
					'per_page' => [
						'default'           => 12,
						'sanitize_callback' => fn( $value ) => min( WMWP_POI_SEARCH_PER_MAX, max( 1, (int) $scalar( $value, 12 ) ) ),
					],
					'lang'     => [
						'default'           => '',
						'sanitize_callback' => fn( $value ) => substr( sanitize_key( (string) $scalar( $value, '' ) ), 0, 5 ),
					],
					'poi_type' => [
						'default'           => [],
						'sanitize_callback' => fn( $value ) => array_values( array_filter( array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ), 'strlen' ) ),
					],
					// Filtri dei luoghi: filter[<chiave della faccetta>][]=valore; un filtro che non è una lista vale come assente
					'filter'   => [
						'default'           => [],
						'sanitize_callback' => function ( $value ) {
							$filters = [];
							foreach ( is_array( $value ) ? $value : [] as $key => $values ) {
								if ( is_array( $values ) ) {
									$filters[ (string) $key ] = array_values( array_filter( array_map( 'strval', array_filter( $values, 'is_scalar' ) ), 'strlen' ) );
								}
							}
							return $filters;
						},
					],
				],
			]
		);
	}
);

/** Risposta della rotta `wmwp/v1/pois`: { total, items, facets }. */
function wmwp_poi_search_rest( WP_REST_Request $request ) {
	$copy = get_option( WMWP_POI_SEARCH_OPTION, null );
	// Una copia scritta prima che il testo cercabile fosse per lingua (una stringa sola) vale come assente: la copia si
	// scrive tutta insieme, quindi basta guardare il primo POI
	$first = is_array( $copy ) ? reset( $copy ) : false;
	if ( ! is_array( $copy ) || ( false !== $first && ! is_array( $first['searchable'] ?? null ) ) ) {
		// Senza copia (subito dopo l'aggiornamento del plugin, o con un sito nuovo) la ricostruzione dell'indice, che la
		// scrive, parte subito invece che al giro orario del cron: una volta sola, anche con molte richieste
		wmwp_poi_search_schedule_rebuild();
		return new WP_Error( 'wmwp_poi_search_unavailable', __( 'Points of interest are not available right now.', 'wp-package' ), [ 'status' => 503 ] );
	}
	// Una copia senza i luoghi (scritta prima delle faccette dei luoghi) risponde, senza luoghi, e si riscrive subito
	if ( false !== $first && ! array_key_exists( 'wheres', $first ) ) {
		wmwp_poi_search_schedule_rebuild();
	}
	$lang   = (string) $request->get_param( 'lang' );
	$lang   = '' !== $lang ? $lang : wmwp_current_lang();
	$short  = strtolower( substr( $lang, 0, 2 ) );
	$text   = wmwp_poi_search_normalize( wmwp_poi_search_text( (string) $request->get_param( 'q' ) ) );
	$words  = '' === $text ? [] : preg_split( '/\s+/u', $text );
	$chosen = (array) $request->get_param( 'poi_type' );

	// Tutte le parole nel testo della lingua della pagina (con ripiego sull'italiano, campo per campo); prima chi le ha
	// nel nome della lingua della pagina
	$found = [];
	foreach ( $copy as $poi ) {
		$searchable = (array) ( $poi['searchable'] ?? [] );
		$haystack   = (string) ( $searchable[ $short ] ?? $searchable['it'] ?? '' );
		foreach ( $words as $word ) {
			if ( false === strpos( $haystack, $word ) ) {
				continue 2;
			}
		}
		$title  = wmwp_text( $poi['name'] ?? [], $lang );
		$normal = wmwp_poi_search_normalize( $title['text'] );
		$in     = ! empty( $words );
		foreach ( $words as $word ) {
			$in = $in && false !== strpos( $normal, $word );
		}
		$found[] = [
			'poi'   => $poi,
			'title' => $title,
			'rank'  => $in ? 0 : 1,
		];
	}
	usort(
		$found,
		function ( array $a, array $b ): int {
			return $a['rank'] <=> $b['rank'] ?: strnatcasecmp( $a['title']['text'], $b['title']['text'] ) ?: $a['poi']['id'] <=> $b['poi']['id'];
		}
	);

	// Valori di ogni POI per ogni faccetta: i tipi e, per le faccette dei luoghi, i luoghi che la faccetta tiene. OR
	// dentro un filtro, AND fra i filtri; ogni faccetta conta sui POI che passano gli altri filtri, non il proprio (come
	// computeFacets() di search-logic.js). Un POI con più valori conta in ognuno
	$places  = wmwp_poi_search_place_facets();
	$filters = (array) $request->get_param( 'filter' );
	$picked  = [ WMWP_POI_SEARCH_FACET => $chosen ];
	foreach ( $places as $facet ) {
		$picked[ (string) $facet['key'] ] = array_values( (array) ( $filters[ (string) $facet['key'] ] ?? [] ) );
	}
	foreach ( $found as $i => $row ) {
		$values = [ WMWP_POI_SEARCH_FACET => array_values( array_unique( array_map( 'strval', array_column( (array) $row['poi']['types'], 'identifier' ) ) ) ) ];
		foreach ( $places as $facet ) {
			$values[ (string) $facet['key'] ] = array_values( array_filter( array_map( 'strval', (array) ( $row['poi']['wheres'] ?? [] ) ), fn( $where ) => wmwp_poi_search_keep_place( $facet, $where ) ) );
		}
		$found[ $i ]['values'] = $values;
	}
	$passes = function ( array $row, string $skip ) use ( $picked ): bool {
		foreach ( $picked as $key => $wanted ) {
			if ( $key !== $skip && $wanted && ! array_intersect( $wanted, $row['values'][ $key ] ) ) {
				return false;
			}
		}
		return true;
	};
	$sort = function ( array &$values ): void {
		usort(
			$values,
			function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'] ?: strnatcasecmp( $a['label'], $b['label'] );
			}
		);
	};

	// Faccetta dei tipi. I tipi scelti restano anche con 0 risultati, con il loro nome, come fa search-logic.js per le
	// faccette dei percorsi
	$counts = [];
	$labels = [];
	foreach ( $found as $row ) {
		if ( ! $passes( $row, WMWP_POI_SEARCH_FACET ) ) {
			continue;
		}
		foreach ( (array) $row['poi']['types'] as $type ) {
			$key            = (string) $type['identifier'];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			$labels[ $key ] = wmwp_text( $type['name'] ?? [], $lang );
		}
	}
	foreach ( array_diff( $chosen, array_keys( $counts ) ) as $key ) {
		$counts[ $key ] = 0;
		$labels[ $key ] = [
			'text' => (string) $key,
			'lang' => null,
		];
		foreach ( $copy as $poi ) {
			foreach ( (array) ( $poi['types'] ?? [] ) as $type ) {
				if ( (string) $type['identifier'] === $key ) {
					$labels[ $key ] = wmwp_text( $type['name'] ?? [], $lang );
					break 2;
				}
			}
		}
	}
	$values = [];
	foreach ( $counts as $key => $count ) {
		$values[] = [
			'id'       => (string) $key,
			'label'    => '' !== $labels[ $key ]['text'] ? $labels[ $key ]['text'] : (string) $key,
			// Lingua del nome, per marcarlo con `lang` se ripiega su un'altra (WCAG 3.1.2)
			'lang'     => $labels[ $key ]['lang'],
			'count'    => $count,
			'selected' => in_array( (string) $key, $chosen, true ),
		];
	}
	$sort( $values );
	$facets = [
		[
			'key'      => WMWP_POI_SEARCH_FACET,
			'label'    => __( 'Type', 'wp-package' ),
			'disabled' => false,
			'values'   => $values,
		],
	];

	// Faccette dei luoghi: il valore è il nome del luogo, che fa anche da etichetta
	foreach ( $places as $facet ) {
		$key    = (string) $facet['key'];
		$counts = [];
		foreach ( $found as $row ) {
			if ( $passes( $row, $key ) ) {
				foreach ( $row['values'][ $key ] as $where ) {
					$counts[ $where ] = ( $counts[ $where ] ?? 0 ) + 1;
				}
			}
		}
		foreach ( $picked[ $key ] as $where ) {
			$counts[ $where ] = $counts[ $where ] ?? 0;
		}
		$values = [];
		foreach ( $counts as $where => $count ) {
			$values[] = [
				'id'       => (string) $where,
				'label'    => (string) $where,
				'lang'     => null,
				'count'    => $count,
				'selected' => in_array( (string) $where, $picked[ $key ], true ),
			];
		}
		$sort( $values );
		$facets[] = [
			'key'      => $key,
			'label'    => (string) ( $facet['label'] ?? $key ),
			'disabled' => false,
			'values'   => $values,
		];
	}

	$found = array_values( array_filter( $found, fn( array $row ): bool => $passes( $row, '' ) ) );
	$per_page = (int) $request->get_param( 'per_page' );
	$page     = (int) $request->get_param( 'page' );
	$template = wmwp_detail_link_template( 'poi' );
	$items    = [];
	foreach ( array_slice( $found, ( $page - 1 ) * $per_page, $per_page ) as $row ) {
		$poi     = $row['poi'];
		$excerpt = wmwp_text( $poi['excerpt'] ?? [], $lang );
		$items[] = [
			'id'        => $poi['id'],
			'title'     => $row['title']['text'],
			'url'       => str_replace( '{id}', (string) $poi['id'], $template ),
			'typeKey'   => 'poi',
			'typeLabel' => __( 'Point of interest', 'wp-package' ),
			'image'     => ! empty( $poi['image'] ) ? [ 'src' => $poi['image'], 'alt' => '' ] : null,
			'code'      => null,
			'meta'      => '' !== $excerpt['text'] ? [ $excerpt['text'] ] : [],
			// Lingua di titolo ed estratto, per marcarli con `lang` se ripiegano su un'altra lingua (WCAG 3.1.2)
			'titleLang' => $row['title']['lang'],
			'metaLang'  => $excerpt['lang'],
			'badge'     => null,
		];
	}
	return rest_ensure_response(
		[
			'total'  => count( $found ),
			'items'  => $items,
			'facets' => $facets,
		]
	);
}
