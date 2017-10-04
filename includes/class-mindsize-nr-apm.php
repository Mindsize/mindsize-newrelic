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

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}
}
