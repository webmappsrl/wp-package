<?php
/**
 * Icone del set IcoMoon dello shard (oc:8969): `<awsApi>/json/icons.json`, il file che lo shard carica da
 * `/icons-upload` e che usano anche Nova (campo icona dei termini) e l'app. Il tema chiede un'icona con il nome che il
 * JSON dello shard porta in `icon_name` e la mostra con il colore del testo (`fill: currentColor`).
 *
 * Il set pesa circa 1 MB: si scarica solo quando si chiede un nome mai chiesto, e in cache restano solo gli SVG dei
 * nomi già chiesti (un giorno, così un'icona cambiata nel set arriva entro un giorno).
 *
 * @package wp-package
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_ICONS_CACHE_KEY = 'wmwp_icons';

/**
 * Icone del set per nome: name => [ 'paths' => list<string>, 'size' => int ]. Legge il formato IcoMoon «set»
 * (`icons[].paths` + `selection[].name`, o `icons[].tags[0]`) e quello vecchio (`icons[].properties.name` +
 * `icons[].icon.paths`). Null se il set non risponde o non si legge.
 *
 * @return array<string, array{paths: list<string>, size: int}>|null
 */
function wmwp_icons_set(): ?array {
	$aws = wmwp_configured_api_urls()['aws_api'];
	if ( '' === $aws ) {
		return null;
	}
	$response = wp_remote_get( $aws . '/json/icons.json', [ 'timeout' => 10 ] );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || ! is_array( $data['icons'] ?? null ) ) {
		return null;
	}
	$size  = (int) ( $data['height'] ?? $data['preferences']['fontPref']['metrics']['emSize'] ?? 1024 );
	$size  = $size > 0 ? $size : 1024;
	$icons = [];
	foreach ( array_values( $data['icons'] ) as $i => $icon ) {
		if ( ! is_array( $icon ) ) {
			continue;
		}
		if ( isset( $icon['properties']['name'] ) ) {
			$name  = (string) $icon['properties']['name'];
			$paths = $icon['icon']['paths'] ?? [];
		} else {
			$name  = (string) ( $data['selection'][ $i ]['name'] ?? $icon['tags'][0] ?? '' );
			$paths = $icon['paths'] ?? [];
		}
		$paths = array_values( array_filter( (array) $paths, 'is_string' ) );
		if ( '' !== $name && $paths ) {
			$icons[ $name ] = [
				'paths' => $paths,
				'size'  => $size,
			];
		}
	}
	return $icons;
}

/**
 * SVG di un'icona del set dello shard, decorativo (`aria-hidden`), con il colore del testo; '' se il nome non c'è o il
 * set non risponde. Il nome viene di solito da `icon_name` di un termine nel JSON dello shard.
 */
function wmwp_icon_svg( string $name ): string {
	static $set = false;
	$name = trim( $name );
	if ( '' === $name ) {
		return '';
	}
	$cache = get_transient( WMWP_ICONS_CACHE_KEY );
	$cache = is_array( $cache ) ? $cache : [];
	if ( array_key_exists( $name, $cache ) ) {
		return (string) $cache[ $name ];
	}
	// Il set scaricato resta in memoria per la richiesta; a cache vuota (scaduta o svuotata) si riscarica
	if ( false === $set || [] === $cache ) {
		$set = wmwp_icons_set();
	}
	if ( null === $set ) {
		// Set che non risponde: niente icona, si riprova al più presto fra un'ora
		$cache[ $name ] = '';
		set_transient( WMWP_ICONS_CACHE_KEY, $cache, HOUR_IN_SECONDS );
		$set = false;
		return '';
	}
	$svg = '';
	if ( isset( $set[ $name ] ) ) {
		$size = (int) $set[ $name ]['size'];
		$svg  = '<svg class="wmwp-icon" viewBox="0 0 ' . $size . ' ' . $size . '" fill="currentColor" aria-hidden="true" focusable="false">';
		foreach ( $set[ $name ]['paths'] as $path ) {
			$svg .= '<path d="' . esc_attr( $path ) . '"/>';
		}
		$svg .= '</svg>';
	}
	$cache[ $name ] = $svg;
	set_transient( WMWP_ICONS_CACHE_KEY, $cache, DAY_IN_SECONDS );
	return $svg;
}
