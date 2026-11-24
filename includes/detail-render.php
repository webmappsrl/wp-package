<?php
/**
 * Pagine di dettaglio (oc:8969): lettura del JSON, controllo dell'elemento e template.
 *
 * Il JSON si legge dal file della traccia su AWS (o MinIO) e dall'API dello shard (`api/ec/poi/<id>`, e
 * `api/ec/track/<id>` se il file non c'è), con una cache breve (`wmwp_detail_cache_ttl`, 60 s) e la richiesta
 * condizionale al file: i dati sono quelli del backend entro un minuto. Se la fonte non risponde si serve la copia in
 * cache, se c'è (oc:8990, header `X-WMWP-Stale: 1`). Prima di stampare si controlla che l'id porti ancora all'elemento chiesto (dopo un reset
 * dello shard gli id cambiano): la chiave stabile della fonte (`wmwp_detail_source()`) se c'è nel dettaglio e
 * nell'indice, altrimenti lo slug del nome. Il template si cerca prima nel tema (`wp-package/<tipo>.php`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Valore del segno del controllo quando la fonte non ha risposto e si è servita la copia: per la cache breve le visite
// leggono la copia senza richiamare la fonte, e la pagina resta segnalata
const WMWP_DETAIL_STALE_MARK = 'stale';

/**
 * Versione del file della traccia su AWS da leggere (oc:8997 di wm-package: `tracks/<versione>/<id>.json`, accanto al
 * file senza versione), scelta dal tema con il filtro `wmwp_track_json_version`: '' (predefinito) per il file senza
 * versione, `v1`, `v2`… Un valore che non ha la forma `v<numero>` vale come ''. Riguarda solo il dettaglio della
 * traccia: POI, `pois.geojson` ed Elasticsearch non hanno versioni.
 */
function wmwp_track_json_version(): string {
	$version = (string) apply_filters( 'wmwp_track_json_version', '' );
	return 1 === preg_match( '/^v[1-9][0-9]*$/', $version ) ? $version : '';
}

/**
 * JSON del dettaglio (una Feature), o WP_Error: `wmwp_detail_not_found` se lo shard dice che l'elemento non c'è (404:
 * cancellato, o id inesistente), `wmwp_detail_unavailable` se la fonte non risponde (rete, timeout, codice diverso da
 * 200, 304 e 404, risposta che non è una feature) e non c'è una copia da servire. Sul file della traccia decide l'API
 * dello shard: un file che manca su AWS non basta a dire che la traccia non c'è.
 *
 * Con la fonte che non risponde si restituisce la copia in cache, se c'è (oc:8990): il controllo conta come fatto per
 * la cache breve, nel log va un avviso e wmwp_detail_served_stale() lo dice alla pagina. Il ripiego non vale con
 * `$fresh = true`, che chiede il verdetto della fonte, né con il filtro `wmwp_detail_stale_if_error` a false. Il 404
 * della fonte resta un 404 anche con la copia.
 *
 * @return array<string, mixed>|WP_Error
 */
