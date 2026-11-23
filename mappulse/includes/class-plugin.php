<?php
/**
 * Plugin bootstrap: wires every part of MapPulse to WordPress.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class Plugin {

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the one instance, creating it on first use.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks are added here as each part arrives.
	 */
	private function __construct() {}
}
