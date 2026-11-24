<?php
/**
 * Pagine di dettaglio (oc:9000): percorribilità e avviso della traccia, dati che nascono in wm-package (dominio Catasto
 * e campi `not_accessible*` di `EcTrack`), pronti per il tema. Nessun markup: la grafica è del tema.
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Percorribilità del dettaglio della traccia: lo stato (`taxonomies.<famiglia della percorribilità>`), il suo livello
 * per le tacche dal registro degli stati, la nota e la data dell'ultimo aggiornamento (`properties.walkability`).
 *
 * - La data c'è solo se lo stato l'ha messo una persona (`walkability.user` non null): quello scritto dall'import ha la
 *   data dell'import, che direbbe «controllato oggi» per un sentiero che nessuno ha guardato (oc:9000).
 * - Senza termine, o con lo stato di assenza, `absent` è vero e il nome è quello dello stato di assenza.
 * - Il nome è quello del termine se c'è nella lingua della pagina, altrimenti quello del registro, altrimenti il
 *   termine con il suo ripiego (uno stato che il registro non conosce).
 *
 * @param array<string, mixed> $properties properties del JSON della traccia.
 * @return array{identifier: ?string, name: string, name_lang: ?string, level: int, total: int, absent: bool, note: string, note_lang: ?string, updated_at: ?string}
 */
function wmwp_walkability( array $properties, string $lang ): array {
	$family     = wmwp_walkability_taxonomy();
	$absent     = wmwp_status_families()[ $family ]['absent'] ?? null;
	$term       = $properties['taxonomies'][ $family ][0] ?? null;
	$identifier = is_array( $term ) && '' !== (string) ( $term['identifier'] ?? '' ) ? (string) $term['identifier'] : null;
	$level      = wmwp_status_level( $family, $identifier );
	$is_absent  = null === $identifier || $identifier === $absent;

	$from_term = wmwp_text( is_array( $term ) ? ( $term['name'] ?? null ) : null, $lang );
	$from_list = null !== ( $identifier ?? $absent ) ? wmwp_status_name( $family, (string) ( $identifier ?? $absent ), $lang ) : [
		'text' => '',
		'lang' => null,
	];
	$short     = strtolower( substr( $lang, 0, 2 ) );
	if ( '' !== $from_term['text'] && $from_term['lang'] === $short ) {
		$name = $from_term;
	} elseif ( '' !== $from_list['text'] ) {
		$name = $from_list;
	} elseif ( '' !== $from_term['text'] ) {
		$name = $from_term;
	} else {
		// Un termine senza nome: il suo identifier, come un valore fuori registro (oc:9018)
		$name = [
			'text' => (string) ( $identifier ?? '' ),
			'lang' => null,
		];
	}

	$walkability = is_array( $properties['walkability'] ?? null ) ? $properties['walkability'] : [];
	$note        = wmwp_text( $walkability['description'] ?? null, $lang );
	$updated     = $walkability['last_update'] ?? null;
	return [
		'identifier' => $identifier,
		'name'       => $name['text'],
		'name_lang'  => $name['lang'],
		'level'      => $level['level'],
		'total'      => $level['total'],
		'absent'     => $is_absent,
		'note'       => $note['text'],
		'note_lang'  => $note['lang'],
		'updated_at' => null !== ( $walkability['user'] ?? null ) && is_string( $updated ) && '' !== $updated ? $updated : null,
	];
}

/**
 * Avviso del sentiero (`not_accessible`, `not_accessible_message`, `not_accessible_updated_at` di `EcTrack`, oc:8981).
 * Acceso per i valori che lo shard legge come veri (`FILTER_VALIDATE_BOOLEAN`, come `EcTrack::isNotAccessibleOn()`:
 * `true`, `1`, `"1"`, `"true"`, `"yes"`, `"on"`) e con un messaggio non vuoto in almeno una lingua; null altrimenti. Il messaggio è nella lingua della pagina con ripiego, e
 * `lang` dice da quale lingua viene (per marcarlo, WCAG 3.1.2). La data è in UTC, come la scrive lo shard.
 *
 * @param array<string, mixed> $properties properties del JSON della traccia.
 * @return array{message: string, lang: ?string, updated_at: ?string}|null
 */
function wmwp_track_notice( array $properties, string $lang ): ?array {
	if ( ! filter_var( $properties['not_accessible'] ?? null, FILTER_VALIDATE_BOOLEAN ) ) {
		return null;
	}
	$message = wmwp_text( $properties['not_accessible_message'] ?? null, $lang );
	if ( '' === $message['text'] ) {
		return null;
	}
	$updated = $properties['not_accessible_updated_at'] ?? null;
	return [
		'message'    => $message['text'],
		'lang'       => $message['lang'],
		'updated_at' => is_string( $updated ) && '' !== $updated ? $updated : null,
	];
}
