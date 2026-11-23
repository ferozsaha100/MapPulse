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
	}
);

require $_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-mappulse-test-case.php';
