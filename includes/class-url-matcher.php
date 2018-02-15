<?php
namespace Mindsize\NewRelic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UrlMatcher {

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function get_context() {
		switch ( true ) {
			case defined( 'WP_CLI' ) && WP_CLI:
				return 'cli';
				break;
			case ( defined( 'DOING_CRON' ) && DOING_CRON ) || isset( $_GET['doing_wp_cron'] ):
				return 'cron';
				break;
			case $this->are_we_on_rest():
				return 'rest';
				break;
			case $this->are_we_on_ajax():
				return 'ajax';
				break;
			case $this->are_we_on_admin():
				return 'admin';
				break;
			default:
				return 'frontend';
		}
	}

	/**
	 * This has been copied off of wp-includes/canonical.php. It only works if we have HTTP_HOST and REQUEST_URI set on the $_SERVER global
	 * variable, which should be most of the times, except on cron and cli, but those are filtered out before.
	 *
	 * @return string the requested url
	 */
	private function get_url() {
		$requested_url  = is_ssl() ? 'https://' : 'http://';
		$requested_url .= $_SERVER['HTTP_HOST'];
		$requested_url .= $_SERVER['REQUEST_URI'];

		return $requested_url;
	}

	private function match( $what ) {
		return 0 === strpos( $this->get_url(), $what );
	}

	private function are_we_on_rest() {
		return $this->match( trailingslashit( get_home_url() ) . 'wp-json' );
	}

	private function are_we_on_ajax() {
		return $this->match( admin_url( 'admin-ajax.php' ) );
	}

	private function are_we_on_admin() {
		return $this->match( admin_url() );
	}
}
