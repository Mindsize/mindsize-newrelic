<?php
namespace Mindsize\NewRelic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for helper functions library
 *
 * Class WP_NR_Helper
 */
class Plugin_Helper {

	private $plugin = null;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Check if capture url setting is enabled or not
	 *
	 * @return bool
	 */
	public function get_capture_url() {
		return $this->get_setting( 'mindsize_nr_capture_url' );
	}

	public function set_capture_url( $value ) {
		$this->set_setting( 'mindsize_nr_capture_url', $value );
	}

	public function get_separate_environment() {
		return $this->get_setting( 'mindsize_nr_separate_environs' );
	}

	public function set_separate_environment( $value ) {
		$this->set_setting( 'mindsize_nr_separate_environs', $value );
	}

	/**
	 * Get single setting
	 *
	 * @param $setting
	 *
	 * @return bool
	 */
	private function get_setting( $setting ) {
		if ( $this->plugin->network ) {
			$return = (bool) get_site_option( $setting, false );
		} else {
			$return = (bool) get_option( $setting, false );
		}

		return $return;
	}

	private function set_setting( $setting, $value ) {
		if ( $this->plugin->network ) {
			update_site_option( $setting, $value );
		} else {
			update_option( $setting, $value );
		}
	}
}
