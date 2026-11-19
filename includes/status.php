<?php
/**
 * Registro degli stati delle famiglie (oc:9000): per ogni famiglia di tassonomia «a stati» (una sola scelta, in un
 * ordine che va dall'assenza di informazione al meglio) l'elenco dei suoi stati, nell'ordine dello shard, e quale stato
 * dice «non lo sappiamo». Serve agli indicatori a tacche (tante tacche quanti sono gli stati della famiglia nello shard)
 * e ai filtri della ricerca (un'opzione per stato).
 *
 * Lo shard oggi non espone l'elenco degli stati di una famiglia: il registro lo scrive nel codice, con la
 * percorribilità del dominio Catasto di wm-package (`WalkabilityTaxonomyInstaller::TERMS`); il tema aggiunge le famiglie
 * del suo shard con il filtro `wmwp_status_families`. Quando l'API ci sarà, cambia solo la fonte del registro.
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identifier della famiglia della percorribilità: lo decide lo shard con
 * `wm-package.features.trail_registry.walkability_taxonomy` (default `walkability-status`); il tema lo segue con il
 * filtro `wmwp_walkability_taxonomy`.
 */
function wmwp_walkability_taxonomy(): string {
	return (string) apply_filters( 'wmwp_walkability_taxonomy', 'walkability-status' );
}

/**
 * Famiglie a stati: identifier → stato di assenza (null se la famiglia non ce l'ha) e stati in ordine, ciascuno con i
 * nomi per lingua.
 *
 * @return array<string, array{absent: ?string, states: list<array{identifier: string, name: array<string, string>}>}>
 */
function wmwp_status_families(): array {
	$walkability = wmwp_walkability_taxonomy();
	// Gli stati di osm2cai, come li crea wm-package: prima l'assenza di informazione, poi dal peggiore al migliore
	$families = [
		$walkability => [
			'absent' => $walkability . '-unknown',
			'states' => [
				[
					'identifier' => $walkability . '-unknown',
					'name'       => [
						'it' => 'Sconosciuta',
						'en' => 'Unknown',
					],
				],
				[
					'identifier' => $walkability . '-not-walkable',
					'name'       => [
						'it' => 'Non percorribile',
						'en' => 'Not walkable',
					],
				],
				[
					'identifier' => $walkability . '-partially-walkable',
					'name'       => [
						'it' => 'Parzialmente percorribile',
						'en' => 'Partially walkable',
					],
				],
				[
					'identifier' => $walkability . '-walkable',
					'name'       => [
						'it' => 'Percorribile',
						'en' => 'Walkable',
					],
				],
			],
		],
	];
	return (array) apply_filters( 'wmwp_status_families', $families );
}

/**
 * Livello di uno stato per gli indicatori a tacche: il totale è il numero degli stati della famiglia nello shard, stato
 * di assenza compreso, e il livello è la posizione dello stato (da 1): la percorribilità ha 4 tacche, «Non percorribile»
 * ne accende 2 perché la prima è il posto di «Sconosciuta» (Rubens, 18/11). Lo stato di assenza, il termine mancante,
 * una famiglia o uno stato che il registro non conosce valgono 0; `known` è false per un identifier che il registro non
 * ha (uno stato aggiunto nello shard prima che il registro lo sappia: si mostra a parole, senza tacche).
 *
 * @return array{level: int, total: int, known: bool}
 */
function wmwp_status_level( string $family, ?string $identifier ): array {
	$families = wmwp_status_families();
	if ( ! isset( $families[ $family ] ) ) {
		return [
			'level' => 0,
			'total' => 0,
			'known' => false,
		];
	}
	$absent = $families[ $family ]['absent'] ?? null;
	$states = array_values( array_column( (array) ( $families[ $family ]['states'] ?? [] ), 'identifier' ) );
	$known  = null === $identifier || in_array( $identifier, $states, true );
	$index  = null === $identifier || $identifier === $absent ? false : array_search( $identifier, $states, true );
	return [
		'level' => false === $index ? 0 : $index + 1,
		'total' => count( $states ),
		'known' => $known,
	];
}

/**
 * Nome di uno stato del registro nella lingua, con ripiego (wmwp_text()); vuoto per uno stato che il registro non ha.
 *
 * @return array{text: string, lang: ?string}
 */
function wmwp_status_name( string $family, string $identifier, string $lang ): array {
	foreach ( (array) ( wmwp_status_families()[ $family ]['states'] ?? [] ) as $state ) {
		if ( ( $state['identifier'] ?? '' ) === $identifier ) {
			return wmwp_text( $state['name'] ?? null, $lang );
		}
	}
	return [
		'text' => '',
		'lang' => null,
	];
}
