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
		add_action( 'init', array( Shortcode::class, 'register' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_data' ) );

		Integrations\Elementor::register();
		Boundaries\Sources::register();
		Rest_Controller::register();
		Maps_Controller::register();
		Links_Controller::register();
		// A map's Edit links open the builder wherever they appear, the admin bar included.
		add_filter( 'get_edit_post_link', array( Builder_Screen::class, 'edit_link' ), 10, 2 );

		if ( is_admin() ) {
			( new Importer() )->register();
			( new Sources_Screen() )->register();
			( new Builder_Screen() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once MAPPULSE_DIR . 'includes/class-cli.php';
			require_once MAPPULSE_DIR . 'includes/class-cli-sources.php';
			\WP_CLI::add_command( 'mappulse', CLI::class );
			\WP_CLI::add_command( 'mappulse sources', CLI_Sources::class );
		}
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
	 * Registers the Gutenberg block from its block.json.
	 */
	public function register_block(): void {
		register_block_type( MAPPULSE_DIR . 'blocks/mappulse' );
	}

	/**
	 * Hands the block editor the list of maps for its picker.
	 */
	public function block_editor_data(): void {
		$maps = array();
		foreach ( Map_Post_Type::choices() as $id => $title ) {
			$maps[] = array(
				'id'    => $id,
				'title' => $title,
			);
		}
		wp_add_inline_script(
			'agentview-mappulse-editor-script',
			'window.mappulseBlock = ' . wp_json_encode( array( 'maps' => $maps ) ) . ';',
			'before'
		);
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
