<?php
/**
 * Shared helpers for MapPulse tests.
 */

use MapPulse\Map_Document;

abstract class MapPulse_Test_Case extends WP_UnitTestCase {

	/** The raw fixture document, exactly as a map file would hold it. */
	protected function fixture(): array {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/sample.map.json' ), true );
	}

	/** The fixture after sanitizing: what the store keeps and the renderer draws. */
	protected function doc(): array {
		return Map_Document::sanitize( $this->fixture() );
	}
}
