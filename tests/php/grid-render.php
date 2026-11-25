<?php
/**
 * Test (oc:9003): shortcode delle griglie. La griglia dei percorsi disegna sul server la prima pagina (card, numero
 * dei risultati, link alle pagine) e passa al JS il risultato iniziale e la modalità griglia; senza layer o senza copia
 * mostra un messaggio, mai tutte le tracce; una griglia sola per pagina.
 * Si lancia con: wp eval-file tests/php/grid-render.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1. Rimette l'option com'era, anche su errore.
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

$saved   = get_option( WMWP_TRACKS_COPY_OPTION, null );
$restore = function () use ( $saved ) {
	null === $saved ? delete_option( WMWP_TRACKS_COPY_OPTION ) : update_option( WMWP_TRACKS_COPY_OPTION, $saved, false );
};
set_exception_handler(
	function ( Throwable $e ) use ( $restore ) {
		$restore();
		echo 'FAIL: errore nel test: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
		exit( 1 );
	}
);

$track = fn( int $id, array $layers ) => [
	'id'                 => $id,
	'name'               => [ 'it' => 'Sentiero ' . $id ],
	'ref'                => 'T ' . $id,
	'feature_image'      => '',
	'distance'           => 4.5,
	'duration_forward'   => 90.0,
	'ascent'             => 0.0,
	'layers'             => $layers,
	'terms'              => [],
	'taxonomyActivities' => [ 'mtb' ],
	'taxonomyWheres'     => [ 'Sardegna', 'Oliena' ],
	'taxonomyIcons'      => [],
];
update_option( WMWP_TRACKS_COPY_OPTION, [ $track( 1, [ 11 ] ), $track( 2, [ 11 ] ), $track( 3, [ 11 ] ), $track( 4, [ 10 ] ) ], false );
add_filter(
	'wmwp_grid_config',
	function ( $config, string $id ) {
		$base = [
			'type'     => 'tracks',
			'layer'    => 11,
			'facets'   => [ [ 'key' => 'activity', 'label' => 'Attività', 'type' => 'list', 'field' => 'taxonomyActivities' ] ],
			'per_page' => 2,
			'nouns'    => [ 'one' => '%s sentiero', 'other' => '%s sentieri' ],
			'title'    => 'Sentieri',
			'placeholder' => 'Cerca per prova',
		];
		if ( 'prova' === $id ) {
			return $base;
		}
		if ( 'senza-layer' === $id ) {
			return array_merge( $base, [ 'layer' => null ] );
		}
		return $config;
	},
	10,
	2
);

// 1. Prima pagina sul server: 2 card su 3, numero dei risultati, link alla pagina 2, modalità griglia per il JS
wmwp_grid_rendered( false );
$html = do_shortcode( '[wmwp_tracks_grid id="prova"]' );
// Le card della prima pagina stanno prima dei <template> (card e riga, che il JS clona)
$results = substr( $html, 0, (int) strpos( $html, '<template' ) );
$check( 2 === substr_count( $results, 'data-wmwp="link"' ), 'prima pagina: 2 card' );
$check( false !== strpos( $html, 'Sentiero 1' ) && false !== strpos( $html, 'Sentiero 2' ) && false === strpos( $html, 'Sentiero 4' ), 'prima pagina: le tracce del layer, nell\'ordine' );
// L'indirizzo della card con un solo escape: `&` nell'HTML, non `&amp;#038;` (esc_url più l'escape di DOMDocument)
$check( false !== strpos( $results, 'wmwp_detail=track&amp;wmwp_id=' ) && false === strpos( $results, '#038;' ), 'card: indirizzo con un solo escape' );
$check( false !== strpos( $html, '3 sentieri' ), 'prima pagina: numero dei risultati con il nome della griglia' );
$check( false !== strpos( $html, 'pg=2' ), 'prima pagina: link alla pagina 2' );
$check( false !== strpos( $html, 'data-wmwp="search"' ), 'prima pagina: l\'interfaccia della ricerca in modalità griglia' );
$inline = implode( '', (array) wp_scripts()->get_data( 'wmwp-search', 'before' ) );
$check( false !== strpos( $inline, '"grid":true' ) && false !== strpos( $inline, '"initial"' ), 'config del JS: modalità griglia e risultato iniziale' );

// 1b. Testo nell'indirizzo (oc:9003): il campo è visibile e la prima pagina ha solo le tracce che l'API trova (finta qui)
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		return false === strpos( $url, 'query=' ) ? $pre : [ 'headers' => [], 'body' => wp_json_encode( [ 'hits' => [ 'hits' => [ [ '_source' => [ 'id' => 3 ] ], [ '_source' => [ 'id' => 4 ] ] ] ] ] ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
	},
	10,
	3
);
wmwp_grid_rendered( false );
$_GET['q'] = 'prova-testo';
$texted    = do_shortcode( '[wmwp_tracks_grid id="prova"]' );
unset( $_GET['q'] );
$texted_results = substr( $texted, 0, (int) strpos( $texted, '<template' ) );
$check( false !== strpos( $texted_results, '1 sentiero' ) && false !== strpos( $texted_results, 'Sentiero 3' ) && false === strpos( $texted_results, 'Sentiero 1' ), 'testo: prima pagina con le sole tracce trovate nel layer' );
$check( false === strpos( $texted, 'data-wmwp="form" hidden' ) && false !== strpos( $texted, 'value="prova-testo"' ), 'testo: campo visibile con il testo cercato' );
$check( false !== strpos( $texted, 'placeholder="Cerca per prova"' ), 'testo: suggerimento del campo già nell\'HTML (senza JavaScript)' );
// Una griglia è stata disegnata: il blocco 2 prova che la seconda non si disegna
wmwp_grid_rendered( true );

// 2. Una griglia sola per pagina
$check( '' === do_shortcode( '[wmwp_tracks_grid id="prova"]' ), 'seconda griglia nella pagina: niente' );

// 2b. Link alle pagine dal server: la stessa finestra del JS (5 pagine attorno alla corrente, più precedente e successiva)
$pager = wmwp_grid_pager_html( 10, 69, 'pg' );
$check( 7 === substr_count( $pager, 'wmwp-search__page' ) && false !== strpos( $pager, 'pg=11' ) && false !== strpos( $pager, 'pg=9' ) && false === strpos( $pager, 'pg=69"' ), 'pagine: finestra come il JS' );

// 3. Senza layer o senza copia: un messaggio, nessuna griglia
wmwp_grid_rendered( false );
$empty = do_shortcode( '[wmwp_tracks_grid id="senza-layer"]' );
$check( false !== strpos( $empty, 'wmwp-grid__message' ) && false === strpos( $empty, 'data-wmwp="search"' ), 'senza layer: messaggio' );
wmwp_grid_rendered( false );
delete_option( WMWP_TRACKS_COPY_OPTION );
$nocopy = do_shortcode( '[wmwp_tracks_grid id="prova"]' );
$check( false !== strpos( $nocopy, 'wmwp-grid__message' ) && false === strpos( $nocopy, 'data-wmwp="search"' ), 'senza copia: messaggio' );
wmwp_grid_rendered( false );
$check( false !== strpos( do_shortcode( '[wmwp_tracks_grid id="inesistente"]' ), 'wmwp-grid__message' ), 'griglia sconosciuta: messaggio' );

// 4. Griglia dei POI con una pagina oltre l'ultima: l'ultima, come i percorsi
add_filter( 'wmwp_grid_config', fn( $config, string $id ) => 'poi-prova' === $id ? [ 'type' => 'pois', 'per_page' => 12, 'nouns' => [ 'one' => '%s punto', 'other' => '%s punti' ] ] : $config, 10, 2 );
if ( is_array( get_option( WMWP_POI_SEARCH_OPTION, null ) ) ) {
	wmwp_grid_rendered( false );
	$_GET['pg'] = '999';
	$pois       = do_shortcode( '[wmwp_pois_grid id="poi-prova"]' );
	unset( $_GET['pg'] );
	$results = substr( $pois, 0, (int) strpos( $pois, '<template' ) );
	$check( substr_count( $results, 'data-wmwp="link"' ) > 0 && false !== strpos( $results, 'aria-current="page"' ), 'POI: pagina oltre l\'ultima → l\'ultima' );
}

$restore();

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
