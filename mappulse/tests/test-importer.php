<?php
use MapPulse\Importer;

class Test_Importer extends MapPulse_Test_Case {

	public function test_sample_map_imports_and_renders() {
		$id = Importer::from_file( Importer::sample_path() );
		$this->assertIsInt( $id );
		$this->assertSame( 16, preg_match_all( '/class="mappulse__zone[ "]/', mappulse_render_map( $id ) ) );
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

	public function set_up() {
		parent::set_up();
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
	}

	/** Signs in a new user with the given role. */
	private function sign_in( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/** Runs the import handler as the current user. Returns where it redirected, or the message it died with. */
	private function run_import( string $nonce, array $post = array(), array $files = array() ): array {
		$_POST                = $post;
		$_FILES               = $files;
		$_REQUEST['_wpnonce'] = $nonce;
		$stop                 = static function ( $location ) {
			throw new RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $stop );
		try {
			( new Importer() )->handle_request();
		} catch ( WPDieException $e ) {
			return array( 'died' => $e->getMessage() );
		} catch ( RuntimeException $e ) {
			return array( 'redirect' => $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $stop );
			$_POST  = array();
			$_FILES = array();
			unset( $_REQUEST['_wpnonce'] );
		}
		return array();
	}

	private function map_count(): int {
		return count(
			get_posts(
				array(
					'post_type'   => 'mappulse_map',
					'post_status' => 'any',
				)
			)
		);
	}

	public function test_import_request_without_the_capability_is_refused() {
		$this->sign_in( 'editor' );
		$result = $this->run_import( wp_create_nonce( 'mappulse_import' ), array( 'mappulse_sample' => '1' ) );
		$this->assertStringContainsString( 'not allowed', $result['died'] ?? '' );
		$this->assertSame( 0, $this->map_count() );
	}

	public function test_import_request_with_a_bad_nonce_is_refused() {
		$this->sign_in( 'administrator' );
		$result = $this->run_import( 'not-a-nonce', array( 'mappulse_sample' => '1' ) );
		$this->assertArrayHasKey( 'died', $result );
		$this->assertStringNotContainsString( 'not allowed', $result['died'] );
		$this->assertSame( 0, $this->map_count() );
	}

	public function test_import_request_from_a_manager_imports_and_redirects() {
		$this->sign_in( 'administrator' );
		$result = $this->run_import( wp_create_nonce( 'mappulse_import' ), array( 'mappulse_sample' => '1' ) );
		$this->assertStringContainsString( 'mappulse_imported=', $result['redirect'] ?? '' );
		$this->assertSame( 1, $this->map_count() );
	}

	public function test_a_file_path_that_is_not_a_real_upload_is_refused() {
		$this->sign_in( 'administrator' );
		$files  = array(
			'mappulse_file' => array(
				'name'     => 'sample.map.json',
				'tmp_name' => Importer::sample_path(),
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( Importer::sample_path() ),
			),
		);
		$result = $this->run_import( wp_create_nonce( 'mappulse_import' ), array(), $files );
		$this->assertStringContainsString( 'mappulse_error=mappulse_file', $result['redirect'] ?? '' );
		$this->assertSame( 0, $this->map_count() );
	}
}
