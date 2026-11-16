<?php
/**
 * Test (oc:8983): storico dei percorsi dei dettagli. Scrittura e lettura, una riga per lingua e percorso, percorsi
 * lunghi, le sole righe che cambiano, righe con l'id dimenticate, basi dello storico, plugin senza tabella. Gira sulla
 * tabella della sandbox, mai su quella vera. Si lancia con: wp eval-file tests/php/detail-history.php
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

$row = function ( string $lang, string $path, string $type, string $key, string $base, string $root, int $suffix, bool $current ): array {
	return [
		'lang'    => $lang,
		'path'    => $path,
		'type'    => $type,
		'key'     => $key,
		'base'    => $base,
		'root'    => $root,
		'suffix'  => $suffix,
		'current' => $current,
	];
};
$empty = function () {
	$GLOBALS['wpdb']->query( 'TRUNCATE TABLE `' . wmwp_detail_paths_table() . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
};

// 1. Scrittura e lettura: lingua → percorso → riga
wmwp_detail_history_write(
	[
		$row( 'it', 'sentiero/monte-x', 'track', 's:100', 'sentiero', 'monte-x', 0, true ),
		$row( 'it', 'sentiero/monte-x-2', 'track', 's:200', 'sentiero', 'monte-x', 2, false ),
	]
);
$history = wmwp_detail_history_load();
$check(
	[ 'type' => 'track', 'key' => 's:100', 'base' => 'sentiero', 'root' => 'monte-x', 'suffix' => 0, 'current' => true ] === ( $history['it']['sentiero/monte-x'] ?? null ),
	'scrittura e lettura'
);
$check( false === ( $history['it']['sentiero/monte-x-2']['current'] ?? null ) && 2 === ( $history['it']['sentiero/monte-x-2']['suffix'] ?? null ), 'riga non attuale con il suo suffisso' );

// 2. Un percorso, una riga: dato a un altro elemento, la riga si sovrascrive
wmwp_detail_history_write( [ $row( 'it', 'sentiero/monte-x', 'track', 's:300', 'sentiero', 'monte-x', 0, true ) ] );
$count = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM `' . wmwp_detail_paths_table() . '` WHERE lang = %s AND path = %s', 'it', 'sentiero/monte-x' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( 1 === $count && 's:300' === ( wmwp_detail_history_lookup( 'it', 'sentiero/monte-x' )['key'] ?? null ), 'un percorso, una riga' );

// 3. Lo stesso percorso in due lingue sono due righe
wmwp_detail_history_write( [ $row( 'en', 'sentiero/monte-x', 'track', 's:100', 'sentiero', 'monte-x', 0, true ) ] );
$check( 's:100' === ( wmwp_detail_history_lookup( 'en', 'sentiero/monte-x' )['key'] ?? null ) && 's:300' === ( wmwp_detail_history_lookup( 'it', 'sentiero/monte-x' )['key'] ?? null ), 'stesso percorso in due lingue' );
$check( null === wmwp_detail_history_lookup( 'it', 'sentiero/che-non-c-e' ), 'percorso sconosciuto → null' );

// 4. Un percorso più lungo della lunghezza indicizzabile si ritrova per impronta
$long = 'sentiero/' . str_repeat( 'a', 300 );
wmwp_detail_history_write( [ $row( 'it', $long, 'track', 's:400', 'sentiero', str_repeat( 'a', 300 ), 0, true ) ] );
$check( 's:400' === ( wmwp_detail_history_lookup( 'it', $long )['key'] ?? null ), 'percorso lungo' );

// 5. Si scrivono solo le righe che cambiano
$storico = [ 'it' => [ 'sentiero/a' => [ 'type' => 'track', 'key' => 's:1', 'base' => 'sentiero', 'root' => 'a', 'suffix' => 0, 'current' => true ] ] ];
$uguale  = [ [ 'lang' => 'it', 'path' => 'sentiero/a', 'type' => 'track', 'id' => 1, 'key' => 's:1', 'base' => 'sentiero', 'root' => 'a', 'suffix' => 0 ] ];
$check( [] === wmwp_detail_history_changes( $storico, $uguale ), 'niente cambiato, niente da scrivere' );
$rinomina = [ [ 'lang' => 'it', 'path' => 'sentiero/b', 'type' => 'track', 'id' => 1, 'key' => 's:1', 'base' => 'sentiero', 'root' => 'b', 'suffix' => 0 ] ];
$changes  = wmwp_detail_history_changes( $storico, $rinomina );
$check( 2 === count( $changes ), 'rinomina: due righe da scrivere' );
$check( in_array( $row( 'it', 'sentiero/b', 'track', 's:1', 'sentiero', 'b', 0, true ), $changes, true ), 'rinomina: percorso nuovo attuale' );
$check( in_array( $row( 'it', 'sentiero/a', 'track', 's:1', 'sentiero', 'a', 0, false ), $changes, true ), 'rinomina: percorso vecchio non attuale, stesso proprietario' );

// 6. Le righe registrate con l'id si dimenticano tutte, quelle con la chiave della fonte restano
$empty();
wmwp_detail_history_write(
	[
		$row( 'it', 'sentiero/uno', 'track', 's:1', 'sentiero', 'uno', 0, true ),
		$row( 'it', 'sentiero/due', 'track', 'i:2', 'sentiero', 'due', 0, true ),
	]
);
wmwp_detail_history_write( [ $row( 'it', 'poi/tre', 'poi', 'i:3', 'poi', 'tre', 0, true ) ] );
$check( 1 === wmwp_detail_history_forget_ids( 'track' ), 'una riga con l\'id dimenticata, solo fra le tracce' );
$check( null !== wmwp_detail_history_lookup( 'it', 'poi/tre' ), 'le righe con l\'id dei POI restano' );
$check( null !== wmwp_detail_history_lookup( 'it', 'sentiero/uno' ) && null === wmwp_detail_history_lookup( 'it', 'sentiero/due' ), 'resta la riga con la chiave della fonte' );

// 7. Basi dello storico
wmwp_detail_history_write( [ $row( 'it', 'itinerario/tre', 'track', 's:3', 'itinerario', 'tre', 0, false ) ] );
$bases = wmwp_detail_history_bases();
$check( in_array( 'sentiero', $bases, true ) && in_array( 'itinerario', $bases, true ), 'basi dello storico: ' . implode( ', ', $bases ) );

// 8. Tabella che non risponde mentre la versione dice che c'è: nessun errore fatale; la lettura completa dà null, così
// wmwp_detail_index() tiene l'indice di prima invece di costruirne uno senza storico
$missing = function () {
	return $GLOBALS['wpdb']->prefix . 'wmwp_detail_paths_missing';
};
add_filter( 'wmwp_detail_paths_table', $missing, 20 );
$check( null === wmwp_detail_history_load(), 'tabella che non risponde: lettura dello storico null' );
$check( null === wmwp_detail_history_lookup( 'it', 'sentiero/uno' ), 'senza tabella: nessuna riga' );
$check( null === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", WMWP_DETAIL_PATHS_VERSION_OPTION ) ), 'tabella sparita: versione tolta, così si ricrea' );
wmwp_detail_history_write( [ $row( 'it', 'sentiero/x', 'track', 's:9', 'sentiero', 'x', 0, true ) ] );
$check( 0 === wmwp_detail_history_forget_ids() && [] === wmwp_detail_history_bases(), 'senza tabella: scrittura e cancellazione senza effetti' );
remove_filter( 'wmwp_detail_paths_table', $missing, 20 );

// 9. Correzioni a mano (comando WP-CLI): forget libera un percorso e rimette in gioco gli omonimi, che al giro dopo si
// riassegnano in ordine di chiave; il percorso vecchio dell'omonimo resta suo
if ( ! function_exists( 'wmwp_detail_history_forget' ) || ! function_exists( 'wmwp_detail_history_rows' ) ) {
	$check( false, 'wmwp_detail_history_forget / wmwp_detail_history_rows non esistono' );
} else {
	$empty();
	wmwp_detail_history_write(
		[
			$row( 'it', 'sentiero/monte-x', 'track', 's:100', 'sentiero', 'monte-x', 0, false ),
			$row( 'it', 'sentiero/monte-x-2', 'track', 's:200', 'sentiero', 'monte-x', 2, true ),
			$row( 'it', 'sentiero/monte-y', 'track', 's:100', 'sentiero', 'monte-y', 0, true ),
		]
	);
	$check( [ $row( 'it', 'sentiero/monte-y', 'track', 's:100', 'sentiero', 'monte-y', 0, true ) ] === wmwp_detail_history_rows( [ 'path' => 'sentiero/monte-y' ] ), 'righe di un percorso' );
	$check( 2 === count( wmwp_detail_history_rows( [ 'type' => 'track', 'key' => 's:100' ] ) ), 'righe di un elemento' );
	$check( 1 === wmwp_detail_history_forget( '/sentiero/monte-x/' ), 'forget: una riga cancellata, con le barre ai lati' );
	$sentiero_base = function (): string {
		return 'sentiero';
	};
	$dopo_forget = wmwp_detail_build_index(
		[
			[ 'type' => 'track', 'id' => 1, 'names' => [ 'it' => 'Monte Y' ], 'kind' => null, 'source' => '100' ],
			[ 'type' => 'track', 'id' => 2, 'names' => [ 'it' => 'Monte X' ], 'kind' => null, 'source' => '200' ],
		],
		$sentiero_base,
		[ 'it' ],
		wmwp_detail_history_load()
	);
	$check( 'sentiero/monte-x' === ( $dopo_forget['urls']['track'][2]['it'] ?? null ), 'forget: l\'omonimo prende il percorso liberato' );
	$check( 's:200' === ( wmwp_detail_history_lookup( 'it', 'sentiero/monte-x-2' )['key'] ?? null ), 'forget: il percorso vecchio dell\'omonimo resta suo' );
	$check( 'sentiero/monte-y' === ( $dopo_forget['urls']['track'][1]['it'] ?? null ), 'forget: gli altri elementi non cambiano' );
	$check( 0 === wmwp_detail_history_forget( 'sentiero/che-non-c-e' ), 'forget di un percorso sconosciuto: niente' );

	// Il comando svuota l'indice in cache anche quando non può ricostruirlo subito (ricostruzione in corso)
	if ( class_exists( 'WMWP_Detail_Paths_Command' ) ) {
		$salvato = [ get_transient( 'wmwp_detail_index' ), get_transient( 'wmwp_detail_index_lock' ) ];
		wmwp_detail_history_write( [ $row( 'it', 'sentiero/da-liberare', 'track', 's:500', 'sentiero', 'da-liberare', 0, false ) ] );
		set_transient( 'wmwp_detail_index', [ 'langs' => [ 'it' ], 'paths' => [], 'urls' => [], 'items' => [], 'count' => 0, 'config' => wmwp_detail_base_signature() ], 600 );
		set_transient( 'wmwp_detail_index_lock', 1, 60 );
		( new WMWP_Detail_Paths_Command() )->forget( [ 'sentiero/da-liberare' ], [] );
		$check( false === get_transient( 'wmwp_detail_index' ), 'forget: indice in cache svuotato' );
		false === $salvato[0] ? delete_transient( 'wmwp_detail_index' ) : set_transient( 'wmwp_detail_index', $salvato[0], HOUR_IN_SECONDS );
		false === $salvato[1] ? delete_transient( 'wmwp_detail_index_lock' ) : set_transient( 'wmwp_detail_index_lock', $salvato[1], 60 );
	}
}
$check( class_exists( 'WMWP_Detail_Paths_Command' ), 'comando WP-CLI caricato' );

// 10. Comando list: le righe di un percorso (formato json, che si può catturare)
if ( class_exists( 'WMWP_Detail_Paths_Command' ) ) {
	$empty();
	wmwp_detail_history_write( [ $row( 'it', 'sentiero/lista', 'track', 's:321', 'sentiero', 'lista', 0, true ) ] );
	ob_start();
	( new WMWP_Detail_Paths_Command() )->list_paths( [ '/sentiero/lista/' ], [ 'format' => 'json' ] );
	$uscita = (string) ob_get_clean();
	$check( false !== strpos( $uscita, '"key":"s:321"' ) && false !== strpos( $uscita, '"current":1' ), 'list: righe del percorso (' . trim( $uscita ) . ')' );
}

// 11. Basi delle regole in un punto solo: anche il ripiego di wmwp_detail_bases() (option mai scritta) le prende dallo
// storico
wmwp_detail_history_write( [ $row( 'it', 'storica-8983/vecchio', 'track', 's:1', 'storica-8983', 'vecchio', 0, false ) ] );
delete_option( 'wmwp_detail_bases' );
$check( in_array( 'storica-8983', wmwp_detail_bases(), true ), 'basi delle regole: anche quelle dello storico' );

// 12. Tabella che non si crea (nome non valido): tentativo segnato, nessun nuovo tentativo entro l'ora, poi sì
$versione_forzata = $wmwp_sandbox_filters[ 'pre_option_' . WMWP_DETAIL_PATHS_VERSION_OPTION ];
remove_filter( 'pre_option_' . WMWP_DETAIL_PATHS_VERSION_OPTION, $versione_forzata );
$rotta = function () {
	return $GLOBALS['wpdb']->prefix . 'wmwp detail rotta';
};
add_filter( 'wmwp_detail_paths_table', $rotta, 40 );
delete_option( WMWP_DETAIL_PATHS_VERSION_OPTION );
delete_option( WMWP_DETAIL_PATHS_FAILED_OPTION );
wmwp_detail_history_install();
$check( ! wmwp_detail_history_available() && (int) get_option( WMWP_DETAIL_PATHS_FAILED_OPTION, 0 ) > 0, 'tabella non creata: tentativo segnato' );
update_option( WMWP_DETAIL_PATHS_FAILED_OPTION, time() - 10, false );
wmwp_detail_history_install();
$check( time() - 10 === (int) get_option( WMWP_DETAIL_PATHS_FAILED_OPTION, 0 ), 'tabella non creata: nessun nuovo tentativo entro l\'ora' );
update_option( WMWP_DETAIL_PATHS_FAILED_OPTION, time() - 3700, false );
wmwp_detail_history_install();
$check( (int) get_option( WMWP_DETAIL_PATHS_FAILED_OPTION, 0 ) >= time() - 5, 'tabella non creata: nuovo tentativo dopo un\'ora' );
remove_filter( 'wmwp_detail_paths_table', $rotta, 40 );
add_filter( 'pre_option_' . WMWP_DETAIL_PATHS_VERSION_OPTION, $versione_forzata );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
