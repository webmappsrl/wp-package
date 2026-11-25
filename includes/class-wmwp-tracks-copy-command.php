<?php
/**
 * Comando WP-CLI della copia delle tracce per le griglie (oc:9003). Serve alle prove: un WordPress collegato a uno
 * shard con pochi dati (es. dev, dove quasi nessun percorso ha distanza e tempo) carica la risposta salvata di un altro
 * ambiente; `refresh` ricarica dallo shard collegato. Il rebuild orario dell'indice la riscrive comunque. Caricato solo
 * con WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copia delle tracce delle griglie.
 */
class WMWP_Tracks_Copy_Command {

	/**
	 * Riempie la copia da una risposta salvata dell'API Elasticsearch della ricerca.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : File JSON: la risposta dell'API (`hits.hits[]._source`) o una lista di tracce.
	 *
	 * ## EXAMPLES
	 *
	 *     curl -s "https://<shard>/api/v2/elasticsearch?app=<shard>_<app>" -o tracce.json
	 *     wp wmwp tracks-copy load tracce.json
	 *
	 * @param array<int, string> $args Argomenti.
	 */
	public function load( array $args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( "File non leggibile: {$file}" );
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'Il file non è JSON.' );
		}
		$hits = $data['hits'] ?? $data;
		if ( isset( $hits['hits'] ) && is_array( $hits['hits'] ) ) {
			$hits = $hits['hits'];
		}
		$copy = wmwp_tracks_copy_build( (array) $hits );
		if ( ! $copy ) {
			WP_CLI::error( 'Nessuna traccia con id nel file: la copia resta com\'era.' );
		}
		update_option( WMWP_TRACKS_COPY_OPTION, $copy, false );
		$this->summary( $copy );
	}

	/**
	 * Ricarica la copia dallo shard collegato, con un rebuild dell'indice dei dettagli.
	 */
	public function refresh(): void {
		// Il rebuild salta se l'ultimo è di meno di 5 minuti fa (WMWP_DETAIL_REBUILD_INTERVAL): qui lo si vuole adesso
		delete_option( WMWP_DETAIL_REBUILT_AT );
		$before = get_option( WMWP_TRACKS_COPY_OPTION, null );
		if ( null === wmwp_detail_index( true ) ) {
			WP_CLI::error( 'Rebuild dell\'indice non riuscito o scartato: la copia resta com\'era.' );
		}
		$copy = wmwp_tracks_copy();
		if ( null === $copy ) {
			WP_CLI::error( 'Rebuild fatto, ma la copia delle tracce non c\'è: controlla il log di PHP.' );
		}
		if ( $before === get_option( WMWP_TRACKS_COPY_OPTION, null ) ) {
			WP_CLI::warning( 'La copia non è cambiata: lo shard dà le stesse tracce, o il rebuild non l\'ha riscritta.' );
		}
		$this->summary( $copy );
	}

	/**
	 * @param list<array<string, mixed>> $copy Copia.
	 */
	private function summary( array $copy ): void {
		$distance = count( array_filter( $copy, fn( $track ) => ( $track['distance'] ?? 0 ) > 0 ) );
		$duration = count( array_filter( $copy, fn( $track ) => ( $track['duration_forward'] ?? 0 ) > 0 ) );
		WP_CLI::success( sprintf( 'Copia delle tracce: %d, con distanza %d, con tempo %d.', count( $copy ), $distance, $duration ) );
	}
}