function wmwp_detail_fetch( string $type, int $id, bool $fresh = false ) {
	$urls   = wmwp_configured_api_urls();
	$origin = $urls['origin'];
	if ( '' === $origin || ! in_array( $type, WMWP_DETAIL_TYPES, true ) ) {
		return new WP_Error( 'wmwp_detail_config', __( 'Shard not configured', 'wp-package' ) );
	}
	/**
	 * Durata in secondi della cache breve del JSON (predefinita 60): per questo tempo il dettaglio non si richiede, e
	 * le visite e i crawler sulla stessa pagina non chiamano niente. 0 spegne la cache.
	 *
	 * @param int $ttl
	 */
	$ttl    = (int) apply_filters( 'wmwp_detail_cache_ttl', MINUTE_IN_SECONDS );
	// Due transient: il JSON (grande, si riscrive solo quando cambia) e il segno del controllo (piccolo, scade da solo
	// dopo la cache breve): con il 304 si rinnova solo il segno
	$key     = 'wmwp_detail_json_' . $type . '_' . $id;
	$checked = 'wmwp_detail_chk_' . $type . '_' . $id;
	$cached  = $ttl > 0 ? get_transient( $key ) : false;
	$cached  = is_array( $cached ) && is_array( $cached['feature'] ?? null ) ? $cached : null;
	// Il segnale della copia dice com'è andata l'ultima lettura di questo dettaglio: si accende solo se esce dalla copia
	$flag = $type . '/' . $id;
	unset( $GLOBALS['wmwp_detail_stale'][ $flag ] );
	$mark = null !== $cached && ! $fresh ? get_transient( $checked ) : false;
	if ( false !== $mark ) {
		if ( WMWP_DETAIL_STALE_MARK === $mark ) {
			// Nella cache breve dopo un controllo fallito: è ancora la copia che la fonte non ha confermato
			$GLOBALS['wmwp_detail_stale'][ $flag ] = true;
		}
		return $cached['feature'];
	}

	// La traccia dal file che lo shard pubblica su AWS, lo stesso che restituisce l'API; il POI un file non lo ha. Con
	// una versione (wmwp_track_json_version()) prima il suo file, e se non c'è (shard che non la scrive ancora) quello
	// senza versione. La richiesta condizionale vale solo per il file da cui viene la copia
	$entry  = null;
	$reason = '';
	if ( 'track' === $type && '' !== $urls['aws_api'] ) {
		$version = wmwp_track_json_version();
		$files   = array_unique( [ $urls['aws_api'] . '/tracks/' . ( '' !== $version ? $version . '/' : '' ) . $id . '.json', $urls['aws_api'] . '/tracks/' . $id . '.json' ] );
		foreach ( $files as $file ) {
			$why   = '';
			$same  = null !== $cached && ( $cached['url'] ?? $urls['aws_api'] . '/tracks/' . $id . '.json' ) === $file;
			$entry = wmwp_detail_fetch_file( $file, $same ? $cached : null, $why );
			if ( null !== $entry ) {
				$entry['url'] = $file;
				break;
			}
			// Con due file il motivo dice quale: «file AWS v1/5.json: HTTP 404; file AWS tracks/5.json: …»
			$reason .= 'file AWS' . ( count( $files ) > 1 ? ' ' . basename( dirname( $file ) ) . '/' . basename( $file ) : '' ) . ': ' . $why . '; ';
		}
	}
	if ( null === $entry ) {
		$api = wmwp_http_json( wmwp_server_url( $origin . '/api/ec/' . $type . '/' . $id ), WMWP_DETAIL_TIMEOUT );
		if ( 404 === $api['code'] ) {
			if ( null !== $cached ) {
				// L'elemento non c'è più: la sua copia non deve uscire al prossimo errore della fonte
				delete_transient( $key );
				delete_transient( $checked );
			}
			return new WP_Error( 'wmwp_detail_not_found', __( 'Detail not found', 'wp-package' ) );
		}
		$entry = wmwp_detail_is_feature( $api['data'] ) ? [ 'feature' => $api['data'] ] : null;
		if ( null === $entry ) {
			$reason .= 'API: ' . wmwp_detail_failure_reason( (int) $api['code'], (string) $api['error'] );
		}
	}
	if ( null === $entry ) {
		/**
		 * Con la fonte che non risponde il dettaglio esce dalla copia in cache, se c'è, invece del 503 (oc:8990). false
		 * torna al 503 senza un rilascio del plugin.
		 *
		 * @param bool   $stale Servire la copia (predefinito true).
		 * @param string $type  Tipo del dettaglio (`track`, `poi`).
		 * @param int    $id    Id dello shard.
		 */
		if ( null !== $cached && ! $fresh && (bool) apply_filters( 'wmwp_detail_stale_if_error', true, $type, $id ) ) {
			// Il controllo conta come fatto per la cache breve: le visite del minuto dopo non richiamano la fonte, e
			// nel log va un avviso per controllo
			set_transient( $checked, WMWP_DETAIL_STALE_MARK, $ttl );
			$GLOBALS['wmwp_detail_stale'][ $flag ] = true;
			error_log( sprintf( '[wp-package] dettaglio %s %d dalla copia, fonte non raggiungibile: %s', $type, $id, $reason ) );
			return $cached['feature'];
		}
		error_log( sprintf( '[wp-package] dettaglio %s %d 503, %s: %s', $type, $id, null === $cached ? 'nessuna copia' : 'copia non usata', $reason ) );
		return new WP_Error( 'wmwp_detail_unavailable', __( 'Detail not available', 'wp-package' ) );
	}
	if ( $ttl > 0 ) {
		// Il JSON si scrive per WMWP_DETAIL_JSON_TTL: dopo la cache breve si richiede con ETag/Last-Modified, e con il 304
		// si riusa. Con il 304 la copia si riscrive solo quando le resta meno di un'ora, per non scrivere nel database a
		// ogni controllo: dopo l'ultimo controllo riuscito resta quindi almeno un'ora e al massimo WMWP_DETAIL_JSON_TTL
		// (con una object cache esterna la scadenza non si legge e la copia si riscrive a ogni 304, in memoria)
		if ( empty( $entry['not_modified'] ) ) {
			unset( $entry['not_modified'] );
			set_transient( $key, $entry, WMWP_DETAIL_JSON_TTL );
		} elseif ( (int) get_option( '_transient_timeout_' . $key ) - time() < HOUR_IN_SECONDS ) {
			set_transient( $key, $cached, WMWP_DETAIL_JSON_TTL );
		}
		set_transient( $checked, 1, $ttl );
	}
	return $entry['feature'];
}

