<?php
/*
Plugin Name: WP Package
Plugin URI: https://github.com/webmappsrl/wp-package
Description: Unified site search and detail pages for Webmapp shards: tracks and POIs from the shard APIs, sources added by the theme.
Version: 0.7.0
Author: Webmapp
Author URI: https://webmapp.it
License: GPL2
Text Domain: wp-package
Domain Path: /languages
Requires at least: 6.3
Requires PHP: 7.4
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMWP_VERSION', '0.7.0' );
define( 'WMWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WMWP_URL', plugin_dir_url( __FILE__ ) );

require_once WMWP_PATH . 'includes/i18n.php';
require_once WMWP_PATH . 'includes/settings.php';
require_once WMWP_PATH . 'includes/http.php';
require_once WMWP_PATH . 'includes/detail-history.php';
require_once WMWP_PATH . 'includes/detail-index.php';
require_once WMWP_PATH . 'includes/detail-base.php';
require_once WMWP_PATH . 'includes/detail-route.php';
require_once WMWP_PATH . 'includes/detail-render.php';
require_once WMWP_PATH . 'includes/detail-data.php';
require_once WMWP_PATH . 'includes/app-config.php';
require_once WMWP_PATH . 'includes/app-filters.php';
require_once WMWP_PATH . 'includes/app-overlays.php';
require_once WMWP_PATH . 'includes/status.php';
require_once WMWP_PATH . 'includes/detail-status.php';
require_once WMWP_PATH . 'includes/detail-seo.php';
require_once WMWP_PATH . 'includes/detail-wpml.php';
require_once WMWP_PATH . 'includes/icons.php';
require_once WMWP_PATH . 'includes/class-wmwp-detail-sitemap-provider.php';
require_once WMWP_PATH . 'includes/search.php';
require_once WMWP_PATH . 'includes/search-pois.php';
require_once WMWP_PATH . 'includes/tracks-copy.php';
require_once WMWP_PATH . 'includes/grid.php';
require_once WMWP_PATH . 'includes/map.php';

// Comando dello storico dei percorsi dei dettagli: `wp wmwp detail-paths list|forget` (oc:8983)
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WMWP_PATH . 'includes/class-wmwp-detail-paths-command.php';
	WP_CLI::add_command( 'wmwp detail-paths', 'WMWP_Detail_Paths_Command' );
	// Copia delle tracce delle griglie, per le prove: `wp wmwp tracks-copy load|refresh` (oc:9003)
	require_once WMWP_PATH . 'includes/class-wmwp-tracks-copy-command.php';
	WP_CLI::add_command( 'wmwp tracks-copy', 'WMWP_Tracks_Copy_Command' );
}

// Alla disattivazione il plugin toglie i suoi eventi cron (indice dei dettagli, config.json dell'app)
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
		wp_clear_scheduled_hook( WMWP_APP_CONFIG_CRON_HOOK );
		// La copia dei POI per la ricerca, e le aree dei POI che ne derivano, si rifanno al primo rinnovo dell'indice
		// (oc:9000, oc:9018)
		delete_option( WMWP_POI_SEARCH_OPTION );
		delete_option( WMWP_TRACKS_COPY_OPTION );
		delete_option( WMWP_POI_LAYERS_OPTION );
	}
);
