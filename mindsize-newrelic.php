<?php
namespace Mindsize\NewRelic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Name:          Mindsize Newrelic
 * Description:          Better WordPress data for New Relic, with WooCommerce support.
 * Author:               Mindsize
 * Author URI:           https://mindsize.me
 * Version:              1.3.1
 * Requires at least:    4.4
 * Tested up to:         4.8
 * WC requires at least: 2.6.0
 * WC tested up to:      3.2.0
 */

define( 'MINDSIZE_NR_VERSION', '1.3.1' );
define( 'MINDSIZE_NR_SLUG', 'mindsize-newrelic' );
define( 'MINDSIZE_NR_FILE', __FILE__ );
define( 'MINDSIZE_NR_DIR', plugin_dir_path( MINDSIZE_NR_FILE ) );
define( 'MINDSIZE_NR_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( MINDSIZE_NR_FILE ) ), basename( MINDSIZE_NR_FILE ) ) ) );

if( file_exists( MINDSIZE_NR_DIR . 'vendor/autoload_52.php' ) ) {
	require( MINDSIZE_NR_DIR . 'vendor/autoload_52.php' );
}

if( class_exists( __NAMESPACE__ .'\\Plugin_Factory' ) ) {

	function mindsize_newrelic() {
		return Plugin_Factory::create();
	}

	function init_plugin() {
		$plugin = mindsize_newrelic();

		$plugin->init();
	}

	add_action( 'plugins_loaded', __NAMESPACE__ . '\\init_plugin' );
}
