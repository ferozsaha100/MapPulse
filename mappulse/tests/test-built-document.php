<?php
use MapPulse\Map_Document;
use MapPulse\Renderer;

/**
 * The document the JavaScript builder writes must be one the PHP side accepts and draws.
 * tests/fixtures/built-document.json is written by the Jest suite (UPDATE_FIXTURES=1 npm run test:js).
 */
class Test_Built_Document extends MapPulse_Test_Case {

	private function built(): array {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/built-document.json' ), true );
	}

	public function test_the_builders_document_passes_the_sanitizer() {
		$doc = Map_Document::sanitize( $this->built() );
		$this->assertIsArray( $doc );
		$this->assertSame( array( 'gulfport', 'marina', '33707' ), wp_list_pluck( $doc['zones'], 'id' ) );
		$this->assertSame( 'Polygon', $doc['zones'][0]['geometry']['type'] );
		$this->assertSame( array( 'neighborhood', 'city', 'zip', 'county' ), $doc['priority'] );
		$this->assertFalse( $doc['zones'][0]['backdrop'] );
	}

	public function test_the_builders_document_renders_three_zones() {
		$svg = Renderer::svg( Map_Document::sanitize( $this->built() ) );
		$this->assertSame( 3, substr_count( $svg, 'class="mappulse__zone ' ) );
		$this->assertStringContainsString( 'GULFPORT', $svg );
	}

	public function test_a_hidden_zone_is_stored_but_not_drawn_or_listed() {
		$raw               = $this->built();
		$raw['zones'][1]['hidden'] = true;
		unset( $raw['topology']['zones']['marina'] );
		$doc = Map_Document::sanitize( $raw );
		$this->assertIsArray( $doc );
		$this->assertTrue( $doc['zones'][1]['hidden'] );
		$svg  = Renderer::svg( $doc );
		$html = Renderer::section( $doc, $svg, array() );
		$this->assertSame( 2, substr_count( $svg, 'class="mappulse__zone ' ) );
		$this->assertStringNotContainsString( 'Marina District', $html );
	}

	public function test_a_zone_without_shape_or_source_geometry_is_still_rejected() {
		$raw = $this->built();
		unset( $raw['topology']['zones']['marina'], $raw['zones'][1]['geometry'] );
		$this->assertSame( 'mappulse_zone_shape', Map_Document::sanitize( $raw )->get_error_code() );
	}
}
