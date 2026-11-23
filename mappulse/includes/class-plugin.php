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
	 * Adds the hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'init', array( Map_Post_Type::class, 'register' ) );
	}

	/**
	 * Runs on activation: gives administrators the right to manage maps.
	 */
	public static function activate(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( Map_Post_Type::CAPABILITY );
		}
	}

	/**
	 * Registers the front-end stylesheet and script. They are enqueued only where a map is rendered.
	 */
	public function register_assets(): void {
		wp_register_style( 'mappulse', MAPPULSE_URL . 'assets/css/mappulse.css', array(), MAPPULSE_VERSION );
		wp_register_script(
			'mappulse',
			MAPPULSE_URL . 'assets/js/mappulse.js',
			array(),
			MAPPULSE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
