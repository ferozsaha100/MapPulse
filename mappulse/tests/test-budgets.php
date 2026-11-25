<?php
/**
 * The front end stays small: the spec's limits for the view script and the stylesheet.
 */
class Test_Budgets extends MapPulse_Test_Case {

	public function test_the_view_script_is_under_15_kb_gzipped() {
		$size = strlen( gzencode( file_get_contents( MAPPULSE_DIR . 'assets/js/mappulse.js' ), 9 ) );
		$this->assertLessThan( 15000, $size );
	}

	public function test_the_stylesheet_is_under_5_kb_gzipped() {
		$size = strlen( gzencode( file_get_contents( MAPPULSE_DIR . 'assets/css/mappulse.css' ), 9 ) );
		$this->assertLessThan( 5000, $size );
	}

	public function test_the_view_script_asks_for_nothing_and_needs_nothing() {
		$script = file_get_contents( MAPPULSE_DIR . 'assets/js/mappulse.js' );
		foreach ( array( 'fetch(', 'XMLHttpRequest', 'import ', 'require(', 'jQuery', 'wp.' ) as $unwanted ) {
			$this->assertStringNotContainsString( $unwanted, $script, $unwanted );
		}
		$this->assertSame( array(), wp_scripts()->registered['mappulse']->deps );
	}
}
