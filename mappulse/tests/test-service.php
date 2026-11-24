<?php
use MapPulse\Boundaries\Service;
use MapPulse\Boundaries\Sources;

class Test_Service extends MapPulse_Boundary_Test_Case {

	const LAYER = 'https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/22';
	const ID    = 'layer:st-petersburg-neighborhoods';
	const BOX   = '-82.75,27.72,-82.60,27.82';

	private function search_routes(): void {
		$this->http->reset()
			->fixture( 'MapServer/22?f=json', 'stpete-layer-info.json' )
			->fixture( '/22/query', 'stpete-search-kenwood.json' )
			->fixture( '/28/query', 'census-places-gulfport.json' )
			->fixture( '/82/query', 'census-counties-pinellas.json' )
			->fixture( '/2/query', 'census-zcta-33701.json' )
			->fixture( 'tigerWMS_Current/MapServer?f=json', 'layers.json' );
	}

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

	private function add_layer(): void {
		Sources::add_layer( self::LAYER, 'St. Petersburg neighborhoods', 'St. Petersburg, FL' );
	}

	public function test_search_merges_providers_and_orders_exact_matches_first() {
		$this->search_routes();
		$this->add_layer();
		$found = Service::search( 'gulfport' );
		$this->assertSame( array(), $found['errors'] );
		$this->assertSame( 'Gulfport', $found['results'][0]['name'] );
		$this->assertContains( self::ID, wp_list_pluck( $found['results'], 'source' ) );
		$this->assertContains( 'census', wp_list_pluck( $found['results'], 'source' ) );
	}

	public function test_names_that_start_with_the_text_come_before_names_that_contain_it() {
		$this->search_routes();
		$this->add_layer();
		$names = wp_list_pluck( Service::search( 'kenwood', array( 'type' => 'neighborhood' ) )['results'], 'name' );
		$this->assertSame( array( 'KENWOOD GATEWAY BUSINESS ASSN', 'HISTORIC KENWOOD NBRHD ASSN' ), $names );
	}

	public function test_a_failing_provider_is_reported_and_the_rest_still_answer() {
		$this->search_routes();
		$this->add_layer();
		$this->http->reset()->on( 'egis.stpete.org', 'down', 503 )
			->fixture( '/28/query', 'census-places-gulfport.json' )
			->fixture( '/82/query', 'census-counties-pinellas.json' )
			->fixture( 'tigerWMS_Current/MapServer?f=json', 'layers.json' );
		$found = Service::search( 'gulfport' );
		$this->assertSame( 'Gulfport', $found['results'][0]['name'] );
		$this->assertSame( array( self::ID ), array_keys( $found['errors'] ) );
	}

	public function test_type_filter_skips_providers_that_do_not_offer_it() {
		$this->search_routes();
		$this->add_layer();
		Service::search( 'kenwood', array( 'type' => 'neighborhood' ) );
		$this->assertSame( 0, $this->http->count( 'tigerweb' ) );
	}

	public function test_text_that_is_too_short_is_one_error_and_no_request() {
		$found = Service::search( 'k' );
		$this->assertSame( array(), $found['results'] );
		$this->assertSame( array( 'query' ), array_keys( $found['errors'] ) );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_fetch_from_an_unknown_source_is_an_error() {
		$this->assertSame( 'mappulse_unknown_source', Service::fetch( 'layer:nope', '1' )->get_error_code() );
	}

	public function test_base_returns_all_six_layers_for_a_valid_box() {
		$this->base_routes();
		$this->add_layer();
		$base = Service::base( Service::parse_bbox( self::BOX ) );
		$this->assertSame( array( 'land', 'cities', 'areas', 'water', 'roadsMajor', 'roadsMinor' ), array_keys( $base ) );
		foreach ( $base as $name => $layer ) {
			$this->assertSame( 'FeatureCollection', $layer['type'], $name );
			$this->assertNotEmpty( $layer['features'], $name );
		}
		$this->assertStringContainsString( 'geometryType=esriGeometryEnvelope', implode( ' ', $this->http->requests ) );
		$this->assertStringContainsString( 'maxAllowableOffset', implode( ' ', $this->http->requests ) );
	}

	public function test_base_rejects_malformed_and_oversized_boxes() {
		foreach ( array( 'a,b,c,d', '-82.9,27.6,-82.5', '-82.5,27.6,-82.9,27.9', '-84,27,-82,28', '-82.7,95,-82.6,96' ) as $text ) {
			$this->assertSame( 'mappulse_bad_bbox', Service::parse_bbox( $text )->get_error_code(), $text );
		}
		$this->assertSame( 'mappulse_bad_bbox', Service::base( array( -84, 27, -82, 28 ) )->get_error_code() );
		$this->assertSame( 0, $this->http->count() );
	}

	public function test_base_is_cached_by_rounded_box() {
		$this->base_routes();
		Service::base( Service::parse_bbox( '-82.751,27.721,-82.601,27.819' ) );
		$before = $this->http->count();
		Service::base( Service::parse_bbox( '-82.758,27.728,-82.609,27.811' ) );
		$this->assertSame( $before, $this->http->count() );
	}

	public function test_a_census_failure_fails_the_base_and_caches_nothing() {
		$this->base_routes();
		$this->http->reset()->on( 'tigerweb', 'down', 503 );
		$this->assertWPError( Service::base( Service::parse_bbox( self::BOX ) ) );
		$this->base_routes();
		$this->assertIsArray( Service::base( Service::parse_bbox( self::BOX ) )['water'] );
	}
}
