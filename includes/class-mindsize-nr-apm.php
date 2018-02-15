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
		// wp_die( es_preit( array( __FILE__ . ':' . __LINE__, 'slmfsmdfml' ), true ) );
		$this->set_context();

		// $this->maybe_disable_autorum();
		// $this->maybe_include_template();

		// add_action( 'init', array( $this, 'set_custom_variables' ) );

		// add_action( 'wp', array( $this, 'set_post_id' ), 10 );
		// add_action( 'wp_async_task_before_job', array( $this, 'async_before_job_track_time' ), 9999, 1 );
		// add_action( 'wp_async_task_after_job', array( $this, 'async_after_job_set_attribute' ), 9999, 1 );

		// do_action( 'mindsize_nr_apm_init', $this );

		// // if woocommerce is present
		// if ( function_exists( 'wc' ) ) {
		// 	add_filter( 'mindsize_nr_pq_transaction_name', array( $this, 'woocommerce_pq_transaction_names' ), 10, 2 );
		// 	add_filter( 'mindsize_nr_ajax_transaction_name', array( $this, 'woocommerce_ajax_transaction_names' ) );
		// }
	}

	private function set_context() {
		$context = $this->plugin->helper->urlmatcher->get_context();

		switch ( $context ) {
			case 'cli':
				$this->cli = true;
				break;
			case 'cron':
				$this->cron = true;
				break;
			case 'rest':
				$this->rest = true;
				break;
			case 'ajax':
				$this->ajax = true;
				break;
			case 'admin':
				$this->admin = true;
				break;
			case 'frontend':
				$this->frontend = true;
		}
	}

	/**
	 * This is practically a copy of the default is_admin() function in WordPress core (as of 4.9.1).
	 *
	 * Reason we need this is because is_admin returns true for a bunch of other requests as well that
	 * are technically not the admin. Those are:
	 * - load-scripts.php
	 * - load-styles.php
	 * - ajax requests
	 *
	 * {@see https://codex.wordpress.org/Function_Reference/is_admin}
	 *
	 * This will STILL be true for ajax requests, but the load-scripts and load-styles should no longer
	 * be counted as Admin requests.
	 *
	 * @return boolean whether we're actually on the
	 */
	private function is_admin() {
		if ( isset( $GLOBALS['current_screen'] ) ) {
			return $GLOBALS['current_screen']->in_admin();
		} elseif ( defined( 'WP_ADMIN' ) ) {
			return WP_ADMIN;
		}

		return false;
	}

	/**
	 * Sets up request context so we can separate the apps in the apm by name and set additional
	 * custom variables later.
	 */
	private function maybe_set_context() {
		/**
		 * Admin, CLI, CRON are available at plugins_loaded.
		 */
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->cli = true;
			$this->config();
			$this->set_cli_transaction();
			return;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$this->cron = true;
			$this->config();
			$this->set_cron_transaction();
			return;
		}

		/**
		 * Admin is less important than AJAX, so we'll set it here (because it's available),
		 * but we're not going to config or break because AJAX is only available later.
		 */
		if ( $this->is_admin() ) {
			$this->admin = true;
		}

		/**
		 * If we've gotten here, we're not in CRON, CLI, so let's schedule the next ones:
		 * - wp_default_styles: check for AJAX, Admin, or Front end
		 * - rest_api_init: check for REST
		 *
		 * Why can't we use wp for AJAX check? Because wp doesn't run for AJAX
		 * requests...
		 *
		 * And then if any of those fire, unhook the ones following it
		 */
		add_action( 'wp_default_styles', array( $this, 'maybe_set_context_to_ajax' ) );
		add_action( 'rest_api_init', array( $this, 'maybe_set_context_to_rest' ) );
		add_action( 'wp', array( $this, 'maybe_set_context_to_fe' ) );
	}

	/**
	 * Hooked into {@see wp_default_styles}, this will determine whether we're in an AJAX context.
	 * If we are, we're unhooking the REST check.
	 *
	 * @return void
	 */
	public function maybe_set_context_to_ajax() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$this->ajax = true;
			$this->set_ajax_transaction();

			/**
			 * Because we've determined we're in AJAX context, we're gonna set the app to AJAX, and then
			 * remove the check for REST and FE.
			 */
			remove_action( 'rest_api_init', array( $this, 'maybe_set_context_to_rest' ) );
			remove_action( 'wp', array( $this, 'maybe_set_context_to_fe' ) );
		}
		/**
		 * Setting it here will cause both front end AND REST be set to true, but since we're getting our
		 * context in an order where REST comes first, this is acceptable.
		 */
		$this->frontend = ! ( $this->admin || $this->cron || $this->ajax || $this->cli );

		/**
		 * The reason I have this here, in wp_default_styles, is because this is the first time where I can
		 * semi-reliably tell what's going on. If we're not in AJAX, we're either Admin or Front end at
		 * this point. We're not unhooking the rest_api_init, because that comes later, so we *could* be in
		 * rest context as well.
		 *
		 * The downside of this is that the app name will be set twice. The upside is that there's literally
		 * no other way to achieve this.
		 */
		$this->config();
	}

	/**
	 * Hooked into {@see rest_api_init} action, this will test whether we're in a REST or front end
	 * context.
	 *
	 * Hilarity: REST will go through wp IF and only IF it's not the index, or not a
	 * notfound route.
	 *
	 * @return void
	 */
	public function maybe_set_context_to_rest() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$this->rest = true;
			$this->config();
			$this->set_rest_transaction();

			/**
			 * We're in REST territory, let's remove the FE check
			 */
			remove_action( 'wp', array( $this, 'maybe_set_context_to_fe' ) );
		}
	}

	/**
	 * Hooked into {@see wp} action, this will test whether we're in a REST or front end
	 * context.
	 *
	 * @return void
	 */
	public function maybe_set_context_to_fe() {
		/**
		 * Since this is the last point the only possibility here is that we're in admin
		 * - we're in Front end, which means everything else is false
		 *
		 * Either way, we need to call config here.
		 */
		$this->set_fe_transaction();
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

		// Watch out, because this will be ran twice for REST routes!
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
	 * - frontend
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
			case $this->frontend;
				return 'Frontend';
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
			newrelic_set_user_attributes( $user->ID, '', implode( ', ', $user->roles ) );
		} else {
			newrelic_set_user_attributes( 'not-logged-in', '', 'no-role' );
		}

		/**
		 * Set the theme used
		 */
		$theme = wp_get_theme();
		newrelic_add_custom_parameter( 'Theme Name', $theme->get( 'Name' ) );
		newrelic_add_custom_parameter( 'Theme Files', $theme->get_stylesheet() );
	}

	/**
	 * Called from {@see maybe_set_context_to_cli}
	 *
	 * @return void
	 */
	private function set_cli_transaction() {
		if ( ! function_exists( 'newrelic_name_transaction' ) ) {
			return;
		}

		$transaction = apply_filters( 'mindsize_nr_cli_transaction_name', false, $this );

		if ( false === $transaction ) {
			$transaction = sprintf( 'wp %s', implode( ' ', \WP_CLI::get_runner()->arguments ) );
		}

		$assoc_args = \WP_CLI::get_runner()->assoc_args;

		if ( ! empty( $assoc_args ) ) {
			$assoc = [];
			foreach( $assoc_args as $arg => $value ) {
				$assoc[] = sprintf( '--%s=%s', $arg, $value );
			}

			newrelic_add_custom_parameter( 'assoc_args', implode( ' ', $assoc ) );
		}

		newrelic_name_transaction( apply_filters( 'wp_nr_cli_transaction_name', $transaction ) );
	}

	private function set_cron_transaction() {
		// Currently there's no way to reliably figure out which cron schedule we're running
	}

	/**
	 * Called from {@see maybe_set_context_to_ajax}.
	 *
	 * @return string
	 */
	private function set_ajax_transaction() {
		if ( ! function_exists( 'newrelic_name_transaction' ) ) {
			return;
		}

		$transaction = apply_filters( 'mindsize_nr_ajax_transaction_name', false, $this );

		if ( false === $transaction ) {
			$transaction = array_key_exists( 'action', $_REQUEST ) ? $_REQUEST['action'] : 'generic ajax request';
		}

		if ( ! empty( $transaction ) ) {
			newrelic_name_transaction( apply_filters( 'wp_nr_ajax_transaction_name', $transaction ) );
		}
	}

	/**
	 * Called from {@see maybe_set_context_to_rest}.
	 *
	 * @return void
	 */
	private function set_rest_transaction() {
		if ( ! function_exists( 'newrelic_name_transaction' ) ) {
			return;
		}
		global $wp;

		$transaction = apply_filters( 'mindsize_nr_rest_transaction_name', false, $this );

		if ( false === $transaction ) {
			if ( empty( $wp->query_vars['rest_route'] ) ) {
				$route = '/';
			} else {
				$route = $wp->query_vars['rest_route'];
			}

			$transaction = sprintf( '%s %s', $_SERVER['REQUEST_METHOD'], $route );
		}

		if ( ! empty( $transaction ) ) {
			newrelic_name_transaction( apply_filters( 'wp_nr_rest_transaction_name', $transaction ) );
		}
	}

	/**
	 * Set current transaction name as per the main WP_Query
	 *
	 * This is hooked into {@see wp}, so it's available in the following contexts:
	 * - Frontend
	 * - Admin
	 *
	 * It is NOT available in the following contexts:
	 * - REST (technically it would be on *some routes*, but we removed it for reliability. There's another method for that)
	 * - CRON
	 * - CLI
	 * - AJAX (technically would be on *some*, but there's another method for that)
	 *
	 * @param WP_Query $query
	 */
	private function set_fe_transaction() {
		if ( ! function_exists( 'newrelic_name_transaction' ) ) {
			return;
		}

		global $wp_query;

		$transaction = apply_filters( 'mindsize_nr_pq_transaction_name', false, $wp_query, $this );

		if ( false === $transaction && $wp_query->is_main_query() ) {
			if ( is_front_page() && is_home() ) {
				$transaction = 'Default Home Page';
			} elseif ( is_front_page() ) {
				$transaction = 'Front Page';
			} elseif ( is_home() ) {
				$transaction = 'Blog Page';
			} elseif ( is_single() ) {
				$post_type = ( ! empty( $wp_query->query['post_type'] ) ) ? $wp_query->query['post_type'] : 'Post';
				$transaction = "Single - {$post_type}";
			} elseif ( is_page() ) {
				if ( isset( $wp_query->query['pagename'] ) ) {
					$this->add_custom_parameter( 'page', $wp_query->query['pagename'] );
				}
				$transaction = "Page";
			} elseif ( is_date() ) {
				$transaction = 'Date Archive';
			} elseif ( is_search() ) {
				if ( isset( $wp_query->query['s'] ) ) {
					$this->add_custom_parameter( 'search', $wp_query->query['s'] );
				}
				$transaction = 'Search Page';
			} elseif ( is_feed() ) {
				$transaction = 'Feed';
			} elseif ( is_post_type_archive() ) {
				$post_type = post_type_archive_title( '', false );
				$transaction = "Archive - {$post_type}";
			} elseif ( is_category() ) {
				if ( isset( $wp_query->query['category_name'] ) ) {
					$this->add_custom_parameter( 'cat_slug', $wp_query->query['category_name'] );
				}
				$transaction = "Category";
			} elseif ( is_tag() ) {
				if ( isset( $wp_query->query['tag'] ) ) {
					$this->add_custom_parameter( 'tag_slug', $wp_query->query['tag'] );
				}
				$transaction = "Tag";
			} elseif ( is_tax() ) {
				$tax    = key( $wp_query->tax_query->queried_terms );
				$term   = implode( ' | ', $wp_query->tax_query->queried_terms[ $tax ]['terms'] );
				$this->add_custom_parameter( 'term_slug', $term );
				$transaction = "Tax - {$tax}";
			}
		}

		if ( ! empty( $transaction ) ) {
			newrelic_name_transaction( apply_filters( 'wp_nr_pq_transaction_name', $transaction ) );
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
	 * Hooked into {@see mindsize_nr_ajax_transaction_name}, changes the AJAX transaction name
	 * if it's WooCommerce
	 *
	 * @param string $transaction
	 * @return void
	 */
	public function woocommerce_ajax_transaction_names( $transaction ) {
		if ( false !== $transaction ) {
			return $transaction;
		}

		return array_key_exists( 'wc-ajax', $_REQUEST ) ? sprintf( 'WC AJAX: %s', $_REQUEST['wc-ajax'] ) : $transaction;
	}

	/**
	 * Method that hooks into the {@see $this->set_fe_transaction} method to overwrite the transaction name and
	 * maybe set a custom parameter in case of the shop.
	 *
	 * @param string $transaction
	 * @return void
	 */
	public function woocommerce_pq_transaction_names( $transaction, $query ) {

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
