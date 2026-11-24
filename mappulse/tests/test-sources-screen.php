<?php
use MapPulse\Boundaries\Sources;
use MapPulse\Sources_Screen;

class Test_Sources_Screen extends MapPulse_Boundary_Test_Case {

	const LAYER = 'https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/22';

	public function set_up() {
		parent::set_up();
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
		$this->http->fixture( 'MapServer/22?f=json', 'stpete-layer-info.json' )
			->fixture( 'GEOID%3D%271228175%27', 'census-place-shape.json' )
			->fixture( '/28/query', 'census-places-gulfport.json' )
			->fixture( '/82/query', 'census-counties-pinellas.json' )
			->fixture( 'tigerWMS_Current/MapServer?f=json', 'layers.json' );
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	private function page( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		( new Sources_Screen() )->render_page();
		return ob_get_clean();
	}

	private function square(): array {
		return array(
			'type'       => 'Feature',
			'properties' => array( 'name' => 'Square' ),
			'geometry'   => array(
				'type'        => 'Polygon',
				'coordinates' => array( array( array( -82.7, 27.7 ), array( -82.6, 27.7 ), array( -82.6, 27.8 ), array( -82.7, 27.8 ), array( -82.7, 27.7 ) ) ),
			),
		);
	}

	public function test_outline_svg_draws_one_path_inside_the_box() {
		$svg = Sources_Screen::outline_svg( $this->square() );
		$this->assertMatchesRegularExpression( '/^<svg[^>]+viewBox="0 0 420 420"/', $svg );
		$this->assertSame( 1, substr_count( $svg, '<path' ) );
		preg_match( '/ d="([^"]+)"/', $svg, $match );
		preg_match_all( '/-?\d+(\.\d+)?/', $match[1], $numbers );
		$this->assertGreaterThanOrEqual( 8, count( $numbers[0] ) );
		foreach ( $numbers[0] as $number ) {
			$this->assertGreaterThanOrEqual( 0, (float) $number );
			$this->assertLessThanOrEqual( 420, (float) $number );
		}
	}

	public function test_outline_svg_handles_multipolygons_and_holes() {
		$feature             = $this->square();
		$feature['geometry'] = array(
			'type'        => 'MultiPolygon',
			'coordinates' => array(
				array(
					array( array( 0, 0 ), array( 4, 0 ), array( 4, 4 ), array( 0, 4 ), array( 0, 0 ) ),
					array( array( 1, 1 ), array( 2, 1 ), array( 2, 2 ), array( 1, 1 ) ),
				),
				array( array( array( 5, 5 ), array( 6, 5 ), array( 6, 6 ), array( 5, 5 ) ) ),
			),
		);
		$svg                 = Sources_Screen::outline_svg( $feature );
		$this->assertSame( 3, substr_count( $svg, 'M' ) );
		$this->assertStringContainsString( 'fill-rule="evenodd"', $svg );
	}

	public function test_outline_of_an_empty_shape_is_an_empty_string() {
		$feature                            = $this->square();
		$feature['geometry']['coordinates'] = array();
		$this->assertSame( '', Sources_Screen::outline_svg( $feature ) );
	}

	public function test_page_lists_sources_and_the_add_form() {
		Sources::add_layer( self::LAYER, 'St. Petersburg neighborhoods', 'St. Petersburg, FL' );
		$html = $this->page();
		$this->assertStringContainsString( 'US Census Bureau', $html );
		$this->assertStringContainsString( 'St. Petersburg neighborhoods', $html );
		$this->assertStringContainsString( 'name="mappulse_layer_url"', $html );
		$this->assertStringContainsString( 'value="mappulse_add_layer"', $html );
	}

	public function test_search_results_are_listed_with_boundary_links() {
		$html = $this->page( array( 'q' => 'gulfport', 'state' => 'FL', 'type' => 'city' ) );
		$this->assertStringContainsString( 'Gulfport', $html );
		$this->assertStringContainsString( 'Florida', $html );
		$this->assertStringContainsString( 'shape=census%7Cplace%3A1228175', $html );
	}

	public function test_a_chosen_result_shows_its_outline_and_facts() {
		$html = $this->page( array( 'shape' => 'census|place:1228175' ) );
		$this->assertStringContainsString( '<svg class="mappulse-outline"', $html );
		$this->assertStringContainsString( 'openstreetmap.org', $html );
		$this->assertStringContainsString( 'Gulfport', $html );
	}

	public function test_a_failing_source_is_named_on_the_page_and_the_page_still_renders() {
		$this->http->reset()->on( 'tigerweb', 'down', 503 );
		$html = $this->page( array( 'q' => 'gulfport' ) );
		$this->assertStringContainsString( 'US Census Bureau', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'name="mappulse_layer_url"', $html );
	}

	public function test_page_says_when_a_source_has_more_matches_than_it_shows() {
		$features = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$features[] = array(
				'type'       => 'Feature',
				'geometry'   => null,
				'properties' => array(
					'BASENAME' => 'Springfield ' . $i,
					'NAME'     => 'Springfield city',
					'STATE'    => '12',
					'GEOID'    => (string) ( 1200000 + $i ),
				),
			);
		}
		$this->http->reset()->on(
			'/28/query',
			array(
				'type'     => 'FeatureCollection',
				'features' => $features,
			)
		)->fixture( 'tigerWMS_Current/MapServer?f=json', 'layers.json' );
		$html = $this->page( array( 'q' => 'springfield', 'type' => 'city' ) );
		$this->assertStringContainsString( 'Springfield 19', $html );
		$this->assertStringContainsString( 'mappulse-more', $html );
	}

