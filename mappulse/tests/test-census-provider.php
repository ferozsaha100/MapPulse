<?php
use MapPulse\Boundaries\Census_Provider;

class Test_Census_Provider extends MapPulse_Boundary_Test_Case {

	/** @var Census_Provider */
	private $census;

	public function set_up() {
		parent::set_up();
		$this->census = new Census_Provider();
		$this->http->fixture( 'GEOID%3D%271228175%27', 'census-place-shape.json' )
			->fixture( '/28/query', 'census-places-gulfport.json' )
			->fixture( '/2/query', 'census-zcta-33701.json' )
			->fixture( '/82/query', 'census-counties-pinellas.json' )
			->fixture( 'MapServer?f=json', 'layers.json' );
	}

	public function test_city_search_returns_typed_results_with_state_as_parent() {
		$results = $this->census->search( 'gulfport', array( 'state' => 'FL', 'type' => 'city' ) );
		$this->assertSame(
			array(
				array(
					'source' => 'census',
					'record' => 'place:1228175',
					'name'   => 'Gulfport',
					'type'   => 'city',
					'parent' => 'Florida',
					'center' => array( -82.7104, 27.7462 ),
				),
			),
			$results
		);
		$query = implode( ' ', $this->http->requests );
		$this->assertStringContainsString( 'UPPER%28BASENAME%29+LIKE+%27GULFPORT%25%27', $query );
		$this->assertStringContainsString( 'STATE%3D%2712%27', $query );
	}

	public function test_a_name_search_covers_cities_and_counties() {
		$types = wp_list_pluck( $this->census->search( 'pinellas', array() ), 'type' );
		$this->assertContains( 'county', $types );
		$this->assertSame( 0, $this->http->count( '/2/query' ) );
	}

	public function test_five_digits_search_zip_areas_only() {
		$results = $this->census->search( '33701', array() );
		$this->assertCount( 1, $results );
		$this->assertSame( 'zip', $results[0]['type'] );
		$this->assertSame( 'zcta:33701', $results[0]['record'] );
		$this->assertSame( 1, $this->http->count( '/query' ) );
	}

	public function test_type_filter_limits_the_layers_queried() {
		$results = $this->census->search( 'pinellas', array( 'type' => 'county' ) );
		$this->assertSame( 'Pinellas County', $results[0]['name'] );
		$this->assertSame( 'county', $results[0]['type'] );
		$this->assertSame( 1, $this->http->count( '/query' ) );
		$this->assertSame( 1, $this->http->count( '/82/query' ) );
	}

	public function test_a_contains_search_follows_an_empty_starts_with_search() {
		$this->http->reset()->fixture( '%27%25PORT%25%27', 'census-places-gulfport.json' )->fixture( '/query', 'empty.json' )->fixture( 'MapServer?f=json', 'layers.json' );
		$results = $this->census->search( 'port', array( 'type' => 'city' ) );
		$this->assertSame( 'Gulfport', $results[0]['name'] );
	}

	public function test_city_search_also_finds_unincorporated_places() {
		$this->http->reset()->fixture( 'MapServer/30/query', 'census-cdp-fisher.json' )->fixture( '/28/query', 'empty.json' )->fixture( 'MapServer?f=json', 'layers.json' );
		$results = $this->census->search( 'Fisher Island', array( 'type' => 'city', 'state' => 'FL' ) );
		$this->assertCount( 1, $results );
		$this->assertSame( 'Fisher Island', $results[0]['name'] );
		$this->assertSame( 'city', $results[0]['type'] );
		$this->assertSame( 'cdp:1222575', $results[0]['record'] );
		$this->assertSame( 'Florida', $results[0]['parent'] );
	}

	public function test_an_unincorporated_place_can_be_fetched() {
		$this->http->reset()->fixture( 'MapServer/30/query', 'census-cdp-shape.json' )->fixture( 'MapServer?f=json', 'layers.json' );
		$feature = $this->census->fetch( 'cdp:1222575' );
		$this->assertSame( 'city', $feature['properties']['type'] );
		$this->assertSame( 'Fisher Island', $feature['properties']['name'] );
		$this->assertSame( array( 'city', 'zip', 'county' ), $this->census->types() );
	}

	public function test_unknown_state_is_rejected() {
		$this->assertSame( 'mappulse_bad_state', $this->census->search( 'gulfport', array( 'state' => 'ZZ' ) )->get_error_code() );
	}

	public function test_short_or_empty_text_is_rejected() {
		$this->assertSame( 'mappulse_bad_query', $this->census->search( 'g', array() )->get_error_code() );
		$this->assertSame( 'mappulse_bad_query', $this->census->search( '  ', array() )->get_error_code() );
		$this->assertSame( 'mappulse_bad_query', $this->census->search( '%%%%', array() )->get_error_code() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_search_results_are_cached() {
		$this->census->search( 'gulfport', array( 'type' => 'city' ) );
		$before = $this->http->count();
		$this->census->search( 'Gulfport ', array( 'type' => 'city' ) );
		$this->assertSame( $before, $this->http->count() );
	}

	public function test_fetch_returns_a_feature_and_caches_it() {
		$feature = $this->census->fetch( 'place:1228175' );
		$this->assertSame( 'Feature', $feature['type'] );
		$this->assertSame(
			array(
				'source' => 'census',
				'record' => 'place:1228175',
				'name'   => 'Gulfport',
				'type'   => 'city',
			),
			$feature['properties']
		);
		$this->assertSame( 'Polygon', $feature['geometry']['type'] );
		$before = $this->http->count();
		$this->census->fetch( 'place:1228175' );
		$this->assertSame( $before, $this->http->count() );
	}

	public function test_fetch_rejects_a_malformed_record() {
		$this->assertSame( 'mappulse_bad_record', $this->census->fetch( "place:12'; DROP" )->get_error_code() );
		$this->assertSame( 'mappulse_bad_record', $this->census->fetch( 'road:1' )->get_error_code() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_fetch_of_an_unknown_record_is_not_found_and_not_cached() {
		$this->http->reset()->fixture( '/query', 'empty.json' )->fixture( 'MapServer?f=json', 'layers.json' );
		$this->assertSame( 'mappulse_not_found', $this->census->fetch( 'place:9999999' )->get_error_code() );
		$this->assertSame( 'mappulse_not_found', $this->census->fetch( 'place:9999999' )->get_error_code() );
		$this->assertSame( 2, $this->http->count( '/query' ) );
	}
}
