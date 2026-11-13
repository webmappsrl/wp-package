<?php
/**
 * Richieste HTTP comuni del plugin.
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Richiesta GET con il JSON della risposta: stato HTTP (0 se la richiesta non è arrivata: rete, timeout), dati
 * decodificati (anche una stringa JSON dentro un JSON, come pois.geojson sul dev; null se non è JSON o lo stato non è
 * 200) e risposta grezza, per le intestazioni. L'indirizzo si usa com'è: per lo shard passa da wmwp_server_url().
 *
 * @param array<string, string> $headers Intestazioni della richiesta.
 * @return array{code: int, data: array<mixed>|null, response: array<string, mixed>|null}
 */
function wmwp_http_json( string $url, int $timeout, array $headers = [] ): array {
	$response = wp_remote_get(
		$url,
		[
			'timeout' => $timeout,
			'headers' => $headers,
		]
	);
	if ( is_wp_error( $response ) ) {
		return [
			'code'     => 0,
			'data'     => null,
			'response' => null,
		];
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = null;
	if ( 200 === $code ) {
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
	}
	return [
		'code'     => $code,
		'data'     => is_array( $data ) ? $data : null,
		'response' => $response,
	];
}
