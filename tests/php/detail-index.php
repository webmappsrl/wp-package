<?php
/**
 * Test (oc:8969, oc:8983): slug e indice dei dettagli. Il nome nella lingua con ripiego sull'italiano, lo slug che non
 * resta mai vuoto, i doppioni (nella stessa base e lingua) con `-2`, `-3`… in ordine di chiave, i percorsi tenuti
 * dallo storico e gli indirizzi per lingua. Si lancia con: wp eval-file tests/php/detail-index.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require __DIR__ . '/history-sandbox.php';

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

// 3. Doppioni nella stessa base: senza storico il primo per chiave resta senza suffisso, gli altri -2, -3…; in
// un'altra base restano senza
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

$check( 'sentiero/monte-corrasi' === ( $index['urls']['track'][1]['it'] ?? null ), 'primo doppione senza suffisso' );
$check( 'sentiero/monte-corrasi-2' === ( $index['urls']['track'][2]['it'] ?? null ), 'secondo doppione con -2' );
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
$check( 'trail/monte-corrasi' === ( $index['urls']['track'][1]['en'] ?? null ), 'inglese senza traduzione del nome' );

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

// 6. Chiave stabile della fonte: `wmwp_detail_source()` legge `out_source_feature_id`, un tema la cambia con il filtro
// `wmwp_detail_source`. I doppioni si ordinano per chiave (nid numerici, chiavi testuali, solo id): l'indirizzo non
// cambia quando lo shard rinumera gli id (reset notturno, reimport)
$check( 'D-77' === wmwp_detail_source( 'poi', [ 'out_source_feature_id' => 'D-77' ] ), 'fonte da out_source_feature_id' );
$check( '' === wmwp_detail_source( 'track', [ 'out_source_feature_id' => [ 'x' ] ] ) && '' === wmwp_detail_source( 'track', [] ), 'fonte assente o non scalare → vuota' );
$filtro = function ( $source, $type, $props ) {
	return isset( $props['nid'] ) ? (string) $props['nid'] : $source;
};
add_filter( 'wmwp_detail_source', $filtro, 20, 3 );
$check( '4076' === wmwp_detail_source( 'track', [ 'nid' => 4076 ] ), 'filtro wmwp_detail_source' );
remove_filter( 'wmwp_detail_source', $filtro, 20 );

$monte = function ( array $ids ) use ( $base, $sentiero ) {
	return wmwp_detail_build_index(
		[
			[ 'type' => 'track', 'id' => $ids[0], 'names' => [ 'it' => 'Monte' ], 'kind' => $sentiero, 'source' => '4076' ],
			[ 'type' => 'track', 'id' => $ids[1], 'names' => [ 'it' => 'Monte' ], 'kind' => $sentiero, 'source' => 'N 12/B' ],
			[ 'type' => 'track', 'id' => $ids[2], 'names' => [ 'it' => 'Monte' ], 'kind' => $sentiero ],
		],
		$base,
		[ 'it' ]
	);
};
$prima = $monte( [ 11, 12, 13 ] );
$check( 'sentiero/monte' === ( $prima['urls']['track'][11]['it'] ?? null ), 'nid numerico per primo: senza suffisso' );
$check( 'sentiero/monte-2' === ( $prima['urls']['track'][12]['it'] ?? null ), 'chiave testuale dopo: -2' );
$check( 'sentiero/monte-3' === ( $prima['urls']['track'][13]['it'] ?? null ), 'solo id per ultimo: -3' );
$dopo = $monte( [ 211, 212, 213 ] );
$check( 'sentiero/monte' === ( $dopo['urls']['track'][211]['it'] ?? null ) && 'sentiero/monte-2' === ( $dopo['urls']['track'][212]['it'] ?? null ), 'stesso indirizzo dopo la rinumerazione degli id' );

// 7. Chiave dell'elemento e ordine fra omonimi (oc:8983): la chiave della fonte se c'è, altrimenti l'id; prima i nid
// numerici in ordine crescente, poi le chiavi testuali, poi gli elementi con il solo id
if ( ! function_exists( 'wmwp_detail_item_key' ) || ! function_exists( 'wmwp_detail_key_compare' ) ) {
	$check( false, 'wmwp_detail_item_key / wmwp_detail_key_compare non esistono' );
} else {
	$check( 's:4076' === wmwp_detail_item_key( '4076', 12 ), 'chiave della fonte' );
	$check( 'i:12' === wmwp_detail_item_key( null, 12 ) && 'i:12' === wmwp_detail_item_key( '', 12 ), 'senza fonte: id' );
	$keys = [ 'i:3', 's:N 12/B', 's:4076', 's:99', 'i:1' ];
	usort( $keys, 'wmwp_detail_key_compare' );
	$check( [ 's:99', 's:4076', 's:N 12/B', 'i:1', 'i:3' ] === $keys, 'ordine: nid numerici, chiavi testuali, id: ' . implode( ', ', $keys ) );
}

// 8. Percorsi con lo storico (oc:8983). Lo storico è un array lingua → percorso → riga, come lo restituisce
// wmwp_detail_history_load()
$row   = function ( string $type, string $key, string $base_name, string $root, int $suffix, bool $current ): array {
	return [
		'type'    => $type,
		'key'     => $key,
		'base'    => $base_name,
		'root'    => $root,
		'suffix'  => $suffix,
		'current' => $current,
	];
};
$track = function ( int $id, string $name, ?string $source = null ) use ( $sentiero ): array {
	return [ 'type' => 'track', 'id' => $id, 'names' => [ 'it' => $name ], 'kind' => $sentiero, 'source' => $source ];
};
$build = function ( array $items, array $history, ?array &$assignments = null, ?callable $base_fn = null ) use ( $base ): array {
	return wmwp_detail_build_index( $items, $base_fn ?? $base, [ 'it' ], [ 'it' => $history ], $assignments );
};

// Senza storico il risultato non dipende dall'ordine in cui arrivano gli elementi
$rovescio = $build( [ $track( 2, 'Monte Corrasi' ), $track( 1, 'Monte Corrasi' ) ], [] );
$check( 'sentiero/monte-corrasi' === ( $rovescio['urls']['track'][1]['it'] ?? null ) && 'sentiero/monte-corrasi-2' === ( $rovescio['urls']['track'][2]['it'] ?? null ), 'senza storico: il primo per chiave senza suffisso, in qualunque ordine' );

// Un omonimo nuovo non sposta chi c'era, anche se ha il nid più basso
$assegnati = null;
$nuovo     = $build(
	[ $track( 1, 'Monte X', '500' ), $track( 2, 'Monte X', '100' ) ],
	[ 'sentiero/monte-x' => $row( 'track', 's:500', 'sentiero', 'monte-x', 0, true ) ],
	$assegnati
);
$check( 'sentiero/monte-x' === ( $nuovo['urls']['track'][1]['it'] ?? null ), 'omonimo nuovo: chi c\'era tiene il percorso' );
$check( 'sentiero/monte-x-2' === ( $nuovo['urls']['track'][2]['it'] ?? null ), 'omonimo nuovo: il nuovo prende -2' );
$check(
	in_array(
		[ 'lang' => 'it', 'path' => 'sentiero/monte-x-2', 'type' => 'track', 'id' => 2, 'key' => 's:100', 'base' => 'sentiero', 'root' => 'monte-x', 'suffix' => 2 ],
		(array) $assegnati,
		true
	),
	'assegnazioni: percorso, chiave, base, slug di partenza e suffisso'
);
$check( 2 === count( (array) $assegnati ), 'un\'assegnazione per percorso' );

// Regola A: il percorso resta dell'elemento a cui è stato dato, anche dopo la sua rinomina
$storico_a = [
	'sentiero/monte-x'   => $row( 'track', 's:100', 'sentiero', 'monte-x', 0, true ),
	'sentiero/monte-x-2' => $row( 'track', 's:200', 'sentiero', 'monte-x', 2, true ),
];
$regola_a  = $build( [ $track( 1, 'Monte Y', '100' ), $track( 2, 'Monte X', '200' ) ], $storico_a );
$check( 'sentiero/monte-y' === ( $regola_a['urls']['track'][1]['it'] ?? null ), 'rinominato: percorso nuovo' );
$check( 'sentiero/monte-x-2' === ( $regola_a['urls']['track'][2]['it'] ?? null ), 'l\'omonimo resta a -2' );
$check( ! isset( $regola_a['paths']['it']['sentiero/monte-x'] ), 'il percorso del rinominato non va a nessun altro' );

// Il percorso torna libero quando l'elemento a cui era stato dato non c'è più
$libero = $build( [ $track( 2, 'Monte X', '200' ), $track( 3, 'Monte X', '300' ) ], $storico_a );
$check( 'sentiero/monte-x' === ( $libero['urls']['track'][3]['it'] ?? null ), 'elemento sparito: il percorso va al nuovo omonimo' );
$check( 'sentiero/monte-x-2' === ( $libero['urls']['track'][2]['it'] ?? null ), 'elemento sparito: -2 resta a chi ce l\'ha' );

// Una rinomina che cambia lo slug di partenza non tiene il percorso vecchio, anche se finisce con un numero
$tappa = $build( [ $track( 7, 'Cammino X Tappa', '7' ) ], [ 'sentiero/cammino-x-tappa-2' => $row( 'track', 's:7', 'sentiero', 'cammino-x-tappa-2', 0, true ) ] );
$check( 'sentiero/cammino-x-tappa' === ( $tappa['urls']['track'][7]['it'] ?? null ), 'slug di partenza cambiato: percorso nuovo' );

// Stesso id per una traccia e un POI senza chiave della fonte (i:5 tutti e due): uno non prende il percorso dell'altro
$stessa_base = function (): string {
	return 'luoghi';
};
$stesso_id   = $build(
	[
		[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null ],
		[ 'type' => 'poi', 'id' => 5, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null ],
	],
	[ 'luoghi/nuraghe' => $row( 'track', 'i:5', 'luoghi', 'nuraghe', 0, true ) ],
	$assegnati,
	$stessa_base
);
$check( 'luoghi/nuraghe' === ( $stesso_id['urls']['track'][5]['it'] ?? null ) && 'luoghi/nuraghe-2' === ( $stesso_id['urls']['poi'][5]['it'] ?? null ), 'stesso id fra traccia e POI: ognuno il suo percorso' );

// Nome senza lettere: lo slug è l'id, quindi dopo un reset cambia e il percorso è nuovo
$assegnati = null;
$simboli   = $build( [ $track( 77, '— !!', '9' ) ], [ 'sentiero/41' => $row( 'track', 's:9', 'sentiero', '41', 0, true ) ], $assegnati );
$check( 'sentiero/77' === ( $simboli['urls']['track'][77]['it'] ?? null ), 'nome senza lettere: percorso dall\'id nuovo' );
$check( '77' === ( $assegnati[0]['root'] ?? null ) && 0 === ( $assegnati[0]['suffix'] ?? null ), 'nome senza lettere: slug di partenza = id' );

// Suffisso assegnato, nell'indice: dice a wmwp_detail_slug_matches() quale `-<n>` è di quell'elemento
$check( 2 === ( $nuovo['suffixes']['track'][2]['it'] ?? null ) && ! isset( $nuovo['suffixes']['track'][1] ), 'suffisso assegnato nell\'indice, solo se c\'è' );

// Chiave della fonte ripetuta (due elementi con lo stesso nid): resta all'id più basso, l'altro elemento usa l'id. Al
// secondo giro, con lo storico del primo, i percorsi non si scambiano
$doppio_1 = null;
$doppio   = $build( [ $track( 9, 'Monte', 'X' ), $track( 5, 'Monte', 'X' ) ], [], $doppio_1 );
$storia_d = [];
foreach ( (array) $doppio_1 as $a ) {
	$storia_d[ $a['path'] ] = $row( $a['type'], $a['key'], $a['base'], $a['root'], $a['suffix'], true );
}
$doppio_2 = $build( [ $track( 9, 'Monte', 'X' ), $track( 5, 'Monte', 'X' ) ], $storia_d );
$check( 'sentiero/monte' === ( $doppio['urls']['track'][5]['it'] ?? null ) && 'sentiero/monte-2' === ( $doppio['urls']['track'][9]['it'] ?? null ), 'nid doppio: la chiave resta all\'id più basso' );
$check( in_array( 'i:9', array_column( (array) $doppio_1, 'key' ), true ), 'nid doppio: l\'altro elemento usa l\'id' );
$check( ( $doppio['urls'] ?? null ) === ( $doppio_2['urls'] ?? null ), 'nid doppio: stessi percorsi al secondo giro' );

// Chiave con spazi ai lati: nell'indice ripulita
$spazi = $build( [ $track( 3, 'Spazi', ' 4076 ' ) ], [] );
$check( '4076' === ( $spazi['items']['track'][3]['source'] ?? null ), 'chiave della fonte ripulita nell\'indice' );

// 9. Protezioni dello storico (oc:8983): chiave della fonte crollata (API cambiate) e shard svuotato e ricaricato
if ( ! function_exists( 'wmwp_detail_keys_collapsed' ) || ! function_exists( 'wmwp_detail_shard_renumbered' ) ) {
	$check( false, 'wmwp_detail_keys_collapsed / wmwp_detail_shard_renumbered non esistono' );
} else {
	$prima_fonti = [
		'items' => [
			'track' => [
				1 => [ 'names' => null, 'source' => 'a' ],
				2 => [ 'names' => null, 'source' => 'b' ],
				3 => [ 'names' => null, 'source' => 'c' ],
			],
			'poi'   => [ 9 => [ 'names' => null, 'source' => null ] ],
		],
	];
	$con       = function ( int $id, ?string $source, string $type = 'track' ): array {
		return [ 'type' => $type, 'id' => $id, 'names' => null, 'kind' => null, 'source' => $source ];
	};
	$check( true === wmwp_detail_keys_collapsed( $prima_fonti, [ $con( 1, 'a' ), $con( 2, null ), $con( 3, null ) ] ), 'chiave crollata: da 3 a 1' );
	$check( false === wmwp_detail_keys_collapsed( $prima_fonti, [ $con( 1, 'a' ), $con( 2, 'b' ), $con( 3, null ) ] ), 'chiave calata: da 3 a 2' );
	// Una cancellazione vera (gli elementi spariscono, quelli rimasti hanno la chiave) non è un crollo della chiave: il
	// calo degli elementi lo giudica il controllo sui conteggi, che alla terza volta accetta
	$check( false === wmwp_detail_keys_collapsed( $prima_fonti, [ $con( 1, 'a' ) ] ), 'elementi cancellati, chiave presente → no' );
	$check( false === wmwp_detail_keys_collapsed( $prima_fonti, [] ), 'nessun elemento del tipo → lo giudica il controllo sui conteggi' );
	// La perdita della chiave si misura sugli elementi presenti in tutti e due gli indici: cancellazioni e aggiunte senza
	// chiave non spostano il conto (giro 1 delle review interne)
	$minoranza = [ 'items' => [ 'track' => [] ] ];
	$solo_id   = [];
	for ( $n = 1; $n <= 10; $n++ ) {
		$minoranza['items']['track'][ $n ] = [ 'names' => null, 'source' => $n <= 2 ? 'k' . $n : null ];
		if ( $n > 2 ) {
			$solo_id[] = $con( $n, null );
		}
	}
	$check( false === wmwp_detail_keys_collapsed( $minoranza, $solo_id ), 'cancellati i soli elementi con la chiave → no' );
	$import = [ $con( 1, 'a' ), $con( 2, 'b' ), $con( 3, 'c' ) ];
	for ( $n = 20; $n < 40; $n++ ) {
		$import[] = $con( $n, null );
	}
	$check( false === wmwp_detail_keys_collapsed( $prima_fonti, $import ), 'import in blocco senza chiave → no' );
	$check( false === wmwp_detail_keys_collapsed( null, [ $con( 1, null ) ] ), 'nessun indice prima' );
	$check( false === wmwp_detail_keys_collapsed( [ 'items' => [ 'track' => [ 1 => [ 'names' => null, 'source' => null ] ] ] ], [ $con( 1, null ) ] ), 'nessuna chiave neanche prima' );

	$check( [ 'track' ] === wmwp_detail_shard_renumbered( $prima_fonti, [ $con( 15, 'a' ) ] ), 'stessa chiave, id diverso → tracce rinumerate' );
	$check( [] === wmwp_detail_shard_renumbered( $prima_fonti, [ $con( 1, 'a' ), $con( 4, 'z' ) ] ), 'stesso id, o chiave nuova → no' );
	$check( [] === wmwp_detail_shard_renumbered( $prima_fonti, [ $con( 15, null ) ] ), 'elementi senza chiave → no' );
	$check( [] === wmwp_detail_shard_renumbered( null, [ $con( 15, 'a' ) ] ), 'nessun indice prima → no' );
	$check( [] === wmwp_detail_shard_renumbered( $prima_fonti, [ $con( 15, 'a', 'poi' ) ] ), 'stessa chiave ma altro tipo → no' );
	// In produzione un elemento reimportato (o un nid copiato con un duplicato in Nova) cambia id da solo: non è un
	// azzeramento dello shard, che cambia l'id alla maggior parte degli elementi
	$check( [] === wmwp_detail_shard_renumbered( $prima_fonti, [ $con( 15, 'a' ), $con( 2, 'b' ), $con( 3, 'c' ) ] ), 'un solo elemento su tre con un id nuovo → no' );
	$check( [ 'track' ] === wmwp_detail_shard_renumbered( $prima_fonti, [ $con( 15, 'a' ), $con( 16, 'b' ), $con( 3, 'c' ) ] ), 'due su tre con un id nuovo → tracce rinumerate' );
	$check( [] === wmwp_detail_shard_renumbered( $prima_fonti, [ $con( 1, 'a' ), $con( 2, 'b' ), $con( 3, 'c' ), $con( 40, 'c' ) ] ), 'chiave doppia fra gli elementi nuovi: non conta' );

}

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
