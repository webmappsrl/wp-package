<?php
/**
 * Test (oc:9018): indirizzi vecchi degli elementi (es. quelli del sito di prima) nello storico prima che servano. Il tema
 * li dà con il filtro `wmwp_detail_legacy_paths` dalle proprietà dell'elemento: per i POI quelle di pois.geojson, per i
 * percorsi quelle del dettaglio, letto solo per quelli che non sono ancora passati (una volta). Righe non attuali, con
 * la chiave dell'elemento: il percorso vecchio dà 301 alla pagina attuale, anche codificato e in un'altra lingua.
 * Shard finto (pre_http_request), storico nella sandbox.
 * Si lancia con: wp eval-file tests/php/detail-legacy.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require __DIR__ . '/history-sandbox.php';
add_filter( 'wmwp_track_kind_taxonomy', fn() => '', 99 );

$failures = [];
$check    = function ( bool $ok, string $what ) use ( &$failures ) {
	if ( ! $ok ) {
		$failures[] = $what;
	}
};

$saved_shard = get_option( WMWP_OPTION_SHARD );
$saved_app   = get_option( WMWP_OPTION_APP_ID );
$saved_done  = get_option( WMWP_DETAIL_LEGACY_OPTION, null );
$saved_fail  = get_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, null );
delete_option( WMWP_DETAIL_LEGACY_FAILED_OPTION );
update_option( WMWP_OPTION_SHARD, 'forestasdev' );
update_option( WMWP_OPTION_APP_ID, 1 );
delete_option( WMWP_DETAIL_LEGACY_OPTION );
$urls = function ( $urls ) {
	return [
		'elastic_api' => 'http://shard.test/api/v2/elasticsearch',
		'origin'      => 'http://shard.test',
		'aws_api'     => '',
	];
};
add_filter( 'wmwp_api_urls', $urls, 99 );

// Chiave della fonte: il nid del sito di prima, come fa il tema di Forestas
$source = fn( $value, $type, $props ) => (string) ( $props['sardegnasentieri_id'] ?? $props['forestas']['sardegnasentieri_id'] ?? '' );
add_filter( 'wmwp_detail_source', $source, 10, 3 );
// Indirizzi vecchi dal campo `old_url` (il tema di Forestas li prende da forestas.url): italiano e inglese
$legacy = function ( $paths, $type, $props ) {
	$url = (string) ( $props['old_url'] ?? '' );
	if ( '' === $url ) {
		return $paths;
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	return [
		[ 'lang' => 'it', 'path' => $path ],
		[ 'lang' => 'en', 'path' => $path ],
	];
};
add_filter( 'wmwp_detail_legacy_paths', $legacy, 10, 3 );

$detail_calls = 0;
$list_calls   = 0;
$fake         = function ( $pre, $args, $url ) use ( &$detail_calls, &$list_calls ) {
	$json = function ( $data ) {
		return [ 'headers' => [], 'body' => wp_json_encode( $data ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
	};
	if ( false !== strpos( $url, 'elasticsearch' ) ) {
		++$list_calls;
		return $json(
			[
				'hits' => [
					[ 'id' => 10, 'name' => [ 'it' => 'Salita al Corrasi' ], 'sardegnasentieri_id' => 401 ],
					[ 'id' => 11, 'name' => [ 'it' => 'Sentiero senza indirizzo' ], 'sardegnasentieri_id' => 402 ],
					[ 'id' => 12, 'name' => [ 'it' => 'Sentiero che non risponde' ], 'sardegnasentieri_id' => 403 ],
				],
			]
		);
	}
	if ( false !== strpos( $url, 'pois.geojson' ) ) {
		return $json(
			[
				'type'     => 'FeatureCollection',
				'features' => [
					[
						'type'       => 'Feature',
						'geometry'   => null,
						'properties' => [
							'id'       => 20,
							'name'     => [ 'it' => 'Nuraghe Losa' ],
							'forestas' => [ 'sardegnasentieri_id' => 900 ],
							'old_url'  => 'https://www.sito-vecchio.example/da-vedere/nuraghe-losa',
						],
					],
				],
			]
		);
	}
	if ( false !== strpos( $url, '/api/ec/track/10' ) ) {
		++$detail_calls;
		return $json( [ 'type' => 'Feature', 'properties' => [ 'id' => 10, 'old_url' => 'https://www.sito-vecchio.example/sentieri/s%E2%80%99araighinos-t-511b' ] ] );
	}
	if ( false !== strpos( $url, '/api/ec/track/12' ) ) {
		++$detail_calls;
		return new WP_Error( 'wmwp_test_down', 'dettaglio non raggiungibile (test)' );
	}
	if ( false !== strpos( $url, '/api/ec/track/11' ) ) {
		++$detail_calls;
		return $json( [ 'type' => 'Feature', 'properties' => [ 'id' => 11 ] ] );
	}
	return $pre;
};
add_filter( 'pre_http_request', $fake, 10, 3 );

// Cache dei dettagli dei due percorsi di prova (id che il sito vero può avere): vuota prima e dopo il test
$forget_details = function () {
	foreach ( [ 10, 11, 12 ] as $id ) {
		delete_transient( 'wmwp_detail_json_track_' . $id );
		delete_transient( 'wmwp_detail_chk_track_' . $id );
	}
};
$forget_details();

$rebuild = function () {
	delete_transient( 'wmwp_detail_index' );
	delete_transient( 'wmwp_detail_index_lock' );
	delete_option( 'wmwp_detail_index_rebuilt_at' );
	delete_option( 'wmwp_detail_index_last' );
	return wmwp_detail_index( true );
};

// Codice WPML dell'inglese del sito (può essere en-us): il tema dà `en`, il plugin lo abbina per prefisso
$en = (string) ( array_values( array_filter( wmwp_detail_languages(), fn( $code ) => 0 === strpos( $code, 'en' ) ) )[0] ?? 'en' );

// 1. Primo giro: indirizzi vecchi nello storico, non attuali
$index = $rebuild();
$check( is_array( $index ) && 4 === (int) ( $index['count'] ?? 0 ), 'indice con tre percorsi e un POI' );
$check( 3 === $detail_calls, "dettaglio letto per i tre percorsi, invece di {$detail_calls}" );
// Un dettaglio che non risponde non blocca gli altri: si segna e si riprova dopo WMWP_DETAIL_LEGACY_RETRY
$check( isset( get_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, [] )['track:s:403'] ), 'il percorso che non risponde è segnato' );
$check( ! isset( get_option( WMWP_DETAIL_LEGACY_OPTION, [] )['track:s:403'] ), 'il percorso che non risponde non è fra quelli passati' );
// Un percorso senza indirizzo vecchio (dettaglio letto da una copia senza `old_url`) non è passato: si riprova come un
// fallito, perché il dato può arrivare dopo
$check( ! isset( get_option( WMWP_DETAIL_LEGACY_OPTION, [] )['track:s:402'] ) && isset( get_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, [] )['track:s:402'] ), 'percorso senza indirizzo: non passato, da riprovare' );
// I POI non si segnano: gli indirizzi stanno in pois.geojson e si ricalcolano a ogni giro, senza letture
$check( ! isset( get_option( WMWP_DETAIL_LEGACY_OPTION, [] )['poi:s:900'] ), 'POI: mai fra quelli passati' );
$poi = wmwp_detail_history_lookup( 'it', 'da-vedere/nuraghe-losa' );
$check( is_array( $poi ) && 'poi' === $poi['type'] && 's:900' === $poi['key'] && false === $poi['current'], 'POI: indirizzo vecchio nello storico, non attuale' );
$track = wmwp_detail_history_lookup( 'it', 'sentieri/s%e2%80%99araighinos-t-511b' );
$check( is_array( $track ) && 's:401' === $track['key'] && false === $track['current'], 'percorso: indirizzo vecchio codificato nello storico' );
$check( is_array( wmwp_detail_history_lookup( $en, 'sentieri/s%e2%80%99araighinos-t-511b' ) ), 'percorso: anche in inglese' );

// 2. Il percorso vecchio porta alla pagina attuale: codificato (maiuscolo o minuscolo) o con il carattere vero
foreach ( [ 'sentieri/s%E2%80%99araighinos-t-511b', 'sentieri/s’araighinos-t-511b' ] as $old ) {
	$result = wmwp_detail_resolve( 'it', $old );
	$check( 301 === $result['status'] && false !== strpos( (string) ( $result['location'] ?? '' ), 'salita-al-corrasi' ), "301 da {$old} alla pagina attuale (" . wp_json_encode( $result ) . ')' );
}
$check( 301 === wmwp_detail_resolve( $en, 'sentieri/s%E2%80%99araighinos-t-511b' )['status'], '301 anche dalla pagina inglese' );
$check( in_array( 'sentieri', wmwp_detail_bases(), true ) && in_array( 'da-vedere', wmwp_detail_bases(), true ), 'le basi vecchie entrano nelle regole' );

// 3. Secondo giro: nessuna nuova lettura dei dettagli
$detail_calls = 0;
$rebuild();
$check( 0 === $detail_calls, "secondo giro: nessuna lettura dei dettagli (il fallito aspetta), invece di {$detail_calls}" );
// Passata l'attesa, il percorso che non rispondeva si riprova
update_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, [ 'track:s:403' => time() - WMWP_DETAIL_LEGACY_RETRY - 1, 'track:s:402' => time() ], false );
$detail_calls = 0;
$rebuild();
$check( 1 === $detail_calls, "dopo l'attesa: si riprova il percorso che non rispondeva, invece di {$detail_calls}" );

// 3a. Review interna (C2): un percorso letto senza errori ma senza indirizzo vecchio si riprova al più
// WMWP_DETAIL_LEGACY_EMPTY_TRIES volte, poi è passato (creato in Nova, nessun indirizzo Drupal); un errore di lettura
// invece non conta. Le chiavi di elementi che non sono più nell'indice si tolgono
update_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, [ 'track:s:403' => time(), 'track:s:402' => [ 'at' => time() - WMWP_DETAIL_LEGACY_RETRY - 1, 'empty' => WMWP_DETAIL_LEGACY_EMPTY_TRIES - 1 ], 'track:s:998' => time() ], false );
update_option( WMWP_DETAIL_LEGACY_OPTION, get_option( WMWP_DETAIL_LEGACY_OPTION, [] ) + [ 'track:s:999' => true ], false );
$forget_details();
$detail_calls = 0;
$rebuild();
$check( 1 === $detail_calls, "ultimo tentativo del percorso senza indirizzo: una lettura, invece di {$detail_calls}" );
$check( isset( get_option( WMWP_DETAIL_LEGACY_OPTION, [] )['track:s:402'] ) && ! isset( get_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, [] )['track:s:402'] ), 'percorso senza indirizzo dopo i tentativi: passato' );
$check( ! isset( get_option( WMWP_DETAIL_LEGACY_OPTION, [] )['track:s:999'] ) && ! isset( get_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, [] )['track:s:998'] ), 'chiavi di elementi fuori dall\'indice tolte' );
$check( isset( get_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, [] )['track:s:403'] ), 'il percorso che non risponde resta da riprovare' );
$detail_calls = 0;
$rebuild();
$check( 0 === $detail_calls, "dopo: il percorso senza indirizzo non si rilegge, invece di {$detail_calls}" );

// 3b. In una pagina (non cron, non WP-CLI) il giro non legge dettagli: li lascia al cron, che pianifica (review finale)
delete_option( WMWP_DETAIL_LEGACY_OPTION );
delete_option( WMWP_DETAIL_LEGACY_FAILED_OPTION );
$forget_details();
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
$in_page = fn() => false;
add_filter( 'wmwp_detail_legacy_can_read', $in_page );
$detail_calls = 0;
$rebuild();
remove_filter( 'wmwp_detail_legacy_can_read', $in_page );
$check( 0 === $detail_calls, "in una pagina nessuna lettura dei dettagli, invece di {$detail_calls}" );
$check( false !== wp_next_scheduled( WMWP_DETAIL_CRON_HOOK ), 'in una pagina: giro del cron pianificato per le letture' );
$check( ! isset( get_option( WMWP_DETAIL_LEGACY_OPTION, [] )['track:s:401'] ), 'in una pagina: il percorso non è segnato come passato' );
$check( is_array( wmwp_detail_history_lookup( 'it', 'da-vedere/nuraghe-losa' ) ), 'in una pagina: le righe dei POI, che non leggono niente, ci sono' );
wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );

// 3c. Un 404 sotto una base che c'è solo nello storico (es. /node/ di una pagina editoriale) non forza la ricostruzione
delete_option( 'wmwp_detail_index_rebuilt_at' );
$list_calls = 0;
$result     = wmwp_detail_resolve( 'it', 'sentieri/non-esiste' );
$check( 404 === $result['status'] && 0 === $list_calls, "404 sotto una base vecchia senza ricostruzione, invece di {$list_calls} letture dell'elenco" );

// 4. Le righe vecchie restano anche dopo che si dimenticano le righe registrate con l'id
wmwp_detail_history_forget_ids();
$check( is_array( wmwp_detail_history_lookup( 'it', 'da-vedere/nuraghe-losa' ) ), 'le righe vecchie restano' );

remove_filter( 'pre_http_request', $fake, 10 );
$forget_details();
remove_filter( 'wmwp_api_urls', $urls, 99 );
remove_filter( 'wmwp_detail_source', $source, 10 );
remove_filter( 'wmwp_detail_legacy_paths', $legacy, 10 );
update_option( WMWP_OPTION_SHARD, $saved_shard );
update_option( WMWP_OPTION_APP_ID, $saved_app );
if ( null === $saved_fail ) {
	delete_option( WMWP_DETAIL_LEGACY_FAILED_OPTION );
} else {
	update_option( WMWP_DETAIL_LEGACY_FAILED_OPTION, $saved_fail, false );
}
if ( null === $saved_done ) {
	delete_option( WMWP_DETAIL_LEGACY_OPTION );
} else {
	update_option( WMWP_DETAIL_LEGACY_OPTION, $saved_done, false );
}

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
