<?php
/**
 * Pagine di dettaglio (oc:9000): letture dei dati che nascono in wm-package, pronte per il tema.
 *
 * Regola: ciò che legge un dato costruito da wm-package (Blocchi Dettaglio, galleria con i crediti, POI correlati, dati
 * tecnici, zone `where`, tipi dei POI) sta nel plugin; il tema ne fa solo la grafica e le regole sue (per esempio quali
 * zone mostrare). Le funzioni restituiscono dati, mai markup, e non hanno testi visibili.
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Testo di un valore dello shard nella lingua della pagina: prima `$lang`, poi l'italiano, poi la prima lingua non
 * vuota (nello shard alcuni nomi hanno solo `en`). Una lista per lingua (roadbook, informazioni utili) si unisce con un
 * a capo. `lang` è la lingua da cui viene il testo, null per un valore senza lingue: serve a marcare con `lang` un
 * testo che ripiega su un'altra lingua (WCAG 3.1.2).
 *
 * @param mixed $value Stringa, o array per lingua di stringhe o di liste.
 * @return array{text: string, lang: ?string}
 */
function wmwp_text( $value, string $lang ): array {
	if ( is_string( $value ) ) {
		return [
			'text' => trim( $value ),
			'lang' => null,
		];
	}
	if ( is_array( $value ) ) {
		// I codici di WPML possono avere la regione (en-us): i testi dello shard stanno sotto le due lettere
		foreach ( array_merge( [ $lang, strtolower( substr( $lang, 0, 2 ) ), 'it' ], array_keys( $value ) ) as $key ) {
			$text = $value[ $key ] ?? null;
			if ( is_array( $text ) ) {
				$text = implode( "\n", array_filter( array_map( 'trim', array_filter( $text, 'is_string' ) ) ) );
			}
			$text = is_scalar( $text ) ? trim( (string) $text ) : '';
			if ( '' !== $text ) {
				return [
					'text' => $text,
					'lang' => (string) $key,
				];
			}
		}
	}
	return [
		'text' => '',
		'lang' => null,
	];
}

/**
 * Righe dei Blocchi Dettaglio (`config_detail`, oc:8959): tutte le righe dei blocchi `info`, nell'ordine, come le mostra
 * la webapp; le righe senza testo si saltano. Il contenuto resta il valore per lingua dello shard (HTML), perché il tema
 * lo prepara a modo suo (titoli, lingua).
 *
 * @param array<string, mixed> $properties properties del JSON della traccia.
 * @return list<array{title: string, title_lang: ?string, content: mixed}>
 */
function wmwp_detail_blocks( array $properties, string $lang ): array {
	$rows   = [];
	$blocks = $properties['config_detail'] ?? null;
	foreach ( is_array( $blocks ) ? $blocks : [] as $block ) {
		if ( ! is_array( $block ) || 'info' !== ( $block['box_type'] ?? null ) || ! is_array( $block['items'] ?? null ) ) {
			continue;
		}
		foreach ( $block['items'] as $item ) {
			if ( ! is_array( $item ) || '' === trim( wp_strip_all_tags( wmwp_text( $item['content'] ?? null, $lang )['text'] ) ) ) {
				continue;
			}
			$title  = wmwp_text( $item['title'] ?? null, $lang );
			$rows[] = [
				'title'      => $title['text'],
				'title_lang' => $title['lang'],
				'content'    => $item['content'],
			];
		}
	}
	return $rows;
}

/**
 * Foto della galleria (`image_gallery`, `MediaResource` di wm-package): immagine intera, miniatura (400x200, poi
 * `thumbnail`, poi l'immagine intera) e i testi che Nova scrive su ogni foto, nella lingua della pagina.
 *
 * @param array<string, mixed> $properties properties del JSON.
 * @return list<array{url: string, thumbnail: string, caption: string, author: string, credits: string}>
 */
