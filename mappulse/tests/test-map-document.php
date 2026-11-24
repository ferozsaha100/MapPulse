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
}
