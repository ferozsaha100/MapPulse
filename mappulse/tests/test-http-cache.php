<?php
use MapPulse\Boundaries\Cache;
use MapPulse\Boundaries\Http;

class Test_Http_Cache extends MapPulse_Boundary_Test_Case {

	const URL = 'https://tigerweb.geo.census.gov/arcgis/rest/services/x';

	public function test_json_from_an_allowed_host_is_returned() {
		$this->http->on( 'tigerweb', '{"features":[]}' );
		$this->assertSame( array( 'features' => array() ), Http::get_json( self::URL ) );
	}

	public function test_query_values_are_url_encoded() {
		$this->http->on( 'tigerweb', '{"ok":true}' );
		Http::get_json( self::URL, array( 'where' => "NAME LIKE 'A%'" ) );
		$this->assertStringEndsWith( '?where=NAME+LIKE+%27A%25%27', $this->http->requests[0] );
	}

	public function test_other_hosts_are_refused_without_a_request() {
		$this->assertSame( 'mappulse_host_not_allowed', Http::get_json( 'https://example.com/x' )->get_error_code() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_http_urls_are_refused() {
		$this->assertSame( 'mappulse_host_not_allowed', Http::get_json( 'http://tigerweb.geo.census.gov/x' )->get_error_code() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_server_errors_are_retried_twice_then_reported() {
		$this->http->on( 'tigerweb', 'down', 503 );
		$this->assertSame( 'mappulse_http', Http::get_json( self::URL )->get_error_code() );
		$this->assertSame( 3, $this->http->count() );
	}

	public function test_client_errors_are_not_retried() {
		$this->http->on( 'tigerweb', 'missing', 404 );
		$this->assertSame( 'mappulse_http', Http::get_json( self::URL )->get_error_code() );
		$this->assertSame( 1, $this->http->count() );
	}

	public function test_html_answers_are_rejected() {
		$this->http->on( 'tigerweb', '<html><body>Service unavailable</body></html>' );
		$this->assertSame( 'mappulse_bad_response', Http::get_json( self::URL )->get_error_code() );
	}

	public function test_arcgis_error_objects_are_errors() {
		$this->http->on( 'tigerweb', '{"error":{"code":400,"message":"Invalid query"}}' );
		$error = Http::get_json( self::URL );
		$this->assertSame( 'mappulse_source_error', $error->get_error_code() );
		$this->assertStringContainsString( 'Invalid query', $error->get_error_message() );
	}

	public function test_bodies_over_the_cap_are_rejected() {
		add_filter(
			'mappulse_max_response_bytes',
			static function () {
				return 10;
			}
		);
		$this->http->on( 'tigerweb', '{"features":[1,2,3,4,5,6]}' );
		$this->assertSame( 'mappulse_too_large', Http::get_json( self::URL )->get_error_code() );
	}

	public function test_cache_round_trip_and_expiry() {
		$this->assertTrue( Cache::set( 'k', array( 'a' => 1 ), 60 ) );
		$this->assertSame( array( 'a' => 1 ), Cache::get( 'k' ) );
		$file = glob( Cache::dir() . '/*.json' )[0];
		$data = json_decode( file_get_contents( $file ), true );
		$data['expires'] = time() - 1;
		file_put_contents( $file, wp_json_encode( $data ) );
		$this->assertNull( Cache::get( 'k' ) );
	}

	public function test_corrupt_cache_file_is_ignored() {
		Cache::set( 'k', array( 'a' => 1 ) );
		file_put_contents( glob( Cache::dir() . '/*.json' )[0], '{broken' );
		$this->assertNull( Cache::get( 'k' ) );
	}

	public function test_unwritable_cache_folder_does_not_break_set() {
		$file = tempnam( get_temp_dir(), 'mp' );
		add_filter(
			'mappulse_cache_dir',
			static function () use ( $file ) {
				return $file . '/cache';
			},
			20
		);
		$this->assertFalse( Cache::set( 'k', array( 'a' => 1 ) ) );
		$this->assertNull( Cache::get( 'k' ) );
		unlink( $file );
	}

	public function test_flush_removes_every_entry() {
		Cache::set( 'a', array( 1 ) );
		Cache::set( 'b', array( 2 ) );
		$this->assertSame( 2, Cache::flush() );
		$this->assertNull( Cache::get( 'a' ) );
	}
}
