<?php
/**
 * PHPUnit bootstrap. Works inside wp-env (tests-cli) and with any WP_TESTS_DIR.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

$_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/mappulse.php';

		// Elementor is loaded when the environment has it (wp-env does), so the widget can be tested for real.
		$elementor = WP_PLUGIN_DIR . '/elementor/elementor.php';
		if ( file_exists( $elementor ) ) {
			require $elementor;
		}
	}
);

tests_add_filter(
	'plugins_loaded',
	static function () {
		$GLOBALS['mappulse_widget_loaded_early'] = class_exists( 'MapPulse\\Integrations\\Elementor_Widget', false );
	},
	999
);

require $_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-mappulse-test-case.php';