	public function test_page_does_not_claim_more_matches_for_a_narrow_search() {
		$this->assertStringNotContainsString( 'mappulse-more', $this->page( array( 'q' => 'gulfport', 'type' => 'city', 'state' => 'FL' ) ) );
	}

	public function test_search_text_is_escaped_in_the_page() {
		$html = $this->page( array( 'q' => '"><script>x</script>' ) );
		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringNotContainsString( 'value=""><', $html );
	}

	/** Runs an admin-post handler as the current user: where it redirected, or the message it died with. */
	private function post_to( string $method, string $nonce, array $post ): array {
		$_POST                = $post;
		$_REQUEST['_wpnonce'] = $nonce;
		$stop                 = static function ( $location ) {
			throw new RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $stop );
		try {
			( new Sources_Screen() )->$method();
		} catch ( WPDieException $e ) {
			return array( 'died' => $e->getMessage() );
		} catch ( RuntimeException $e ) {
			return array( 'redirect' => $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $stop );
			$_POST = array();
			unset( $_REQUEST['_wpnonce'] );
		}
		return array();
	}

	public function test_adding_a_layer_needs_the_capability_and_the_nonce() {
		$post = array(
			'mappulse_layer_url'   => self::LAYER,
			'mappulse_layer_label' => 'St. Petersburg neighborhoods',
			'mappulse_layer_place' => 'St. Petersburg, FL',
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertStringContainsString( 'not allowed', $this->post_to( 'handle_add', wp_create_nonce( 'mappulse_sources' ), $post )['died'] ?? '' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertArrayHasKey( 'died', $this->post_to( 'handle_add', 'not-a-nonce', $post ) );
		$this->assertSame( array(), Sources::layers() );

		$this->assertStringContainsString( 'mappulse_layer_added=1', $this->post_to( 'handle_add', wp_create_nonce( 'mappulse_sources' ), $post )['redirect'] ?? '' );
		$this->assertCount( 1, Sources::layers() );
	}

	public function test_a_rejected_layer_redirects_with_its_error_code() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = $this->post_to( 'handle_add', wp_create_nonce( 'mappulse_sources' ), array( 'mappulse_layer_url' => 'https://example.com/x.json' ) );
		$this->assertStringContainsString( 'mappulse_error=mappulse_bad_layer_url', $result['redirect'] ?? '' );
	}

	public function test_removing_a_layer_and_clearing_the_cache_need_the_capability() {
		Sources::add_layer( self::LAYER, 'St. Petersburg neighborhoods' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$nonce = wp_create_nonce( 'mappulse_sources' );
		$this->assertArrayHasKey( 'died', $this->post_to( 'handle_remove', $nonce, array( 'mappulse_layer_id' => 'layer:st-petersburg-neighborhoods' ) ) );
		$this->assertArrayHasKey( 'died', $this->post_to( 'handle_flush', $nonce, array() ) );
		$this->assertCount( 1, Sources::layers() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$nonce = wp_create_nonce( 'mappulse_sources' );
		$this->assertArrayHasKey( 'redirect', $this->post_to( 'handle_remove', $nonce, array( 'mappulse_layer_id' => 'layer:st-petersburg-neighborhoods' ) ) );
		$this->assertSame( array(), Sources::layers() );
		$this->assertArrayHasKey( 'redirect', $this->post_to( 'handle_flush', $nonce, array() ) );
	}
}
