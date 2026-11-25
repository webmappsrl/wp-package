<?php
/**
 * Test (oc:9018): il config.json dell'app si scarica solo da WP-Cron (wmwp_app_config_refresh), con richiesta
 * condizionale (If-None-Match, If-Modified-Since); wmwp_app_config() dà l'ultima copia buona senza mai chiamare lo
 * shard. Una risposta 304 tiene la copia, un errore la tiene e lo scrive nel log, una copia della cache di prima
 * (transient con le sole tiles e layer_colors) non vale. Le risposte dello shard sono finte (pre_http_request).
 * Si lancia con: wp eval-file tests/php/app-config.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1. Rimette opzioni e transient com'erano.
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

$saved_shard  = get_option( WMWP_OPTION_SHARD );
$saved_app    = get_option( WMWP_OPTION_APP_ID );
$saved_config = get_option( WMWP_APP_CONFIG_OPTION, null );
$saved_failed = get_option( WMWP_APP_CONFIG_FAILED_OPTION, null );
$saved_next   = wp_next_scheduled( WMWP_APP_CONFIG_CRON_HOOK );
// Rimesse com'erano alla fine, anche se il test si interrompe con un errore (il gestore degli errori fatali di WordPress
// chiude il processo prima delle funzioni di shutdown)
$restore = function () use ( $saved_shard, $saved_app, $saved_config, $saved_failed, $saved_next ) {
	update_option( WMWP_OPTION_SHARD, $saved_shard );
	update_option( WMWP_OPTION_APP_ID, $saved_app );
	null === $saved_failed ? delete_option( WMWP_APP_CONFIG_FAILED_OPTION ) : update_option( WMWP_APP_CONFIG_FAILED_OPTION, $saved_failed, false );
	wp_clear_scheduled_hook( WMWP_APP_CONFIG_CRON_HOOK );
	wp_schedule_event( $saved_next ?: time(), 'hourly', WMWP_APP_CONFIG_CRON_HOOK );
	null === $saved_config ? delete_option( WMWP_APP_CONFIG_OPTION ) : update_option( WMWP_APP_CONFIG_OPTION, $saved_config, false );
};
set_exception_handler(
	function ( Throwable $e ) use ( $restore ) {
		$restore();
		echo 'FAIL: errore nel test: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
		exit( 1 );
	}
);
update_option( WMWP_OPTION_SHARD, 'forestasdev' );
update_option( WMWP_OPTION_APP_ID, 1 );
delete_option( WMWP_APP_CONFIG_OPTION );

$fixture  = [
	'MAP' => [
		'filters' => [ 'activities' => [ 'type' => 'select', 'options' => [ [ 'identifier' => 'hiking' ] ] ] ],
		'layers'  => [ [ 'id' => 11, 'style' => [ 'color' => '#de1b0d' ] ] ],
	],
];
$calls    = 0;
$answer   = 200;
$sent     = [];
$fake     = function ( $pre, $args, $url ) use ( &$calls, &$answer, &$sent, $fixture ) {
	if ( false === strpos( $url, '/api/app/webmapp/1/config.json' ) ) {
		return $pre;
	}
	++$calls;
	$sent = (array) ( $args['headers'] ?? [] );
	if ( 500 === $answer ) {
		return [ 'headers' => [], 'body' => 'errore', 'response' => [ 'code' => 500, 'message' => 'Error' ], 'cookies' => [] ];
	}
	if ( 'nomap' === $answer ) {
		return [ 'headers' => [], 'body' => wp_json_encode( [ 'error' => 'Server Error' ] ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
	}
	if ( 304 === $answer ) {
		return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 304, 'message' => 'Not Modified' ], 'cookies' => [] ];
	}
	return [
		'headers'  => [
			'etag'          => '"a"',
			'last-modified' => 'Tue, 24 Nov 2026 05:00:32 GMT',
		],
		'body'     => wp_json_encode( $fixture ),
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'cookies'  => [],
	];
};
add_filter( 'pre_http_request', $fake, 10, 3 );

// 1. Senza copia: nessuna richiesta nella pagina, null
$check( null === wmwp_app_config(), 'senza copia wmwp_app_config() dà null' );
$check( 0 === $calls, "wmwp_app_config() non chiama lo shard, invece {$calls} richieste" );

// 2. Una copia della cache di prima (solo tiles e layer_colors) non vale come copia nuova
set_transient( 'wmwp_map_config_' . md5( wmwp_app_config_url() ), [ 'tiles' => [], 'layer_colors' => [] ], DAY_IN_SECONDS );
$check( null === wmwp_app_config(), 'la transient di prima non vale come config.json' );

// 3. Il cron scarica: 200 con ETag e Last-Modified
$check( true === wmwp_app_config_refresh(), 'download riuscito' );
$check( 'hiking' === ( wmwp_app_config()['MAP']['filters']['activities']['options'][0]['identifier'] ?? '' ), 'MAP.filters dalla copia scaricata' );
$check( 1 === $calls, "una richiesta, invece di {$calls}" );
$check( ! isset( $sent['If-None-Match'] ), 'la prima richiesta non è condizionale' );

// 4. 304: richiesta condizionale, copia invariata
$answer = 304;
$before = (int) ( get_option( WMWP_APP_CONFIG_OPTION )['fetched_at'] ?? 0 );
$check( true === wmwp_app_config_refresh(), '304 vale come riuscito' );
$check( '"a"' === ( $sent['If-None-Match'] ?? '' ), 'la seconda richiesta porta If-None-Match' );
$check( 'Tue, 24 Nov 2026 05:00:32 GMT' === ( $sent['If-Modified-Since'] ?? '' ), 'la seconda richiesta porta If-Modified-Since' );
$check( 'hiking' === ( wmwp_app_config()['MAP']['filters']['activities']['options'][0]['identifier'] ?? '' ), 'dopo 304 la copia resta' );
$check( (int) ( get_option( WMWP_APP_CONFIG_OPTION )['fetched_at'] ?? 0 ) >= $before, 'dopo 304 fetched_at aggiornato' );

// 5. Errore: la copia resta, il cron dice false
$answer = 500;
$check( false === wmwp_app_config_refresh(), 'errore: refresh dà false' );
$check( 'hiking' === ( wmwp_app_config()['MAP']['filters']['activities']['options'][0]['identifier'] ?? '' ), "dopo un errore resta l'ultima copia buona" );

// 5b. Un 200 con un JSON che non è un config.json (senza MAP, es. un errore dello shard) non sostituisce la copia buona
$answer = 'nomap';
$check( false === wmwp_app_config_refresh(), 'JSON senza MAP: refresh dà false' );
$check( 'hiking' === ( wmwp_app_config()['MAP']['filters']['activities']['options'][0]['identifier'] ?? '' ), "JSON senza MAP: resta l'ultima copia buona" );

// 5c. Senza copia buona e con lo shard giù il giro singolo non si ripianifica a ogni richiesta, ma dopo
// WMWP_APP_CONFIG_RETRY dall'ultimo fallimento (review interna di oc:9018)
$check( (int) get_option( WMWP_APP_CONFIG_FAILED_OPTION, 0 ) >= time() - 5, 'fallimento: ora segnata' );
wp_clear_scheduled_hook( WMWP_APP_CONFIG_CRON_HOOK );

// 6. Un'altra app: la copia dell'app di prima non vale, e il giro singolo che si pianifica rispetta l'attesa
update_option( WMWP_OPTION_APP_ID, 2 );
$check( null === wmwp_app_config(), "la copia di un'altra app non vale" );
$next = (int) wp_next_scheduled( WMWP_APP_CONFIG_CRON_HOOK );
$check( $next >= time() + WMWP_APP_CONFIG_RETRY - 10, 'dopo un fallimento il giro singolo aspetta (' . ( $next - time() ) . ' s)' );
wp_clear_scheduled_hook( WMWP_APP_CONFIG_CRON_HOOK );
delete_option( WMWP_APP_CONFIG_FAILED_OPTION );
$check( null === wmwp_app_config() && (int) wp_next_scheduled( WMWP_APP_CONFIG_CRON_HOOK ) <= time() + 5, 'senza fallimenti il giro singolo è subito' );
wp_clear_scheduled_hook( WMWP_APP_CONFIG_CRON_HOOK );
update_option( WMWP_OPTION_APP_ID, 1 );
update_option( WMWP_APP_CONFIG_FAILED_OPTION, time(), false );
$answer = 200;
$check( true === wmwp_app_config_refresh() && false === get_option( WMWP_APP_CONFIG_FAILED_OPTION ), 'un download riuscito toglie il fallimento' );

// 7. La mappa usa la stessa copia, senza richieste
$calls = 0;
$check( [ [ 'id' => 11, 'color' => '#de1b0d' ] ] === wmwp_map_layer_colors(), 'i colori della mappa vengono dalla copia condivisa' );
$check( 0 === $calls, "la mappa non chiama lo shard, invece {$calls} richieste" );

remove_filter( 'pre_http_request', $fake, 10 );
delete_transient( 'wmwp_map_config_' . md5( wmwp_app_config_url() ) );
$restore();

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
