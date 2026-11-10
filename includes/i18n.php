<?php
/**
 * Traduzioni del plugin. Il percorso è relativo alla cartella dei plugin e si calcola dal file principale:
 * nessun nome di cartella scritto nel codice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wmwp_load_textdomain(): void {
	load_plugin_textdomain( 'wp-package', false, dirname( plugin_basename( WMWP_PATH . 'index.php' ) ) . '/languages' );
}
add_action( 'init', 'wmwp_load_textdomain' );
