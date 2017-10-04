<?php
namespace Mindsize\NewRelic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class Plugin_Admin {
	private $plugin = null;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function init() {
		// Add the appropriate admin page
		if ( $this->plugin->network ) {
			add_action( 'network_admin_menu', array( $this, 'action_admin_menu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );
		}

		// Save settings
		add_action( 'admin_init', array( $this, 'save_settings' ) );
	}

	/**
	 * Save settings for the plugin after filtering. Network aware.
	 *
	 * Option keys used:
	 * - mindsize_nr_capture_url
	 * - mindsize_nr_separate_environs
	 *
	 * Nonce name:
	 * - mindsize_nr_settings
	 */
	public function save_settings() {
		$nonce = filter_input( INPUT_POST, 'mindsize_nr_settings', FILTER_SANITIZE_STRING );

		if ( wp_verify_nonce( $nonce, 'mindsize_nr_settings' ) ) {
			// the !! forces the value from being falsy / truthy to be false / true
			$capture_url = !! filter_input( INPUT_POST, 'mindsize_nr_capture_url' );
			$separate_environments = !! filter_input( INPUT_POST, 'mindsize_nr_separate_environs' );

			$this->plugin->helper->set_capture_url( $capture_url );
			$this->plugin->helper->set_separate_environment( $separate_environments );
		}
	}

	/**
	 * Add menu page
	 */
	public function action_admin_menu() {
		if ( $this->plugin->network  ) {
			add_menu_page(
				'Mindsize New Relic',
				'Mindsize New Relic',
				'manage_network',
				'mindsize_nr_settings',
				array( $this, 'dashboard_page' ),
				'',
				20
			);
		} else {
			add_management_page(
				'Mindsize New Relic',
				'Mindsize New Relic',
				'manage_options',
				'mindsize_nr_settings',
				array( $this, 'dashboard_page' )
			);
		}
	}

	/**
	 * Option page
	 */
	public function dashboard_page() {
		$is_capture = $this->plugin->helper->get_capture_url();
		$separate_environs = $this->plugin->helper->get_separate_environment();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mindsize New Relic Settings', MINDSIZE_NR_SLUG ) ?></h1>
			<form method="post" action="">
				<?php
				wp_nonce_field( 'mindsize_nr_settings', 'mindsize_nr_settings' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="mindsize_nr_capture_url"><?php esc_html_e( 'Capture URL Parameters', MINDSIZE_NR_SLUG ); ?></label>
						</th>
						<td>
							<input type="checkbox" name="mindsize_nr_capture_url" id="mindsize_nr_capture_url" <?php checked( true, $is_capture ) ?>>
							<p class="description"><?php esc_html_e( 'Enable this to record parameter passed to PHP script via the URL (everything after the "?" in the URL).', MINDSIZE_NR_SLUG ) ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="mindsize_nr_separate_environs"><?php esc_html_e( 'Separete Enviroments', MINDSIZE_NR_SLUG ); ?></label>
						</th>
						<td>
							<input type="checkbox" name="mindsize_nr_separate_environs" id="mindsize_nr_separate_environs" <?php checked( true, $separate_environs ) ?>>
							<p class="description"><?php esc_html_e( 'Enable this to see requests broken down by request type: Admin / Frontend / API / CRON / AJAX / CLI.', MINDSIZE_NR_SLUG ) ?></p>
						</td>
					</tr>
				</table>
				<?php
				submit_button( esc_html__( 'Save Changes', MINDSIZE_NR_SLUG ), 'submit primary' );
				?>
			</form>
		</div>
		<?php
	}
}
