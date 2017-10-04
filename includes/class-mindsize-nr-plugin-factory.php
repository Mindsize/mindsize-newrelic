<?php
namespace Mindsize\NewRelic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin factory, so we get one shared instance of the class that's not a singleton.
 *
 * @since     1.0.0
 * @author    Mindsize <info@mindsize.me>
 * @copyright Copyright (c) 2017 Mindsize <info@mindsize.me>
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0
 */
class Plugin_Factory {
	public static function create() {
		static $plugin = null;

		if ( null === $plugin ) {
			$plugin = new Plugin( self::is_network_active() );
		}

		return $plugin;
	}

	private static function is_network_active() {
		$plugins = get_site_option( 'active_sitewide_plugins' );

		return is_multisite() && isset( $plugins[ WP_NR_BASENAME ] );
	}
}
