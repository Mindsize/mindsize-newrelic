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

	// contexts
	private $admin    = false;
	private $cron     = false;
	private $ajax     = false;
	private $cli      = false;
	private $rest     = false;
	private $frontend = false;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Let's start the plugin then!
	 */
	public function init() {
		$this->set_context();
		$this->config();
		$this->maybe_disable_autorum();
		$this->maybe_include_template();
		$this->set_user_attribute();

		do_action( 'mindsize_nr_apm_init', $this );
	}

	/**
	 * Sets up request context so we can separate the apps in the apm by name and set additional
	 * custom variables later.
	 */
	private function set_context() {
		if ( is_admin() ) {
			$this->admin = true;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$this->cron = true;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$this->ajax = true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$this->rest = true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->cli = true;
		}

		$this->frontend = ! ( $this->admin && $this->cron && $this->ajax && $this->rest && $this->cli );
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

	/**
	 * Ajax and Cron requests should not have the Browser extension
	 */
	private function maybe_disable_autorum() {
		if ( $this->ajax || $this->cron ) {
			disable_nr_autorum();
		} else {
			add_action( 'pre_amp_render_post', array( $this, 'disable_nr_autorum' ), 9999, 1 );
		}
	}

	/**
	 * Only include the template on the frontend
	 */
	private function maybe_include_template() {
		if ( ! $this->frontend ) {
			return;
		}

		add_filter( 'template_include', array( $this, 'set_template' ), 9999 );
	}

	/**
	 * Set the user on new relic. The three arguments are:
	 * - user, repurposed as the ID
	 * - account, nothing
	 * - product, repurposed as the role
	 *
	 * @see  https://docs.newrelic.com/docs/agents/php-agent/php-agent-api/newrelic_set_user_attributes
	 */
	private function set_user_attribute() {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			newrelic_set_user_attributes( $user->ID, '', array_shift( $user->roles ) );
		} else {
			newrelic_set_user_attributes( 'not-logged-in', '', 'no-role' );
		}
	}

	/**
	 * Return default configuration that can be filtered.
	 *
	 * @return array    default configuration values
	 */
	private function get_default_config() {
		return [
			'newrelic.appname' => $this->get_appname(),
			'newrelic.capture_params' => $this->plugin->helper->get_capture_url(),
		];
	}

	/**
	 * Returns the app name to be used by logging. By default it will be the host as set in the home_url,
	 * appended by the context, if it's not the front page.
	 *
	 * Examples:
	 * example.com CLI
	 * example.com REST
	 * example.com CRON
	 * example.com AJAX
	 * example.com Admin
	 * example.com
	 *
	 * @return string            name to be used as the app name
	 */
	private function get_appname() {
		$context = $this->get_context();
		$home_url = parse_url( home_url() );

		$app_name = $home_url['host'] . ( isset( $home_url['path'] ) ? $home_url['path'] : '' ) . ' ' . $context;

		return apply_filters( 'mindsize_nr_app_name', $app_name );
	}

	/**
	 * Based on the context, return a string that gets appended to the standard app name. If
	 * none of those match, because we're on frontend, then an empty string is returned.
	 *
	 * Only one of them can be returned. In order of importance:
	 * - cli
	 * - rest
	 * - cron
	 * - ajax
	 * - admin
	 *
	 * @return string              name of the context based on the context
	 */
	private function get_context() {
		switch ( true ) {
			case $this->cli;
				return 'CLI';
				break;
			case $this->rest;
				return 'REST';
				break;
			case $this->cron:
				return 'CRON';
				break;
			case $this->ajax;
				return 'AJAX';
				break;
			case $this->admin;
				return 'Admin';
				break;
			default:
				return '';
				break;
		}
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

	/**
	 * Set template custom parameter in current transaction
	 *
	 * @param $template
	 *
	 * @return mixed
	 */
	public function set_template( $template ) {
		if ( function_exists( 'newrelic_add_custom_parameter' ) ) {
			newrelic_add_custom_parameter( 'template', $template );
		}

		return $template;
	}
}
