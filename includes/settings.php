<?php
/**
 * Impostazioni del plugin: shard e app id, e gli URL delle API che ne derivano.
 *
 * Gli shard (nome, origin, URL di Elasticsearch) si leggono da `environment.ts` di wm-types, come nel
 * plugin di partenza (wp-geohub, `admin_page.php`). Gli URL non si salvano: si calcolano a ogni richiesta,
 * così un cambio di shard o di app id vale subito e non restano valori vecchi nelle opzioni.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WMWP_SHARDS_CONFIG_URL = 'https://raw.githubusercontent.com/webmappsrl/wm-types/refs/heads/main/src/environment.ts';
const WMWP_SHARDS_CACHE_KEY  = 'wmwp_shards_config';
// Ultima configurazione letta con successo, senza scadenza: serve se GitHub non risponde e la cache è scaduta.
const WMWP_SHARDS_LAST_OPTION = 'wmwp_shards_config_last';
const WMWP_OPTION_SHARD       = 'wmwp_shard';
const WMWP_OPTION_APP_ID      = 'wmwp_app_id';

/**
 * Shard dichiarati in `environment.ts`: nome => [ origin, elasticApi, awsApi ].
 *
 * @return array<string, array{origin: string, elasticApi: string, awsApi: string}>
 */
function wmwp_parse_shards( string $content ): array {
	$shards  = [];
	$pattern = "/(\\w+):\\s*\\{\\s*origin:\\s*['\"]([^'\"]+)['\"],\\s*elasticApi:\\s*['\"]([^'\"]+)['\"],\\s*graphhopperHost:\\s*['\"][^'\"]+['\"],\\s*awsApi:\\s*['\"]([^'\"]+)['\"]/";
	if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$shards[ $match[1] ] = [
				'origin'     => $match[2],
				'elasticApi' => $match[3],
				'awsApi'     => $match[4],
			];
		}
	}
	return $shards;
}

/**
 * Configurazione degli shard: dalla cache di un giorno, poi da wm-types, poi dall'ultima lettura riuscita.
 *
 * @return array<string, array{origin: string, elasticApi: string, awsApi: string}>
 */
function wmwp_get_shards_config( bool $force_refresh = false ): array {
	if ( ! $force_refresh ) {
		$cached = get_transient( WMWP_SHARDS_CACHE_KEY );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
	}

	$response = wp_remote_get( WMWP_SHARDS_CONFIG_URL, [ 'timeout' => 5 ] );
	$shards   = [];
	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$shards = wmwp_parse_shards( (string) wp_remote_retrieve_body( $response ) );
	}

	if ( $shards ) {
		set_transient( WMWP_SHARDS_CACHE_KEY, $shards, DAY_IN_SECONDS );
		update_option( WMWP_SHARDS_LAST_OPTION, $shards, false );
		return $shards;
	}

	// wm-types non risponde: si usa l'ultima lettura riuscita e la si tiene in cache per un'ora, così ogni
	// pagina non aspetta di nuovo il timeout finché GitHub non torna
	error_log( 'WP Package: configurazione degli shard non disponibile da ' . WMWP_SHARDS_CONFIG_URL );
	$last = get_option( WMWP_SHARDS_LAST_OPTION );
	$last = is_array( $last ) ? $last : [];
	set_transient( WMWP_SHARDS_CACHE_KEY, $last, HOUR_IN_SECONDS );
	return $last;
}

/**
 * URL delle API di uno shard per un'app. Una chiave vuota se lo shard non è fra quelli dichiarati.
 *
 * `origin` è l'indirizzo dello shard, base delle API `api/ec/…` (es. `api/ec/track/multiple`).
 *
 * @return array{elastic_api: string, origin: string}
 */
function wmwp_get_api_urls( string $shard, int $app_id ): array {
	$shards = wmwp_get_shards_config();
	if ( '' === $shard || $app_id <= 0 || ! isset( $shards[ $shard ] ) ) {
		return [
			'elastic_api' => '',
			'origin'      => '',
			'aws_api'     => '',
		];
	}
	return [
		'elastic_api' => untrailingslashit( $shards[ $shard ]['elasticApi'] ),
		'origin'      => untrailingslashit( $shards[ $shard ]['origin'] ),
		'aws_api'     => untrailingslashit( $shards[ $shard ]['awsApi'] ),
	];
}

/**
 * URL delle API dello shard configurato. Il filtro `wmwp_api_urls` li cambia (es. uno shard in locale che non sta in
 * `environment.ts`): riceve e restituisce `[ 'elastic_api' => …, 'origin' => …, 'aws_api' => … ]`. `aws_api` (l'`awsApi` di
 * `environment.ts`, dove lo shard pubblica i file delle tracce) lo legge solo il server, per il dettaglio della traccia.
 */
function wmwp_configured_api_urls(): array {
	$urls = wmwp_get_api_urls( (string) get_option( WMWP_OPTION_SHARD, '' ), (int) get_option( WMWP_OPTION_APP_ID, 0 ) );
	$urls = apply_filters( 'wmwp_api_urls', $urls );
	return [
		'elastic_api' => untrailingslashit( (string) ( $urls['elastic_api'] ?? '' ) ),
		'origin'      => untrailingslashit( (string) ( $urls['origin'] ?? '' ) ),
		'aws_api'     => untrailingslashit( (string) ( $urls['aws_api'] ?? '' ) ),
	];
}

/**
 * Origin dello shard per le richieste fatte dal server WordPress (oc:8969). Di solito è lo stesso del browser; il
 * filtro `wmwp_server_origin` serve quando il server lo raggiunge da un altro indirizzo (es. WordPress in Docker e
 * shard sull'host: `http://host.docker.internal:8000` invece di `http://localhost:8000`).
 */
