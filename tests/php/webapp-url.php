<?php
/**
 * Test (oc:8969): indirizzo della webapp dello shard configurato, `wmwp_webapp_url()`. Nell'ordine: l'indirizzo scritto
 * nelle impostazioni (o dal filtro `wmwp_webapp_url`), il dominio proprio dei `redirects` di `environment.ts` per shard
 * e app, altrimenti `https://<appId>.<shard>.webmapp.it/`. Con tipo e id, il focus sull'elemento: `#/map?track=<id>`
 * o `#/map?poi=<id>` (verificati sulla webapp di dev il 13/11).
 * Si lancia con: wp eval-file tests/php/webapp-url.php
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

if ( ! function_exists( 'wmwp_webapp_url' ) || ! function_exists( 'wmwp_parse_webapp_redirects' ) ) {
	echo "FAIL: wmwp_webapp_url o wmwp_parse_webapp_redirects non esiste\n";
	exit( 1 );
}

// 1. Domini propri da environment.ts: solo la sezione `redirects`
$env = "export const shards: Shards = {\n  forestas: {\n    origin: 'https://forestas.maphub.it',\n  },\n};\n"
	. "export const redirects: Redirects = {\n  'sentieri.caiparma.it': {\n    shardName: 'geohub',\n    appId: 33,\n  },\n"
	. "  \"mappa.esempio.it\": { shardName: 'forestas', appId: 1 },\n};\n";
$check(
	[
		'sentieri.caiparma.it' => [ 'shard' => 'geohub', 'app' => 33 ],
		'mappa.esempio.it'     => [ 'shard' => 'forestas', 'app' => 1 ],
	] === wmwp_parse_webapp_redirects( $env ),
	'redirects letti da environment.ts'
);
$check( [] === wmwp_parse_webapp_redirects( 'export const shards = {};' ), 'senza redirects, niente' );

$saved = [
	get_option( WMWP_OPTION_SHARD ),
	get_option( WMWP_OPTION_APP_ID ),
	get_option( WMWP_OPTION_WEBAPP ),
	get_transient( WMWP_WEBAPP_REDIRECTS_KEY ),
];
update_option( WMWP_OPTION_SHARD, 'forestasdev' );
update_option( WMWP_OPTION_APP_ID, 1 );
delete_option( WMWP_OPTION_WEBAPP );
set_transient( WMWP_WEBAPP_REDIRECTS_KEY, [ 'mappa.esempio.it' => [ 'shard' => 'forestas', 'app' => 1 ] ], HOUR_IN_SECONDS );

// 2. Regola <appId>.<shard>.webmapp.it, con il focus
$check( 'https://1.forestasdev.webmapp.it/' === wmwp_webapp_url(), 'dev: https://1.forestasdev.webmapp.it/ (' . wmwp_webapp_url() . ')' );
$check( 'https://1.forestasdev.webmapp.it/#/map?track=312' === wmwp_webapp_url( 'track', 312 ), 'focus su una traccia' );
$check( 'https://1.forestasdev.webmapp.it/#/map?poi=7' === wmwp_webapp_url( 'poi', 7 ), 'focus su un POI' );
$check( 'https://1.forestasdev.webmapp.it/' === wmwp_webapp_url( 'layer', 7 ) && 'https://1.forestasdev.webmapp.it/' === wmwp_webapp_url( 'track', 0 ), 'tipo sconosciuto o id non valido: solo la mappa' );

// 3. Dominio proprio dai redirects per lo shard e l'app configurati
update_option( WMWP_OPTION_SHARD, 'forestas' );
$check( 'https://mappa.esempio.it/' === wmwp_webapp_url(), 'produzione con dominio proprio da environment.ts' );
update_option( WMWP_OPTION_APP_ID, 2 );
$check( 'https://2.forestas.webmapp.it/' === wmwp_webapp_url(), 'il dominio proprio vale solo per la sua app' );
update_option( WMWP_OPTION_APP_ID, 1 );

// 4. Indirizzo scritto nelle impostazioni: vince, con la barra finale
update_option( WMWP_OPTION_WEBAPP, 'https://mappa.forestas.it' );
$check( 'https://mappa.forestas.it/' === wmwp_webapp_url() && 'https://mappa.forestas.it/#/map?track=5' === wmwp_webapp_url( 'track', 5 ), 'indirizzo delle impostazioni' );
delete_option( WMWP_OPTION_WEBAPP );

// 5. Filtro: per i temi
$filtro = fn() => 'https://altra.webapp.it';
add_filter( 'wmwp_webapp_url', $filtro );
$check( 'https://altra.webapp.it/#/map?poi=3' === wmwp_webapp_url( 'poi', 3 ), 'filtro wmwp_webapp_url' );
remove_filter( 'wmwp_webapp_url', $filtro );

// 6. Senza shard o app: niente
update_option( WMWP_OPTION_SHARD, '' );
$check( '' === wmwp_webapp_url() && '' === wmwp_webapp_url( 'track', 5 ), 'senza shard, stringa vuota' );

// 7. GitHub non risponde: una sola richiesta, poi l'ultima lettura riuscita resta in cache (un'ora), senza riprovare a
// ogni chiamata (il tema chiede la webapp più volte per pagina)
$richieste = 0;
$giu       = function ( $pre, $args, $url ) use ( &$richieste ) {
	if ( WMWP_SHARDS_CONFIG_URL !== $url ) {
		return $pre;
	}
	++$richieste;
	return new WP_Error( 'http_request_failed', 'timeout' );
};
add_filter( 'pre_http_request', $giu, 10, 3 );
$saved_shards = [ get_transient( WMWP_SHARDS_CACHE_KEY ), get_option( WMWP_WEBAPP_REDIRECTS_LAST ) ];
update_option( WMWP_WEBAPP_REDIRECTS_LAST, [ 'mappa.esempio.it' => [ 'shard' => 'forestas', 'app' => 1 ] ], false );
delete_transient( WMWP_SHARDS_CACHE_KEY );
delete_transient( WMWP_WEBAPP_REDIRECTS_KEY );
$primo   = wmwp_get_webapp_redirects();
$secondo = wmwp_get_webapp_redirects();
remove_filter( 'pre_http_request', $giu, 10 );
$check( 1 === $richieste, 'GitHub giù: una sola richiesta per più chiamate (' . $richieste . ')' );
$check( isset( $primo['mappa.esempio.it'], $secondo['mappa.esempio.it'] ), 'GitHub giù: ultima lettura riuscita' );
false === $saved_shards[0] ? delete_transient( WMWP_SHARDS_CACHE_KEY ) : set_transient( WMWP_SHARDS_CACHE_KEY, $saved_shards[0], DAY_IN_SECONDS );
false === $saved_shards[1] ? delete_option( WMWP_WEBAPP_REDIRECTS_LAST ) : update_option( WMWP_WEBAPP_REDIRECTS_LAST, $saved_shards[1], false );

update_option( WMWP_OPTION_SHARD, $saved[0] );
update_option( WMWP_OPTION_APP_ID, $saved[1] );
false === $saved[2] ? delete_option( WMWP_OPTION_WEBAPP ) : update_option( WMWP_OPTION_WEBAPP, $saved[2] );
false === $saved[3] ? delete_transient( WMWP_WEBAPP_REDIRECTS_KEY ) : set_transient( WMWP_WEBAPP_REDIRECTS_KEY, $saved[3], DAY_IN_SECONDS );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
