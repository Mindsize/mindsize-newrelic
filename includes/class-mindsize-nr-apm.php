<?php
namespace Mindsize\NewRelic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class APM
 *
 * Handles setting all the relevant info for current transaction
 */
class APM {
	private $plugin = null;
	private $config = [];

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function init() {
		add_action( 'pre_amp_render_post', array( $this, 'disable_nr_autorum' ), 9999, 1 );
		$this->config();
	}

	private function get_default_config() {
		return [
			'newrelic.appname' => $this->get_appname(),
			'newrelic.capture_params' => $this->plugin->helper->get_capture_url(),
		];
	}

	/**
	 * Setup the default config variables for our request.
	 */
	public function config() {
		$this->config = apply_filters( 'mindsize_nr_default_config', $this->get_default_config() );

		if ( ! $this->config ) {
			return;
		}

		if ( isset( $this->config['newrelic.appname'] ) && function_exists( 'newrelic_set_appname' ) ) {
			newrelic_set_appname( $this->config['newrelic.appname'] );
		}
		if ( isset( $this->config['newrelic.capture_params'] ) && function_exists( 'newrelic_capture_params' ) ) {
			newrelic_capture_params( $this->config['newrelic.capture_params'] );
		}

		do_action( 'mindsize_nr_setup_config', $this->config );
	}

	private function get_appname() {
		$context = $this->get_context();
		$home_url = parse_url( home_url() );

		$app_name = $home_url['host'] . ( isset( $home_url['path'] ) ? $home_url['path'] : '' ) . $context;

		return apply_filters( 'wp_nr_app_name', $app_name );

	}

	private function get_context() {
		return '';
		// is this an API?
		// is this CLI?
		// is this cron?
		// is this ajax?
		// is this admin?
		//
	}

	/**
	 * Disable New Relic autorum
	 *
	 * @param $post_id
	 */
	public function disable_nr_autorum( $post_id ) {
		if ( ! function_exists( 'newrelic_disable_autorum' ) ) {
			return;
		}

		if ( apply_filters( 'disable_post_autorum', true, $post_id ) ) {
			newrelic_disable_autorum();
		}
	}
}
