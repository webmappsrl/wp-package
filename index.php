<?php
/*
Plugin Name: WP Package
Plugin URI: https://github.com/webmappsrl/wp-package
Description: Unified site search and detail pages for Webmapp shards: tracks and POIs from the shard APIs, sources added by the theme.
Version: 0.2.0
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

define( 'WMWP_VERSION', '0.2.0' );
define( 'WMWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WMWP_URL', plugin_dir_url( __FILE__ ) );

require_once WMWP_PATH . 'includes/i18n.php';
require_once WMWP_PATH . 'includes/settings.php';
require_once WMWP_PATH . 'includes/detail-index.php';
require_once WMWP_PATH . 'includes/detail-base.php';
require_once WMWP_PATH . 'includes/detail-route.php';
require_once WMWP_PATH . 'includes/detail-render.php';
require_once WMWP_PATH . 'includes/detail-seo.php';
require_once WMWP_PATH . 'includes/detail-wpml.php';
require_once WMWP_PATH . 'includes/icons.php';
require_once WMWP_PATH . 'includes/class-wmwp-detail-sitemap-provider.php';
require_once WMWP_PATH . 'includes/search.php';
require_once WMWP_PATH . 'includes/map.php';
