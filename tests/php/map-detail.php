<?php
/**
 * Test (oc:8969): `wmwp_map()`, la mappa del dettaglio per i temi. Tracce con lo stile di ciascuna (colore, spessore,
 * tratteggio, sfondo, etichetta, tooltip, link) e punti con colore, numero, tooltip e link finiscono nelle opzioni della
 * mappa (`data-wmwp-map`, `detail`), con gli id da leggere da `api/ec/track/multiple`. I valori non validi si scartano.
 * Si lancia con: wp eval-file tests/php/map-detail.php
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

if ( ! function_exists( 'wmwp_map' ) ) {
	echo "FAIL: wmwp_map non esiste\n";
	exit( 1 );
}

$options = function ( string $html ): array {
	if ( ! preg_match( '/data-wmwp-map="([^"]*)"/', $html, $m ) ) {
		return [];
	}
	return (array) json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
};

$html = wmwp_map(
	[
		'tracks' => [
			[ 'id' => 6, 'color' => '#5B7A99', 'width' => 2.5, 'dash' => [ 7, 5 ], 'context' => true, 'tooltip' => 'C 104 · Monte', 'url' => 'http://example.test/sentiero/monte/' ],
			[ 'id' => 5, 'color' => '#DE1B0D', 'width' => 4, 'label' => 'C 104 A' ],
			[ 'id' => 0 ],
			[ 'id' => 7, 'color' => 'url(javascript:x)' ],
		],
		'points' => [
			[ 'coordinates' => [ 9.52, 39.87 ], 'color' => '#B7127F', 'number' => 1, 'tooltip' => 'Tavolo <b>', 'url' => 'javascript:alert(1)' ],
			[ 'coordinates' => [ 'x', 39 ] ],
		],
		'height' => 460,
		'label'  => 'Mappa del percorso',
	]
);
$o    = $options( $html );
$check( false !== strpos( $html, 'class="wmwp-map-embed"' ) && false !== strpos( $html, 'aria-label="Mappa del percorso"' ), 'contenitore della mappa con il nome' );
$check( 460 === ( $o['height'] ?? null ), 'altezza' );
$check( [ 6, 5, 7 ] === ( $o['tracks'] ?? null ) && '' !== ( $o['download_url'] ?? '' ), 'id da leggere dall\'API, senza quelli non validi' );
$tracks = $o['detail']['tracks'] ?? [];
$check( 3 === count( $tracks ), 'tre tracce nel dettaglio' );
$check( [ 'id' => 6, 'color' => '#5B7A99', 'width' => 2.5, 'dash' => [ 7, 5 ], 'context' => true, 'label' => '', 'tooltip' => 'C 104 · Monte', 'url' => 'http://example.test/sentiero/monte/' ] === ( $tracks[0] ?? null ), 'traccia collegata con il suo stile' );
$check( '' === ( $tracks[2]['color'] ?? 'x' ), 'colore non valido scartato' );
$points = $o['detail']['points'] ?? [];
$check( 1 === count( $points ), 'punti senza coordinate valide scartati' );
$check( [ 9.52, 39.87 ] === ( $points[0]['coordinates'] ?? null ) && '1' === ( $points[0]['number'] ?? '' ) && '#B7127F' === ( $points[0]['color'] ?? '' ), 'punto con coordinate, numero e colore' );
$check( 'Tavolo' === ( $points[0]['tooltip'] ?? '' ) && '' === ( $points[0]['url'] ?? 'x' ), 'tooltip senza tag e link non http scartato' );

// Partenza e arrivo (`ends`): etichette del tema ripulite, o quelle del plugin con `true`; senza, la chiave non c'è
$ends = $options(
	wmwp_map(
		[
			'tracks' => [
				[ 'id' => 5, 'ends' => [ 'start' => 'Partenza <b>', 'end' => 'Arrivo' ] ],
				[ 'id' => 6, 'ends' => true ],
				[ 'id' => 7 ],
			],
		]
	)
);
$check( [ 'start' => 'Partenza', 'end' => 'Arrivo' ] === ( $ends['detail']['tracks'][0]['ends'] ?? null ), 'partenza e arrivo con le etichette del tema' );
$check( '' !== ( $ends['detail']['tracks'][1]['ends']['start'] ?? '' ) && '' !== ( $ends['detail']['tracks'][1]['ends']['end'] ?? '' ), 'partenza e arrivo con le etichette del plugin' );
$check( ! array_key_exists( 'ends', $ends['detail']['tracks'][2] ?? [] ), 'senza ends niente partenza e arrivo' );

// Solo punti: va bene (la mappa di un POI senza percorsi vicini); niente di niente: nessuna mappa
$solo = $options( wmwp_map( [ 'points' => [ [ 'coordinates' => [ 9, 40 ] ] ] ] ) );
$check( [] === ( $solo['tracks'] ?? null ) && 1 === count( $solo['detail']['points'] ?? [] ), 'mappa con i soli punti' );
$check( '' === wmwp_map( [] ), 'senza tracce né punti, niente mappa' );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
