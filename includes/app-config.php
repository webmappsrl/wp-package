<?php
/**
 * Il config.json dell'app (oc:9018), lo stesso che legge la webapp: una copia sola per mappa e ricerca.
 *
 * Si scarica solo da WP-Cron (`WMWP_APP_CONFIG_CRON_HOOK`, ogni ora, e un giro singolo quando la copia manca), mai
 * nella richiesta di un visitatore, con una richiesta condizionale (`If-None-Match`, `If-Modified-Since`): se il file
 * non è cambiato lo shard risponde 304 e non si riscarica. Un errore lascia l'ultima copia buona e lo scrive nel log.
 * La copia sta in un'option (`WMWP_APP_CONFIG_OPTION`) con l'indirizzo da cui viene: cambiando shard o app la copia
 * vecchia non vale più. La chiave è nuova: la transient di prima (`wmwp_map_config_<md5>`, solo carta e colori)
 * non vale come config.json, altrimenti dopo l'aggiornamento il sito resterebbe un giorno senza i filtri.
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_APP_CONFIG_OPTION    = 'wmwp_app_config_v2';
const WMWP_APP_CONFIG_CRON_HOOK = 'wmwp_app_config_refresh';
const WMWP_APP_CONFIG_TIMEOUT   = 15;
// Ora dell'ultimo download fallito: senza una copia buona il giro singolo si ripianifica non prima di 5 minuti dopo,
// invece che a ogni richiesta (un download da 15 secondi e una riga di log al minuto con lo shard giù)
const WMWP_APP_CONFIG_FAILED_OPTION = 'wmwp_app_config_failed_at';
const WMWP_APP_CONFIG_RETRY         = 5 * MINUTE_IN_SECONDS;

/** Indirizzo del config.json dell'app configurata, la stessa configurazione che legge la webapp ('' senza shard o app). */
function wmwp_app_config_url(): string {
	$origin = wmwp_configured_api_urls()['origin'];
	$app_id = (int) get_option( WMWP_OPTION_APP_ID, 0 );
	return ( '' !== $origin && $app_id > 0 ) ? $origin . '/api/app/webmapp/' . $app_id . '/config.json' : '';
}

/**
 * Ultima copia buona del config.json dell'app configurata; null se non c'è ancora (in quel caso pianifica un giro del
 * cron). Non chiama mai lo shard.
 */
function wmwp_app_config(): ?array {
	$url = wmwp_app_config_url();
	if ( '' === $url ) {
		return null;
	}
	$stored = get_option( WMWP_APP_CONFIG_OPTION, null );
	if ( is_array( $stored ) && $url === ( $stored['url'] ?? '' ) && is_array( $stored['config'] ?? null ) ) {
		return $stored['config'];
	}
	wmwp_app_config_schedule();
	return null;
}

/**
 * Un giro del cron il prima possibile, una volta sola anche con molte richieste; dopo un download fallito, non prima di
 * WMWP_APP_CONFIG_RETRY.
 */
function wmwp_app_config_schedule(): void {
	$when = max( time(), (int) get_option( WMWP_APP_CONFIG_FAILED_OPTION, 0 ) + WMWP_APP_CONFIG_RETRY );
	$next = wp_next_scheduled( WMWP_APP_CONFIG_CRON_HOOK );
	if ( false === $next || $next > $when + MINUTE_IN_SECONDS ) {
		wp_schedule_single_event( $when, WMWP_APP_CONFIG_CRON_HOOK );
	}
}

/**
 * Scarica il config.json (dal cron). Vero se la copia è aggiornata (200 valido o 304), falso se lo shard non risponde
 * o risponde male: la copia di prima resta.
 */
function wmwp_app_config_refresh(): bool {
	$url = wmwp_app_config_url();
	if ( '' === $url ) {
		return false;
	}
	$stored  = get_option( WMWP_APP_CONFIG_OPTION, null );
	$same    = is_array( $stored ) && $url === ( $stored['url'] ?? '' ) && is_array( $stored['config'] ?? null );
	$headers = [];
	if ( $same && '' !== (string) ( $stored['etag'] ?? '' ) ) {
		$headers['If-None-Match'] = (string) $stored['etag'];
	}
	if ( $same && '' !== (string) ( $stored['last_modified'] ?? '' ) ) {
		$headers['If-Modified-Since'] = (string) $stored['last_modified'];
	}
	// Lo legge il server: all'indirizzo con cui WordPress raggiunge lo shard (wmwp_server_origin)
	$response = wp_remote_get(
		wmwp_server_url( $url ),
		[
			'timeout' => WMWP_APP_CONFIG_TIMEOUT,
			'headers' => $headers,
		]
	);
	$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( 304 === $code && $same ) {
		$stored['fetched_at'] = time();
		update_option( WMWP_APP_CONFIG_OPTION, $stored, false );
		delete_option( WMWP_APP_CONFIG_FAILED_OPTION );
		return true;
	}
	$config = 200 === $code ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
	// Un JSON senza `MAP` (es. un errore dello shard con stato 200) non è un config.json: resta la copia di prima
	if ( ! is_array( $config ) || ! is_array( $config['MAP'] ?? null ) ) {
		error_log( 'WP Package: config.json dell\'app non disponibile da ' . $url . ' (' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code ) . '), resta la copia di prima' );
		update_option( WMWP_APP_CONFIG_FAILED_OPTION, time(), false );
		return false;
	}
	update_option(
		WMWP_APP_CONFIG_OPTION,
		[
			'url'           => $url,
			'config'        => $config,
			'etag'          => (string) wp_remote_retrieve_header( $response, 'etag' ),
			'last_modified' => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
			'fetched_at'    => time(),
		],
		false
	);
	delete_option( WMWP_APP_CONFIG_FAILED_OPTION );
	// Overlay e aree dei POI si aggiornano nello stesso giro del cron (wmwp_app_overlays_refresh(), priorità 20)
	return true;
}

add_action( WMWP_APP_CONFIG_CRON_HOOK, 'wmwp_app_config_refresh' );
add_action(
	'init',
	function () {
		$next = wp_next_scheduled( WMWP_APP_CONFIG_CRON_HOOK );
		if ( false === $next ) {
			wp_schedule_event( time(), 'hourly', WMWP_APP_CONFIG_CRON_HOOK );
		}
	}
);
