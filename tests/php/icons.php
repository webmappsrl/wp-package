<?php
/**
 * Test (oc:8969): l'SVG di un'icona dal set IcoMoon dello shard (`<awsApi>/json/icons.json`, quello caricato da
 * `/icons-upload`), dato il nome. Il set (1 MB) si scarica solo per un nome mai chiesto; i nomi già chiesti stanno in
 * cache. Formati del set: IcoMoon «set» (`icons[].paths` + `selection[].name`) e quello vecchio
 * (`icons[].properties.name` + `icons[].icon.paths`). Il set è finto (`pre_http_request`).
 * Si lancia con: wp eval-file tests/php/icons.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
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

if ( ! function_exists( 'wmwp_icon_svg' ) ) {
	echo "FAIL: wmwp_icon_svg non esiste\n";
	exit( 1 );
}

$aws      = 'http://aws.test/wmfe/prova';
$with_aws = function ( $urls ) use ( &$aws ) {
	$urls['aws_api'] = $aws;
	return $urls;
};
add_filter( 'wmwp_api_urls', $with_aws, 99 );

$set   = [
	'height'    => 1024,
	'icons'     => [
		[ 'paths' => [ 'M0 0L10 0L10 10Z', 'M20 20L30 20Z' ], 'tags' => [ 'txn-uno' ] ],
		[ 'paths' => [ 'M5 5L6 6Z' ], 'tags' => [ 'txn-due' ] ],
	],
	'selection' => [ [ 'name' => 'txn-uno' ], [ 'name' => 'txn-due' ] ],
];
$calls = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$set, &$calls, &$aws ) {
		if ( 0 !== strpos( $url, $aws . '/' ) ) {
			return $pre;
		}
		++$calls;
		$ok = $aws . '/json/icons.json' === $url && null !== $set;
		return [
			'headers'  => [],
			'body'     => $ok ? wp_json_encode( $set ) : '',
			'response' => [ 'code' => $ok ? 200 : 404, 'message' => '' ],
			'cookies'  => [],
		];
	},
	10,
	3
);

$saved = get_transient( 'wmwp_icons' );
delete_transient( 'wmwp_icons' );

// 1. Formato IcoMoon «set»: tutti i path dell'icona, viewBox dall'altezza del set
$svg = wmwp_icon_svg( 'txn-uno' );
$check( false !== strpos( $svg, 'viewBox="0 0 1024 1024"' ) && false !== strpos( $svg, '<path d="M0 0L10 0L10 10Z"' ) && false !== strpos( $svg, '<path d="M20 20L30 20Z"' ), 'icona del set con i suoi path' );
$check( false !== strpos( $svg, 'aria-hidden="true"' ) && false !== strpos( $svg, 'focusable="false"' ), 'icona decorativa per lo screen reader' );
$check( 1 === $calls, 'una sola richiesta al set (' . $calls . ')' );

// 2. Un nome già chiesto non scarica di nuovo il set, anche in una richiesta successiva (cache)
$calls = 0;
wmwp_icon_svg( 'txn-uno' );
$check( 0 === $calls, 'nome già chiesto → nessuna richiesta' );

// 3. Nome assente dal set → stringa vuota, e non si riscarica il set a ogni pagina
$check( '' === wmwp_icon_svg( 'txn-manca' ), 'nome assente → stringa vuota' );
$calls = 0;
wmwp_icon_svg( 'txn-manca' );
$check( 0 === $calls, 'nome assente già cercato → nessuna richiesta' );

// 4. Formato vecchio: icons[].properties.name e icons[].icon.paths
delete_transient( 'wmwp_icons' );
$set = [
	'height' => 512,
	'icons'  => [ [ 'properties' => [ 'name' => 'txn-vecchia' ], 'icon' => [ 'paths' => [ 'M1 1L2 2Z' ] ] ] ],
];
$svg = wmwp_icon_svg( 'txn-vecchia' );
$check( false !== strpos( $svg, 'viewBox="0 0 512 512"' ) && false !== strpos( $svg, '<path d="M1 1L2 2Z"' ), 'formato vecchio del set' );

// 4b. Ogni nome ha la sua scadenza: una voce scaduta si rilegge dal set anche se il transient c'è ancora
$set   = [
	'height'    => 1024,
	'icons'     => [ [ 'paths' => [ 'M9 9L8 8Z' ], 'tags' => [ 'txn-uno' ] ] ],
	'selection' => [ [ 'name' => 'txn-uno' ] ],
];
set_transient( 'wmwp_icons', [ 'txn-uno' => [ 'svg' => '<svg>vecchia</svg>', 'until' => time() - 1 ] ], DAY_IN_SECONDS );
$check( false !== strpos( wmwp_icon_svg( 'txn-uno' ), 'M9 9L8 8Z' ), 'voce scaduta → riletta dal set' );

// 5. Set che non risponde o shard senza awsApi → stringa vuota; nella stessa richiesta non si riprova per ogni nome
delete_transient( 'wmwp_icons' );
$set   = null;
$calls = 0;
$check( '' === wmwp_icon_svg( 'txn-uno' ), 'set che non risponde → stringa vuota' );
wmwp_icon_svg( 'txn-due' );
wmwp_icon_svg( 'txn-tre' );
$check( 1 === $calls, 'set che non risponde: una sola richiesta per più nomi (' . $calls . ')' );
delete_transient( 'wmwp_icons' );
$aws = '';
$check( '' === wmwp_icon_svg( 'txn-uno' ), 'senza awsApi → stringa vuota' );

remove_filter( 'wmwp_api_urls', $with_aws, 99 );
delete_transient( 'wmwp_icons' );
if ( false !== $saved ) {
	set_transient( 'wmwp_icons', $saved, DAY_IN_SECONDS );
}

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
