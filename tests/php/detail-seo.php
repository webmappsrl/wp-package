<?php
/**
 * Test (oc:8969): meta del dettaglio. Titolo dal nome, descrizione dal testo senza HTML tagliata a 160 caratteri
 * sulla parola, immagine dalla foto principale, URL quello del dettaglio; il filtro `wmwp_detail_meta` li cambia.
 * Si lancia con: wp eval-file tests/php/detail-seo.php
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

if ( ! function_exists( 'wmwp_detail_meta' ) ) {
	echo "FAIL: wmwp_detail_meta non esiste\n";
	exit( 1 );
}

$long   = str_repeat( 'Sentiero panoramico lungo la costa ', 10 );
$detail = [
	'type'    => 'track',
	'id'      => 5,
	'lang'    => 'it',
	'path'    => 'sentiero/salita',
	'name'    => 'Salita',
	'url'     => 'http://example.test/sentiero/salita/',
	'urls'    => [ 'it' => 'http://example.test/sentiero/salita/' ],
	'feature' => [
		'properties' => [
			'description'   => [ 'it' => '<p><strong>' . $long . '</strong></p>' ],
			'feature_image' => [ 'url' => 'http://example.test/foto.jpg' ],
		],
	],
];

$meta = wmwp_detail_meta( $detail );
$check( 'Salita' === $meta['title'], 'titolo dal nome' );
$check( false === strpos( $meta['description'], '<' ), 'descrizione senza HTML' );
$check( mb_strlen( $meta['description'] ) <= 161, 'descrizione al massimo 160 caratteri più i puntini' );
$check( 0 === strpos( $long, rtrim( $meta['description'], '…' ) ), 'taglio sulla parola, dall\'inizio del testo' );
$check( 'http://example.test/foto.jpg' === $meta['image'], 'immagine dalla foto principale' );
$check( 'http://example.test/sentiero/salita/' === $meta['url'], 'URL del dettaglio' );

// Descrizione assente: stringa vuota, nessun avviso
$detail['feature']['properties'] = [];
$meta                            = wmwp_detail_meta( $detail );
$check( '' === $meta['description'] && '' === $meta['image'], 'dati assenti → vuoti' );

// Il filtro cambia i meta
add_filter(
	'wmwp_detail_meta',
	function ( array $meta ): array {
		$meta['title'] = 'Altro';
		return $meta;
	}
);
$check( 'Altro' === wmwp_detail_meta( $detail )['title'], 'filtro wmwp_detail_meta' );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