function wmwp_detail_gallery( array $properties, string $lang ): array {
	$photos  = [];
	$gallery = $properties['image_gallery'] ?? null;
	foreach ( is_array( $gallery ) ? $gallery : [] as $image ) {
		$url = is_array( $image ) ? (string) ( $image['url'] ?? '' ) : '';
		if ( '' === $url ) {
			continue;
		}
		$photos[] = [
			'url'       => $url,
			'thumbnail' => (string) ( $image['sizes']['400x200'] ?? $image['thumbnail'] ?? $url ),
			'caption'   => wmwp_text( $image['caption'] ?? null, $lang )['text'],
			'author'    => wmwp_text( $image['author'] ?? null, $lang )['text'],
			'credits'   => wmwp_text( $image['credits'] ?? null, $lang )['text'],
		];
	}
	return $photos;
}

/**
 * Foto principale (`feature_image`, `MediaResource` di wm-package): immagine intera, versione grande per la testata
 * (1440x500, se no l'immagine intera) e i testi che Nova scrive sulla foto, nella lingua della pagina. Null senza foto.
 *
 * @param array<string, mixed> $properties properties del JSON.
 * @return array{url: string, large: string, caption: string, author: string, credits: string}|null
 */
function wmwp_detail_feature_image( array $properties, string $lang ): ?array {
	$image = $properties['feature_image'] ?? null;
	$url   = is_array( $image ) ? (string) ( $image['url'] ?? '' ) : '';
	if ( '' === $url ) {
		return null;
	}
	return [
		'url'     => $url,
		'large'   => (string) ( $image['sizes']['1440x500'] ?? $url ),
		'caption' => wmwp_text( $image['caption'] ?? null, $lang )['text'],
		'author'  => wmwp_text( $image['author'] ?? null, $lang )['text'],
		'credits' => wmwp_text( $image['credits'] ?? null, $lang )['text'],
	];
}

/**
 * POI correlati della traccia (`related_pois`): id, nome e tipo nella lingua della pagina (il primo di
 * wmwp_detail_poi_types()), miniatura; `coordinates` è la posizione [lon, lat] quando la geometria è un punto, per la
 * mappa. L'indirizzo lo aggiunge il tema con `wmwp_detail_url()`.
 *
 * @param array<string, mixed> $properties properties del JSON della traccia.
 * @return list<array{id: int, name: string, type: ?string, image: ?string, coordinates: ?array{0: float, 1: float}}>
 */
function wmwp_detail_related_pois( array $properties, string $lang ): array {
	$pois    = [];
	$related = $properties['related_pois'] ?? null;
	foreach ( is_array( $related ) ? $related : [] as $feature ) {
		$poi = is_array( $feature ) ? (array) ( $feature['properties'] ?? [] ) : [];
		$id  = (int) ( $poi['id'] ?? 0 );
		if ( $id <= 0 ) {
			continue;
		}
		$type   = wmwp_detail_poi_types( $poi, $lang )[0]['name'] ?? '';
		$image  = $poi['feature_image']['thumbnail'] ?? null;
		$point  = $feature['geometry']['coordinates'] ?? null;
		$pois[] = [
			'id'          => $id,
			'name'        => wmwp_text( $poi['name'] ?? null, $lang )['text'],
			'type'        => '' !== $type ? $type : null,
			'image'       => is_string( $image ) ? $image : null,
			'coordinates' => 'Point' === ( $feature['geometry']['type'] ?? '' ) && is_array( $point ) && isset( $point[0], $point[1] )
				? [ (float) $point[0], (float) $point[1] ]
				: null,
		];
	}
	return $pois;
}

/**
 * Dati tecnici della traccia (`EcTrackResource` e dati manuali di wm-package): lunghezza in km, dislivelli e quote in
 * metri, tempo di andata in minuti. Un valore assente o non numerico è null; come mostrare lo zero lo decide il tema.
 *
 * @param array<string, mixed> $properties properties del JSON della traccia.
 * @return array{distance: ?float, ascent: ?float, descent: ?float, ele_min: ?float, ele_max: ?float, duration_forward: ?float}
 */
