<?php
use MapPulse\Map_Post_Type;
use MapPulse\Map_Document;
use MapPulse\Maps_Controller;

class Test_Maps_Controller extends MapPulse_Test_Case {

	public function set_up() {
		parent::set_up();
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
	}

	private function built(): array {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/built-document.json' ), true );
	}

	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mappulse/v1' . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function sign_in( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	private function create( string $status = 'publish' ): array {
		return $this->call( 'POST', '/maps', array( 'document' => $this->built(), 'status' => $status ) )->get_data();
	}

	public function test_visitors_and_editors_are_refused_on_every_route() {
		$this->sign_in( 'administrator' );
		$id = $this->create()['id'];

		$calls = array(
			array( 'GET', '/maps' ),
			array( 'POST', '/maps', array( 'document' => $this->built() ) ),
			array( 'GET', '/maps/' . $id ),
			array( 'PUT', '/maps/' . $id, array( 'document' => $this->built() ) ),
			array( 'DELETE', '/maps/' . $id ),
			array( 'POST', '/render', array( 'document' => $this->built() ) ),
		);
		foreach ( array( 0 => 401, 'editor' => 403 ) as $role => $status ) {
			if ( $role ) {
				$this->sign_in( $role );
			} else {
				wp_set_current_user( 0 );
			}
			foreach ( $calls as $call ) {
				$this->assertSame( $status, $this->call( ...$call )->get_status(), $call[0] . ' ' . $call[1] );
			}
		}
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_an_administrator_creates_a_map_and_reads_it_back() {
		$this->sign_in( 'administrator' );
		$response = $this->call( 'POST', '/maps', array( 'document' => $this->built(), 'status' => 'draft' ) );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( 'Gulfport area', $data['title'] );
		$this->assertSame( '[mappulse id="' . $data['id'] . '"]', $data['shortcode'] );

		$read = $this->call( 'GET', '/maps/' . $data['id'] )->get_data();
		$this->assertSame( Map_Document::hash( Map_Document::sanitize( $this->built() ) ), Map_Document::hash( $read['document'] ) );
		$this->assertSame( 'Polygon', $read['document']['zones'][0]['geometry']['type'] );
	}

	public function test_updating_changes_the_title_status_and_document() {
		$this->sign_in( 'administrator' );
		$id           = $this->create( 'draft' )['id'];
		$doc          = $this->built();
		$doc['title'] = 'South Pinellas';
		array_pop( $doc['zones'] );

		$response = $this->call( 'PUT', '/maps/' . $id, array( 'document' => $doc, 'status' => 'publish' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'South Pinellas', get_the_title( $id ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertCount( 2, Map_Post_Type::get( $id )['zones'] );
		$this->assertSame( 2, substr_count( Map_Post_Type::svg( $id ), 'class="mappulse__zone ' ) );
	}

	public function test_a_document_that_fails_the_sanitizer_answers_400_with_its_code() {
		$this->sign_in( 'administrator' );
		$doc                                       = $this->built();
		$doc['topology']['zones']['gulfport']['d'] = 'M0 0<script>';
		$response                                  = $this->call( 'POST', '/maps', array( 'document' => $doc ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'mappulse_bad_path', $response->get_data()['code'] );
		$this->assertSame( 400, $this->call( 'POST', '/maps', array( 'document' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/render', array( 'document' => $doc ) )->get_status() );
		$this->assertCount( 0, get_posts( array( 'post_type' => Map_Post_Type::POST_TYPE, 'post_status' => 'any' ) ) );
	}

	public function test_deleting_moves_the_map_to_the_trash() {
		$this->sign_in( 'administrator' );
		$id       = $this->create()['id'];
		$response = $this->call( 'DELETE', '/maps/' . $id );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( 404, $this->call( 'GET', '/maps/' . $id )->get_status() );
	}

	public function test_render_draws_an_unsaved_document_and_stores_nothing() {
		$this->sign_in( 'administrator' );
		$data = $this->call( 'POST', '/render', array( 'document' => $this->built() ) )->get_data();
		$this->assertStringStartsWith( '<svg', $data['svg'] );
		// The builder shows the whole section, so the preview carries the map's own colors.
		$this->assertStringContainsString( 'data-mappulse', $data['html'] );
		$this->assertStringContainsString( '--mappulse-doc-', $data['html'] );
		$this->assertStringContainsString( 'mappulse--map-only', $data['html'] );
		$this->assertStringNotContainsString( 'mappulse__list', $data['html'] );
		$this->assertSame( 3, substr_count( $data['svg'], 'class="mappulse__zone ' ) );
		$this->assertCount( 0, get_posts( array( 'post_type' => Map_Post_Type::POST_TYPE, 'post_status' => 'any' ) ) );
	}

	public function test_other_posts_and_missing_maps_are_not_found() {
		$this->sign_in( 'administrator' );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		foreach ( array( $page, 999999 ) as $id ) {
			$this->assertSame( 404, $this->call( 'GET', '/maps/' . $id )->get_status() );
			$this->assertSame( 404, $this->call( 'PUT', '/maps/' . $id, array( 'document' => $this->built() ) )->get_status() );
			$this->assertSame( 404, $this->call( 'DELETE', '/maps/' . $id )->get_status() );
		}
		$this->assertSame( 'publish', get_post_status( $page ) );
	}

	public function test_the_list_names_each_map_with_its_zone_count() {
		$this->sign_in( 'administrator' );
		$id   = $this->create( 'draft' )['id'];
		$list = $this->call( 'GET', '/maps' )->get_data();
		$this->assertCount( 1, $list );
		$this->assertSame( $id, $list[0]['id'] );
		$this->assertSame( 'Gulfport area', $list[0]['title'] );
		$this->assertSame( 'draft', $list[0]['status'] );
		$this->assertSame( 3, $list[0]['zones'] );
		$this->assertArrayNotHasKey( 'document', $list[0] );
	}

	public function test_source_boundaries_are_stored_apart_from_the_document_the_site_draws_from() {
		$this->sign_in( 'administrator' );
		$id = $this->create()['id'];

		// What a page view reads holds no boundary coordinates.
		$stored = (string) get_post_meta( $id, Map_Post_Type::META_DOC, true );
		$this->assertStringNotContainsString( 'coordinates', $stored );
		$this->assertLessThan( 12000, strlen( $stored ) );
		$drawn = Map_Post_Type::get( $id );
		$this->assertArrayNotHasKey( 'geometry', $drawn['zones'][0] );
		$this->assertTrue( $drawn['zones'][0]['built'] );
		$this->assertSame( 3, substr_count( Map_Post_Type::svg( $id ), 'class="mappulse__zone ' ) );

		// The builder still gets them back.
		$this->assertStringContainsString( 'coordinates', (string) get_post_meta( $id, Map_Post_Type::META_SOURCES, true ) );
		$full = Map_Post_Type::get_full( $id );
		$this->assertSame( $this->built()['zones'][0]['geometry']['coordinates'][0][0], $full['zones'][0]['geometry']['coordinates'][0][0] );
	}

	public function test_backdrop_clean_and_hidden_survive_saving_and_reopening() {
		$this->sign_in( 'administrator' );
		$doc                         = $this->built();
		$doc['zones'][0]['backdrop'] = true;
		$doc['zones'][2]['clean']    = true;
		$doc['zones'][1]['hidden']   = true;
		unset( $doc['topology']['zones']['marina'] );
		$doc['priority'] = array( 'county', 'zip', 'city', 'neighborhood' );

		$id   = $this->call( 'POST', '/maps', array( 'document' => $doc ) )->get_data()['id'];
		$read = $this->call( 'GET', '/maps/' . $id )->get_data()['document'];

		$this->assertTrue( $read['zones'][0]['backdrop'] );
		$this->assertTrue( $read['zones'][2]['clean'] );
		$this->assertTrue( $read['zones'][1]['hidden'] );
		$this->assertSame( 'MultiPolygon', $read['zones'][1]['geometry']['type'] );
		$this->assertSame( array( 'county', 'zip', 'city', 'neighborhood' ), $read['priority'] );
		// The hidden zone is stored, and the front end draws the other two.
		$this->assertSame( 2, substr_count( Map_Post_Type::svg( $id ), 'class="mappulse__zone ' ) );
		$this->assertStringNotContainsString( 'Marina District', mappulse_render_map( $id ) );
	}

	public function test_replacing_a_builder_map_with_an_imported_document_drops_its_stored_boundaries() {
		$this->sign_in( 'administrator' );
		$id = $this->create()['id'];
		Map_Post_Type::save( $this->doc(), $id );
		$this->assertSame( '', (string) get_post_meta( $id, Map_Post_Type::META_SOURCES, true ) );
		$this->assertArrayNotHasKey( 'geometry', Map_Post_Type::get_full( $id )['zones'][0] );
	}
}
