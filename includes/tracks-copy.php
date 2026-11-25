<?php
/**
 * Copia delle tracce per le griglie (oc:9003).
 *
 * Le pagine d'elenco di un sito non scaricano l'indice nel browser (2,7 MB senza compressione né cache, a ogni visita):
 * le griglie lavorano su una copia sul server. La copia nasce dallo stesso download della lista Elasticsearch che fa il
 * rebuild dell'indice dei dettagli (wmwp_detail_fetch_items()) e si scrive solo quando quel rebuild è accettato, come la
 * copia dei POI: eredita il cron orario e lo scarto delle liste a metà (WMWP_DETAIL_REJECT_RATIO), quindi con lo shard
 * giù o a metà di un import resta l'ultima copia buona.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_TRACKS_COPY_OPTION = 'wmwp_tracks_copy';

/** Campi della copia: quelli della card (source-tracks.js), delle faccette (taxonomyIcons dà il nome di un valore di una
 * lista senza `labels`, come facetValues()) e degli slider. */
const WMWP_TRACKS_COPY_FIELDS = [ 'id', 'name', 'ref', 'feature_image', 'distance', 'duration_forward', 'ascent', 'layers', 'terms', 'taxonomyActivities', 'taxonomyWheres', 'taxonomyIcons' ];

/**
 * Copia dalla lista dell'API: ordinata per nome italiano senza spazi ai lati (come la ricerca unica), con i soli campi
 * usati, numeri come numeri. Una traccia senza id non entra.
 *
 * @param array<int, mixed> $hits Hit della lista (`_source` o già il documento).
 * @return list<array<string, mixed>>
 */
function wmwp_tracks_copy_build( array $hits ): array {
	$copy = [];
	foreach ( $hits as $hit ) {
		$hit = is_array( $hit ) && isset( $hit['_source'] ) && is_array( $hit['_source'] ) ? $hit['_source'] : $hit;
		$id  = is_array( $hit ) ? (int) ( $hit['id'] ?? 0 ) : 0;
		if ( $id <= 0 ) {
			continue;
		}
		$track = array_intersect_key( $hit, array_flip( WMWP_TRACKS_COPY_FIELDS ) );
		$track['id'] = $id;
		foreach ( [ 'distance', 'duration_forward', 'ascent' ] as $number ) {
			$track[ $number ] = is_numeric( $track[ $number ] ?? null ) ? (float) $track[ $number ] : 0.0;
		}
		$track['layers'] = array_values( array_map( 'intval', (array) ( $track['layers'] ?? [] ) ) );
		$copy[]          = $track;
	}
	// Per nome italiano senza spazi e tab ai lati, come sortName() di search-logic.js nella ricerca unica: l'API ordina per
	// `name.keyword` grezzo, e i nomi con un tab o uno spazio in testa finirebbero prima della A. A pari nome, ordine dell'API
	$order = array_flip( array_keys( $copy ) );
	$name  = fn( array $track ): string => trim( (string) ( is_array( $track['name'] ?? null ) ? ( $track['name']['it'] ?? '' ) : ( $track['name'] ?? '' ) ) );
	$keyed = array_map( null, array_keys( $copy ), $copy );
	usort( $keyed, fn( array $a, array $b ): int => strcmp( $name( $a[1] ), $name( $b[1] ) ) ?: $order[ $a[0] ] <=> $order[ $b[0] ] );
	return array_column( $keyed, 1 );
}

/**
 * Copia in attesa del rebuild dell'indice dei dettagli (stesso schema di wmwp_poi_search_pending()).
 *
 * @param array<int, mixed>|null $hits  Hit da preparare.
 * @param bool                   $reset Vero per azzerare la copia in attesa.
 * @return list<array<string, mixed>>|null
 */
function wmwp_tracks_copy_pending( ?array $hits = null, bool $reset = false ): ?array {
	static $pending = null;
	if ( $reset ) {
		$pending = null;
	} elseif ( null !== $hits ) {
		$pending = wmwp_tracks_copy_build( $hits );
	}
	return $pending;
}

/** Scrive la copia in attesa: la chiama il rebuild dell'indice solo quando lo accetta. */
function wmwp_tracks_copy_commit(): void {
	$pending = wmwp_tracks_copy_pending();
	if ( null !== $pending ) {
		update_option( WMWP_TRACKS_COPY_OPTION, $pending, false );
	}
}

/**
 * Copia salvata, null se non c'è ancora (WordPress nuovo, prima del primo rebuild).
 *
 * @return list<array<string, mixed>>|null
 */
function wmwp_tracks_copy( bool $flush = false ): ?array {
	// Una lettura per richiesta (la copia pesa più di 1 MB serializzata): i dettagli dei POI chiedono il layer di ogni
	// percorso vicino. Si rinnova quando l'option cambia (hook sotto)
	static $memo = false;
	if ( $flush ) {
		$memo = false;
		return null;
	}
	if ( false === $memo ) {
		$copy = get_option( WMWP_TRACKS_COPY_OPTION, null );
		$memo = is_array( $copy ) ? $copy : null;
	}
	return $memo;
}
foreach ( [ 'add_option_', 'update_option_', 'delete_option_' ] as $wmwp_tracks_hook ) {
	add_action( $wmwp_tracks_hook . WMWP_TRACKS_COPY_OPTION, fn() => wmwp_tracks_copy( true ) );
}

/**
 * Layer, fra quelli ammessi, con più tracce che hanno il termine `$term` nella famiglia `$family` (es. il layer
 * «Sentieri» per `route-type-trail`): così un tema passa da un termine a un layer senza scrivere id, che cambiano fra
 * ambienti. A pari numero vince il primo degli ammessi. Null senza copia, senza layer ammessi o senza tracce.
 *
 * @param array<int, int|string> $allowed Id dei layer fra cui scegliere (es. le opzioni di `MAP.filters.layers`).
 */
function wmwp_tracks_layer_for_term( string $family, string $term, array $allowed ): ?int {
	$copy    = wmwp_tracks_copy();
	$allowed = array_values( array_map( 'intval', $allowed ) );
	if ( null === $copy || ! $allowed ) {
		return null;
	}
	$counts = array_fill_keys( $allowed, 0 );
	foreach ( $copy as $track ) {
		$identifiers = array_column( (array) ( $track['terms'][ $family ] ?? [] ), 'identifier' );
		if ( ! in_array( $term, $identifiers, true ) ) {
			continue;
		}
		foreach ( (array) ( $track['layers'] ?? [] ) as $layer ) {
			if ( isset( $counts[ (int) $layer ] ) ) {
				++$counts[ (int) $layer ];
			}
		}
	}
	$best = null;
	foreach ( $allowed as $layer ) {
		if ( $counts[ $layer ] > 0 && ( null === $best || $counts[ $layer ] > $counts[ $best ] ) ) {
			$best = $layer;
		}
	}
	return $best;
}
