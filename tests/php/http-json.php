<?php
/**
 * Test (oc:8990): wmwp_http_json() restituisce anche il messaggio dell'errore di rete nella chiave «error»
 * («» se la richiesta ha avuto risposta). Si lancia con: wp eval-file tests/php/http-json.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fake = null;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$fake ) {
		return null === $fake ? $pre : $fake;
	},
	10,
	3
);

$reply = function ( int $code, string $body ): array {
	return [
		'headers'  => [],
		'body'     => $body,
		'response' => [
			'code'    => $code,
			'message' => '',
		],
		'cookies'  => [],
		'filename' => null,
	];
};

// Errore di rete: stato 0 e messaggio del WP_Error.
$fake   = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
$result = wmwp_http_json( 'https://example.test/x', 5 );
if ( 0 !== $result['code'] || 'cURL error 28: timeout' !== ( $result['error'] ?? null ) ) {
	echo "FAIL: errore di rete, atteso code 0 ed error col messaggio, ottenuto " . wp_json_encode( $result['error'] ?? null ) . "\n";
	exit( 1 );
}

// Risposta 200: error vuoto e dati decodificati.
$fake   = $reply( 200, '{"a":1}' );
$result = wmwp_http_json( 'https://example.test/x', 5 );
if ( '' !== ( $result['error'] ?? null ) || [ 'a' => 1 ] !== $result['data'] ) {
	echo "FAIL: risposta 200, atteso error '' e data ['a' => 1]\n";
	exit( 1 );
}

// Risposta 500: lo stato passa, error resta vuoto.
$fake   = $reply( 500, 'errore' );
$result = wmwp_http_json( 'https://example.test/x', 5 );
if ( 500 !== $result['code'] || '' !== ( $result['error'] ?? null ) ) {
	echo "FAIL: risposta 500, atteso code 500 ed error ''\n";
	exit( 1 );
}

echo "PASS\n";
