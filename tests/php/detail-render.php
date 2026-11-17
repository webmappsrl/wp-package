<?php
/**
 * Test (oc:8969): lettura del dettaglio e controllo che l'id porti ancora all'elemento chiesto. Lo shard è finto
 * (`pre_http_request`). Una traccia il cui nome dà lo slug chiesto è valida; con un altro nome (l'id è passato a
 * un'altra traccia) è un 404; un POI, il cui dettaglio non ha il nome, si controlla con l'id della fonte; con lo shard
 * in errore il risultato è un WP_Error (503), o la copia in cache se c'è (oc:8990, casi 5).
 * Si lancia con: wp eval-file tests/php/detail-render.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require __DIR__ . '/history-sandbox.php';

// Gli avvisi del plugin (error_log) vanno su un file temporaneo: i casi del ripiego (5) li leggono, e l'uscita del test
// resta «PASS» o «FAIL: …»
$log_file = (string) tempnam( sys_get_temp_dir(), 'wmwp-log' );
$log_was  = (string) ini_get( 'error_log' );
ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
register_shutdown_function(
	function () use ( $log_file, $log_was ) {
		ini_set( 'error_log', $log_was ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		wp_delete_file( $log_file );
	}
);

$failures = [];
$check    = function ( bool $ok, string $what ) use ( &$failures ) {
	if ( ! $ok ) {
		$failures[] = $what;
	}
};

if ( ! function_exists( 'wmwp_detail_load' ) ) {
	echo "FAIL: wmwp_detail_load non esiste\n";
	exit( 1 );
}

// Le tracce finte stanno solo nell'API dello shard: niente file su AWS, anche se l'ambiente ha `aws_api`
add_filter(
	'wmwp_api_urls',
	function ( $urls ) {
		$urls['aws_api'] = '';
		return $urls;
	},
	99
);

$saved = [ get_transient( 'wmwp_detail_index' ), get_option( 'wmwp_detail_index_rebuilt_at' ) ];
$index = wmwp_detail_build_index(
	[
		[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Salita' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'poi', 'id' => 9, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null, 'source' => 'D-77' ],
		[ 'type' => 'track', 'id' => 6, 'names' => [ 'it' => 'Discesa' ], 'kind' => [ 'it' => 'Sentiero' ], 'source' => 'T-6' ],
	],
	function ( string $type, ?array $kind, string $lang ): string {
		$name = null === $kind ? '' : wmwp_detail_text( $kind, $lang );
		return '' !== $name ? sanitize_title( $name ) : ( 'poi' === $type ? 'poi' : 'track' );
	},
	[ 'it' ]
);
$index['config'] = wmwp_detail_base_signature();
set_transient( 'wmwp_detail_index', $index, 600 );
// Nessuna ricostruzione: il test non chiama la lista dello shard
update_option( 'wmwp_detail_index_rebuilt_at', time(), false );

$shard = [
	'status' => 200,
	'track'  => [ 'it' => 'Salita' ],
	'source' => 'D-77',
	'track6' => [ 'it' => 'Discesa' ],
	'fonte6' => 'T-6',
];
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$shard ) {
		if ( false === strpos( $url, '/api/ec/' ) ) {
			return $pre;
		}
		if ( false !== strpos( $url, '/api/ec/track/6' ) ) {
			$feature = [ 'type' => 'Feature', 'properties' => [ 'id' => 6, 'name' => $shard['track6'], 'out_source_feature_id' => $shard['fonte6'] ], 'geometry' => null ];
		} else {
			$feature = false !== strpos( $url, '/api/ec/track/' )
				? [ 'type' => 'Feature', 'properties' => [ 'id' => 5, 'name' => $shard['track'] ], 'geometry' => null ]
				: [ 'type' => 'Feature', 'properties' => [ 'id' => 9, 'out_source_feature_id' => $shard['source'] ], 'geometry' => null ];
		}
		return [
			'headers'  => [],
			'body'     => 200 === $shard['status'] ? wp_json_encode( $feature ) : 'errore',
			'response' => [
				'code'    => $shard['status'],
				'message' => '',
			],
			'cookies'  => [],
		];
	},
	10,
	3
);

$track = [
	'status' => 200,
	'type'   => 'track',
	'id'     => 5,
	'lang'   => 'it',
	'path'   => 'sentiero/salita',
];
$poi   = [
	'status' => 200,
	'type'   => 'poi',
	'id'     => 9,
	'lang'   => 'it',
	'path'   => 'poi/nuraghe',
];

// 1. Traccia con il nome giusto
$detail = wmwp_detail_load( $track );
$check( is_array( $detail ) && 5 === $detail['id'] && 'Salita' === $detail['name'], 'traccia valida' );

// 1b. Doppioni della 0.3.0 (oc:8983): il suffisso accettato è quello che l'indice ha dato all'elemento. Una traccia
// senza chiave della fonte su `-2` ha la sua pagina; una con la chiave non fa ricostruire l'indice al visitatore
$indice_render = get_transient( 'wmwp_detail_index' );
$base_render   = function ( string $type, ?array $kind, string $lang ): string {
	$name = null === $kind ? '' : wmwp_detail_text( $kind, $lang );
	return '' !== $name ? sanitize_title( $name ) : ( 'poi' === $type ? 'poi' : 'track' );
};
$con_doppi     = wmwp_detail_build_index(
	[
		[ 'type' => 'track', 'id' => 4, 'names' => [ 'it' => 'Salita' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'track', 'id' => 5, 'names' => [ 'it' => 'Salita' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'track', 'id' => 3, 'names' => [ 'it' => 'Discesa' ], 'kind' => [ 'it' => 'Sentiero' ], 'source' => 'T-1' ],
		[ 'type' => 'track', 'id' => 6, 'names' => [ 'it' => 'Discesa' ], 'kind' => [ 'it' => 'Sentiero' ], 'source' => 'T-6' ],
		[ 'type' => 'track', 'id' => 7, 'names' => [ 'it' => 'Foo 3' ], 'kind' => [ 'it' => 'Sentiero' ] ],
		[ 'type' => 'poi', 'id' => 9, 'names' => [ 'it' => 'Nuraghe' ], 'kind' => null, 'source' => 'D-77' ],
	],
	$base_render,
	[ 'it' ]
);
$con_doppi['config'] = wmwp_detail_base_signature();
set_transient( 'wmwp_detail_index', $con_doppi, 600 );
$salita_2 = [ 'status' => 200, 'type' => 'track', 'id' => 5, 'lang' => 'it', 'path' => 'sentiero/salita-2' ];
$check( 'sentiero/salita-2' === ( $con_doppi['urls']['track'][5]['it'] ?? null ), 'indice di prova: Salita 5 su -2' );
$check( is_array( wmwp_detail_load( $salita_2 ) ), 'doppione -2 senza chiave della fonte → pagina' );
$check( false === wmwp_detail_slug_matches( [ 'id' => 5, 'type' => 'track', 'lang' => 'it', 'path' => 'sentiero/salita-3' ], [ 'name' => [ 'it' => 'Salita' ] ], '' ), 'suffisso che l\'indice non ha dato all\'elemento → no' );
$check( false === wmwp_detail_slug_matches( [ 'id' => 5, 'type' => 'track', 'lang' => 'it', 'path' => 'sentiero/salita-alta' ], [ 'name' => [ 'it' => 'Salita' ] ], '' ), 'slug diverso → no' );
// Rinominata da «Foo 3» a «Foo»: l'indice (non ancora ricostruito) le ha dato `foo-3` senza suffisso, quindi il nome
// nuovo non torna
$check( false === wmwp_detail_slug_matches( [ 'id' => 7, 'type' => 'track', 'lang' => 'it', 'path' => 'sentiero/foo-3' ], [ 'name' => [ 'it' => 'Foo' ] ], '' ), 'rinomina da «Foo 3» a «Foo»: foo-3 non è più il suo slug' );
// Con un indice della 0.3.0 le forme della 0.2.0 (`-<id>`, `-<chiave>`) non valgono più: «Foo 3» con id 3 rinominata
// «Foo» non resta servita su `foo-3`
$foo_tre = wmwp_detail_build_index( [ [ 'type' => 'track', 'id' => 3, 'names' => [ 'it' => 'Foo 3' ], 'kind' => [ 'it' => 'Sentiero' ] ] ], $base_render, [ 'it' ] );
$foo_tre['config'] = wmwp_detail_base_signature();
set_transient( 'wmwp_detail_index', $foo_tre, 600 );
$check( isset( $foo_tre['suffixes'] ), 'indice 0.3.0 riconoscibile anche senza doppioni' );
$check( false === wmwp_detail_slug_matches( [ 'id' => 3, 'type' => 'track', 'lang' => 'it', 'path' => 'sentiero/foo-3' ], [ 'name' => [ 'it' => 'Foo' ] ], '' ), 'rinomina da «Foo 3» a «Foo» con id 3: la forma -<id> non vale più' );
set_transient( 'wmwp_detail_index', $con_doppi, 600 );
delete_option( 'wmwp_detail_index_rebuilt_at' );
$check( is_array( wmwp_detail_load( [ 'status' => 200, 'type' => 'track', 'id' => 6, 'lang' => 'it', 'path' => 'sentiero/discesa-2' ] ) ), 'doppione -2 con la chiave della fonte → pagina' );
$check( false === get_option( 'wmwp_detail_index_rebuilt_at' ), 'doppione -2 con la chiave della fonte → nessuna ricostruzione' );
set_transient( 'wmwp_detail_index', $indice_render, 600 );
update_option( 'wmwp_detail_index_rebuilt_at', time(), false );
$check( is_array( $detail ) && isset( $detail['feature']['properties'] ), 'il dettaglio porta il JSON' );

// 2. L'id è passato a un'altra traccia → 404 (null)
delete_transient( 'wmwp_detail_json_track_5' );
delete_transient( 'wmwp_detail_json_poi_9' );
$shard['track'] = [ 'it' => 'Altro nome' ];
$check( null === wmwp_detail_load( $track ), 'nome che non torna → null' );

// 3. POI senza nome: controllo con l'id della fonte, nome dall'indice
delete_transient( 'wmwp_detail_json_track_5' );
delete_transient( 'wmwp_detail_json_poi_9' );
$detail = wmwp_detail_load( $poi );
$check( is_array( $detail ) && 'Nuraghe' === $detail['name'], 'POI valido con il nome dall\'indice' );
$shard['source'] = 'D-78';
delete_transient( 'wmwp_detail_json_poi_9' );
$check( null === wmwp_detail_load( $poi ), 'POI con un altro id della fonte → null' );

// 3a. Traccia con la chiave stabile della fonte: decide la fonte, non il nome. Rinominata resta lei; con lo stesso nome
// ma un'altra fonte (dopo un reset l'id è passato a un'altra traccia omonima) è un 404
$discesa = [
	'status' => 200,
	'type'   => 'track',
	'id'     => 6,
	'lang'   => 'it',
	'path'   => 'sentiero/discesa',
];
$detail  = wmwp_detail_load( $discesa );
$check( is_array( $detail ) && 6 === $detail['id'], 'traccia con la stessa fonte' );
delete_transient( 'wmwp_detail_json_track_6' );
$shard['track6'] = [ 'it' => 'Discesa al mare' ];
$check( is_array( wmwp_detail_load( $discesa ) ), 'traccia rinominata con la stessa fonte → resta valida' );
delete_transient( 'wmwp_detail_json_track_6' );
$shard['track6'] = [ 'it' => 'Discesa' ];
$shard['fonte6'] = 'T-99';
$check( null === wmwp_detail_load( $discesa ), 'stesso nome, altra fonte → null' );
$shard['fonte6'] = 'T-6';
delete_transient( 'wmwp_detail_json_track_6' );

// Doppione con l'indirizzo -<fonte> e un dettaglio senza la fonte (file generato prima che la fonte ci fosse): il nome
// decide, con il suffisso della fonte dell'indice
// Le forme della 0.2.0 valgono solo con un indice della 0.2.0 in cache: senza `suffixes`
$indice_020 = get_transient( 'wmwp_detail_index' );
unset( $indice_020['suffixes'] );
set_transient( 'wmwp_detail_index', $indice_020, 600 );
$monte = [ 'type' => 'track', 'id' => 21, 'lang' => 'it', 'path' => 'sentiero/monte-4076' ];
$check( wmwp_detail_matches( $monte, [ 'properties' => [ 'name' => [ 'it' => 'Monte' ] ] ], [ 'source' => '4076' ] ), 'doppione -<fonte>, dettaglio senza fonte' );
// Un suffisso numerico non dice più di chi è il percorso (`-2` e `-4076` hanno la stessa forma, oc:8983): la forma
// vecchia con una chiave testuale sì
$check( ! wmwp_detail_matches( [ 'type' => 'track', 'id' => 21, 'lang' => 'it', 'path' => 'sentiero/monte-n-12-b' ], [ 'properties' => [ 'name' => [ 'it' => 'Monte' ] ] ], [ 'source' => '4077' ] ), 'doppione -<fonte> testuale di un altro elemento' );
set_transient( 'wmwp_detail_index', $index, 600 );

// 3b. Cache breve del JSON (60 s): due letture di fila chiamano lo shard una volta; un errore non si mette in cache
$shard['source'] = 'D-77';
$calls8969       = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$calls8969 ) {
		if ( false !== strpos( $url, '/api/ec/' ) ) {
			++$calls8969;
		}
		return $pre;
	},
	5,
	3
);
delete_transient( 'wmwp_detail_json_track_5' );
$shard['track'] = [ 'it' => 'Salita' ];
wmwp_detail_fetch( 'track', 5 );
wmwp_detail_fetch( 'track', 5 );
$check( 1 === $calls8969, 'due letture in 60 s → una chiamata allo shard (' . $calls8969 . ')' );
add_filter( 'wmwp_detail_cache_ttl', '__return_zero' );
delete_transient( 'wmwp_detail_json_track_5' );
$calls8969 = 0;
wmwp_detail_fetch( 'track', 5 );
wmwp_detail_fetch( 'track', 5 );
$check( 2 === $calls8969, 'cache spenta con il filtro → due chiamate' );
remove_all_filters( 'wmwp_detail_cache_ttl' );
delete_transient( 'wmwp_detail_json_track_5' );
// In cache la versione di prima della rinomina: si rilegge lo shard invece di dare 404
set_transient( 'wmwp_detail_json_track_5', [ 'feature' => [ 'type' => 'Feature', 'properties' => [ 'id' => 5, 'name' => [ 'it' => 'Vecchia' ] ], 'geometry' => null ], 'checked' => time() ], 60 );
$riletta = wmwp_detail_load( $track );
$check( is_array( $riletta ) && 'Salita' === $riletta['name'], 'JSON vecchio in cache → riletto dallo shard, non 404' );
$check( 'Salita' === ( get_transient( 'wmwp_detail_json_track_5' )['feature']['properties']['name']['it'] ?? '' ), 'la cache prende il JSON nuovo' );
delete_transient( 'wmwp_detail_json_track_5' );

// 4. Elemento che la fonte non ha (404: cancellato, o id che non esiste): pagina 404, non 503
$shard['status'] = 404;
delete_transient( 'wmwp_detail_json_track_5' );
$assente = wmwp_detail_fetch( 'track', 5, true );
$check( is_wp_error( $assente ) && 'wmwp_detail_not_found' === $assente->get_error_code(), 'fetch: 404 della fonte → wmwp_detail_not_found' );
$check( null === wmwp_detail_load( $track ), 'elemento cancellato → null (404), non WP_Error (503)' );
$check( null === wmwp_detail_load( $poi ), 'POI cancellato → null (404)' );

// 4b. Shard in errore (5xx, rete) → WP_Error: la pagina è «non disponibile» (503)
$shard['status'] = 500;
delete_transient( 'wmwp_detail_json_track_5' );
$errore = wmwp_detail_load( $track );
$check( is_wp_error( $errore ) && 'wmwp_detail_unavailable' === $errore->get_error_code(), 'shard in errore → WP_Error wmwp_detail_unavailable' );
$check( false === get_transient( 'wmwp_detail_json_track_5' ), 'l\'errore non va in cache' );

// 5. Ripiego sulla copia (oc:8990): con la fonte che non risponde e il JSON in cache, il dettaglio esce dalla copia, con
// un avviso nel log e il segnale per l'header `X-WMWP-Stale`. Il 404 della fonte, `$fresh = true` e il filtro spento
// restano come prima. Shard e AWS finti a parte (priorità 20, dopo quello sopra), con il contatore delle richieste
// all'API; gli avvisi si leggono dal file temporaneo dell'inizio
$fake      = [
	'api'   => 500,
	'aws'   => 404,
	'calls' => 0,
];
$fake_aws  = 'http://aws.test/wmfe/ripiego';
$fake_http = function ( $pre, $args, $url ) use ( &$fake, $fake_aws ) {
	if ( 0 === strpos( $url, $fake_aws . '/tracks/' ) ) {
		$code = $fake['aws'];
	} elseif ( preg_match( '#/api/ec/(?:track|poi)/\d+$#', $url ) ) {
		++$fake['calls'];
		if ( 0 === $fake['api'] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
		}
		$code = $fake['api'];
	} else {
		return $pre;
	}
	return [
		'headers'  => [ 'etag' => '"c1"' ],
		'body'     => 200 === $code ? wp_json_encode( [ 'type' => 'Feature', 'properties' => [ 'id' => 8990, 'name' => [ 'it' => 'Fresca' ] ], 'geometry' => null ] ) : '',
		'response' => [
			'code'    => $code,
			'message' => '',
		],
		'cookies'  => [],
	];
};
add_filter( 'pre_http_request', $fake_http, 20, 3 );
$with_aws = function ( $urls ) use ( $fake_aws ) {
	$urls['aws_api'] = $fake_aws;
	return $urls;
};
// Copia del JSON in cache, con l'ETag, e il segno del controllo scaduto: la lettura dopo chiede alla fonte
$copy    = function ( string $type, int $id, array $props = [], int $expires = HOUR_IN_SECONDS ) {
	$feature = [ 'type' => 'Feature', 'properties' => $props + [ 'id' => $id, 'name' => [ 'it' => 'Copia' ] ], 'geometry' => null ];
	set_transient( 'wmwp_detail_json_' . $type . '_' . $id, [ 'feature' => $feature, 'etag' => '"c1"', 'modified' => '' ], $expires );
	delete_transient( 'wmwp_detail_chk_' . $type . '_' . $id );
};
$name_of = function ( $feature ): string {
	return is_array( $feature ) ? (string) ( $feature['properties']['name']['it'] ?? '' ) : 'errore';
};
$log_lines = function () use ( $log_file ): array {
	$lines = file( $log_file, FILE_IGNORE_NEW_LINES );
	$ours  = function ( string $line ): bool {
		return false !== strpos( $line, '[wp-package] dettaglio' );
	};
	return array_values( array_filter( is_array( $lines ) ? $lines : [], $ours ) );
};
// Una sola riga del log contiene il testo
$log_has   = function ( string $text ) use ( $log_lines ): bool {
	$match = function ( string $line ) use ( $text ): bool {
		return false !== strpos( $line, $text );
	};
	return 1 === count( array_filter( $log_lines(), $match ) );
};
$log_clear = function () use ( $log_file ) {
	file_put_contents( $log_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
};
set_transient( 'wmwp_detail_index', $index, 600 );
update_option( 'wmwp_detail_index_rebuilt_at', time(), false );

// 5a. Copia in cache, segno scaduto, API 500 → la copia, il segnale e un avviso
$check( ! wmwp_detail_served_stale(), 'ripiego: prima del ripiego nessun segnale' );
$copy( 'poi', 8990 );
$log_clear();
$fake['api']   = 500;
$fake['calls'] = 0;
$check( 'Copia' === $name_of( wmwp_detail_fetch( 'poi', 8990 ) ), 'ripiego: API 500 con la copia → la copia' );
$check( wmwp_detail_served_stale() && wmwp_detail_served_stale( 'poi', 8990 ), 'ripiego: segnale acceso' );
$check( $log_has( '[wp-package] dettaglio poi 8990 dalla copia, fonte non raggiungibile: API: HTTP 500' ), 'ripiego: avviso nel log (' . implode( ' | ', $log_lines() ) . ')' );

// 5b. Seconda visita nello stesso minuto: nessuna richiesta, nessun avviso, la copia resta segnalata
$check( 'Copia' === $name_of( wmwp_detail_fetch( 'poi', 8990 ) ), 'ripiego: seconda visita → la copia' );
$check( 1 === $fake['calls'], 'ripiego: due visite in 60 s → una richiesta allo shard (' . $fake['calls'] . ')' );
$check( 1 === count( $log_lines() ), 'ripiego: due visite in 60 s → un avviso (' . count( $log_lines() ) . ')' );
$check( wmwp_detail_served_stale( 'poi', 8990 ), 'ripiego: seconda visita nello stesso minuto → ancora segnalata' );

// 5c. Errore di rete: il motivo nel log è il messaggio dell'errore
$copy( 'poi', 8990 );
$log_clear();
$fake['api'] = 0;
$check( 'Copia' === $name_of( wmwp_detail_fetch( 'poi', 8990 ) ), 'ripiego: errore di rete con la copia → la copia' );
$check( $log_has( 'fonte non raggiungibile: API: cURL error 28: timeout' ), 'ripiego: errore di rete, motivo nel log (' . implode( ' | ', $log_lines() ) . ')' );

// 5d. Traccia: file su AWS che dà 404 e API 500 → la copia (il 404 del file non vuol dire che la traccia non c'è)
add_filter( 'wmwp_api_urls', $with_aws, 100 );
$copy( 'track', 8990 );
$log_clear();
$fake['api'] = 500;
$fake['aws'] = 404;
$check( 'Copia' === $name_of( wmwp_detail_fetch( 'track', 8990 ) ), 'ripiego: traccia con file AWS 404 e API 500 → la copia' );
$check( wmwp_detail_served_stale( 'track', 8990 ), 'ripiego: traccia, segnale acceso' );
$check( $log_has( '[wp-package] dettaglio track 8990 dalla copia, fonte non raggiungibile: file AWS: HTTP 404; API: HTTP 500' ), 'ripiego: traccia, motivo del file e dell\'API (' . implode( ' | ', $log_lines() ) . ')' );
remove_filter( 'wmwp_api_urls', $with_aws, 100 );

// 5e. Nessuna copia, API 500 → 503 come oggi, con l'avviso
delete_transient( 'wmwp_detail_json_poi_8990' );
delete_transient( 'wmwp_detail_chk_poi_8990' );
$log_clear();
$no_copy = wmwp_detail_fetch( 'poi', 8990 );
$check( is_wp_error( $no_copy ) && 'wmwp_detail_unavailable' === $no_copy->get_error_code(), 'ripiego: nessuna copia → wmwp_detail_unavailable' );
$check( ! wmwp_detail_served_stale( 'poi', 8990 ), 'ripiego: nessuna copia → niente segnale' );
$check( $log_has( '[wp-package] dettaglio poi 8990 503, nessuna copia: API: HTTP 500' ), 'ripiego: nessuna copia, avviso del 503 (' . implode( ' | ', $log_lines() ) . ')' );
$check( false === get_transient( 'wmwp_detail_json_poi_8990' ), 'ripiego: l\'errore non va in cache' );

// 5f. Copia in cache, API 404 → resta 404: l'elemento non c'è più, e la sua copia esce dalla cache. All'errore dopo
// (segno scaduto, API 500) non torna fuori la copia di un elemento cancellato: 503
$copy( 'poi', 8990 );
$fake['api'] = 404;
$not_found   = wmwp_detail_fetch( 'poi', 8990 );
$check( is_wp_error( $not_found ) && 'wmwp_detail_not_found' === $not_found->get_error_code(), 'ripiego: 404 con la copia → wmwp_detail_not_found' );
$check( ! wmwp_detail_served_stale( 'poi', 8990 ), 'ripiego: 404 → niente segnale' );
$check( false === get_transient( 'wmwp_detail_json_poi_8990' ), 'ripiego: 404 → la copia esce dalla cache' );
delete_transient( 'wmwp_detail_chk_poi_8990' );
$fake['api']  = 500;
$after_delete = wmwp_detail_fetch( 'poi', 8990 );
$check( is_wp_error( $after_delete ) && 'wmwp_detail_unavailable' === $after_delete->get_error_code(), 'ripiego: 404 e poi API 500 → wmwp_detail_unavailable, non la copia dell\'elemento cancellato' );

// 5g. Copia in cache, API 500, $fresh = true → 503 come oggi: chi chiede la lettura fresca vuole il verdetto della fonte
$copy( 'poi', 8990 );
$log_clear();
$fake['api'] = 500;
$fresh_read  = wmwp_detail_fetch( 'poi', 8990, true );
$check( is_wp_error( $fresh_read ) && 'wmwp_detail_unavailable' === $fresh_read->get_error_code(), 'ripiego: $fresh = true con la copia → wmwp_detail_unavailable' );
$check( ! wmwp_detail_served_stale( 'poi', 8990 ), 'ripiego: $fresh = true → niente segnale' );
$check( $log_has( '[wp-package] dettaglio poi 8990 503, copia non usata: API: HTTP 500' ), 'ripiego: $fresh = true, avviso del 503 con la copia non usata (' . implode( ' | ', $log_lines() ) . ')' );

// 5h. Filtro spento: come oggi
add_filter( 'wmwp_detail_stale_if_error', '__return_false' );
$copy( 'poi', 8990 );
$log_clear();
$filter_off = wmwp_detail_fetch( 'poi', 8990 );
remove_filter( 'wmwp_detail_stale_if_error', '__return_false' );
$check( is_wp_error( $filter_off ) && 'wmwp_detail_unavailable' === $filter_off->get_error_code(), 'ripiego: filtro spento → wmwp_detail_unavailable' );
$check( ! wmwp_detail_served_stale( 'poi', 8990 ), 'ripiego: filtro spento → niente segnale' );
$check( $log_has( '[wp-package] dettaglio poi 8990 503, copia non usata: API: HTTP 500' ), 'ripiego: filtro spento, avviso del 503 con la copia non usata (' . implode( ' | ', $log_lines() ) . ')' );

// 5i. 304 dal file su AWS: la copia vicina alla scadenza torna a 6 ore; lontana dalla scadenza non si riscrive
add_filter( 'wmwp_api_urls', $with_aws, 100 );
$fake['aws']   = 304;
$fake['calls'] = 0;
$timeout_key   = '_transient_timeout_wmwp_detail_json_track_8990';
$copy( 'track', 8990, [], 30 * MINUTE_IN_SECONDS );
$check( 'Copia' === $name_of( wmwp_detail_fetch( 'track', 8990 ) ), 'ripiego: 304 → la copia' );
$remaining = (int) get_option( $timeout_key ) - time();
$check( $remaining > WMWP_DETAIL_JSON_TTL - MINUTE_IN_SECONDS && $remaining <= WMWP_DETAIL_JSON_TTL, 'ripiego: 304 con la copia a 30 minuti dalla scadenza → di nuovo 6 ore (' . $remaining . ' s)' );
$check( ! wmwp_detail_served_stale( 'track', 8990 ), 'ripiego: 304 → niente segnale' );
$copy( 'track', 8990, [], 5 * HOUR_IN_SECONDS );
$expires_before = (int) get_option( $timeout_key );
wmwp_detail_fetch( 'track', 8990 );
$check( $expires_before === (int) get_option( $timeout_key ), 'ripiego: 304 con la copia a 5 ore dalla scadenza → scadenza invariata' );
$check( 0 === $fake['calls'], 'ripiego: 304 → nessuna richiesta all\'API' );
remove_filter( 'wmwp_api_urls', $with_aws, 100 );

// 5j. Pagina: la copia dello stesso elemento (stessa chiave della fonte) dà la pagina, segnalata
$fake['api'] = 500;
$copy( 'track', 6, [ 'name' => [ 'it' => 'Discesa' ], 'out_source_feature_id' => 'T-6' ] );
$page = wmwp_detail_load( $discesa );
$check( is_array( $page ) && 6 === ( $page['id'] ?? 0 ) && ! isset( $page['redirect'] ), 'ripiego: pagina dalla copia' );
$check( wmwp_detail_served_stale(), 'ripiego: pagina dalla copia segnalata (header X-WMWP-Stale)' );

// 5k. Copia di prima di un reset (altra chiave della fonte) e fonte giù: la lettura fresca non usa la copia, 503 e non 404
$copy( 'track', 6, [ 'name' => [ 'it' => 'Discesa' ], 'out_source_feature_id' => 'T-OLD' ] );
$old_copy = wmwp_detail_load( $discesa );
$check( is_wp_error( $old_copy ) && 'wmwp_detail_unavailable' === $old_copy->get_error_code(), 'ripiego: copia di un altro elemento e fonte giù → 503, non 404' );

// 5l. Indirizzo per id con la stessa copia: come con la fonte giù, l'indirizzo dell'indice senza controllo, non ''
$copy( 'track', 6, [ 'name' => [ 'it' => 'Discesa' ], 'out_source_feature_id' => 'T-OLD' ] );
$check( '/sentiero/discesa/' === substr( wmwp_detail_url_by_id( 'track', 6, 'it' ), -18 ), 'ripiego: indirizzo per id con la copia e la fonte giù → indirizzo dell\'indice' );

// 5m. Ripresa dopo un ripiego: con il segno 'stale' scaduto e la fonte che risponde 200, esce il JSON fresco, il segnale
// si spegne, il segno torna 1 e la copia si aggiorna
$fake['api'] = 500;
$copy( 'poi', 8990 );
wmwp_detail_fetch( 'poi', 8990 );
$check( 'stale' === get_transient( 'wmwp_detail_chk_poi_8990' ) && wmwp_detail_served_stale( 'poi', 8990 ), 'ripresa: dopo il ripiego il segno vale stale e il segnale è acceso' );
delete_transient( 'wmwp_detail_chk_poi_8990' );
$fake['api'] = 200;
$recovered   = wmwp_detail_fetch( 'poi', 8990 );
$stored      = get_transient( 'wmwp_detail_json_poi_8990' );
$check( 'Fresca' === $name_of( $recovered ), 'ripresa: con la fonte tornata esce il JSON fresco' );
$check( ! wmwp_detail_served_stale( 'poi', 8990 ), 'ripresa: il segnale si spegne' );
$check( 1 === get_transient( 'wmwp_detail_chk_poi_8990' ), 'ripresa: il segno del controllo torna 1' );
$check( is_array( $stored ) && 'Fresca' === $name_of( $stored['feature'] ?? null ), 'ripresa: la copia è aggiornata' );

remove_filter( 'pre_http_request', $fake_http, 20 );
foreach ( [ 'poi_8990', 'track_8990', 'track_6' ] as $suffix ) {
	delete_transient( 'wmwp_detail_json_' . $suffix );
	delete_transient( 'wmwp_detail_chk_' . $suffix );
}

false === $saved[0] ? delete_transient( 'wmwp_detail_index' ) : set_transient( 'wmwp_detail_index', $saved[0], HOUR_IN_SECONDS );
false === $saved[1] ? delete_option( 'wmwp_detail_index_rebuilt_at' ) : update_option( 'wmwp_detail_index_rebuilt_at', $saved[1], false );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
