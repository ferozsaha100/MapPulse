<?php
/**
 * Elementor integration loader.
 *
 * The widget class extends an Elementor class, so its file is only loaded when Elementor
 * asks for widgets. Without Elementor the hook never fires and nothing else happens.
 *
 * @package MapPulse
 */

namespace MapPulse\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the MapPulse widget with Elementor.
 */
class Elementor {

	/**
	 * Adds the hook.
	 */
	public static function register(): void {
		add_action( 'elementor/widgets/register', array( self::class, 'register_widget' ) );
	}

	/**
	 * Loads and registers the widget.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor's widget registry.
	 */
	public static function register_widget( $widgets_manager ): void {
		require_once MAPPULSE_DIR . 'includes/integrations/class-elementor-widget.php';
		$widgets_manager->register( new Elementor_Widget() );
	}
}
