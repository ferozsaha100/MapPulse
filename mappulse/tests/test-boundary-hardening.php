<?php
use MapPulse\Boundaries\Arcgis;
use MapPulse\Boundaries\Census_Provider;
use MapPulse\Boundaries\Http;
use MapPulse\Boundaries\Service;
use MapPulse\Boundaries\Sources;

/**
 * Behavior added after the 0.2.0 review: failures that must not be cached, bounded waiting and memory,
 * and searches that must not come back empty for the wrong reason.
 */
class Test_Boundary_Hardening extends MapPulse_Boundary_Test_Case {

	const SERVICE = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Current/MapServer';
	const LAYERS  = 'https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/';
	const ID      = 'layer:st-petersburg-neighborhoods';
	const BOX     = '-82.75,27.72,-82.60,27.82';

	private function base_routes(): void {
		$this->http->reset()
			->fixture( 'MapServer/22?f=json', 'stpete-layer-info.json' )
			->fixture( '/22/query', 'stpete-shape.json' )
			->fixture( 'Hydro/MapServer/1/query', 'base-water.json' )
			->fixture( 'Transportation/MapServer/2/query', 'base-roads-primary.json' )
			->fixture( 'Transportation/MapServer/6/query', 'base-roads-secondary.json' )
			->fixture( 'tigerWMS_Current/MapServer/82/query', 'base-counties.json' )
			->fixture( 'tigerWMS_Current/MapServer/28/query', 'base-places.json' )
			->fixture( 'Hydro/MapServer?f=json', 'hydro-layers.json' )
			->fixture( 'Transportation/MapServer?f=json', 'transport-layers.json' )
			->fixture( 'tigerWMS_Current/MapServer?f=json', 'layers.json' );
	}

	public function test_redirects_are_not_followed() {
		$this->http->on( 'tigerweb', '{"ok":true}' );
		Http::get_json( self::SERVICE );
		$this->assertSame( 0, $this->http->args[0]['redirection'] );
	}

	public function test_a_dead_host_is_tried_twice_then_skipped_for_the_rest_of_the_request() {
		$this->assertSame( 'mappulse_http', Http::get_json( self::SERVICE )->get_error_code() );
		$this->assertSame( 2, $this->http->count() );
		$this->assertSame( 'mappulse_http', Http::get_json( self::SERVICE . '/28' )->get_error_code() );
		$this->assertSame( 2, $this->http->count() );
	}

	public function test_a_client_error_does_not_mark_the_host_dead() {
		$this->http->on( '/missing', 'gone', 404 )->on( 'tigerweb', '{"ok":true}' );
		$this->assertWPError( Http::get_json( self::SERVICE . '/missing' ) );
		$this->assertSame( array( 'ok' => true ), Http::get_json( self::SERVICE ) );
	}

	public function test_a_search_against_a_dead_source_makes_at_most_two_requests() {
		$found = Service::search( 'gulfport' );
		$this->assertArrayHasKey( 'census', $found['errors'] );
		$this->assertLessThanOrEqual( 2, $this->http->count() );
	}

	public function test_a_layer_list_that_cannot_be_read_is_an_error_not_a_guess() {
		$this->assertSame( 'mappulse_http', Arcgis::find_layer( self::SERVICE, '/^counties$/i', 82 )->get_error_code() );
	}

	public function test_an_answer_without_features_is_a_bad_response() {
		$this->http->on( '/28/query', '{"ok":true}' );
		$this->assertSame( 'mappulse_bad_response', Arcgis::query( self::SERVICE . '/28', array() )->get_error_code() );
	}

	public function test_base_with_a_failed_registered_layer_reports_it_and_is_not_cached() {
		$this->base_routes();
		Sources::add_layer( self::LAYERS . '22', 'St. Petersburg neighborhoods' );
		$this->base_routes();
		$this->http->reset()->on( 'egis.stpete.org', 'down', 503 );
		foreach ( array( 'Hydro/MapServer/1/query' => 'base-water.json', 'Transportation/MapServer/2/query' => 'base-roads-primary.json', 'Transportation/MapServer/6/query' => 'base-roads-secondary.json', 'tigerWMS_Current/MapServer/82/query' => 'base-counties.json', 'tigerWMS_Current/MapServer/28/query' => 'base-places.json', 'Hydro/MapServer?f=json' => 'hydro-layers.json', 'Transportation/MapServer?f=json' => 'transport-layers.json', 'tigerWMS_Current/MapServer?f=json' => 'layers.json' ) as $fragment => $file ) {
			$this->http->fixture( $fragment, $file );
		}

		$base = Service::base( Service::parse_bbox( self::BOX ) );
		$this->assertSame( array(), $base['areas']['features'] );
		$this->assertSame( array( self::ID ), array_keys( $base['errors'] ) );
		$this->assertNotEmpty( $base['water']['features'] );

		Http::reset();
		$this->base_routes();
		$base = Service::base( Service::parse_bbox( self::BOX ) );
		$this->assertNotEmpty( $base['areas']['features'] );
		$this->assertSame( array(), $base['errors'] );
	}