function wmwp_server_origin(): string {
	return untrailingslashit( (string) apply_filters( 'wmwp_server_origin', wmwp_configured_api_urls()['origin'] ) );
}

/** URL dell'API Elasticsearch dello shard configurato, '' se shard o app id mancano. */
function wmwp_elastic_url(): string {
	return wmwp_configured_api_urls()['elastic_api'];
}

/** URL dell'API che restituisce più tracce in GeoJSON (al massimo 10 per richiesta), '' se shard o app id mancano. */
function wmwp_tracks_download_url(): string {
	$origin = wmwp_configured_api_urls()['origin'];
	return '' !== $origin ? $origin . '/api/ec/track/multiple' : '';
}

/** Valore del parametro `app` dell'API Elasticsearch: «<shard>_<app id>» (es. `forestasdev_1`). */
function wmwp_app_param(): string {
	$shard  = (string) get_option( WMWP_OPTION_SHARD, '' );
	$app_id = (int) get_option( WMWP_OPTION_APP_ID, 0 );
	return ( '' !== $shard && $app_id > 0 ) ? "{$shard}_{$app_id}" : '';
}

add_action(
	'admin_init',
	function () {
		register_setting( 'wmwp_settings', WMWP_OPTION_SHARD, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ] );
		register_setting( 'wmwp_settings', WMWP_OPTION_APP_ID, [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
		// Base degli indirizzi dei dettagli (oc:8969): per tracce e POI, dal tipo o da una pagina
		foreach ( WMWP_DETAIL_TYPES as $type ) {
			register_setting( 'wmwp_settings', wmwp_detail_use_page_option( $type ), [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ] );
			register_setting( 'wmwp_settings', wmwp_detail_page_option( $type ), [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
		}
	}
);

add_action(
	'admin_menu',
	function () {
		add_options_page( 'WP Package', 'WP Package', 'manage_options', 'wp-package', 'wmwp_render_settings_page' );
	}
);

/** Pagina delle impostazioni: shard, app id e URL di Elasticsearch calcolato (sola lettura). */
function wmwp_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$shards  = wmwp_get_shards_config();
	$current = (string) get_option( WMWP_OPTION_SHARD, '' );
	$app_id  = (int) get_option( WMWP_OPTION_APP_ID, 0 );
	$elastic = wmwp_elastic_url();
	ksort( $shards );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'WP Package', 'wp-package' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wmwp_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wmwp_shard"><?php echo esc_html__( 'Shard', 'wp-package' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( WMWP_OPTION_SHARD ); ?>" id="wmwp_shard">
							<option value=""><?php echo esc_html__( '— Select —', 'wp-package' ); ?></option>
							<?php foreach ( array_keys( $shards ) as $name ) : ?>
								<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $current, $name ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( ! $shards ) : ?>
							<p class="description"><?php echo esc_html__( 'The shard list could not be loaded from wm-types.', 'wp-package' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wmwp_app_id"><?php echo esc_html__( 'App ID', 'wp-package' ); ?></label></th>
					<td><input type="number" min="1" class="small-text" name="<?php echo esc_attr( WMWP_OPTION_APP_ID ); ?>" id="wmwp_app_id" value="<?php echo esc_attr( $app_id ?: '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Elasticsearch API', 'wp-package' ); ?></th>
					<td><code><?php echo $elastic ? esc_html( $elastic ) : esc_html__( 'not configured', 'wp-package' ); ?></code></td>
				</tr>
			</table>
			<h2><?php echo esc_html__( 'Detail pages', 'wp-package' ); ?></h2>
			<p><?php echo esc_html__( 'By default the address of a detail page starts with its type: the route type of a track (e.g. /trail/…, /track/ without one) and the POI type of a point of interest (/poi/ without one). Under a page, every address starts with the path of that page.', 'wp-package' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$labels = [
					'track' => __( 'Tracks', 'wp-package' ),
					'poi'   => __( 'Points of interest', 'wp-package' ),
				];
				foreach ( WMWP_DETAIL_TYPES as $type ) :
					$use_option  = wmwp_detail_use_page_option( $type );
					$page_option = wmwp_detail_page_option( $type );
					$use_page    = (bool) get_option( $use_option, false );
					$forced      = has_filter( 'pre_option_' . $use_option ) || has_filter( 'pre_option_' . $page_option );
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $labels[ $type ] ); ?></th>
						<td>
							<fieldset>
								<label for="<?php echo esc_attr( $use_option ); ?>">
									<input type="checkbox" name="<?php echo esc_attr( $use_option ); ?>" id="<?php echo esc_attr( $use_option ); ?>" value="1" <?php checked( $use_page ); ?> <?php disabled( $forced ); ?>>
									<?php echo esc_html__( 'Address under a page', 'wp-package' ); ?>
								</label>
								<p>
									<label class="screen-reader-text" for="<?php echo esc_attr( $page_option ); ?>"><?php echo esc_html__( 'Page', 'wp-package' ); ?></label>
									<?php
									wp_dropdown_pages(
										[
											'name'              => esc_attr( $page_option ),
											'id'                => esc_attr( $page_option ),
											'selected'          => (int) get_option( $page_option, 0 ),
											'show_option_none'  => esc_html__( '— Select —', 'wp-package' ),
											'option_none_value' => '0',
										]
									);
									?>
								</p>
								<?php if ( $forced ) : ?>
									<p class="description"><?php echo esc_html__( 'Set by the theme: changes here have no effect.', 'wp-package' ); ?></p>
								<?php endif; ?>
							</fieldset>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
