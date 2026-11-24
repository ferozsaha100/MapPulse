<?php
use MapPulse\Boundaries\Service;
use MapPulse\Boundaries\Sources;

class Test_Rest extends MapPulse_Boundary_Test_Case {

	const LAYER = 'https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/22';

	public function set_up() {
		parent::set_up();
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
		$this->http->fixture( 'MapServer/22?f=json', 'stpete-layer-info.json' )
			->fixture( 'GEOID%3D%271228175%27', 'census-place-shape.json' )
			->fixture( '/28/query', 'census-places-gulfport.json' )
			->fixture( '/82/query', 'census-counties-pinellas.json' )
			->fixture( 'tigerWMS_Current/MapServer?f=json', 'layers.json' );
	}

	private function call( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mappulse/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function sign_in( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_routes_are_registered() {
		$routes = array_keys( rest_get_server()->get_routes() );
		foreach ( array( '/mappulse/v1/boundaries/search', '/mappulse/v1/boundaries/fetch', '/mappulse/v1/boundaries/base', '/mappulse/v1/sources' ) as $route ) {
			$this->assertContains( $route, $routes );
		}
	}

	public function test_visitors_and_editors_are_refused() {
		$calls = array(
			array( 'GET', '/boundaries/search', array( 'q' => 'gulfport' ) ),
			array( 'GET', '/boundaries/fetch', array( 'source' => 'census', 'record' => 'place:1228175' ) ),
			array( 'GET', '/boundaries/base', array( 'bbox' => '-82.75,27.72,-82.60,27.82' ) ),
			array( 'GET', '/sources', array() ),
			array( 'POST', '/sources', array( 'url' => self::LAYER, 'label' => 'X' ) ),
		);
		foreach ( $calls as $call ) {
			wp_set_current_user( 0 );
			$this->assertSame( 401, $this->call( ...$call )->get_status(), $call[1] );
			$this->sign_in( 'editor' );
			$this->assertSame( 403, $this->call( ...$call )->get_status(), $call[1] );
		}
		$this->assertSame( 0, $this->http->count() );
		$this->assertSame( array(), Sources::layers() );
	}

	public function test_manager_search_returns_results() {
		$this->sign_in( 'administrator' );
		$response = $this->call( 'GET', '/boundaries/search', array( 'q' => 'gulfport', 'state' => 'FL', 'type' => 'city' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Gulfport', $response->get_data()['results'][0]['name'] );
		$this->assertSame( array(), $response->get_data()['errors'] );
	}

	public function test_missing_query_is_a_400() {
		$this->sign_in( 'administrator' );
		$this->assertSame( 400, $this->call( 'GET', '/boundaries/search' )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/boundaries/search', array( 'q' => 'gulfport', 'type' => 'planet' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/boundaries/base', array( 'bbox' => 'nonsense' ) )->get_status() );
	}

	public function test_manager_fetch_returns_the_feature() {
		$this->sign_in( 'administrator' );
		$response = $this->call( 'GET', '/boundaries/fetch', array( 'source' => 'census', 'record' => 'place:1228175' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Feature', $response->get_data()['type'] );
	}

	public function test_source_failure_is_a_502() {
		$this->sign_in( 'administrator' );
		$this->http->reset()->on( 'tigerweb', 'down', 503 );
		$this->assertSame( 502, $this->call( 'GET', '/boundaries/fetch', array( 'source' => 'census', 'record' => 'place:1228175' ) )->get_status() );
	}

	public function test_unknown_source_and_bad_record_are_client_errors() {
		$this->sign_in( 'administrator' );
		$this->assertSame( 404, $this->call( 'GET', '/boundaries/fetch', array( 'source' => 'layer:nope', 'record' => '1' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/boundaries/fetch', array( 'source' => 'census', 'record' => 'road:1' ) )->get_status() );
	}

	public function test_layer_can_be_added_listed_and_removed_over_rest() {
		$this->sign_in( 'administrator' );
		$added = $this->call( 'POST', '/sources', array( 'url' => self::LAYER, 'label' => 'St. Petersburg neighborhoods', 'parent' => 'St. Petersburg, FL' ) );
		$this->assertSame( 201, $added->get_status() );
		$this->assertSame( 'layer:st-petersburg-neighborhoods', $added->get_data()['id'] );
		$this->assertSame( array( 'census', 'layer:st-petersburg-neighborhoods' ), wp_list_pluck( $this->call( 'GET', '/sources' )->get_data(), 'id' ) );
		$this->assertSame( 400, $this->call( 'POST', '/sources', array( 'url' => 'https://example.com/x.json', 'label' => 'Bad' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'DELETE', '/sources/layer:st-petersburg-neighborhoods' )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/sources/layer:st-petersburg-neighborhoods' )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/sources/census' )->get_status() );
	}

	public function test_describe_counts_rings_points_and_box() {
		$feature = array(
			'type'     => 'Feature',
			'geometry' => array(
				'type'        => 'MultiPolygon',
				'coordinates' => array(
					array( array( array( 0, 0 ), array( 4, 0 ), array( 4, 4 ), array( 0, 4 ), array( 0, 0 ) ), array( array( 1, 1 ), array( 2, 1 ), array( 2, 2 ), array( 1, 1 ) ) ),
					array( array( array( 10, 10 ), array( 11, 10 ), array( 11, 12 ), array( 10, 10 ) ) ),
				),
			),
		);
		$this->assertSame(
			array(
				'rings'  => 3,
				'points' => 13,
				'bbox'   => array( 0.0, 0.0, 11.0, 12.0 ),
			),
			Service::describe( $feature )
		);
	}
}
