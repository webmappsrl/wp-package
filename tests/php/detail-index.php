<?php
/**
 * Test (oc:8969): slug e indice dei dettagli. Il nome nella lingua con ripiego sull'italiano, lo slug che non resta
 * mai vuoto, i doppioni con `-<id>` sui soli elementi in conflitto (nella stessa base e lingua) e gli indirizzi per
 * lingua. Si lancia con: wp eval-file tests/php/detail-index.php
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

if ( ! function_exists( 'wmwp_detail_build_index' ) ) {
	echo "FAIL: wmwp_detail_build_index non esiste\n";
	exit( 1 );
}

// Base finta: il nome del tipo nella lingua, come farà wmwp_detail_base() con l'interruttore spento
$base = function ( string $type, ?array $kind, string $lang ): string {
	$name = null === $kind ? '' : wmwp_detail_text( $kind, $lang );
	return '' !== $name ? sanitize_title( $name ) : ( 'poi' === $type ? 'poi' : 'track' );
};

// 1. Il nome nella lingua: spazi e tabulazioni non contano, l'italiano fa da ripiego
$check( 'Salita' === wmwp_detail_text( [ 'it' => "\tSalita\t", 'en' => '' ], 'en' ), 'nome inglese vuoto → italiano ripulito' );
$check( 'Climb' === wmwp_detail_text( [ 'it' => 'Salita', 'en' => ' Climb ' ], 'en' ), 'nome inglese presente' );
$check( 'Solo' === wmwp_detail_text( 'Solo', 'en' ), 'una stringa vale per tutte le lingue' );
$check( '' === wmwp_detail_text( null, 'it' ), 'niente nome → stringa vuota' );
// Codici di WPML con la regione (en-us): i nomi dell'API sono sotto le due lettere
$check( 'Climb' === wmwp_detail_text( [ 'it' => 'Salita', 'en' => 'Climb' ], 'en-us' ), 'codice WPML en-us → nome en' );

// 2. Lo slug non è mai vuoto
$check( '77' === wmwp_detail_slug( '— !!', 77 ), 'nome senza lettere → id' );
$check( 'monte-corrasi' === wmwp_detail_slug( 'Monte Corrasi', 1 ), 'slug dal nome' );

// 3. Doppioni nella stessa base: tutti con -id; in un'altra base restano senza
$sentiero = [ 'it' => 'Sentiero', 'en' => 'Trail' ];
$items    = [
	[ 'type' => 'track', 'id' => 1, 'names' => [ 'it' => 'Monte Corrasi' ], 'kind' => $sentiero ],
	[ 'type' => 'track', 'id' => 2, 'names' => [ 'it' => 'Monte Corrasi' ], 'kind' => $sentiero ],
	[ 'type' => 'track', 'id' => 3, 'names' => [ 'it' => 'Monte Corrasi' ], 'kind' => [ 'it' => 'Itinerario' ] ],
	[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Salita', 'en' => 'Climb' ], 'kind' => $sentiero ],
	[ 'type' => 'track', 'id' => 6, 'names' => [ 'it' => 'Senza tipo' ], 'kind' => null ],
	[ 'type' => 'poi', 'id' => 5, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null, 'source' => 'D-77' ],
];
$index    = wmwp_detail_build_index( $items, $base, [ 'it', 'en' ] );

$check( 'sentiero/monte-corrasi-1' === ( $index['urls']['track'][1]['it'] ?? null ), 'doppione 1 con -1' );
$check( 'sentiero/monte-corrasi-2' === ( $index['urls']['track'][2]['it'] ?? null ), 'doppione 2 con -2' );
$check( 'itinerario/monte-corrasi' === ( $index['urls']['track'][3]['it'] ?? null ), 'stesso nome in un\'altra base senza -id' );
$check( [ 'it' => 'sentiero/salita', 'en' => 'trail/climb' ] === ( $index['urls']['track'][5] ?? null ), 'indirizzi per lingua' );
$check( 'track/senza-tipo' === ( $index['urls']['track'][6]['en'] ?? null ), 'senza tipo → base di ripiego' );
$check( 'poi/nuraghe' === ( $index['urls']['poi'][5]['it'] ?? null ), 'stesso id per traccia e POI senza conflitti' );
$check( [ 'track', 5 ] === ( $index['paths']['en']['trail/climb'] ?? null ), 'percorso inglese → elemento' );
$check( [ 'poi', 5 ] === ( $index['paths']['it']['poi/nuraghe'] ?? null ), 'percorso del POI → elemento' );
$check( 6 === $index['count'], 'count = numero di elementi' );
$check( [ 'it', 'en' ] === $index['langs'], 'lingue dell\'indice' );
// Nomi e id della fonte di ogni elemento: servono per i POI, il cui dettaglio non ha il nome
$check( 'Climb' === ( $index['items']['track'][5]['names']['en'] ?? null ), 'nomi dell\'elemento nell\'indice' );
$check( 'D-77' === ( $index['items']['poi'][5]['source'] ?? null ), 'id della fonte nell\'indice' );

// 4. Senza nome inglese l'inglese usa l'italiano: un dettaglio senza traduzioni resta raggiungibile
$check( 'trail/monte-corrasi-1' === ( $index['urls']['track'][1]['en'] ?? null ), 'inglese senza traduzione del nome' );

// 5. Il suffisso dei doppioni non deve collidere con uno slug naturale: «Foo» 5 e 7 doppioni, «Foo 5» id 9
$coll = wmwp_detail_build_index(
	[
		[ 'type' => 'poi', 'id' => 5, 'names' => [ 'it' => 'Foo' ], 'kind' => null ],
		[ 'type' => 'poi', 'id' => 7, 'names' => [ 'it' => 'Foo' ], 'kind' => null ],
		[ 'type' => 'poi', 'id' => 9, 'names' => [ 'it' => 'Foo 5' ], 'kind' => null ],
	],
	$base,
	[ 'it' ]
);
$percorsi = [ $coll['urls']['poi'][5]['it'], $coll['urls']['poi'][7]['it'], $coll['urls']['poi'][9]['it'] ];
$check( 3 === count( array_unique( $percorsi ) ), 'tre percorsi diversi: ' . implode( ', ', $percorsi ) );
$check( 'poi/foo-5' === $coll['urls']['poi'][9]['it'], 'lo slug naturale resta a chi ce l\'ha' );
$check( 3 === count( $coll['paths']['it'] ), 'ogni percorso porta a un elemento solo' );
$check( [ 'track' => 0, 'poi' => 3 ] === ( $coll['counts'] ?? null ), 'conteggi per tipo' );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
