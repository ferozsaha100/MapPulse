<?php
use MapPulse\Importer;

class Test_Importer extends MapPulse_Test_Case {

	public function test_sample_map_imports_and_renders() {
		$id = Importer::from_file( Importer::sample_path() );
		$this->assertIsInt( $id );
		$this->assertGreaterThanOrEqual( 16, substr_count( mappulse_render_map( $id ), 'class="mappulse__zone' ) );
	}

	public function test_title_defaults_to_the_document_title() {
		$this->assertSame( 'Test Market', get_the_title( Importer::from_json( wp_json_encode( $this->fixture() ) ) ) );
	}

	public function test_broken_json_is_rejected() {
		$this->assertSame( 'mappulse_json', Importer::from_json( '{"schema":1,' )->get_error_code() );
	}

	public function test_oversized_file_is_rejected() {
		$this->assertSame( 'mappulse_too_large', Importer::from_json( str_repeat( ' ', MAPPULSE_MAX_IMPORT_BYTES + 1 ) )->get_error_code() );
	}

	public function test_import_request_needs_capability_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_POST = array(
			'mappulse_sample' => '1',
			'_wpnonce'        => wp_create_nonce( 'mappulse_import' ),
		);
		$this->expectException( 'WPDieException' );
		( new Importer() )->handle_request();
	}
}