/**
 * Vero se in questa richiesta un dettaglio è uscito dalla copia in cache perché la fonte non ha risposto (oc:8990): la
 * pagina lo dice con l'header `X-WMWP-Stale: 1`. Conta l'ultima lettura di ogni dettaglio, quindi una rilettura che
 * riesce spegne il segnale. Con tipo e id vale solo per quel dettaglio.
 */
function wmwp_detail_served_stale( string $type = '', int $id = 0 ): bool {
	$stale = (array) ( $GLOBALS['wmwp_detail_stale'] ?? [] );
	return '' === $type ? [] !== $stale : ! empty( $stale[ $type . '/' . $id ] );
}

/**
 * Feature GeoJSON con le proprietà, la forma minima di un dettaglio.
 *
 * @param mixed $feature
 */
function wmwp_detail_is_feature( $feature ): bool {
	return is_array( $feature ) && isset( $feature['properties'] ) && is_array( $feature['properties'] );
}

/**
 * Motivo di un fallimento per il log: il messaggio dell'errore di rete se c'è, altrimenti la risposta che non è una
 * feature (codice 200) o il codice HTTP.
 *
 * @param int    $code  Codice HTTP (0 senza risposta).
 * @param string $error Messaggio dell'errore di rete, vuoto se la richiesta è arrivata.
 */
function wmwp_detail_failure_reason( int $code, string $error ): string {
	if ( '' !== $error ) {
		return $error;
	}
	return 200 === $code ? 'risposta che non è una feature' : 'HTTP ' . $code;
}

/**
 * File del dettaglio pubblicato dallo shard all'indirizzo `awsApi` di `environment.ts` (S3 o MinIO, per il plugin è lo
 * stesso), con la richiesta condizionale quando
 * c'è già una copia: `If-None-Match` con l'ETag, `If-Modified-Since` con la data. Con il 304 torna la copia; null se il
 * file non c'è o non risponde, e allora si legge l'API dello shard.
 *
 * @param array<string, mixed>|null $cached Copia in cache: `feature`, `etag`, `modified`.
 * @param string                    $reason Motivo per il log quando torna null: messaggio dell'errore di rete, codice
 *                                          HTTP o risposta che non è una feature; '' altrimenti.
 * @return array{feature: array<string, mixed>, etag: string, modified: string, not_modified?: bool}|null
 */