function wmwp_detail_stats( array $properties ): array {
	$stats = [];
	foreach ( [ 'distance', 'ascent', 'descent', 'ele_min', 'ele_max', 'duration_forward' ] as $key ) {
		$value         = $properties[ $key ] ?? null;
		$stats[ $key ] = is_numeric( $value ) ? (float) $value : null;
	}
	return $stats;
}

/**
 * Zone `where` del contenuto (`DetailTaxonomiesService` di wm-package): nome nella lingua della pagina e livello
 * amministrativo (8 comune, 6 provincia, 4 regione; null per aree e zone senza livello). I nomi uguali all'identifier
 * (nello shard alcune zone si chiamano «R19621461») non contano. Quali zone mostrare lo decide il tema.
 *
 * @param array<string, mixed> $properties properties del JSON.
 * @param string               $source     `taxonomies` (`taxonomies.where`, elenco) o `taxonomy_where` (oggetto id →
 *                                         nomi, con `_admin_level`), nell'ordine in cui lo shard le scrive.
 * @return list<array{identifier: string, name: string, admin_level: ?int}>
 */
function wmwp_detail_places( array $properties, string $lang, string $source = 'taxonomies' ): array {
	$entries = [];
	if ( 'taxonomy_where' === $source ) {
		$map = $properties['taxonomy_where'] ?? null;
		foreach ( is_array( $map ) ? $map : [] as $id => $names ) {
			$names     = (array) $names;
			$entries[] = [
				'identifier'  => (string) $id,
				'name'        => array_filter( $names, fn( $v, $k ) => is_string( $v ) && '_' !== substr( (string) $k, 0, 1 ), ARRAY_FILTER_USE_BOTH ),
				'admin_level' => $names['_admin_level'] ?? null,
			];
		}
	} else {
		$where = $properties['taxonomies']['where'] ?? null;
		foreach ( is_array( $where ) ? $where : [] as $place ) {
			if ( ! is_array( $place ) ) {
				continue;
			}
			$entries[] = [
				'identifier'  => (string) ( $place['identifier'] ?? '' ),
				'name'        => (array) ( $place['name'] ?? [] ),
				'admin_level' => $place['admin_level'] ?? $place['_admin_level'] ?? null,
			];
		}
	}
	$places = [];
	foreach ( $entries as $entry ) {
		$identifier = $entry['identifier'];
		$names      = array_filter( $entry['name'], fn( $v ) => is_string( $v ) && $v !== $identifier );
		$name       = wmwp_text( $names, $lang )['text'];
		if ( '' === $name ) {
			continue;
		}
		$level    = $entry['admin_level'];
		$places[] = [
			'identifier'  => $identifier,
			'name'        => $name,
			'admin_level' => is_numeric( $level ) && (int) $level > 0 ? (int) $level : null,
		];
	}
	return $places;
}

/**
 * Tipi del POI (`poi-type`), nella lingua della pagina con la lingua da cui viene il nome; dal formato vecchio
 * (`taxonomy.poi_type`, un tipo solo senza identifier) se il nuovo manca.
 *
 * @param array<string, mixed> $properties properties del JSON del POI.
 * @return list<array{identifier: string, name: string, lang: ?string}>
 */
function wmwp_detail_poi_types( array $properties, string $lang ): array {
	$terms = $properties['taxonomies']['poi-type'] ?? null;
	if ( ! is_array( $terms ) || ! $terms ) {
		$old   = $properties['taxonomy']['poi_type'] ?? null;
		$terms = is_array( $old ) ? [ $old ] : [];
	}
	$types = [];
	foreach ( $terms as $term ) {
		$name = is_array( $term ) ? wmwp_text( $term['name'] ?? null, $lang ) : [ 'text' => '' ];
		if ( '' === $name['text'] ) {
			continue;
		}
		$types[] = [
			'identifier' => (string) ( $term['identifier'] ?? '' ),
			'name'       => $name['text'],
			'lang'       => $name['lang'],
		];
	}
	return $types;
}
