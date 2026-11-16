<?php
/**
 * Pagine di dettaglio (oc:8983): storico dei percorsi e chiave degli elementi.
 *
 * Un percorso `<base>/<slug>`, una volta dato a un elemento, resta suo finché l'elemento esiste: se l'elemento cambia
 * nome, base o riceve la traduzione del nome, il percorso vecchio porta con un 301 a quello attuale anche dopo la
 * ricostruzione dell'indice, e un omonimo nuovo non toglie l'indirizzo a chi c'era. Per riconoscere un elemento serve
 * una chiave che non cambi quando lo shard rinumera gli id: la chiave della fonte (`wmwp_detail_source()`, per
 * Forestas il nid di Drupal) se c'è, altrimenti l'id, che in produzione non si rinumera.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Versione dello schema della tabella: cambia con lo schema, e un plugin aggiornato sostituendo i file (senza
// riattivarlo, come fa l'init di wp-forestas) aggiorna la tabella al primo caricamento
const WMWP_DETAIL_PATHS_DB_VERSION     = 1;
const WMWP_DETAIL_PATHS_VERSION_OPTION = 'wmwp_detail_paths_db_version';
const WMWP_DETAIL_PATHS_SEED_OPTION    = 'wmwp_detail_paths_seed_pending';
const WMWP_DETAIL_PATHS_FAILED_OPTION  = 'wmwp_detail_paths_failed_at';
const WMWP_DETAIL_PATHS_RETRY_INTERVAL = HOUR_IN_SECONDS;
const WMWP_DETAIL_PATHS_WRITE_BATCH    = 200;
const WMWP_DETAIL_PATHS_COLUMNS        = 'lang, path, item_type, item_key, base, root, suffix, is_current';
// Chiave crollata: fra gli elementi che avevano la chiave della fonte e ci sono ancora, la tiene meno di questa quota
const WMWP_DETAIL_KEY_KEPT_RATIO = 0.5;
// Shard azzerato: fra gli elementi con la chiave della fonte presenti prima e dopo, ha un id nuovo più di questa quota
const WMWP_DETAIL_RENUMBER_RATIO = 0.5;

/**
 * Chiave di un elemento: `s:<chiave della fonte>` se c'è, altrimenti `i:<id>`. Tutto ciò che deve
 * riconoscere un elemento (storico, ordine dei doppioni, percorsi vecchi) passa da qui.
 */
function wmwp_detail_item_key( ?string $source, int $id ): string {
	$source = null === $source ? '' : trim( $source );
	return '' !== $source ? 's:' . $source : 'i:' . $id;
}

/**
 * Ordine fra le chiavi degli omonimi, uguale per lo stesso insieme qualunque sia l'ordine in cui arrivano dall'API:
 * prima le chiavi della fonte numeriche in ordine crescente (i nid: il più vecchio prima), poi quelle non numeriche in
 * ordine alfabetico, poi gli elementi con il solo id in ordine crescente (creati dopo, in Nova).
 */
function wmwp_detail_key_compare( string $a, string $b ): int {
	$rank = function ( string $key ): array {
		$value = (string) substr( $key, 2 );
		if ( 0 === strpos( $key, 's:' ) ) {
			return ctype_digit( $value ) ? [ 0, (int) $value, '' ] : [ 1, 0, $value ];
		}
		return [ 2, (int) $value, '' ];
	};
	[ $group_a, $number_a, $text_a ] = $rank( $a );
	[ $group_b, $number_b, $text_b ] = $rank( $b );
	if ( $group_a !== $group_b ) {
		return $group_a <=> $group_b;
	}
	if ( $number_a !== $number_b ) {
		return $number_a <=> $number_b;
	}
	return strcmp( $text_a, $text_b );
}