	public function test_query_can_return_the_first_page_only() {
		$this->http->fixture( '/28/query', 'places-page-1.json' );
		$result = Arcgis::query( self::SERVICE . '/28', array(), 2, true );
		$this->assertCount( 2, $result['features'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 1, $this->http->count( '/query' ) );
	}

	public function test_a_common_name_returns_the_first_matches_and_says_there_are_more() {
		$this->http->fixture( '/28/query', 'places-page-1.json' )->fixture( '/82/query', 'empty.json' )->fixture( 'MapServer?f=json', 'layers.json' );
		$found = Service::search( 'gulf', array( 'limit' => 2 ) );
		$this->assertCount( 2, $found['results'] );
		$this->assertTrue( $found['more'] );
		$this->assertSame( array(), $found['errors'] );
	}

	public function test_a_narrow_search_does_not_say_there_are_more() {
		$this->http->fixture( '/28/query', 'census-places-gulfport.json' )->fixture( '/82/query', 'empty.json' )->fixture( 'MapServer?f=json', 'layers.json' );
		$this->assertFalse( Service::search( 'gulfport' )['more'] );
	}

	public function test_query_stops_when_pages_add_up_past_the_byte_cap() {
		$page = strlen( file_get_contents( __DIR__ . '/fixtures/http/places-page-1.json' ) );
		add_filter(
			'mappulse_max_response_bytes',
			static function () use ( $page ) {
				return $page + 20;
			}
		);
		$this->http->fixture( 'resultOffset=2', 'places-page-2.json' )->fixture( '/28/query', 'places-page-1.json' );
		$this->assertSame( 'mappulse_too_large', Arcgis::query( self::SERVICE . '/28', array() )->get_error_code() );
	}

	public function test_one_base_layer_can_be_asked_for_alone() {
		$this->base_routes();
		$water = Service::base_layer( Service::parse_bbox( self::BOX ), 'water' );
		$this->assertSame( 'FeatureCollection', $water['type'] );
		$this->assertNotEmpty( $water['features'] );
		$this->assertSame( 0, $this->http->count( 'Transportation' ) );
		$this->assertSame( 0, $this->http->count( 'tigerWMS_Current' ) );
		$this->assertSame( 'mappulse_bad_layer', Service::base_layer( Service::parse_bbox( self::BOX ), 'clouds' )->get_error_code() );
	}

	public function test_every_census_base_layer_is_simplified_at_the_source() {
		$this->base_routes();
		Service::base( Service::parse_bbox( self::BOX ) );
		$queries = array_filter( $this->http->requests, fn( $url ) => false !== strpos( $url, 'tigerweb' ) && false !== strpos( $url, '/query' ) );
		$this->assertCount( 5, $queries );
		foreach ( $queries as $url ) {
			$this->assertStringContainsString( 'maxAllowableOffset', $url );
		}
	}

	public function test_rest_base_route_takes_one_layer() {
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->base_routes();
		$request = new WP_REST_Request( 'GET', '/mappulse/v1/boundaries/base' );
		$request->set_param( 'bbox', self::BOX );
		$request->set_param( 'layer', 'water' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'water', 'errors' ), array_keys( $response->get_data() ) );
		$request->set_param( 'layer', 'clouds' );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_a_layer_registered_again_under_the_same_label_does_not_serve_old_shapes() {
		$this->http->fixture( 'MapServer/21?f=json', 'stpete-layer-info.json' )->fixture( 'MapServer/22?f=json', 'stpete-layer-info.json' )
			->fixture( '/21/query', 'layer21-shape.json' )->fixture( '/22/query', 'stpete-shape.json' );
		Sources::add_layer( self::LAYERS . '21', 'St. Petersburg neighborhoods' );
		$this->assertSame( 'OLD AREA', Sources::get( self::ID )->fetch( '41' )['properties']['name'] );
		Sources::remove_layer( self::ID );
		Sources::add_layer( self::LAYERS . '22', 'St. Petersburg neighborhoods' );
		$this->assertSame( 'HISTORIC KENWOOD NBRHD ASSN', Sources::get( self::ID )->fetch( '41' )['properties']['name'] );
	}

	public function test_like_pattern_bridges_spaces_and_punctuation() {
		$this->assertSame( 'ST%PETERSBURG', Arcgis::like( 'St Petersburg' ) );
		$this->assertSame( 'ST%PETE%S', Arcgis::like( "st. pete's" ) );
		$this->assertSame( 'A%B', Arcgis::like( 'a%_;" b' ) );
		$this->assertSame( '', Arcgis::like( " . ' - " ) );
	}

	public function test_a_name_typed_without_its_period_is_still_found_and_ranked_first() {
		$this->http->fixture( '/28/query', 'census-places-stpete.json' )->fixture( 'MapServer?f=json', 'layers.json' );
		$found = Service::search( 'st petersburg', array( 'type' => 'city' ) );
		$this->assertStringContainsString( 'LIKE+%27ST%25PETERSBURG%25%27', implode( ' ', $this->http->requests ) );
		$this->assertSame( 'St. Petersburg', $found['results'][0]['name'] );
	}

	public function test_text_with_no_letters_or_digits_is_rejected() {
		$this->assertSame( 'mappulse_bad_query', ( new Census_Provider() )->search( " . ' -", array() )->get_error_code() );
		$this->assertSame( 0, $this->http->count() );
	}
}
