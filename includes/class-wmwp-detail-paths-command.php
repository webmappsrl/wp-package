<?php
/**
 * Comando WP-CLI dello storico dei percorsi dei dettagli (oc:8983): vedere a chi appartiene un percorso e liberarlo.
 * Serve alle correzioni a mano, per esempio quando un nome corretto passa da un elemento a un altro e la redazione
 * vuole che l'indirizzo senza suffisso vada all'elemento che oggi porta quel nome. Caricato solo con WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storico dei percorsi delle pagine di dettaglio di tracce e POI.
 */
class WMWP_Detail_Paths_Command {

	/**
	 * Mostra a chi appartengono i percorsi nello storico: quelli di un percorso, o tutti quelli di un elemento.
	 *
	 * ## OPTIONS
	 *
	 * [<path>]
	 * : Percorso `<base>/<slug>`, con o senza barre ai lati (senza il prefisso della lingua).
	 *
	 * [--lang=<lang>]
	 * : Lingua del percorso; senza, tutte.
	 *
	 * [--type=<type>]
	 * : Tipo dell'elemento, `track` o `poi`, con --key o --id.
	 *
	 * [--key=<key>]
	 * : Chiave della fonte dell'elemento (per Forestas il nid di Drupal).
	 *
	 * [--id=<id>]
	 * : Id dello shard, per gli elementi senza chiave della fonte.
	 *
	 * [--format=<format>]
	 * : Formato dell'uscita: table, csv, json, yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wmwp detail-paths list sentiero/monte-x
	 *     wp wmwp detail-paths list --type=track --key=4076
	 *
	 * @subcommand list
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc_args
	 */
	public function list_paths( array $args, array $assoc_args ): void {
		$this->require_history();
		if ( isset( $args[0] ) ) {
			$where = [ 'path' => (string) $args[0] ];
			if ( isset( $assoc_args['lang'] ) ) {
				$where['lang'] = (string) $assoc_args['lang'];
			}
		} elseif ( isset( $assoc_args['type'] ) && ( isset( $assoc_args['key'] ) || isset( $assoc_args['id'] ) ) ) {
			$where = [
				'type' => (string) $assoc_args['type'],
				'key'  => wmwp_detail_item_key( $assoc_args['key'] ?? null, (int) ( $assoc_args['id'] ?? 0 ) ),
			];
		} else {
			WP_CLI::error( 'Serve un percorso, oppure --type con --key o --id.' );
			return;
		}
		$rows = wmwp_detail_history_rows( $where );
		if ( ! $rows ) {
			WP_CLI::log( 'Nessun percorso nello storico.' );
			return;
		}
		// `current` come 1/0: nella tabella un false stamperebbe una cella vuota
		$rows = array_map(
			function ( array $row ): array {
				$row['current'] = $row['current'] ? 1 : 0;
				return $row;
			},
			$rows
		);
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, [ 'lang', 'path', 'type', 'key', 'suffix', 'current' ] );
	}

	/**
	 * Libera un percorso: lo toglie dallo storico, rimette in gioco gli omonimi, svuota l'indice in cache e prova a
	 * ricostruirlo (se non ci riesce subito, lo fa la richiesta successiva). Il percorso va all'elemento che oggi porta
	 * quel nome (il primo in ordine di chiave); i percorsi di prima degli omonimi restano loro e portano con un 301 a
	 * quelli nuovi.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Percorso `<base>/<slug>`, con o senza barre ai lati (senza il prefisso della lingua).
	 *
	 * [--lang=<lang>]
	 * : Lingua del percorso; senza, tutte.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wmwp detail-paths forget sentiero/monte-x
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc_args
	 */
	public function forget( array $args, array $assoc_args ): void {
		$this->require_history();
		$path    = wmwp_detail_normalize_path( (string) $args[0] );
		$deleted = wmwp_detail_history_forget( $path, isset( $assoc_args['lang'] ) ? (string) $assoc_args['lang'] : null );
		if ( 0 === $deleted ) {
			WP_CLI::warning( "Il percorso {$path} non è nello storico." );
			return;
		}
		// Indice in cache svuotato: se la ricostruzione non riesce adesso (ricostruzione in corso, shard giù, indice
		// scartato) la fa la richiesta successiva
		delete_transient( WMWP_DETAIL_INDEX_KEY );
		delete_option( WMWP_DETAIL_REBUILT_AT );
		$index = wmwp_detail_index( true );
		if ( null === $index || false === get_transient( WMWP_DETAIL_INDEX_KEY ) ) {
			WP_CLI::warning( "Percorso {$path} liberato ({$deleted} righe), ma l'indice non si è ricostruito adesso: lo farà la richiesta successiva." );
			return;
		}
		foreach ( (array) $index['paths'] as $lang => $paths ) {
			if ( isset( $paths[ $path ] ) ) {
				WP_CLI::log( sprintf( '%s: %s → %s %d', $lang, $path, $paths[ $path ][0], $paths[ $path ][1] ) );
			}
		}
		WP_CLI::success( "Percorso {$path} liberato ({$deleted} righe) e indice ricostruito." );
	}

	/** Esce con un errore se lo storico non c'è (tabella non creata). */
	private function require_history(): void {
		if ( ! wmwp_detail_history_available() ) {
			WP_CLI::error( 'Lo storico dei percorsi non è disponibile: la tabella non è stata creata (vedi il log).' );
		}
	}
}