/** Tabella dello storico; il filtro `wmwp_detail_paths_table` serve solo ai test (sandbox), non è interfaccia pubblica. */
function wmwp_detail_paths_table(): string {
	global $wpdb;
	return (string) apply_filters( 'wmwp_detail_paths_table', $wpdb->prefix . 'wmwp_detail_paths' );
}

/** Impronta di lingua e percorso: la chiave unica, perché un percorso lungo supera la lunghezza indicizzabile. */
function wmwp_detail_path_hash( string $lang, string $path ): string {
	return md5( $lang . '|' . $path );
}

/**
 * La tabella esiste? Una `SHOW TABLES`, che non dipende dalla lingua dei messaggi di errore del database.
 */
function wmwp_detail_history_table_exists( string $table ): bool {
	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	$found    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	$wpdb->suppress_errors( $suppress );
	return $table === $found;
}

/**
 * Crea o aggiorna una tabella dello storico con dbDelta(); true se dopo la tabella esiste. Una riga per lingua e
 * percorso: proprietario (tipo e chiave), base e slug di partenza da cui è nato, suffisso, se è il percorso attuale.
 */
function wmwp_detail_history_create_table( string $table ): bool {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset  = $wpdb->get_charset_collate();
	$suppress = $wpdb->suppress_errors( true );
	dbDelta(
		"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(32) NOT NULL,
  lang varchar(20) NOT NULL,
  path varchar(1000) NOT NULL,
  item_type varchar(10) NOT NULL,
  item_key varchar(191) NOT NULL,
  base varchar(255) NOT NULL,
  root varchar(255) NOT NULL,
  suffix int(10) unsigned NOT NULL DEFAULT 0,
  is_current tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY item_key (item_key)
) {$charset};"
	);
	$wpdb->suppress_errors( $suppress );
	return wmwp_detail_history_table_exists( $table );
}

/**
 * Crea o aggiorna la tabella quando la versione salvata non è quella dello schema. Se la tabella prima non c'era, il
 * giro successivo dell'indice è il primo: i percorsi dell'indice esistente entrano nello storico (wmwp_detail_index()).
 * Se non si può creare (utente del database senza permessi) il plugin funziona senza storico, e si riprova al massimo
 * una volta all'ora invece che a ogni caricamento.
 */
function wmwp_detail_history_install(): void {
	if ( wmwp_detail_history_available() || time() - (int) get_option( WMWP_DETAIL_PATHS_FAILED_OPTION, 0 ) < WMWP_DETAIL_PATHS_RETRY_INTERVAL ) {
		return;
	}
	global $wpdb;
	$table   = wmwp_detail_paths_table();
	$existed = wmwp_detail_history_table_exists( $table );
	if ( ! wmwp_detail_history_create_table( $table ) ) {
		update_option( WMWP_DETAIL_PATHS_FAILED_OPTION, time(), false );
		error_log( '[wp-package] tabella dello storico dei percorsi non creata: ' . $wpdb->last_error );
		return;
	}
	update_option( WMWP_DETAIL_PATHS_VERSION_OPTION, WMWP_DETAIL_PATHS_DB_VERSION, false );
	delete_option( WMWP_DETAIL_PATHS_FAILED_OPTION );
	if ( ! $existed ) {
		update_option( WMWP_DETAIL_PATHS_SEED_OPTION, 1, false );
	}
}
add_action( 'init', 'wmwp_detail_history_install', 0 );

/** Lo storico si può usare: la tabella è alla versione dello schema. */
function wmwp_detail_history_available(): bool {
	return WMWP_DETAIL_PATHS_DB_VERSION === (int) get_option( WMWP_DETAIL_PATHS_VERSION_OPTION, 0 );
}

/**
 * Riga dello storico dalla riga della tabella.
 *
 * @param array<string, mixed> $record
 * @return array{type: string, key: string, base: string, root: string, suffix: int, current: bool}
 */
