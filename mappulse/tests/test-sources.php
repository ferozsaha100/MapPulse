<?php
use MapPulse\Boundaries\Http;
use MapPulse\Boundaries\Sources;

class Test_Sources extends MapPulse_Boundary_Test_Case {

	const LAYER = 'https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/22';
	const ID    = 'layer:st-petersburg-neighborhoods';

	public function set_up() {
		parent::set_up();
		$this->http->fixture( 'MapServer/3?f=json', 'layer-point.json' )
			->fixture( 'MapServer/22?f=json', 'stpete-layer-info.json' )
			->fixture( 'OBJECTID%3D41', 'stpete-shape.json' )
			->fixture( '/22/query', 'stpete-search-kenwood.json' );
	}

	private function add() {
		return Sources::add_layer( self::LAYER, 'St. Petersburg neighborhoods', 'St. Petersburg, FL' );
	}

	public function test_census_is_always_the_first_source() {
		$this->assertSame( array( 'census' ), array_map( fn( $p ) => $p->id(), Sources::all() ) );
		$this->add();
		$this->assertSame( array( 'census', self::ID ), array_map( fn( $p ) => $p->id(), Sources::all() ) );
	}

	public function test_adding_a_polygon_layer_stores_it_and_allows_its_host() {
		$this->assertNotContains( 'egis.stpete.org', Http::allowed_hosts() );
		$entry = $this->add();
		$this->assertSame( self::ID, $entry['id'] );
		$this->assertSame( 'NANAME', $entry['name_field'] );
		$this->assertSame( 'OBJECTID', $entry['id_field'] );
		$this->assertContains( 'egis.stpete.org', Http::allowed_hosts() );
		$this->assertSame( 'neighborhood', Sources::get( self::ID )->types()[0] );
	}

	public function test_layer_urls_must_be_https_arcgis_layers() {
		$bad = array(
			'http://egis.stpete.org/arcgis/rest/services/X/MapServer/22',
			'https://egis.stpete.org/arcgis/rest/services/X/MapServer',
			'https://localhost/arcgis/rest/services/X/MapServer/1',
			'https://10.0.0.5/arcgis/rest/services/X/MapServer/1',
			'https://example.com/file.json',
		);
		foreach ( $bad as $url ) {
			$this->assertSame( 'mappulse_bad_layer_url', Sources::add_layer( $url, 'Bad' )->get_error_code(), $url );
		}
		$this->assertSame( array(), Sources::layers() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_point_layers_are_rejected() {
		$error = Sources::add_layer( 'https://egis.stpete.org/arcgis/rest/services/X/MapServer/3', 'Fire stations' );
		$this->assertSame( 'mappulse_not_polygons', $error->get_error_code() );
		$this->assertSame( array(), Sources::layers() );
		$this->assertNotContains( 'egis.stpete.org', Http::allowed_hosts() );
	}

	public function test_the_same_layer_cannot_be_added_twice() {
		$this->add();
		$this->assertSame( 'mappulse_duplicate_layer', $this->add()->get_error_code() );
		$this->assertSame( 'mappulse_duplicate_layer', Sources::add_layer( self::LAYER, 'Another label' )->get_error_code() );
		$this->assertCount( 1, Sources::layers() );
	}

	public function test_layer_search_returns_neighborhood_results() {
		$this->add();
		$results = Sources::get( self::ID )->search( 'kenwood', array() );
		$this->assertSame(
			array(
				'source' => self::ID,
				'record' => '41',
				'name'   => 'HISTORIC KENWOOD NBRHD ASSN',
				'type'   => 'neighborhood',
				'parent' => 'St. Petersburg, FL',
				'center' => null,
			),
			$results[0]
		);
		$this->assertStringContainsString( 'UPPER%28NANAME%29+LIKE+%27%25KENWOOD%25%27', implode( ' ', $this->http->requests ) );
	}

	public function test_layer_search_for_another_type_sends_nothing() {
		$this->add();
		$before = $this->http->count();
		$this->assertSame( array(), Sources::get( self::ID )->search( 'kenwood', array( 'type' => 'city' ) ) );
		$this->assertSame( $before, $this->http->count() );
	}

	public function test_layer_fetch_returns_a_feature() {
		$this->add();
		$feature = Sources::get( self::ID )->fetch( '41' );
		$this->assertSame( 'neighborhood', $feature['properties']['type'] );
		$this->assertSame( 'HISTORIC KENWOOD NBRHD ASSN', $feature['properties']['name'] );
		$this->assertSame( 'Polygon', $feature['geometry']['type'] );
		$this->assertSame( 'mappulse_bad_record', Sources::get( self::ID )->fetch( '41 OR 1=1' )->get_error_code() );
	}

	public function test_removing_a_layer_removes_its_host() {
		$this->add();
		$this->assertTrue( Sources::remove_layer( self::ID ) );
		$this->assertNull( Sources::get( self::ID ) );
		$this->assertNotContains( 'egis.stpete.org', Http::allowed_hosts() );
		$this->assertFalse( Sources::remove_layer( self::ID ) );
	}
}
