<?php
/*
Plugin Name: WP Package
Plugin URI: https://github.com/webmappsrl/wp-package
Description: Unified site search for Webmapp shards: tracks from the shard Elasticsearch API and sources added by the theme.
Version: 0.1.0
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

define( 'WMWP_VERSION', '0.1.0' );
define( 'WMWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WMWP_URL', plugin_dir_url( __FILE__ ) );

require_once WMWP_PATH . 'includes/i18n.php';
require_once WMWP_PATH . 'includes/settings.php';
require_once WMWP_PATH . 'includes/search.php';
require_once WMWP_PATH . 'includes/map.php';
