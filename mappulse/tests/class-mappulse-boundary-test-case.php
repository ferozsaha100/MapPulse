<?php
/**
 * Base class for boundary tests: mocked HTTP and a private, empty cache folder.
 */
abstract class MapPulse_Boundary_Test_Case extends MapPulse_Test_Case {

	/** @var MapPulse_Http_Mock */
	protected $http;

	/** @var string */
	protected $cache_dir;

	public function set_up() {
		parent::set_up();
		$this->http      = new MapPulse_Http_Mock();
		// The unincorporated-places layer has nothing to say unless a test gives it a route.
		$this->http->fallback( 'MapServer/30/query', 'empty.json' );
		$this->cache_dir = get_temp_dir() . 'mappulse-test-cache-' . getmypid();
		$dir             = $this->cache_dir;
		add_filter(
			'mappulse_cache_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		\MapPulse\Boundaries\Cache::flush();
		// Each test stands for a new page request: forget which hosts failed in the last one.
		\MapPulse\Boundaries\Http::reset();
	}

	/** Decoded fixture from tests/fixtures/http/. */
	protected function http_fixture( string $file ): array {
		return json_decode( file_get_contents( __DIR__ . '/fixtures/http/' . $file ), true );
	}
}