function wmwp_detail_fetch_file( string $url, ?array $cached, string &$reason = '' ): ?array {
	$reason  = '';
	$headers = [];
	if ( null !== $cached && '' !== (string) ( $cached['etag'] ?? '' ) ) {
		$headers['If-None-Match'] = (string) $cached['etag'];
	} elseif ( null !== $cached && '' !== (string) ( $cached['modified'] ?? '' ) ) {
		$headers['If-Modified-Since'] = (string) $cached['modified'];
	}
	$response = wp_remote_get(
		$url,
		[
			'timeout' => 5,
			'headers' => $headers,
		]
	);
	if ( is_wp_error( $response ) ) {
		$reason = $response->get_error_message();
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 304 === $code && null !== $cached && $headers ) {
		return [
			'feature'      => $cached['feature'],
			'etag'         => (string) ( $cached['etag'] ?? '' ),
			'modified'     => (string) ( $cached['modified'] ?? '' ),
			'not_modified' => true,
		];
	}
	$feature = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
	if ( ! wmwp_detail_is_feature( $feature ) ) {
		$reason = wmwp_detail_failure_reason( $code, '' );
		return null;
	}
	return [
		'feature'  => $feature,
		'etag'     => (string) wp_remote_retrieve_header( $response, 'etag' ),
		'modified' => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
	];
}

/**
 * Lo slug richiesto è quello del nome nel JSON, con o senza il suffisso dei doppioni? Il suffisso accettato è quello che
 * l'indice ha dato all'elemento in quella lingua (`-2`, `-3`…, oc:8983), non un `-<n>` qualunque: un elemento rinominato
 * da «Foo 3» a «Foo» non resta servito su `foo-3`. Quelli della 0.2.0, `-<chiave stabile>` dell'indice o `-<id>` con un
 * numero in più se anche quello era preso, valgono ancora per gli indirizzi rimasti in cache. Null se il JSON non ha il
 * nome (il dettaglio di un POI).
 *
 * @param array<string, mixed> $resolved Risultato di wmwp_detail_resolve() con lang e path.
 * @param array<string, mixed> $props    `properties` del JSON.
 */
