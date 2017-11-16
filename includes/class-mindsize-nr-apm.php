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

		add_action( 'init', array( $this, 'set_custom_variables' ) );
		add_action( 'parse_query', array( $this, 'set_transaction' ), 10 );
		add_action( 'wp', array( $this, 'set_post_id' ), 10 );
		add_action( 'wp_async_task_before_job', array( $this, 'async_before_job_track_time' ), 9999, 1 );
		add_action( 'wp_async_task_after_job', array( $this, 'async_after_job_set_attribute' ), 9999, 1 );

		do_action( 'mindsize_nr_apm_init', $this );

		// if woocommerce is present
		if ( function_exists( 'wc' ) ) {
			add_filter( 'mindsize_nr_transaction_name', array( $this, 'woocommerce_transaction_names' ), 10, 2 );
		}
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
			$this->disable_nr_autorum();
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

		return apply_filters( 'mindsize_nr_app_name', $app_name, $context );
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
	public function disable_nr_autorum( $post_id = null ) {
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

	/**
	 * Custom variables set up automatically for all transactions:
	 * - user id | 'not-logged-in'
	 * - theme used
	 */
	public function set_custom_variables() {
		/**
		 * Set the user
		 */
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			newrelic_set_user_attributes( $user->ID, '', array_shift( $user->roles ) );
		} else {
			newrelic_set_user_attributes( 'not-logged-in', '', 'no-role' );
		}

		/**
		 * Set the theme used
		 */
		$theme = wp_get_theme();
		newrelic_add_custom_parameter( 'Theme Name', $theme->get( 'Name' ) );
		newrelic_add_custom_parameter( 'Theme files', $theme->get_stylesheet() );
	}

	/**
	 * Set current transaction name as per the main WP_Query
	 *
	 * @param $query
	 */
	public function set_transaction( $query ) {

		if ( ! function_exists( 'newrelic_name_transaction' ) ) {
			return;
		}

		// set transaction
		$transaction = apply_filters( 'mindsize_nr_transaction_name', false, $query, $this );

		if ( false === $transaction && $query->is_main_query() ) {
			if ( is_front_page() && is_home() ) {
				$transaction = 'Default Home Page';
			} elseif ( is_front_page() ) {
				$transaction = 'Front Page';
			} elseif ( is_home() ) {
				$transaction = 'Blog Page';
			} elseif ( is_network_admin() ) {
				$transaction = 'Network Dashboard';
			} elseif ( is_admin() ) {
				$transaction = 'Dashboard';
			} elseif ( is_single() ) {
				$post_type = ( ! empty( $query->query['post_type'] ) ) ? $query->query['post_type'] : 'Post';
				$transaction = "Single - {$post_type}";
			} elseif ( is_page() ) {
				if ( isset( $query->query['pagename'] ) ) {
					$this->add_custom_parameter( 'page', $query->query['pagename'] );
				}
				$transaction = "Page";
			} elseif ( is_date() ) {
				$transaction = 'Date Archive';
			} elseif ( is_search() ) {
				if ( isset( $query->query['s'] ) ) {
					$this->add_custom_parameter( 'search', $query->query['s'] );
				}
				$transaction = 'Search Page';
			} elseif ( is_feed() ) {
				$transaction = 'Feed';
			} elseif ( is_post_type_archive() ) {
				$post_type = post_type_archive_title( '', false );
				$transaction = "Archive - {$post_type}";
			} elseif ( is_category() ) {
				if ( isset( $query->query['category_name'] ) ) {
					$this->add_custom_parameter( 'cat_slug', $query->query['category_name'] );
				}
				$transaction = "Category";
			} elseif ( is_tag() ) {
				if ( isset( $query->query['tag'] ) ) {
					$this->add_custom_parameter( 'tag_slug', $query->query['tag'] );
				}
				$transaction = "Tag";
			} elseif ( is_tax() ) {
				$tax    = key( $query->tax_query->queried_terms );
				$term   = implode( ' | ', $query->tax_query->queried_terms[ $tax ]['terms'] );
				$this->add_custom_parameter( 'term_slug', $term );
				$transaction = "Tax - {$tax}";
			}
		}

		if ( ! empty( $transaction ) ) {
			newrelic_name_transaction( apply_filters( 'wp_nr_transaction_name', $transaction ) );
		}
	}

	/**
	 * Set post_id custom parameter if it's single post
	 *
	 * @param $wp
	 */
	public function set_post_id( $wp ) {
		if ( is_single() && function_exists( 'newrelic_add_custom_parameter' ) ) {
			newrelic_add_custom_parameter( 'post_id', apply_filters( 'mindsize_nr_post_id', get_the_ID() ) );
		}
	}

	/**
	 * Adds a custom parameter through `newrelic_add_custom_parameter`
	 * Prefixes the $key with 'msnr_' to avoid collisions with NRQL reserved words
	 *
	 * @see https://docs.newrelic.com/docs/agents/php-agent/configuration/php-agent-api#api-custom-param
	 *
	 * @param $key      string  Custom parameter key
	 * @param $value    string  Custom parameter value
	 * @return bool
	 */
	public function add_custom_parameter( $key, $value ) {
		if ( function_exists( 'newrelic_add_custom_parameter' ) ) {
			//prefixing with msnr_ to avoid collisions with reserved works in NRQL
			$key = 'msnr_' . $key;
			return newrelic_add_custom_parameter( $key, apply_filters( 'mindsize_nr_add_custom_parameter', $value, $key ) );
		}

		return false;
	}

	/**
	 * Track time before starting async job
	 *
	 * @param $hook
	 */
	public function async_before_job_track_time( $hook ) {
		if ( false === $this->async_tasks ) {
			$this->async_tasks = array();
		}

		$this->async_tasks[ $hook ] = array(
			'start_time' => microtime( true ),
		);
	}

	/**
	 * Set time taken for async task into custom parameter
	 *
	 * @param $hook
	 */
	public function async_after_job_set_attribute( $hook ) {
		if ( is_array( $this->async_tasks ) && ! empty( $this->async_tasks[ $hook ] ) ) {
			$this->async_tasks[ $hook ]['end_time'] = microtime( true );

			$time_diff = $this->async_tasks[ $hook ]['start_time'] - $this->async_tasks[ $hook ]['end_time'];

			if ( function_exists( 'newrelic_add_custom_parameter' ) ) {
				newrelic_add_custom_parameter( 'wp_async_task-' . $hook, $time_diff );
			}
		}
	}

	/**
	 * Method that hooks into the {@see $this->set_transaction} method to overwrite the transaction name and
	 * maybe set a custom parameter in case of the shop.
	 *
	 * @param [type] $transaction
	 * @return void
	 */
	public function woocommerce_transaction_names( $transaction, $query ) {

		if ( false !== $transaction ) {
			return $transaction;
		}

		if ( is_cart() ) {
			$transaction = 'Cart';
		} elseif ( is_checkout_pay_page() ) {
			$transaction = 'Checkout - Pay Page';
		} elseif ( is_checkout() ) {
			$transaction = 'Checkout';
		} elseif ( is_shop() ) {
			$transaction = 'Shop';
			$page = ( isset( $query->query['page'] ) ) ? $query->query['page'] : false;

			if ( $page ) {
				$this->add_custom_parameter( 'page', $page );
			}
		} elseif ( is_account_page() ) {
			$transaction = 'My Account page';

			if ( is_wc_endpoint_url() ) {
				$wc_endpoints = WC()->query->get_query_vars();

				foreach ( $wc_endpoints as $key => $value ) {
					if ( isset( $query->query_vars[ $key ] ) ) {
						$this->add_custom_parameter( 'wc_endpoint', $query->query_vars[ $key ] );
						break;
					}
				}
			}
		}

		return $transaction;
	}
}
