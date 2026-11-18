<?php
/*
Plugin Name: WP Package
Plugin URI: https://github.com/webmappsrl/wp-package
Description: Unified site search and detail pages for Webmapp shards: tracks and POIs from the shard APIs, sources added by the theme.
Version: 0.5.0
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

define( 'WMWP_VERSION', '0.5.0' );
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
require_once WMWP_PATH . 'includes/status.php';
require_once WMWP_PATH . 'includes/detail-status.php';
require_once WMWP_PATH . 'includes/detail-seo.php';
require_once WMWP_PATH . 'includes/detail-wpml.php';
require_once WMWP_PATH . 'includes/icons.php';
require_once WMWP_PATH . 'includes/class-wmwp-detail-sitemap-provider.php';
require_once WMWP_PATH . 'includes/search.php';
require_once WMWP_PATH . 'includes/search-pois.php';
require_once WMWP_PATH . 'includes/map.php';

// Comando dello storico dei percorsi dei dettagli: `wp wmwp detail-paths list|forget` (oc:8983)
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WMWP_PATH . 'includes/class-wmwp-detail-paths-command.php';
	WP_CLI::add_command( 'wmwp detail-paths', 'WMWP_Detail_Paths_Command' );
}

// Alla disattivazione il plugin toglie il suo evento cron (ricostruzione dell'indice dei dettagli)
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( WMWP_DETAIL_CRON_HOOK );
		// La copia dei POI per la ricerca si rifà al primo rinnovo dell'indice (oc:9000)
		delete_option( WMWP_POI_SEARCH_OPTION );
	}
);
