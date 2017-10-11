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
	 * - mindsize_nr_enable_browser
	 * - mindsize_nr_browser_settings
	 *
	 * Not saved to options, but form variable name used:
	 * - mindsize_nr_reset_browser_settings
	 *
	 * Nonce name:
	 * - mindsize_nr_settings
	 */
	public function save_settings() {
		$nonce = filter_input( INPUT_POST, 'mindsize_nr_settings', FILTER_SANITIZE_STRING );

		if ( wp_verify_nonce( $nonce, 'mindsize_nr_settings' ) ) {
			// the !! forces the value from being falsy / truthy to be false / true
			$capture_url           = !! filter_input( INPUT_POST, 'mindsize_nr_capture_url' );
			$separate_environments = !! filter_input( INPUT_POST, 'mindsize_nr_separate_environs' );
			$enable_browser        = !! filter_input( INPUT_POST, 'mindsize_nr_enable_browser' );

			$reset_browser         = !! filter_input( INPUT_POST, 'mindsize_nr_reset_browser_settings' );
			$browser_settings      =    filter_input( INPUT_POST, 'mindsize_nr_browser_settings' );

			$this->plugin->helper->set_capture_url( $capture_url );
			$this->plugin->helper->set_separate_environment( $separate_environments );
			$this->plugin->helper->set_enable_browser( $enable_browser );

			if ( $reset_browser ) {
				$this->plugin->helper->set_browser_settings( false );
			} elseif( $browser_settings ) {
				$extracted_browser_settings = $this->extract_settings( $browser_settings );
				$this->plugin->helper->set_browser_settings( $extracted_browser_settings );
			}
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
		$is_capture        = !! $this->plugin->helper->get_capture_url();
		$separate_environs = !! $this->plugin->helper->get_separate_environment();
		$enable_browser    = !! $this->plugin->helper->get_enable_browser();
		$browser_settings  =    $this->plugin->helper->get_browser_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mindsize New Relic Settings', MINDSIZE_NR_SLUG ) ?></h1>
			<form method="post" action="">
				<?php
				wp_nonce_field( 'mindsize_nr_settings', 'mindsize_nr_settings' );
				?>
				<table class="form-table">
					<?php
					$this->capture_url_field( $is_capture );

					$this->separate_environs_field( $separate_environs );

					$this->enable_browser_field( $enable_browser );

					$this->browser_settings_field( $browser_settings );

					$this->reset_browser_settings_field();
					?>
				</table>
				<?php
				submit_button( esc_html__( 'Save Changes', MINDSIZE_NR_SLUG ), 'submit primary' );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Takes an entire javascript snippet provided by New Relic, and extracts the las bit of
	 * configuration information at the end of the script.
	 *
	 * @see  https://rpm.newrelic.com/accounts/1721910/browser/new  Choose the Copy/Paste Javascript code option
	 *
	 * @param  string    $script   a complete unescaped <script> element
	 * @return array               extracted configuration values
	 */
	private function extract_settings( $script ) {
		$script = stripslashes( $script );
		preg_match( '/NREUM\.info=\{(.*)\}/', $script, $matches );

		$vars = explode( ',', $matches[1] );

		$settings = array();
		foreach ( $vars as $var ) {
			list( $key, $value ) = explode( ':', $var );
			$value = trim( $value, '"' );

			$key = sanitize_title( $key );
			$value = sanitize_text_field( $value );

			$settings[ $key ] = $value;
		}// end foreach

		return $settings;
	}

	private function capture_url_field( $is_capture ) {
		?>
		<tr>
			<th scope="row">
				<label for="mindsize_nr_capture_url"><?php esc_html_e( 'Capture URL Parameters', MINDSIZE_NR_SLUG ); ?></label>
			</th>
			<td>
				<input type="checkbox" name="mindsize_nr_capture_url" id="mindsize_nr_capture_url" <?php checked( true, $is_capture ) ?>>
				<p class="description"><?php esc_html_e( 'Enable this to record parameter passed to PHP script via the URL (everything after the "?" in the URL).', MINDSIZE_NR_SLUG ) ?></p>
			</td>
		</tr>
		<?php
	}
	private function separate_environs_field( $separate_environs ) {
		?>
		<tr>
			<th scope="row">
				<label for="mindsize_nr_separate_environs"><?php esc_html_e( 'Separete Enviroments', MINDSIZE_NR_SLUG ); ?></label>
			</th>
			<td>
				<input type="checkbox" name="mindsize_nr_separate_environs" id="mindsize_nr_separate_environs" <?php checked( true, $separate_environs ) ?>>
				<p class="description"><?php esc_html_e( 'Enable this to see requests broken down by request type: Admin / Frontend / API / CRON / AJAX / CLI.', MINDSIZE_NR_SLUG ) ?></p>
			</td>
		</tr>
		<?php
	}

	private function enable_browser_field( $enable_browser ) {
		?>
		<tr>
			<th scope="row">
				<label for="mindsize_nr_enable_browser"><?php esc_html_e( 'Enable browser tracking', MINDSIZE_NR_SLUG ); ?></label>
			</th>
			<td>
				<input type="checkbox" name="mindsize_nr_enable_browser" id="mindsize_nr_enable_browser" <?php checked( true, $enable_browser ) ?>>
				<p class="description"><?php esc_html_e( 'If this is ticked, the browser tracking Javascript snippet will be output. You need to paste your browser tracking code into the box as well.', MINDSIZE_NR_SLUG ) ?></p>
			</td>
		</tr>
		<?php
	}

	private function browser_settings_field( $browser_settings ) {
		?>
		<tr>
			<th scope="row">
				<label for="mindsize_nr_browser_settings"><?php esc_html_e( 'Browser Tracking Javascript', MINDSIZE_NR_SLUG ); ?></label>
			</th>
			<td>
				<textarea name="mindsize_nr_browser_settings" id="mindsize_nr_browser_settings" cols="120" rows="20"></textarea>
				<p class="description"><?php esc_html_e( 'Paste your tracking code here and save.', MINDSIZE_NR_SLUG ) ?></p>
				<h2>Parsed settings</h2>
				<?php
				// and output the settings
				if ( is_array( $browser_settings ) ) {
					?>
					<table>
						<thead>
							<tr>
								<th>key</th>
								<th>value</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $browser_settings as $key => $value ) {
								printf( '<tr><td>%s</td><td>%s</td></tr>', $key, $value );
							}
							?>
						</tbody>
					</table>
					<?php
				} else {
					echo '<p>Setting is either not an array, or empty</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}

	private function reset_browser_settings_field() {
		?>
		<tr>
			<th scope="row">
				<label for="mindsize_nr_reset_browser_settings"><?php esc_html_e( 'Reset browser tracking code settings', MINDSIZE_NR_SLUG ); ?></label>
			</th>
			<td>
				<input type="checkbox" name="mindsize_nr_reset_browser_settings" id="mindsize_nr_reset_browser_settings">
				<p class="description"><?php esc_html_e( 'If this is ticked, the browser tracking Javascript settings will be reset.', MINDSIZE_NR_SLUG ) ?></p>
			</td>
		</tr>
		<?php
	}
}
