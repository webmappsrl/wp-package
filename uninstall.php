<?php
/**
 * Eliminazione del plugin (oc:8983): cancella la tabella dello storico dei percorsi dei dettagli e le sue option, in
 * ogni sito della rete, e la copia dei POI per la ricerca (oc:9000). La disattivazione lascia lo storico, così
 * riattivando il plugin c'è ancora; cancella solo la copia dei POI, che si rifà al primo rinnovo dell'indice.
 *
 * All'eliminazione il plugin non è caricato: nomi della tabella e delle option scritti per esteso, come in
 * `includes/detail-history.php`.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wmwp_uninstall = function () {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wmwp_detail_paths`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( [ 'wmwp_detail_paths_db_version', 'wmwp_detail_paths_seed_pending', 'wmwp_detail_paths_failed_at', 'wmwp_poi_search_copy' ] as $option ) {
		delete_option( $option );
	}
};

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $wmwp_site ) {
		switch_to_blog( (int) $wmwp_site );
		$wmwp_uninstall();
		restore_current_blog();
	}
} else {
	$wmwp_uninstall();
}