function wmwp_detail_slug_matches( array $resolved, array $props, string $known ): ?bool {
	$name = wmwp_detail_text( $props['name'] ?? null, (string) $resolved['lang'] );
	if ( '' === $name ) {
		return null;
	}
	$requested = wmwp_detail_split_path( (string) $resolved['path'] )[1];
	$expected  = wmwp_detail_slug( $name, (int) $resolved['id'] );
	$index  = wmwp_detail_index();
	$suffix = (int) ( $index['suffixes'][ (string) ( $resolved['type'] ?? '' ) ][ (int) $resolved['id'] ][ (string) $resolved['lang'] ] ?? 0 );
	if ( $requested === $expected || ( $suffix > 0 && $requested === $expected . '-' . $suffix ) ) {
		return true;
	}
	// Le forme della 0.2.0 solo con un indice della 0.2.0 ancora in cache (senza `suffixes`): con un indice 0.3.0
	// accetterebbero `foo-3` per un elemento con id o chiave 3 rinominato da «Foo 3» a «Foo»
	if ( isset( $index['suffixes'] ) ) {
		return false;
	}
	foreach ( array_filter( [ sanitize_title( $known ), (string) (int) $resolved['id'] ] ) as $suffix ) {
		if ( $requested === $expected . '-' . $suffix || 0 === strpos( $requested, $expected . '-' . $suffix . '-' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * L'id porta ancora all'elemento chiesto? Se dettaglio e indice hanno la chiave stabile, decide quella: una traccia
 * rinominata resta lei, una omonima che dopo un reset ha preso quell'id no. Altrimenti decide lo slug del nome
 * (wmwp_detail_slug_matches()); senza nome né chiave, si accetta.
 *
 * @param array<string, mixed>      $resolved Risultato di wmwp_detail_resolve() con lang e path.
 * @param array<string, mixed>      $feature  JSON del dettaglio.
 * @param array<string, mixed>|null $item     Elemento dell'indice (`items`).
 */
function wmwp_detail_matches( array $resolved, array $feature, ?array $item ): bool {
	$props  = (array) $feature['properties'];
	$source = wmwp_detail_source( (string) $resolved['type'], $props );
	$known  = (string) ( $item['source'] ?? '' );
	if ( '' !== $source && '' !== $known ) {
		return $source === $known;
	}
	return false !== wmwp_detail_slug_matches( $resolved, $props, $known );
}

/**
 * Indirizzi del dettaglio di un elemento in ogni lingua dell'indice in cui c'è.
 *
 * @param list<string>|null $langs Lingue; null = wmwp_detail_languages().
 * @return array<string, string> lingua → URL
 */
function wmwp_detail_urls( string $type, int $id, ?array $langs = null ): array {
	$urls = [];
	foreach ( null === $langs ? wmwp_detail_languages() : $langs as $code ) {
		$url = wmwp_detail_url( $type, $id, (string) $code );
		if ( '' !== $url ) {
			$urls[ (string) $code ] = $url;
		}
	}
	return $urls;
}

/**
 * Dettaglio di un elemento risolto: il JSON e quello che serve al template. Null se l'elemento non c'è più (404 della
 * fonte) o l'id non porta più all'elemento chiesto, anche dopo una ricostruzione dell'indice: la pagina risponde 404,
 * mai con un altro elemento. `[ 'redirect' => URL ]` se dopo la ricostruzione l'elemento sta a un altro indirizzo
 * (rinomina, traduzione arrivata, cambio di base): la pagina risponde 301. WP_Error se la fonte non risponde (503).
 *
 * @param array<string, mixed> $resolved Risultato di wmwp_detail_resolve() con status 200, lang e path.
 * @return array<string, mixed>|WP_Error|null
 */
function wmwp_detail_load( array $resolved ) {
	$type    = (string) $resolved['type'];
	$id      = (int) $resolved['id'];
	$feature = wmwp_detail_fetch( $type, $id );
	if ( is_wp_error( $feature ) && 'wmwp_detail_not_found' !== $feature->get_error_code() ) {
		return $feature;
	}
	$item = wmwp_detail_index()['items'][ $type ][ $id ] ?? null;
	if ( ! is_wp_error( $feature ) && ! wmwp_detail_matches( $resolved, $feature, $item ) ) {
		// Prima il JSON fresco: in cache può esserci la versione di prima di una rinomina
		$feature = wmwp_detail_fetch( $type, $id, true );
		if ( is_wp_error( $feature ) && 'wmwp_detail_not_found' !== $feature->get_error_code() ) {
			return $feature;
		}
	}
	if ( is_wp_error( $feature ) || ! wmwp_detail_matches( $resolved, $feature, $item ) ) {
		// L'elemento non c'è più o l'id è di un altro (reset dello shard): l'indice è vecchio
		return wmwp_detail_load_again( $resolved );
	}
	// Elemento giusto per la chiave stabile, ma con un nome che non dà più lo slug chiesto (rinomina, traduzione): con
	// l'indice nuovo si manda all'indirizzo attuale
	$known = (string) ( $item['source'] ?? '' );
	if ( '' !== $known && false === wmwp_detail_slug_matches( $resolved, (array) $feature['properties'], $known )
		&& wmwp_detail_rebuild_allowed() && null !== wmwp_detail_index( true ) ) {
		$path = wmwp_detail_index()['urls'][ $type ][ $id ][ (string) $resolved['lang'] ] ?? '';
		if ( '' !== $path && $path !== $resolved['path'] ) {
			return [ 'redirect' => wmwp_detail_url( $type, $id, (string) $resolved['lang'] ) ];
		}
	}
	return wmwp_detail_build( $resolved, $feature );
}

/**
 * Secondo tentativo di wmwp_detail_load(), con l'indice ricostruito (se permesso): lo stesso percorso può portare a un
 * altro id (reset dello shard) o a un altro indirizzo (301). Null se non porta più a niente.
 *
 * @param array<string, mixed> $resolved
 * @return array<string, mixed>|WP_Error|null
 */
function wmwp_detail_load_again( array $resolved ) {
	if ( ! wmwp_detail_rebuild_allowed() || null === wmwp_detail_index( true ) ) {
		return null;
	}
	$again = wmwp_detail_resolve( (string) $resolved['lang'], (string) $resolved['path'] );
	if ( 301 === $again['status'] && ! empty( $again['location'] ) ) {
		return [ 'redirect' => (string) $again['location'] ];
	}
	if ( 200 !== $again['status'] ) {
		return null;
	}
	$again  += [
		'lang' => $resolved['lang'],
		'path' => $resolved['path'],
	];
	$feature = wmwp_detail_fetch( (string) $again['type'], (int) $again['id'], true );
	if ( is_wp_error( $feature ) ) {
		return 'wmwp_detail_not_found' === $feature->get_error_code() ? null : $feature;
	}
	$item = wmwp_detail_index()['items'][ $again['type'] ][ (int) $again['id'] ] ?? null;
	return wmwp_detail_matches( $again, $feature, $item ) ? wmwp_detail_build( $again, $feature ) : null;
}

/**
 * Il dettaglio per il template: elemento, lingua, nome (dal JSON, o dall'indice per il POI), indirizzi per lingua.
 *
 * @param array<string, mixed> $resolved
 * @param array<string, mixed> $feature
 * @return array<string, mixed>
 */
function wmwp_detail_build( array $resolved, array $feature ): array {
	$type  = (string) $resolved['type'];
	$id    = (int) $resolved['id'];
	$lang  = (string) $resolved['lang'];
	$index = wmwp_detail_index();
	$name  = wmwp_detail_text( $feature['properties']['name'] ?? null, $lang );
	if ( '' === $name ) {
		$name = wmwp_detail_text( $index['items'][ $type ][ $id ]['names'] ?? null, $lang );
	}
	$urls = wmwp_detail_urls( $type, $id, (array) ( $index['langs'] ?? [] ) );
	return [
		'type'    => $type,
		'id'      => $id,
		'lang'    => $lang,
		'path'    => (string) $resolved['path'],
		'name'    => $name,
		'url'     => $urls[ $lang ] ?? '',
		'urls'    => $urls,
		'feature' => $feature,
	];
}

/**
 * Dettaglio della richiesta in corso, null se non è una pagina di dettaglio. È la variabile `$detail` dei template.
 *
 * @return array<string, mixed>|null
 */
function wmwp_detail_current(): ?array {
	return isset( $GLOBALS['wmwp_detail'] ) && is_array( $GLOBALS['wmwp_detail'] ) ? $GLOBALS['wmwp_detail'] : null;
}

/**
 * Indirizzo della scheda PDF di un elemento sullo shard (oc:9018; stampa del wm-package, oc:8982):
 * `<shard>/print/ec-track/<id>` o `<shard>/print/ec-poi/<id>`, come dice `docs/resources/Print.md` del wm-package (il
 * JSON degli elementi non porta il link). Vuoto se la stampa dell'app è spenta (`OPTIONS.ec_pdf_enabled` del
 * config.json; la chiave deprecata `print_track_enable` non conta) o se la copia del config.json non c'è ancora: il
 * template allora non mostra il pulsante. `lang` va solo se l'app ha quella lingua, altrimenti lo shard risponderebbe
 * 404; senza, la scheda esce nella lingua di default dell'app. Non chiama lo shard.
 *
 * Il link apre una rotta che accoda la generazione del PDF: il template lo marca `rel="nofollow"`.
 *
 * @param string $type `track` o `poi`.
 * @param int    $id   Id dello shard.
 * @param string $lang Lingua della pagina, anche con la variante di WPML (`en-us`).
 */
function wmwp_detail_print_url( string $type, int $id, string $lang ): string {
	$routes = [
		'track' => 'ec-track',
		'poi'   => 'ec-poi',
	];
	if ( $id <= 0 || ! isset( $routes[ $type ] ) ) {
		return '';
	}
	$config = wmwp_app_config();
	$origin = wmwp_configured_api_urls()['origin'];
	if ( null === $config || '' === $origin || true !== ( $config['OPTIONS']['ec_pdf_enabled'] ?? null ) ) {
		return '';
	}
	$url       = $origin . '/print/' . $routes[ $type ] . '/' . $id;
	$code      = strtolower( (string) strtok( $lang, '-_' ) );
	$available = (array) ( $config['LANGUAGES']['available'] ?? [] );
	return in_array( $code, $available, true ) ? add_query_arg( 'lang', $code, $url ) : $url;
}

/** Vero se la richiesta è un dettaglio che lo shard non ha potuto dare (pagina 503). */
function wmwp_detail_unavailable(): bool {
	return ! empty( $GLOBALS['wmwp_detail_unavailable'] );
}

// Dopo la risoluzione (priorità 1): si legge il JSON e si decide 200, 404 o 503
add_action(
	'template_redirect',
	function () {
		global $wp_query;
		$resolved = $GLOBALS['wmwp_detail_resolved'] ?? null;
		if ( is_array( $resolved ) && 404 === (int) $resolved['status'] ) {
			// Già 404 per la rotta: niente da leggere
			return;
		}
		if ( is_array( $resolved ) && 200 !== (int) $resolved['status'] ) {
			$resolved = null;
		} elseif ( ! is_array( $resolved ) ) {
			if ( isset( $GLOBALS['wmwp_detail_resolved'] ) || '' === (string) get_query_var( 'wmwp_detail_path' ) ) {
				return;
			}
			// Nessun indice (shard mai raggiunto): la pagina non è disponibile, non inesistente
			$resolved = null;
		}
		$detail = null === $resolved ? new WP_Error( 'wmwp_detail_unavailable', '' ) : wmwp_detail_load( $resolved );
		if ( is_array( $detail ) && isset( $detail['redirect'] ) ) {
			wp_safe_redirect( (string) $detail['redirect'], 301 );
			exit;
		}
		if ( is_wp_error( $detail ) ) {
			$GLOBALS['wmwp_detail_unavailable'] = true;
			$wp_query->is_404                    = false;
			status_header( 503 );
			header( 'Retry-After: ' . WMWP_DETAIL_RETRY_AFTER );
			nocache_headers();
			return;
		}
		if ( null === $detail ) {
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		$GLOBALS['wmwp_detail'] = $detail;
		$wp_query->is_404       = false;
		$wp_query->is_home      = false;
		status_header( 200 );
		if ( wmwp_detail_served_stale() ) {
			// Pagina servita dalla copia in cache: la fonte non ha risposto (oc:8990). Come il 503, non va tenuta da una
			// cache di pagina o da una CDN oltre il guasto
			header( 'X-WMWP-Stale: 1' );
			nocache_headers();
		}
	},
	2
);

add_filter(
	'template_include',
	function ( string $template ): string {
		$detail = wmwp_detail_current();
		if ( $detail ) {
			return wmwp_locate_template( $detail['type'] . '.php' );
		}
		return wmwp_detail_unavailable() ? wmwp_locate_template( 'detail-unavailable.php' ) : $template;
	},
	99
);

add_filter(
	'body_class',
	function ( array $classes ): array {
		$detail = wmwp_detail_current();
		if ( $detail ) {
			$classes[] = 'wmwp-detail';
			$classes[] = 'wmwp-detail-' . $detail['type'];
		}
		return $classes;
	}
);

/**
 * Apertura della pagina di un template del plugin, su qualsiasi tema: `get_header()` per i temi classici; per i temi
 * a blocchi, che non hanno `header.php`, il documento e la parte `header` del tema.
 */
function wmwp_template_header(): void {
	if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
		get_header();
		return;
	}
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php
	block_template_part( 'header' );
}

/** Chiusura della pagina, speculare a wmwp_template_header(). */
function wmwp_template_footer(): void {
	if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
		get_footer();
		return;
	}
	block_template_part( 'footer' );
	?>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
}
