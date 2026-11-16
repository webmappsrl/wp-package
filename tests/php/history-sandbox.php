<?php
/**
 * Sandbox dello storico dei percorsi per i test (oc:8983). I test girano con `wp eval-file` sul sito vero: senza
 * questa sandbox scriverebbero righe di prova nella tabella vera dello storico, e quelle righe riserverebbero percorsi
 * agli elementi veri. Crea `{prefix}wmwp_detail_paths_test`, la svuota, fa puntare lì lo storico (filtro interno
 * `wmwp_detail_paths_table`), forza la versione dello schema e spegne il primo giro; alla fine della richiesta (anche
 * dopo `exit` o un errore fatale, a differenza di `finally`) cancella la tabella, toglie i filtri e rimette com'erano
 * le option vere dello storico e lo stato vero dell'indice (transient, ultimo indice buono, basi).
 *
 * Non è un test: si include come prima riga dei `tests/php/detail-*.php`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wmwp_detail_history_create_table' ) ) {
	echo "FAIL: wmwp_detail_history_create_table non esiste\n";
	exit( 1 );
}

// Le option vere dello storico, lette prima dei filtri: un test che ne cancella una (il primo giro cancella
// `wmwp_detail_paths_seed_pending`, e delete_option() non passa dai filtri) la ritrova com'era alla fine
$wmwp_sandbox_options = [];
foreach ( [ WMWP_DETAIL_PATHS_VERSION_OPTION, WMWP_DETAIL_PATHS_SEED_OPTION, WMWP_DETAIL_PATHS_FAILED_OPTION ] as $wmwp_sandbox_option ) {
	$wmwp_sandbox_options[ $wmwp_sandbox_option ] = get_option( $wmwp_sandbox_option, null );
}
// Anche lo stato vero dell'indice: i test lo cancellano e lo riscrivono con lo shard finto. Senza, dopo la suite il sito
// resterebbe senza l'ultimo indice buono, e al primo giro della 0.3.0 i percorsi vecchi non entrerebbero nello storico
$wmwp_sandbox_index = [
	'transient' => get_transient( 'wmwp_detail_index' ),
	'options'   => [],
];
foreach ( [ 'wmwp_detail_index_last', 'wmwp_detail_index_rebuilt_at', 'wmwp_detail_bases', 'wmwp_detail_index_rejects' ] as $wmwp_sandbox_option ) {
	$wmwp_sandbox_index['options'][ $wmwp_sandbox_option ] = get_option( $wmwp_sandbox_option, null );
}

$wmwp_sandbox_table = $GLOBALS['wpdb']->prefix . 'wmwp_detail_paths_test';
if ( ! wmwp_detail_history_create_table( $wmwp_sandbox_table ) ) {
	echo "FAIL: tabella di prova dello storico non creata\n";
	exit( 1 );
}
$GLOBALS['wpdb']->query( "TRUNCATE TABLE `{$wmwp_sandbox_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$wmwp_sandbox_filters = [
	'wmwp_detail_paths_table'                     => function () use ( $wmwp_sandbox_table ) {
		return $wmwp_sandbox_table;
	},
	'pre_option_' . WMWP_DETAIL_PATHS_VERSION_OPTION => function () {
		return WMWP_DETAIL_PATHS_DB_VERSION;
	},
	'pre_option_' . WMWP_DETAIL_PATHS_SEED_OPTION    => function () {
		return 0;
	},
];
foreach ( $wmwp_sandbox_filters as $wmwp_sandbox_hook => $wmwp_sandbox_callback ) {
	add_filter( $wmwp_sandbox_hook, $wmwp_sandbox_callback );
}

register_shutdown_function(
	function () use ( $wmwp_sandbox_table, $wmwp_sandbox_filters, $wmwp_sandbox_options, $wmwp_sandbox_index ) {
		$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS `{$wmwp_sandbox_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Prima i filtri, poi le option: con i filtri ancora attivi update_option() vedrebbe il valore forzato
		foreach ( $wmwp_sandbox_filters as $hook => $callback ) {
			remove_filter( $hook, $callback );
		}
		foreach ( $wmwp_sandbox_options as $option => $value ) {
			null === $value ? delete_option( $option ) : update_option( $option, $value, false );
		}
		foreach ( $wmwp_sandbox_index['options'] as $option => $value ) {
			null === $value ? delete_option( $option ) : update_option( $option, $value, 'wmwp_detail_bases' === $option );
		}
		false === $wmwp_sandbox_index['transient'] ? delete_transient( 'wmwp_detail_index' ) : set_transient( 'wmwp_detail_index', $wmwp_sandbox_index['transient'], WMWP_DETAIL_INDEX_TTL );
	}
);
