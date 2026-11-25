<?php
/**
 * Test (oc:9018): indirizzo della scheda PDF di un elemento (stampa del wm-package, oc:8982). Il sito mostra il
 * pulsante solo se `OPTIONS.ec_pdf_enabled` del config.json è acceso, e costruisce da sé `<shard>/print/ec-track/<id>`
 * o `<shard>/print/ec-poi/<id>`, con la lingua della pagina se è fra quelle dell'app (altrimenti lo shard risponde
 * 404, quindi si lascia la sua lingua di default). Nessuna richiesta allo shard: si legge la copia del config.json.
 * Si lancia con: wp eval-file tests/php/detail-print.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1. Rimette le opzioni com'erano.
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
// Rimesse com'erano alla fine, anche se il test si interrompe con un errore (il gestore degli errori fatali di WordPress
// chiude il processo prima delle funzioni di shutdown)
$restore = function () use ( $saved_shard, $saved_app, $saved_config ) {
	update_option( WMWP_OPTION_SHARD, $saved_shard );
	update_option( WMWP_OPTION_APP_ID, $saved_app );
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
$origin = wmwp_configured_api_urls()['origin'];
$calls  = 0;
$count  = function ( $pre ) use ( &$calls ) {
	++$calls;
	return $pre;
};
add_filter( 'pre_http_request', $count, 1 );

$copy = function ( array $config ) {
	update_option( WMWP_APP_CONFIG_OPTION, [ 'url' => wmwp_app_config_url(), 'config' => $config ], false );
};
$base = [
	'MAP'       => [ 'layers' => [] ],
	'LANGUAGES' => [ 'default' => 'it', 'available' => [ 'en', 'it' ] ],
];

// 1. Senza copia del config.json: nessun pulsante
delete_option( WMWP_APP_CONFIG_OPTION );
$check( '' === wmwp_detail_print_url( 'track', 263, 'it' ), 'senza config.json: indirizzo vuoto' );

// 2. Opzione assente (UAT oggi) o spenta: nessun pulsante
$copy( $base );
$check( '' === wmwp_detail_print_url( 'track', 263, 'it' ), 'ec_pdf_enabled assente: indirizzo vuoto' );
$copy( $base + [ 'OPTIONS' => [ 'ec_pdf_enabled' => false, 'print_track_enable' => true ] ] );
$check( '' === wmwp_detail_print_url( 'track', 263, 'it' ), 'ec_pdf_enabled spento: vuoto, anche con la chiave deprecata accesa' );

// 3. Opzione accesa: rotta della scheda con la lingua della pagina
$copy( $base + [ 'OPTIONS' => [ 'ec_pdf_enabled' => true ] ] );
$check( '' !== $origin, 'origin dello shard configurato' );
$check( $origin . '/print/ec-track/263?lang=it' === wmwp_detail_print_url( 'track', 263, 'it' ), 'traccia in italiano' );
$check( $origin . '/print/ec-poi/177?lang=en' === wmwp_detail_print_url( 'poi', 177, 'en' ), 'POI in inglese' );
// Codice di WPML con la variante (in locale «en-us»): vale la lingua
$check( $origin . '/print/ec-track/263?lang=en' === wmwp_detail_print_url( 'track', 263, 'en-us' ), 'lingua con la variante: en-us → en' );
// Lingua che l'app non ha: lo shard risponderebbe 404, si lascia la sua lingua di default
$check( $origin . '/print/ec-track/263' === wmwp_detail_print_url( 'track', 263, 'fr' ), 'lingua fuori dall\'app: senza lang' );
$copy( [ 'MAP' => [ 'layers' => [] ], 'OPTIONS' => [ 'ec_pdf_enabled' => true ] ] );
$check( $origin . '/print/ec-track/263' === wmwp_detail_print_url( 'track', 263, 'it' ), 'senza LANGUAGES: senza lang' );

// 4. Tipo sconosciuto o id non valido: niente indirizzo
$copy( $base + [ 'OPTIONS' => [ 'ec_pdf_enabled' => true ] ] );
$check( '' === wmwp_detail_print_url( 'layer', 3, 'it' ), 'tipo sconosciuto: vuoto' );
$check( '' === wmwp_detail_print_url( 'track', 0, 'it' ), 'id 0: vuoto' );

// 5. Shard non configurato: niente indirizzo
update_option( WMWP_OPTION_SHARD, '' );
$check( '' === wmwp_detail_print_url( 'track', 263, 'it' ), 'senza shard: vuoto' );

$check( 0 === $calls, "nessuna richiesta allo shard, invece {$calls}" );

remove_filter( 'pre_http_request', $count, 1 );
$restore();

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