function wmwp_detail_history_row( array $record ): array {
	return [
		'type'    => (string) $record['item_type'],
		'key'     => (string) $record['item_key'],
		'base'    => (string) $record['base'],
		'root'    => (string) $record['root'],
		'suffix'  => (int) $record['suffix'],
		'current' => 1 === (int) $record['is_current'],
	];
}

/**
 * Esegue una lettura sulla tabella senza stampare errori: una tabella che manca o non risponde vale come storico vuoto.
 *
 * @return mixed Risultato di $wpdb, o null.
 */
function wmwp_detail_history_query( callable $query ) {
	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	$result   = $query( $wpdb, wmwp_detail_paths_table() );
	$wpdb->suppress_errors( $suppress );
	return $result;
}

/**
 * Tutto lo storico, lingua → percorso → riga (la forma che riceve wmwp_detail_build_index()); [] se lo storico non c'è
 * (tabella mai creata). Null se la tabella c'è ma la lettura fallisce: un indice costruito senza lo storico
 * riassegnerebbe i percorsi, quindi chi lo chiama non deve andare avanti. Se la tabella è sparita (versione salvata ma
 * tabella cancellata) si toglie la versione, così la tabella si ricrea al caricamento successivo.
 *
 * @return array<string, array<string, array{type: string, key: string, base: string, root: string, suffix: int, current: bool}>>|null
 */
