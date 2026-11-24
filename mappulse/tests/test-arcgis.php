<?php
use MapPulse\Boundaries\Arcgis;

class Test_Arcgis extends MapPulse_Boundary_Test_Case {

	const SERVICE = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Current/MapServer';

	public function set_up() {
		parent::set_up();
		$this->http->fixture( 'resultOffset=2', 'places-page-2.json' )
			->fixture( '/28/query', 'places-page-1.json' )
			->fixture( 'MapServer/22?f=json', 'stpete-layer-info.json' )
			->fixture( 'MapServer?f=json', 'layers.json' );
	}

	public function test_clean_escapes_and_strips() {
		$this->assertSame( "ST. PETE''S", Arcgis::clean( "st. pete's" ) );
		$this->assertSame( "O''BRIEN", Arcgis::clean( "O'Brien" ) );
		$this->assertSame( 'A B', Arcgis::clean( 'a%_;" b' ) );
		$this->assertSame( 60, strlen( Arcgis::clean( str_repeat( 'x', 500 ) ) ) );
	}

	public function test_clean_keeps_accented_letters() {
		$this->assertSame( 'ESPAÑOLA', Arcgis::clean( 'Española' ) );
	}

	public function test_layer_is_found_by_name_then_by_fallback() {
		$this->assertSame( 28, Arcgis::find_layer( self::SERVICE, '/^incorporated places$/i', null ) );
		$this->assertSame( 7, Arcgis::find_layer( self::SERVICE, '/^no such layer$/i', 7 ) );
		$this->assertWPError( Arcgis::find_layer( self::SERVICE, '/^no such layer$/i', null ) );
	}

	public function test_group_layers_are_never_picked() {
		$this->assertSame( 28, Arcgis::find_layer( self::SERVICE, '/places$/i', null ) );
	}

	public function test_layer_list_is_requested_once() {
		Arcgis::find_layer( self::SERVICE, '/^counties$/i', null );
		Arcgis::find_layer( self::SERVICE, '/^incorporated places$/i', null );
		$this->assertSame( 1, $this->http->count( 'MapServer?f=json' ) );
	}

	public function test_layer_info_reports_geometry_fields_and_id_field() {
		$info = Arcgis::layer_info( 'https://tigerweb.geo.census.gov/arcgis/rest/services/X/MapServer/22' );
		$this->assertSame( 'esriGeometryPolygon', $info['geometryType'] );
		$this->assertSame( 'esriFieldTypeString', $info['fields']['NANAME'] );
		$this->assertSame( 'OBJECTID', $info['id_field'] );
	}

	public function test_query_follows_paging_and_merges_features() {
		$result = Arcgis::query( self::SERVICE . '/28', array( 'where' => "BASENAME LIKE 'GULF%'" ) );
		$this->assertSame( 'FeatureCollection', $result['type'] );
		$this->assertCount( 3, $result['features'] );
		$this->assertStringContainsString( 'resultOffset=2', $this->http->requests[1] );
		$this->assertStringContainsString( 'f=geojson', $this->http->requests[0] );
		$this->assertStringContainsString( 'outSR=4326', $this->http->requests[0] );
	}

	public function test_query_stops_at_the_feature_cap() {
		$this->assertSame( 'mappulse_too_many_features', Arcgis::query( self::SERVICE . '/28', array( 'where' => '1=1' ), 2 )->get_error_code() );
	}
}
