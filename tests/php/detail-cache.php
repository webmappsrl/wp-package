<?php
/**
 * Test (oc:8969): lettura degli elementi dallo shard e cache dell'indice. Lo shard è finto (`pre_http_request`):
 * l'indice si costruisce dalla lista delle tracce di Elasticsearch e da `pois.geojson` (che sul dev arriva come
 * stringa JSON da decodificare due volte); con lo shard in errore o con una risposta parziale resta l'indice vecchio;
 * una ricostruzione forzata non si ripete prima di 5 minuti.
 * Si lancia con: wp eval-file tests/php/detail-cache.php
 * Stampa «PASS» o «FAIL: …» ed esce con 0 o 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require __DIR__ . '/history-sandbox.php';
// Le tracce di prova hanno il tipo in `route-type`: dalla 0.5.0 la famiglia la indica il tema
// (wmwp_track_kind_taxonomy, oc:9000), qui la indica il test, così non dipende dal tema attivo
add_filter( 'wmwp_track_kind_taxonomy', fn() => 'route-type', 99 );

$failures = [];
$check    = function ( bool $ok, string $what ) use ( &$failures ) {
	if ( ! $ok ) {
		$failures[] = $what;
	}
};

if ( ! function_exists( 'wmwp_detail_index' ) ) {
	echo "FAIL: wmwp_detail_index non esiste\n";
	exit( 1 );
}

// Le basi del test non dipendono dal tema attivo (Forestas mette i POI sotto una pagina)
foreach ( [ 'wmwp_detail_tracks_use_page', 'wmwp_detail_tracks_page', 'wmwp_detail_pois_use_page', 'wmwp_detail_pois_page' ] as $option ) {
	remove_all_filters( 'pre_option_' . $option );
}

// Stato pulito: niente indice, niente ricostruzioni recenti
$reset = function () {
	delete_transient( 'wmwp_detail_index' );
	delete_transient( 'wmwp_detail_index_lock' );
	delete_option( 'wmwp_detail_index_rebuilt_at' );
	delete_option( 'wmwp_detail_index_last' );
};
$reset();

$hit  = function ( int $id, string $name, ?string $type ) {
	$hit = [
		'id'   => $id,
		'name' => [ 'it' => $name ],
	];
	if ( null !== $type ) {
		$hit['terms'] = [ 'route-type' => [ [ 'identifier' => 'x', 'name' => [ 'it' => $type ] ] ] ];
	}
	return $hit;
};
$poi  = function ( int $id, string $name ) {
	return [
		'type'       => 'Feature',
		'properties' => [
			'id'         => $id,
			'name'       => [ 'it' => $name ],
			'taxonomies' => [ 'poi-type' => [ [ 'identifier' => 'y', 'name' => [ 'it' => 'Nuraghi' ] ] ] ],
		],
	];
};
$full = [
	'tracks' => [ $hit( 1, 'Uno', 'Sentiero' ), $hit( 2, 'Due', 'Cammino' ), $hit( 3, 'Tre', null ) ],
	'pois'   => [ $poi( 10, 'Nuraghe A' ), $poi( 11, 'Nuraghe B' ) ],
];

$shard = [
	'status' => 200,
	'data'   => $full,
];
$calls = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$shard, &$calls ) {
		if ( false === strpos( $url, 'elasticsearch' ) && false === strpos( $url, 'pois.geojson' ) ) {
			return $pre;
		}
		++$calls;
		if ( 200 !== $shard['status'] ) {
			return [
				'headers'  => [],
				'body'     => 'errore',
				'response' => [
					'code'    => $shard['status'],
					'message' => 'Errore',
				],
				'cookies'  => [],
			];
		}
		$body = false !== strpos( $url, 'elasticsearch' )
			? wp_json_encode( [ 'hits' => $shard['data']['tracks'] ] )
			// Come sul dev: il file è una stringa JSON dentro un JSON
			: wp_json_encode( wp_json_encode( [ 'type' => 'FeatureCollection', 'features' => $shard['data']['pois'] ] ) );
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
		];
	},
	10,
	3
);

// 1. Primo indice dallo shard
$index = wmwp_detail_index();
$check( is_array( $index ) && 5 === $index['count'], 'indice da 5 elementi (3 tracce + 2 POI)' );
$check( [ 'track', 2 ] === ( $index['paths']['it']['cammino/due'] ?? null ), 'traccia sotto la base del suo tipo' );
$check( [ 'track', 3 ] === ( $index['paths']['it']['track/tre'] ?? null ), 'traccia senza tipo sotto track' );
$check( [ 'poi', 10 ] === ( $index['paths']['it']['nuraghi/nuraghe-a'] ?? null ), 'POI dal pois.geojson con doppia codifica' );

// 2. Shard in errore: resta l'indice vecchio
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard['status'] = 500;
$index           = wmwp_detail_index( true );
$check( is_array( $index ) && 5 === $index['count'], 'shard in errore → indice vecchio' );

// 3. Risposta parziale (meno della metà): resta l'indice vecchio
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard  = [
	'status' => 200,
	'data'   => [
		'tracks' => [ $hit( 1, 'Uno', 'Sentiero' ) ],
		'pois'   => [],
	],
];
$index = wmwp_detail_index( true );
$check( is_array( $index ) && 5 === $index['count'], 'risposta parziale → indice vecchio' );

// 4. Due ricostruzioni forzate di fila: la seconda non chiama lo shard
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard = [
	'status' => 200,
	'data'   => $full,
];
wmwp_detail_index( true );
$before = $calls;
wmwp_detail_index( true );
$check( $before === $calls, 'seconda ricostruzione entro 5 minuti → nessuna chiamata' );
$check( false === wmwp_detail_rebuild_allowed(), 'ricostruzione non permessa subito dopo' );

// 5. Base cambiata (impostazioni o tema): l'indice in cache non vale più e si ricostruisce
delete_option( 'wmwp_detail_index_rebuilt_at' );
$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'POI test', 'post_name' => 'poi-test-8969' ] );
add_filter( 'pre_option_wmwp_detail_pois_use_page', function () { return '1'; } );
add_filter( 'pre_option_wmwp_detail_pois_page', function () use ( $page ) { return (string) $page; } );
$index = wmwp_detail_index();
$check( [ 'poi', 10 ] === ( $index['paths']['it']['poi-test-8969/nuraghe-a'] ?? null ), 'base dei POI cambiata → indice ricostruito con la pagina' );
remove_all_filters( 'pre_option_wmwp_detail_pois_use_page' );
remove_all_filters( 'pre_option_wmwp_detail_pois_page' );
wp_delete_post( $page, true );

// 6. Elasticsearch vuoto (POI interi): l'indice non perde le tracce
$reset();
$shard = [ 'status' => 200, 'data' => $full ];
wmwp_detail_index();
delete_option( 'wmwp_detail_index_rebuilt_at' );
$shard['data'] = [ 'tracks' => [], 'pois' => $full['pois'] ];
$index = wmwp_detail_index( true );
$check( is_array( $index ) && 3 === ( $index['counts']['track'] ?? 0 ), 'lista delle tracce vuota → indice vecchio' );
// Base rinominata (stesso id, altro percorso): l'impronta cambia
$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Base', 'post_name' => 'base-8969-a' ] );
add_filter( 'pre_option_wmwp_detail_pois_use_page', function () { return '1'; } );
add_filter( 'pre_option_wmwp_detail_pois_page', function () use ( $page ) { return (string) $page; } );
$prima = wmwp_detail_base_signature();
wp_update_post( [ 'ID' => $page, 'post_name' => 'base-8969-b' ] );
$check( $prima !== wmwp_detail_base_signature(), 'pagina base rinominata → impronta diversa' );
remove_all_filters( 'pre_option_wmwp_detail_pois_use_page' );
remove_all_filters( 'pre_option_wmwp_detail_pois_page' );
wp_delete_post( $page, true );
// Le basi stanno in un'opzione piccola: init non carica l'indice
$shard = [ 'status' => 200, 'data' => $full ];
$reset();
wmwp_detail_index();
$check( in_array( 'sentiero', (array) get_option( 'wmwp_detail_bases' ), true ), 'basi salvate nell\'opzione' );

// 7. Senza indice in cache e shard giù: null, mai un indice vuoto salvato, e niente nuovo tentativo subito
$reset();
$shard['status'] = 500;
$check( null === wmwp_detail_index(), 'niente cache e shard giù → null' );
$check( false === get_transient( 'wmwp_detail_index' ), 'nessun indice vuoto salvato' );
$before = $calls;
wmwp_detail_index();
$check( $before === $calls, 'shard giù: il tentativo successivo aspetta 5 minuti' );

// 8. Storico dei percorsi nella ricostruzione (oc:8983). Le tracce con la chiave della fonte: out_source_feature_id
$con_fonte   = function ( int $id, string $name, string $source, string $type = 'Sentiero' ) use ( $hit ): array {
	return $hit( $id, $name, $type ) + [ 'out_source_feature_id' => $source ];
};
$svuota      = function () {
	$GLOBALS['wpdb']->query( 'TRUNCATE TABLE `' . wmwp_detail_paths_table() . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
};
$ricostruisci = function () {
	delete_option( 'wmwp_detail_index_rebuilt_at' );
	return wmwp_detail_index( true );
};
if ( ! function_exists( 'wmwp_detail_history_seed_rows' ) ) {
	$check( false, 'wmwp_detail_history_seed_rows non esiste' );
} else {
	// La chiave della fonte crolla (API cambiate): l'indice si scarta sempre, anche alla terza volta, e lo storico resta
	$reset();
	$svuota();
	$shard  = [
		'status' => 200,
		'data'   => [
			'tracks' => [ $con_fonte( 1, 'Uno', '101' ), $con_fonte( 2, 'Due', '102' ), $con_fonte( 3, 'Tre', '103' ) ],
			'pois'   => $full['pois'],
		],
	];
	$buono  = wmwp_detail_index();
	$storia = wmwp_detail_history_load();
	$check( 's:101' === ( wmwp_detail_history_lookup( 'it', 'sentiero/uno' )['key'] ?? null ), 'storico scritto con l\'indice accettato' );
	$shard['data']['tracks'] = [ $hit( 1, 'Uno', 'Sentiero' ), $hit( 2, 'Due', 'Sentiero' ), $hit( 3, 'Tre', 'Sentiero' ) ];
	for ( $volta = 1; $volta <= 3; $volta++ ) {
		$scartato = $ricostruisci();
	}
	$check( '101' === ( $scartato['items']['track'][1]['source'] ?? null ), 'chiave crollata: indice scartato anche alla terza volta' );
	$check( $storia === wmwp_detail_history_load(), 'chiave crollata: storico intatto' );

	// Primo giro della versione nuova: l'indice 0.2.0 (doppioni con -<nid>) entra nello storico, poi si ricalcola
	$reset();
	$svuota();
	$shard   = [
		'status' => 200,
		'data'   => [
			'tracks' => [ $con_fonte( 11, 'Monte', '4076' ), $con_fonte( 12, 'Monte', 'N 12/B' ) ],
			'pois'   => $full['pois'],
		],
	];
	$vecchio = wmwp_detail_index();
	foreach ( $vecchio['paths'] as $lang => $paths ) {
		$rinomina = [
			'sentiero/monte'   => 'sentiero/monte-4076',
			'sentiero/monte-2' => 'sentiero/monte-n-12-b',
		];
		foreach ( $rinomina as $nuovo => $vecchio_percorso ) {
			if ( isset( $paths[ $nuovo ] ) ) {
				[ $type, $id ] = $paths[ $nuovo ];
				unset( $vecchio['paths'][ $lang ][ $nuovo ] );
				$vecchio['paths'][ $lang ][ $vecchio_percorso ] = [ $type, $id ];
				$vecchio['urls'][ $type ][ $id ][ $lang ]       = $vecchio_percorso;
			}
		}
	}
	set_transient( 'wmwp_detail_index', $vecchio, 600 );
	$svuota();
	$primo_giro = function () {
		return 1;
	};
	add_filter( 'pre_option_' . WMWP_DETAIL_PATHS_SEED_OPTION, $primo_giro, 20 );
	$dopo = $ricostruisci();
	remove_filter( 'pre_option_' . WMWP_DETAIL_PATHS_SEED_OPTION, $primo_giro, 20 );
	$check( 'sentiero/monte' === ( $dopo['urls']['track'][11]['it'] ?? null ) && 'sentiero/monte-2' === ( $dopo['urls']['track'][12]['it'] ?? null ), 'primo giro: doppioni ricalcolati con -2' );
	$vecchia_riga = wmwp_detail_history_lookup( 'it', 'sentiero/monte-4076' );
	$check( 's:4076' === ( $vecchia_riga['key'] ?? null ) && false === ( $vecchia_riga['current'] ?? null ), 'primo giro: il percorso -<nid> resta del suo elemento, non attuale' );

	// Lo shard viene svuotato e ricaricato (reset di dev e UAT): le righe con l'id si dimenticano, quelle col nid restano
	wmwp_detail_history_write(
		[
			[ 'lang' => 'it', 'path' => 'sentiero/prova-accatastamento', 'type' => 'track', 'key' => 'i:13', 'base' => 'sentiero', 'root' => 'prova-accatastamento', 'suffix' => 0, 'current' => true ],
		]
	);
	$shard['data']['tracks'] = [ $con_fonte( 21, 'Monte', '4076' ), $con_fonte( 22, 'Monte', 'N 12/B' ) ];
	$rinumerato              = $ricostruisci();
	$check( null === wmwp_detail_history_lookup( 'it', 'sentiero/prova-accatastamento' ), 'shard rinumerato: righe con l\'id dimenticate' );
	$check( 'sentiero/monte' === ( $rinumerato['urls']['track'][21]['it'] ?? null ), 'shard rinumerato: stesso percorso con il nid' );

	// Nome senza lettere: lo slug è l'id, dopo un reset il percorso cambia e quello vecchio resta dell'elemento
	$reset();
	$svuota();
	$shard['data']['tracks'] = [ $con_fonte( 41, '— !!', '900' ) ];
	wmwp_detail_index();
	$shard['data']['tracks'] = [ $con_fonte( 77, '— !!', '900' ) ];
	$ricostruisci();
	$riga_41 = wmwp_detail_history_lookup( 'it', 'sentiero/41' );
	$riga_77 = wmwp_detail_history_lookup( 'it', 'sentiero/77' );
	$check( 's:900' === ( $riga_41['key'] ?? null ) && false === ( $riga_41['current'] ?? null ) && true === ( $riga_77['current'] ?? null ), 'nome senza lettere: percorso vecchio non attuale, nuovo attuale' );

	// Una base che sparisce resta nelle regole di rewrite: i percorsi vecchi arrivano ancora al plugin
	$reset();
	$svuota();
	$shard['data']['tracks'] = [ $con_fonte( 1, 'Uno', '101' ), $con_fonte( 2, 'Due', '102', 'Cammino' ) ];
	wmwp_detail_index();
	$shard['data']['tracks'] = [ $con_fonte( 1, 'Uno', '101' ), $con_fonte( 2, 'Due', '102' ) ];
	$ricostruisci();
	$check( in_array( 'cammino', (array) get_option( 'wmwp_detail_bases' ), true ), 'base sparita ancora nelle basi delle regole' );

	// Storico illeggibile mentre la versione dice che c'è (tabella sparita): l'indice costruito senza storico
	// riassegnerebbe i percorsi, quindi resta quello di prima
	$reset();
	$svuota();
	$shard['data']['tracks'] = [ $con_fonte( 1, 'Uno', '101' ), $con_fonte( 2, 'Due', '102' ) ];
	wmwp_detail_index();
	$sparita = function () {
		return $GLOBALS['wpdb']->prefix . 'wmwp_detail_paths_sparita';
	};
	add_filter( 'wmwp_detail_paths_table', $sparita, 30 );
	$shard['data']['tracks'] = [ $con_fonte( 1, 'Uno bis', '101' ), $con_fonte( 2, 'Due', '102' ) ];
	$illeggibile             = $ricostruisci();
	remove_filter( 'wmwp_detail_paths_table', $sparita, 30 );
	$check( isset( $illeggibile['paths']['it']['sentiero/uno'] ) && ! isset( $illeggibile['paths']['it']['sentiero/uno-bis'] ), 'storico illeggibile: resta l\'indice di prima' );

	// Primo giro anche quando c'è solo l'ultimo indice buono (transient scaduto)
	$reset();
	$svuota();
	update_option( 'wmwp_detail_index_last', $vecchio, false );
	add_filter( 'pre_option_' . WMWP_DETAIL_PATHS_SEED_OPTION, $primo_giro, 20 );
	$shard['data']['tracks'] = [ $con_fonte( 11, 'Monte', '4076' ), $con_fonte( 12, 'Monte', 'N 12/B' ) ];
	$ricostruisci();
	remove_filter( 'pre_option_' . WMWP_DETAIL_PATHS_SEED_OPTION, $primo_giro, 20 );
	$check( 's:4076' === ( wmwp_detail_history_lookup( 'it', 'sentiero/monte-4076' )['key'] ?? null ), 'primo giro dall\'ultimo indice buono' );

	// Lo storico si scrive solo con un indice accettato: primo giro e azzeramento dello shard non toccano la tabella se
	// l'indice si scarta (qui per il calo degli elementi)
	$reset();
	$svuota();
	$shard['data'] = [
		'tracks' => [ $con_fonte( 1, 'Uno', '101' ), $con_fonte( 2, 'Due', '102' ), $con_fonte( 3, 'Tre', '103' ) ],
		'pois'   => $full['pois'],
	];
	wmwp_detail_index();
	$svuota();
	wmwp_detail_history_write( [ [ 'lang' => 'it', 'path' => 'sentiero/da-nova', 'type' => 'track', 'key' => 'i:13', 'base' => 'sentiero', 'root' => 'da-nova', 'suffix' => 0, 'current' => true ] ] );
	add_filter( 'pre_option_' . WMWP_DETAIL_PATHS_SEED_OPTION, $primo_giro, 20 );
	$shard['data'] = [
		'tracks' => [ $con_fonte( 21, 'Uno', '101' ) ],
		'pois'   => [],
	];
	$ricostruisci();
	remove_filter( 'pre_option_' . WMWP_DETAIL_PATHS_SEED_OPTION, $primo_giro, 20 );
	$check( null === wmwp_detail_history_lookup( 'it', 'sentiero/uno' ), 'indice scartato: il primo giro non scrive' );
	$check( null !== wmwp_detail_history_lookup( 'it', 'sentiero/da-nova' ), 'indice scartato: le righe con l\'id restano' );

	// Basi cambiate (impostazioni) mentre la chiave è crollata: i controlli usano comunque l'ultimo indice
	$reset();
	$svuota();
	$shard['data'] = [
		'tracks' => [ $con_fonte( 1, 'Uno', '101' ), $con_fonte( 2, 'Due', '102' ), $con_fonte( 3, 'Tre', '103' ) ],
		'pois'   => $full['pois'],
	];
	wmwp_detail_index();
	$storia_basi = wmwp_detail_history_load();
	$pagina_poi  = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'POI 8983', 'post_name' => 'poi-test-8983' ] );
	$usa_pagina  = function () {
		return '1';
	};
	$la_pagina   = function () use ( $pagina_poi ) {
		return (string) $pagina_poi;
	};
	add_filter( 'pre_option_wmwp_detail_pois_use_page', $usa_pagina );
	add_filter( 'pre_option_wmwp_detail_pois_page', $la_pagina );
	$shard['data']['tracks'] = [ $hit( 1, 'Uno', 'Sentiero' ), $hit( 2, 'Due', 'Sentiero' ), $hit( 3, 'Tre', 'Sentiero' ) ];
	$ricostruisci();
	remove_filter( 'pre_option_wmwp_detail_pois_use_page', $usa_pagina );
	remove_filter( 'pre_option_wmwp_detail_pois_page', $la_pagina );
	$check( $storia_basi === wmwp_detail_history_load(), 'basi cambiate con la chiave crollata: storico intatto' );
	// Subito dopo, senza poter ricostruire: stesso comportamento del tentativo scartato, l'ultimo indice e non il 503
	add_filter( 'pre_option_wmwp_detail_pois_use_page', $usa_pagina );
	add_filter( 'pre_option_wmwp_detail_pois_page', $la_pagina );
	update_option( 'wmwp_detail_index_rebuilt_at', time(), false );
	$senza_ricostruire = wmwp_detail_index();
	remove_filter( 'pre_option_wmwp_detail_pois_use_page', $usa_pagina );
	remove_filter( 'pre_option_wmwp_detail_pois_page', $la_pagina );
	$check( is_array( $senza_ricostruire ) && isset( $senza_ricostruire['paths']['it']['sentiero/uno'] ), 'basi cambiate, ricostruzione non permessa: l\'ultimo indice, non null' );
	wp_delete_post( $pagina_poi, true );

	// Da capo a fondo: rinomina, cambio di base e traduzione arrivata portano con un 301 all'indirizzo nuovo dopo la
	// ricostruzione
	$reset();
	$svuota();
	$shard['data'] = [
		'tracks' => [ $con_fonte( 1, 'Uno', '101' ), $con_fonte( 2, 'Due', '102' ) ],
		'pois'   => $full['pois'],
	];
	wmwp_detail_index();
	$shard['data']['tracks'] = [ $con_fonte( 1, 'Uno bis', '101' ), $con_fonte( 2, 'Due', '102' ) ];
	$ricostruisci();
	$r = wmwp_detail_resolve( 'it', 'sentiero/uno' );
	$check( 301 === $r['status'] && '/sentiero/uno-bis/' === substr( (string) ( $r['location'] ?? '' ), -18 ), 'da capo a fondo: rinomina → 301 (' . ( $r['location'] ?? '' ) . ')' );
	$shard['data']['tracks'] = [ $con_fonte( 1, 'Uno bis', '101' ), $con_fonte( 2, 'Due', '102', 'Cammino' ) ];
	$ricostruisci();
	$r = wmwp_detail_resolve( 'it', 'sentiero/due' );
	$check( 301 === $r['status'] && '/cammino/due/' === substr( (string) ( $r['location'] ?? '' ), -13 ), 'da capo a fondo: cambio di base → 301 (' . ( $r['location'] ?? '' ) . ')' );
	$en_8983 = null;
	foreach ( wmwp_detail_languages() as $code ) {
		if ( 0 === strpos( $code, 'en' ) ) {
			$en_8983 = $code;
		}
	}
	if ( null === $en_8983 ) {
		echo "SKIP: traduzione arrivata (nessuna lingua inglese attiva)\n";
	} else {
		$tradotta                 = $con_fonte( 1, 'Uno bis', '101' );
		$tradotta['name']['en']   = 'One';
		$shard['data']['tracks'] = [ $tradotta, $con_fonte( 2, 'Due', '102', 'Cammino' ) ];
		$ricostruisci();
		$r = wmwp_detail_resolve( $en_8983, 'sentiero/uno-bis' );
		$check( 301 === $r['status'] && '/sentiero/one/' === substr( (string) ( $r['location'] ?? '' ), -14 ), 'da capo a fondo: traduzione arrivata → 301 (' . ( $r['location'] ?? '' ) . ')' );
	}
}

$reset();

// Ricostruzione in anticipo: un evento cron ogni ora rifà l'indice, che in cache dura di più, così di solito non la
// paga un visitore
$check( false !== wp_next_scheduled( 'wmwp_detail_refresh_index' ), 'evento cron della ricostruzione programmato' );
$check( defined( 'WMWP_DETAIL_INDEX_TTL' ) && WMWP_DETAIL_INDEX_TTL > HOUR_IN_SECONDS, 'l\'indice in cache dura più dell\'intervallo del cron' );

if ( $failures ) {
	echo 'FAIL: ' . implode( '; ', $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