function wmwp_detail_history_load(): ?array {
	global $wpdb;
	if ( ! wmwp_detail_history_available() ) {
		return [];
	}
	$records = wmwp_detail_history_query(
		function ( $wpdb, string $table ) {
			return $wpdb->get_results( 'SELECT ' . WMWP_DETAIL_PATHS_COLUMNS . " FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	);
	if ( '' !== (string) $wpdb->last_error ) {
		error_log( '[wp-package] storico dei percorsi non leggibile: ' . $wpdb->last_error );
		if ( ! wmwp_detail_history_table_exists( wmwp_detail_paths_table() ) ) {
			delete_option( WMWP_DETAIL_PATHS_VERSION_OPTION );
		}
		return null;
	}
	$history = [];
	foreach ( (array) $records as $record ) {
		$history[ (string) $record['lang'] ][ (string) $record['path'] ] = wmwp_detail_history_row( $record );
	}
	return $history;
}

/**
 * Riga dello storico di un percorso in una lingua, o null.
 *
 * @return array{type: string, key: string, base: string, root: string, suffix: int, current: bool}|null
 */
function wmwp_detail_history_lookup( string $lang, string $path ): ?array {
	if ( ! wmwp_detail_history_available() ) {
		return null;
	}
	$record = wmwp_detail_history_query(
		function ( $wpdb, string $table ) use ( $lang, $path ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT " . WMWP_DETAIL_PATHS_COLUMNS . " FROM `{$table}` WHERE path_hash = %s", wmwp_detail_path_hash( $lang, $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	);
	return is_array( $record ) && $lang === $record['lang'] && $path === $record['path'] ? wmwp_detail_history_row( $record ) : null;
}

/**
 * Righe dello storico da scrivere dopo un indice: ogni percorso assegnato nuovo o cambiato, come attuale; ogni percorso
 * che era attuale e non è più assegnato, come non attuale con lo stesso proprietario (resta suo finché l'elemento
 * esiste). Se non cambia niente, [].
 *
 * @param array<string, array<string, array<string, mixed>>> $history     Storico, come wmwp_detail_history_load().
 * @param list<array<string, mixed>>                           $assignments Assegnazioni di wmwp_detail_build_index().
 * @return list<array{lang: string, path: string, type: string, key: string, base: string, root: string, suffix: int, current: bool}>
 */
function wmwp_detail_history_changes( array $history, array $assignments ): array {
	$changes  = [];
	$assigned = [];
	foreach ( $assignments as $assignment ) {
		$lang = (string) $assignment['lang'];
		$path = (string) $assignment['path'];
		$want = [
			'type'    => (string) $assignment['type'],
			'key'     => (string) $assignment['key'],
			'base'    => (string) $assignment['base'],
			'root'    => (string) $assignment['root'],
			'suffix'  => (int) $assignment['suffix'],
			'current' => true,
		];
		$assigned[ $lang ][ $path ] = true;
		if ( ( $history[ $lang ][ $path ] ?? null ) !== $want ) {
			$changes[] = [ 'lang' => $lang, 'path' => $path ] + $want;
		}
	}
	foreach ( $history as $lang => $rows ) {
		foreach ( $rows as $path => $row ) {
			if ( ! empty( $row['current'] ) && ! isset( $assigned[ $lang ][ $path ] ) ) {
				$changes[] = [
					'lang'    => (string) $lang,
					'path'    => (string) $path,
					'type'    => (string) $row['type'],
					'key'     => (string) $row['key'],
					'base'    => (string) $row['base'],
					'root'    => (string) $row['root'],
					'suffix'  => (int) $row['suffix'],
					'current' => false,
				];
			}
		}
	}
	return $changes;
}

/**
 * Scrive righe dello storico (una per lingua e percorso: una riga che c'è già si sovrascrive), a blocchi. False, con
 * una riga nel log, se una scrittura fallisce.
 *
 * @param list<array{lang: string, path: string, type: string, key: string, base: string, root: string, suffix: int, current: bool}> $rows
 */
function wmwp_detail_history_write( array $rows ): bool {
	global $wpdb;
	if ( ! $rows ) {
		return true;
	}
	if ( ! wmwp_detail_history_available() ) {
		return false;
	}
	foreach ( array_chunk( $rows, WMWP_DETAIL_PATHS_WRITE_BATCH ) as $chunk ) {
		$placeholders = [];
		$values       = [];
		foreach ( $chunk as $row ) {
			$placeholders[] = '(%s, %s, %s, %s, %s, %s, %s, %d, %d)';
			array_push(
				$values,
				wmwp_detail_path_hash( (string) $row['lang'], (string) $row['path'] ),
				(string) $row['lang'],
				(string) $row['path'],
				(string) $row['type'],
				(string) $row['key'],
				(string) $row['base'],
				(string) $row['root'],
				(int) $row['suffix'],
				empty( $row['current'] ) ? 0 : 1
			);
		}
		$written = wmwp_detail_history_query(
			function ( $wpdb, string $table ) use ( $placeholders, $values ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				return $wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (path_hash, lang, path, item_type, item_key, base, root, suffix, is_current) VALUES " . implode( ', ', $placeholders ) . ' ON DUPLICATE KEY UPDATE item_type = VALUES(item_type), item_key = VALUES(item_key), base = VALUES(base), root = VALUES(root), suffix = VALUES(suffix), is_current = VALUES(is_current)', $values ) );
			}
		);
		if ( false === $written ) {
			error_log( '[wp-package] storico dei percorsi non scritto: ' . $wpdb->last_error );
			return false;
		}
	}
	return true;
}

/**
 * Dimentica le righe registrate con l'id (`i:…`), di un tipo o di tutti: dopo che lo shard è stato svuotato e
 * ricaricato (reset di dev e UAT) quegli id sono di altri elementi. Restituisce quante righe ha cancellato.
 */
function wmwp_detail_history_forget_ids( ?string $type = null ): int {
	if ( ! wmwp_detail_history_available() ) {
		return 0;
	}
	$deleted = wmwp_detail_history_query(
		function ( $wpdb, string $table ) use ( $type ) {
			return null === $type
				? $wpdb->query( "DELETE FROM `{$table}` WHERE item_key LIKE 'i:%'" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE item_key LIKE 'i:%%' AND item_type = %s", $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	);
	return is_int( $deleted ) ? $deleted : 0;
}

/**
 * Basi che compaiono nello storico: entrano nelle regole di rewrite, così il percorso vecchio di una base che non c'è
 * più (pagina base rinominata, nome di un route-type cambiato) arriva ancora al plugin.
 *
 * @return list<string>
 */
function wmwp_detail_history_bases(): array {
	if ( ! wmwp_detail_history_available() ) {
		return [];
	}
	$bases = wmwp_detail_history_query(
		function ( $wpdb, string $table ) {
			return $wpdb->get_col( "SELECT DISTINCT base FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	);
	return array_values( array_map( 'strval', (array) $bases ) );
}

/**
 * Tipi degli elementi che lo storico ha avuto sotto una base: per il ripiego per slug di un percorso sotto una base
 * che l'indice non ha più, così l'indirizzo vecchio di una traccia non porta a un POI con lo stesso slug.
 *
 * @return list<string>
 */
function wmwp_detail_history_base_types( string $base ): array {
	if ( ! wmwp_detail_history_available() ) {
		return [];
	}
	$types = wmwp_detail_history_query(
		function ( $wpdb, string $table ) use ( $base ) {
			return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT item_type FROM `{$table}` WHERE base = %s", $base ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	);
	return array_values( array_map( 'strval', (array) $types ) );
}

/**
 * Basi delle regole di rewrite, in un punto solo: quelle dell'indice e quelle dello storico, ordinate e senza doppioni.
 *
 * @param array<string, mixed> $index Indice dei dettagli.
 * @return list<string>
 */
function wmwp_detail_rule_bases( array $index ): array {
	$bases = array_values( array_unique( array_merge( wmwp_detail_index_bases( $index ), wmwp_detail_history_bases() ) ) );
	sort( $bases );
	return $bases;
}

/**
 * Elementi con la chiave della fonte, per tipo, fra gli elementi letti dallo shard (wmwp_detail_fetch_items()).
 *
 * @param list<array<string, mixed>> $items
 * @return array<string, int>
 */
function wmwp_detail_count_sources( array $items ): array {
	$counts = [];
	foreach ( $items as $item ) {
		$type            = (string) $item['type'];
		$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + ( isset( $item['source'] ) && '' !== (string) $item['source'] ? 1 : 0 );
	}
	return $counts;
}

/**
 * La chiave della fonte è crollata rispetto all'indice precedente: fra gli elementi che c'erano anche prima (stesso
 * tipo e id) e avevano la chiave, la tiene meno della metà. Vuol dire che le API hanno smesso di darla (è successo con
 * `out_source_feature_id` in oc:8959): con quell'indice i percorsi si libererebbero e gli omonimi si scambierebbero
 * l'indirizzo, e lo storico lo registrerebbe. Si guardano solo gli elementi presenti tutte e due le volte: cancellazioni
 * e aggiunte, con o senza chiave, non spostano il conto (le giudica il controllo sui conteggi di wmwp_detail_index()).
 *
 * @param array<string, mixed>|null  $previous Indice precedente.
 * @param list<array<string, mixed>> $items    Elementi nuovi.
 */
function wmwp_detail_keys_collapsed( ?array $previous, array $items ): bool {
	$had  = [];
	$kept = [];
	foreach ( $items as $item ) {
		$type    = (string) $item['type'];
		$element = $previous['items'][ $type ][ (int) $item['id'] ] ?? null;
		if ( ! is_array( $element ) || '' === trim( (string) ( $element['source'] ?? '' ) ) ) {
			continue;
		}
		$had[ $type ]  = ( $had[ $type ] ?? 0 ) + 1;
		$kept[ $type ] = ( $kept[ $type ] ?? 0 ) + ( '' !== trim( (string) ( $item['source'] ?? '' ) ) ? 1 : 0 );
	}
	foreach ( $had as $type => $count ) {
		if ( $kept[ $type ] < WMWP_DETAIL_KEY_KEPT_RATIO * $count ) {
			return true;
		}
	}
	return false;
}

/**
 * Tipi per cui lo shard è stato svuotato e ricaricato (reset di dev e UAT, `TRUNCATE ... RESTART IDENTITY`): più della
 * metà degli elementi con la chiave della fonte, presenti anche nell'indice precedente, ha un id diverso. Uno solo non
 * basta: in produzione un elemento cancellato e reimportato cambia id da solo, e cancellare per quello le righe di tutti
 * gli elementi creati in Nova farebbe perdere i loro indirizzi. Le chiavi doppie non contano.
 *
 * @param array<string, mixed>|null  $previous Indice precedente.
 * @param list<array<string, mixed>> $items    Elementi nuovi.
 * @return list<string>
 */
function wmwp_detail_shard_renumbered( ?array $previous, array $items ): array {
	// Chiave → id, per tipo, contando le chiavi doppie (due elementi con lo stesso nid): quelle non dicono niente
	$before = [];
	foreach ( (array) ( $previous['items'] ?? [] ) as $type => $elements ) {
		foreach ( (array) $elements as $id => $element ) {
			$source = trim( (string) ( $element['source'] ?? '' ) );
			if ( '' !== $source ) {
				$before[ $type ][ $source ][] = (int) $id;
			}
		}
	}
	$now = [];
	foreach ( $items as $item ) {
		$source = trim( (string) ( $item['source'] ?? '' ) );
		if ( '' !== $source ) {
			$now[ (string) $item['type'] ][ $source ][] = (int) $item['id'];
		}
	}
	$renumbered = [];
	foreach ( $now as $type => $keys ) {
		$common  = 0;
		$changed = 0;
		foreach ( $keys as $source => $ids ) {
			$old = $before[ $type ][ $source ] ?? [];
			if ( 1 === count( $ids ) && 1 === count( $old ) ) {
				++$common;
				$changed += $ids[0] !== $old[0] ? 1 : 0;
			}
		}
		if ( $common > 0 && $changed > WMWP_DETAIL_RENUMBER_RATIO * $common ) {
			$renumbered[] = (string) $type;
		}
	}
	return $renumbered;
}

/**
 * Righe del primo giro della versione con lo storico: i percorsi di un indice esistente, come non attuali, ognuno del
 * suo elemento. Così i percorsi di prima (per esempio i doppioni con `-<nid>` della 0.2.0) restano del loro elemento e
 * portano con un 301 a quelli ricalcolati con le regole nuove. wmwp_detail_index() le scrive solo con un indice
 * accettato.
 *
 * @param array<string, mixed> $index Indice esistente (transient o ultimo indice buono).
 * @return list<array{lang: string, path: string, type: string, key: string, base: string, root: string, suffix: int, current: bool}>
 */
function wmwp_detail_history_seed_rows( array $index ): array {
	$rows = [];
	foreach ( (array) ( $index['paths'] ?? [] ) as $lang => $paths ) {
		foreach ( (array) $paths as $path => $target ) {
			[ $type, $id ]   = [ (string) $target[0], (int) $target[1] ];
			[ $base, $slug ] = wmwp_detail_split_path( (string) $path );
			$rows[]          = [
				'lang'    => (string) $lang,
				'path'    => (string) $path,
				'type'    => $type,
				'key'     => wmwp_detail_item_key( $index['items'][ $type ][ $id ]['source'] ?? null, $id ),
				'base'    => $base,
				'root'    => $slug,
				'suffix'  => 0,
				'current' => false,
			];
		}
	}
	return $rows;
}

/**
 * Id che ha oggi nell'indice l'elemento con quella chiave, o null se non c'è più. La chiave di ogni elemento viene da
 * wmwp_detail_item_key(), come quando è stata scritta: una chiave `i:<id>` vale solo se l'elemento con quell'id è
 * ancora senza chiave della fonte, altrimenti l'id è passato a un altro elemento.
 *
 * @param array<string, mixed> $index Indice dei dettagli.
 */
function wmwp_detail_index_id_for_key( array $index, string $type, string $key ): ?int {
	$elements = (array) ( $index['items'][ $type ] ?? [] );
	if ( 0 === strpos( $key, 'i:' ) ) {
		$id = (int) substr( $key, 2 );
		return isset( $elements[ $id ] ) && wmwp_detail_item_key( $elements[ $id ]['source'] ?? null, $id ) === $key ? $id : null;
	}
	foreach ( $elements as $id => $element ) {
		if ( wmwp_detail_item_key( $element['source'] ?? null, (int) $id ) === $key ) {
			return (int) $id;
		}
	}
	return null;
}

// Un sito cancellato dalla rete porta via anche la sua tabella dello storico
add_filter(
	'wpmu_drop_tables',
	function ( array $tables, int $site_id ): array {
		global $wpdb;
		$tables[] = $wpdb->get_blog_prefix( $site_id ) . 'wmwp_detail_paths';
		return $tables;
	},
	10,
	2
);

/**
 * Righe dello storico, per il comando WP-CLI: di un percorso (`path`, con `lang` facoltativa) o di un elemento (`type`
 * e `key`, la chiave di wmwp_detail_item_key()).
 *
 * @param array{path?: string, lang?: string, type?: string, key?: string} $where
 * @return list<array{lang: string, path: string, type: string, key: string, base: string, root: string, suffix: int, current: bool}>
 */
function wmwp_detail_history_rows( array $where ): array {
	if ( ! wmwp_detail_history_available() ) {
		return [];
	}
	$records = wmwp_detail_history_query(
		function ( $wpdb, string $table ) use ( $where ) {
			$select = "SELECT " . WMWP_DETAIL_PATHS_COLUMNS . " FROM `{$table}`";
			if ( isset( $where['path'] ) ) {
				$path = wmwp_detail_normalize_path( (string) $where['path'] );
				return isset( $where['lang'] )
					? $wpdb->get_results( $wpdb->prepare( "{$select} WHERE path_hash = %s", wmwp_detail_path_hash( (string) $where['lang'], $path ) ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					: $wpdb->get_results( $wpdb->prepare( "{$select} WHERE path = %s ORDER BY lang", $path ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return $wpdb->get_results( $wpdb->prepare( "{$select} WHERE item_type = %s AND item_key = %s ORDER BY lang, is_current DESC, path", (string) ( $where['type'] ?? '' ), (string) ( $where['key'] ?? '' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	);
	$rows = [];
	foreach ( (array) $records as $record ) {
		$rows[] = [ 'lang' => (string) $record['lang'], 'path' => (string) $record['path'] ] + wmwp_detail_history_row( $record );
	}
	return $rows;
}

/**
 * Libera un percorso (correzione a mano, `wp wmwp detail-paths forget`): toglie le sue righe dallo storico, in una
 * lingua o in tutte, e rende non attuali le righe con la stessa base e lo stesso slug di partenza. Così al giro
 * successivo gli omonimi si riassegnano in ordine di chiave, e il percorso va all'elemento che oggi porta quel nome; i
 * loro percorsi di prima restano loro (301). Restituisce le righe cancellate.
 */
function wmwp_detail_history_forget( string $path, ?string $lang = null ): int {
	$where = [ 'path' => $path ];
	if ( null !== $lang ) {
		$where['lang'] = $lang;
	}
	$rows = wmwp_detail_history_rows( $where );
	foreach ( $rows as $row ) {
		wmwp_detail_history_query(
			function ( $wpdb, string $table ) use ( $row ) {
				$wpdb->delete( $table, [ 'path_hash' => wmwp_detail_path_hash( $row['lang'], $row['path'] ) ] );
				return $wpdb->update(
					$table,
					[ 'is_current' => 0 ],
					[
						'lang' => $row['lang'],
						'base' => $row['base'],
						'root' => $row['root'],
					]
				);
			}
		);
	}
	return count( $rows );
}
