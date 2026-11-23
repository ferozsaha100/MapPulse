<?php
/**
 * Plugin Name:       MapPulse
 * Plugin URI:        https://github.com/ferozsaha100/MapPulse
 * Description:       Clickable, branded market maps for real estate websites, drawn from real boundary data. Works as an Elementor widget, a Gutenberg block and a shortcode.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Agent View Digital
 * Author URI:        https://agentviewdigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mappulse
 *
 * @package MapPulse
 */

defined( 'ABSPATH' ) || exit;

define( 'MAPPULSE_VERSION', '0.1.0' );
define( 'MAPPULSE_FILE', __FILE__ );
define( 'MAPPULSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAPPULSE_URL', plugin_dir_url( __FILE__ ) );
define( 'MAPPULSE_MAX_IMPORT_BYTES', 2 * 1024 * 1024 );
define( 'MAPPULSE_MAX_ZONES', 200 );

require_once MAPPULSE_DIR . 'includes/class-map-document.php';
require_once MAPPULSE_DIR . 'includes/class-renderer.php';
require_once MAPPULSE_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', array( 'MapPulse\Plugin', 'instance' ) );
