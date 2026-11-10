<?php
/**
 * Test (oc:8952): con wm-types che non risponde, la configurazione degli shard si chiede una volta sola, poi
 * vale quella di riserva in cache. Si lancia con: wp eval-file tests/php/shards-fallback.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1. Toglie il transient alla fine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$calls = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$calls ) {
		if ( false !== strpos( $url, 'wm-types' ) ) {
			++$calls;
			return new WP_Error( 'wmwp_test_down', 'wm-types non risponde (test)' );
		}
		return $pre;
	},
	10,
	3
);
delete_transient( WMWP_SHARDS_CACHE_KEY );
$first  = wmwp_get_shards_config();
$second = wmwp_get_shards_config();
delete_transient( WMWP_SHARDS_CACHE_KEY );
if ( 1 !== $calls ) {
	echo "FAIL: {$calls} richieste a wm-types invece di 1\n";
	exit( 1 );
}
if ( $first !== $second ) {
	echo "FAIL: la seconda lettura è diversa dalla prima\n";
	exit( 1 );
}
echo "PASS\n";
