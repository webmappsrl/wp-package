<?php
/**
 * Test (oc:9000): letture del dettaglio dei dati che nascono in wm-package (includes/detail-data.php) — testo nella
 * lingua con ripiego, Blocchi Dettaglio, galleria, POI correlati, dati tecnici, luoghi, tipi del POI. Proprietà ridotte
 * da quelle del dev (tracce 99 e 42, POI 11, 18/11). Il caso vuoto non dà avvisi PHP.
 * Si lancia con: wp eval-file tests/php/detail-data.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$failures = [];
$check    = function ( bool $ok, string $what ) use ( &$failures ) {
	if ( ! $ok ) {
		$failures[] = $what;
	}
};
set_error_handler(
	function ( $errno, $message, $file, $line ) use ( &$failures ) {
		$failures[] = "avviso PHP: $message ($file:$line)";
		return true;
	}
);

// wmwp_text(): lingua della pagina, poi italiano, poi la prima lingua non vuota; liste unite con l'a capo
$check( [ 'text' => 'Ciao', 'lang' => 'it' ] === wmwp_text( [ 'en' => '', 'it' => 'Ciao' ], 'en' ), 'ripiego sull\'italiano' );
$check( [ 'text' => 'Hello', 'lang' => 'en' ] === wmwp_text( [ 'en' => ' Hello ', 'it' => 'Ciao' ], 'en' ), 'lingua della pagina' );
$check( [ 'text' => 'Dorgali', 'lang' => 'en' ] === wmwp_text( [ 'en' => 'Dorgali' ], 'it' ), 'prima lingua non vuota' );
$check( [ 'text' => 'Hello', 'lang' => 'en' ] === wmwp_text( [ 'it' => 'Ciao', 'en' => 'Hello' ], 'en-us' ), 'codice con la regione' );
$check( [ 'text' => 'Testo', 'lang' => null ] === wmwp_text( ' Testo ', 'it' ), 'stringa senza lingua' );
$check( [ 'text' => "Uno\nDue", 'lang' => 'it' ] === wmwp_text( [ 'it' => [ 'Uno', ' ', 'Due' ] ], 'it' ), 'lista unita' );
$check( [ 'text' => '', 'lang' => null ] === wmwp_text( null, 'it' ), 'valore assente' );

$track = [
	'distance'         => 12.5,
	'ascent'           => '430',
	'descent'          => 0,
	'ele_min'          => null,
	'ele_max'          => 'n.d.',
	'duration_forward' => 210,
	'config_detail'    => [
		[ 'box_type' => 'gallery' ],
		[
			'box_type' => 'info',
			'items'    => [
				[
					'title'   => [ 'it' => 'Tappa 1', 'en' => 'Stage 1' ],
					'content' => [ 'it' => '<p>Da Buggerru</p>' ],
				],
				[
					'title'   => [ 'it' => 'Vuota' ],
					'content' => [ 'it' => '<p> </p>' ],
				],
			],
		],
	],
	'image_gallery'    => [
		[
			'url'       => 'https://example.org/a.jpg',
			'sizes'     => [ '400x200' => 'https://example.org/a-400.jpg' ],
			'caption'   => [ 'it' => 'Il ponte' ],
			'author'    => 'Mario Rossi',
			'credits'   => [ 'it' => 'Forestas' ],
		],
		[ 'url' => '' ],
		[
			'url'       => 'https://example.org/b.jpg',
			'thumbnail' => 'https://example.org/b-t.jpg',
		],
	],
	'feature_image'    => [
		'url'     => 'https://example.org/f.jpg',
		'sizes'   => [ '1440x500' => 'https://example.org/f-1440.jpg' ],
		'caption' => [ 'it' => 'Il sentiero', 'en' => 'The trail' ],
		'author'  => 'Anna',
	],
	'related_pois'     => [
		[
			'geometry'   => [ 'type' => 'Point', 'coordinates' => [ 9.1, '40.2', 300 ] ],
			'properties' => [
				'id'            => 11,
				'name'          => [ 'it' => 'Cascata Mularza Noa', 'en' => 'Mularza Noa Waterfall' ],
				'taxonomies'    => [ 'poi-type' => [ [ 'identifier' => 'waterway-waterfall', 'name' => [ 'it' => 'Cascata' ] ] ] ],
				// Formato vecchio: vince il nuovo, come in wmwp_detail_poi_types()
				'taxonomy'      => [ 'poi_type' => [ 'name' => [ 'it' => 'Vecchio tipo' ] ] ],
				'feature_image' => [ 'thumbnail' => 'https://example.org/c.jpg' ],
			],
		],
		[ 'properties' => [ 'id' => 0 ] ],
	],
	'taxonomies'       => [
		'where' => [
			[
				'identifier' => '80',
				'name'       => [ 'it' => 'Area B (Centro-EST)', 'en' => 'Area B (Centro-EST)' ],
			],
			[
				'identifier'  => 'R39979',
				'name'        => [ 'en' => 'Nuoro' ],
				'admin_level' => 6,
			],
			[
				'identifier'  => 'R19621461',
				'name'        => [ 'en' => 'R19621461' ],
				'admin_level' => 8,
			],
		],
	],
];

// Blocchi Dettaglio: solo `info`, righe con testo, valore del contenuto come nello shard
$blocks = wmwp_detail_blocks( $track, 'en' );
$check( 1 === count( $blocks ), 'una riga con testo' );
$check( 'Stage 1' === ( $blocks[0]['title'] ?? '' ) && 'en' === ( $blocks[0]['title_lang'] ?? '' ), 'titolo nella lingua' );
$check( [ 'it' => '<p>Da Buggerru</p>' ] === ( $blocks[0]['content'] ?? null ), 'contenuto per lingua' );

// Galleria: foto senza URL saltata, miniatura 400x200 poi thumbnail poi l'URL
$gallery = wmwp_detail_gallery( $track, 'it' );
$check( 2 === count( $gallery ), 'due foto' );
$check( 'https://example.org/a-400.jpg' === ( $gallery[0]['thumbnail'] ?? '' ), 'miniatura 400x200' );
$check( 'Il ponte' === ( $gallery[0]['caption'] ?? '' ) && 'Mario Rossi' === ( $gallery[0]['author'] ?? '' ) && 'Forestas' === ( $gallery[0]['credits'] ?? '' ), 'didascalia, autore, credits' );
$check( 'https://example.org/b-t.jpg' === ( $gallery[1]['thumbnail'] ?? '' ) && '' === ( $gallery[1]['caption'] ?? null ), 'thumbnail e testi vuoti' );

// POI correlati: id 0 saltato
$related = wmwp_detail_related_pois( $track, 'en' );
$check( 1 === count( $related ), 'un POI correlato' );
$check( [ 'id' => 11, 'name' => 'Mularza Noa Waterfall', 'type' => 'Cascata', 'image' => 'https://example.org/c.jpg', 'coordinates' => [ 9.1, 40.2 ] ] === ( $related[0] ?? null ), 'dati del POI correlato' );
$no_point = wmwp_detail_related_pois( [ 'related_pois' => [ [ 'geometry' => [ 'type' => 'LineString' ], 'properties' => [ 'id' => 3 ] ] ] ], 'it' );
$check( isset( $no_point[0] ) && array_key_exists( 'coordinates', $no_point[0] ) && null === $no_point[0]['coordinates'], 'senza punto nessuna posizione' );

// Foto principale: grande 1440x500 o l'immagine, testi nella lingua
$check( [ 'url' => 'https://example.org/f.jpg', 'large' => 'https://example.org/f-1440.jpg', 'caption' => 'The trail', 'author' => 'Anna', 'credits' => '' ] === wmwp_detail_feature_image( $track, 'en' ), 'foto principale' );
$check( null === wmwp_detail_feature_image( [], 'it' ) && null === wmwp_detail_feature_image( [ 'feature_image' => [ 'url' => '' ] ], 'it' ), 'senza foto principale' );

// Dati tecnici: numeri (anche in stringa) come float, il resto null
$stats = wmwp_detail_stats( $track );
$check( 12.5 === $stats['distance'] && 430.0 === $stats['ascent'] && 0.0 === $stats['descent'], 'numeri come float' );
$check( null === $stats['ele_min'] && null === $stats['ele_max'] && 210.0 === $stats['duration_forward'], 'assenti e non numerici null' );

// Luoghi: nomi uguali all'identifier scartati, livello amministrativo
$places = wmwp_detail_places( $track, 'it' );
$check( [ [ 'identifier' => '80', 'name' => 'Area B (Centro-EST)', 'admin_level' => null ], [ 'identifier' => 'R39979', 'name' => 'Nuoro', 'admin_level' => 6 ] ] === $places, 'luoghi della traccia' );

// Luoghi dei POI da `taxonomy_where`, con `_admin_level` e le chiavi che iniziano con `_` scartate
$poi = [
	'taxonomy_where' => [
		'85'     => [ 'en' => 'Area G (Nord-EST)', 'it' => 'Area G (Nord-EST)', '_source' => 'geohub_conf_32' ],
		'R40376' => [ 'en' => 'Bolotana', '_source' => 'osmfeatures', '_admin_level' => 8 ],
	],
	'taxonomies'     => [ 'poi-type' => [ [ 'identifier' => 'crossroads', 'name' => [ 'it' => 'Incroci - waypoint' ] ], [ 'identifier' => 'tlc', 'name' => [ 'it' => 'Visibilità TLC', 'en' => 'TLC visibility' ] ] ] ],
];
$check( [ [ 'identifier' => '85', 'name' => 'Area G (Nord-EST)', 'admin_level' => null ], [ 'identifier' => 'R40376', 'name' => 'Bolotana', 'admin_level' => 8 ] ] === wmwp_detail_places( $poi, 'it', 'taxonomy_where' ), 'luoghi del POI' );

// Tipi del POI, nella lingua con ripiego
$types = wmwp_detail_poi_types( $poi, 'en' );
$check( [ [ 'identifier' => 'crossroads', 'name' => 'Incroci - waypoint', 'lang' => 'it' ], [ 'identifier' => 'tlc', 'name' => 'TLC visibility', 'lang' => 'en' ] ] === $types, 'tipi del POI' );
$check( [ [ 'identifier' => '', 'name' => 'Rifugio', 'lang' => 'it' ] ] === wmwp_detail_poi_types( [ 'taxonomy' => [ 'poi_type' => [ 'name' => [ 'it' => 'Rifugio' ] ] ] ], 'it' ), 'tipo dal formato vecchio' );

// Caso vuoto: liste vuote e null, nessun avviso
$check( [] === wmwp_detail_blocks( [], 'it' ) && [] === wmwp_detail_gallery( [], 'it' ) && [] === wmwp_detail_related_pois( [], 'it' ), 'vuoto: liste vuote' );
$check( [] === wmwp_detail_places( [], 'it' ) && [] === wmwp_detail_poi_types( [], 'it' ), 'vuoto: luoghi e tipi' );
$check( [ 'distance' => null, 'ascent' => null, 'descent' => null, 'ele_min' => null, 'ele_max' => null, 'duration_forward' => null ] === wmwp_detail_stats( [] ), 'vuoto: dati tecnici' );
$check( [] === wmwp_detail_blocks( [ 'config_detail' => 'x' ], 'it' ) && [] === wmwp_detail_gallery( [ 'image_gallery' => 'x' ], 'it' ), 'valori di tipo sbagliato' );

restore_error_handler();
if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
