<?php
use MapPulse\Map_Document;

class Test_Map_Document extends MapPulse_Test_Case {

	public function test_valid_document_passes_and_keeps_zone_order() {
		$doc = Map_Document::sanitize( $this->fixture() );
		$this->assertIsArray( $doc );
		$this->assertSame( array( 'downtown', 'old-northeast', 'historic-kenwood' ), wp_list_pluck( $doc['zones'], 'id' ) );
	}

	public function test_wrong_schema_is_rejected() {
		$raw           = $this->fixture();
		$raw['schema'] = 2;
		$this->assertSame( 'mappulse_schema', Map_Document::sanitize( $raw )->get_error_code() );
	}

	public function test_path_data_with_other_characters_is_rejected() {
		$raw                                      = $this->fixture();
		$raw['topology']['zones']['downtown']['d'] = 'M0 0L5 5Z"/><script>alert(1)</script>';
		$this->assertSame( 'mappulse_bad_path', Map_Document::sanitize( $raw )->get_error_code() );
	}

	public function test_text_and_links_are_cleaned() {
		$raw                     = $this->fixture();
		$raw['zones'][0]['name'] = 'Down<script>x</script>town';
		$raw['zones'][0]['link'] = 'javascript:alert(1)';
		$raw['style']['zone']    = 'red;background:url(x)';
		$doc                     = Map_Document::sanitize( $raw );
		$this->assertSame( 'Downtown', $doc['zones'][0]['name'] );
		$this->assertSame( '', $doc['zones'][0]['link'] );
		$this->assertSame( '#DCCFB6', $doc['style']['zone'] );
	}

	public function test_duplicate_zone_ids_are_rejected() {
		$raw                   = $this->fixture();
		$raw['zones'][1]['id'] = 'downtown';
		$this->assertSame( 'mappulse_duplicate_zone', Map_Document::sanitize( $raw )->get_error_code() );
	}

	public function test_zone_without_shape_is_rejected() {
		$raw = $this->fixture();
		unset( $raw['topology']['zones']['downtown'] );
		$this->assertSame( 'mappulse_zone_shape', Map_Document::sanitize( $raw )->get_error_code() );
	}

	public function test_frame_outside_limits_is_rejected() {
		$raw                   = $this->fixture();
		$raw['frame']['width'] = 90000;
		$this->assertSame( 'mappulse_frame', Map_Document::sanitize( $raw )->get_error_code() );
	}

	private function with_geometry( $geometry ): array {
		$raw                         = $this->fixture();
		$raw['zones'][0]['geometry'] = $geometry;
		return $raw;
	}

	private function square(): array {
		return array(
			'type'        => 'Polygon',
			'coordinates' => array( array( array( -82.7, 27.7 ), array( -82.6, 27.7 ), array( -82.6, 27.8 ), array( -82.7, 27.7 ) ) ),
		);
	}

	public function test_a_map_made_before_the_builder_gains_no_new_keys() {
		$doc = Map_Document::sanitize( $this->fixture() );
		$this->assertArrayNotHasKey( 'priority', $doc );
		$this->assertSame( array( 'id', 'name', 'type', 'source', 'link', 'text' ), array_keys( $doc['zones'][0] ) );
	}

	public function test_source_geometry_keeps_only_type_and_coordinates() {
		$geometry        = $this->square();
		$geometry['crs'] = array( 'evil' => '<script>' );
		$doc             = Map_Document::sanitize( $this->with_geometry( $geometry ) );
		$this->assertSame( array( 'type', 'coordinates' ), array_keys( $doc['zones'][0]['geometry'] ) );
		$this->assertSame( -82.7, $doc['zones'][0]['geometry']['coordinates'][0][0][0] );
	}

	public function test_source_geometry_with_a_text_coordinate_is_rejected() {
		$geometry                            = $this->square();
		$geometry['coordinates'][0][1][0] = '<b>';
		$this->assertSame( 'mappulse_zone_geometry', Map_Document::sanitize( $this->with_geometry( $geometry ) )->get_error_code() );
	}

	public function test_source_geometry_of_another_type_is_rejected() {
		$error = Map_Document::sanitize( $this->with_geometry( array( 'type' => 'Point', 'coordinates' => array( 1, 2 ) ) ) );
		$this->assertSame( 'mappulse_zone_geometry', $error->get_error_code() );
	}

	public function test_an_oversized_source_geometry_is_rejected_by_name() {
		$geometry = $this->square();
		$ring     = array();
		for ( $i = 0; $i < 30000; $i++ ) {
			$ring[] = array( -82.123456 + $i / 1e6, 27.123456 );
		}
		$geometry['coordinates'] = array( $ring );
		$error                   = Map_Document::sanitize( $this->with_geometry( $geometry ) );
		$this->assertSame( 'mappulse_zone_too_large', $error->get_error_code() );
		$this->assertStringContainsString( 'Downtown', $error->get_error_message() );
	}

	public function test_backdrop_and_clean_become_booleans_only_with_geometry() {
		$raw                         = $this->with_geometry( $this->square() );
		$raw['zones'][0]['backdrop'] = 'yes';
		$doc                         = Map_Document::sanitize( $raw );
		$this->assertTrue( $doc['zones'][0]['backdrop'] );
		$this->assertFalse( $doc['zones'][0]['clean'] );
	}

	public function test_a_bad_priority_falls_back_to_the_default_order() {
		$raw             = $this->fixture();
		$raw['priority'] = array( 'zip', 'zip', 'city', 'county' );
		$this->assertSame( array( 'neighborhood', 'city', 'zip', 'county' ), Map_Document::sanitize( $raw )['priority'] );
		$raw['priority'] = array( 'county', 'zip', 'city', 'neighborhood' );
		$this->assertSame( array( 'county', 'zip', 'city', 'neighborhood' ), Map_Document::sanitize( $raw )['priority'] );
	}
}
