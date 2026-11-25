<?php
use MapPulse\Map_Document;
use MapPulse\Map_Post_Type;
use MapPulse\Renderer;

/**
 * 0.5.0: the hover card.
 */
class Test_Card extends MapPulse_Test_Case {

	private function with( callable $change ) {
		$raw = $this->fixture();
		$change( $raw );
		return Map_Document::sanitize( $raw );
	}

	private function section( array $doc, array $args = array() ): string {
		return Renderer::section( $doc, Renderer::svg( $doc ), $args );
	}

	private function card(): array {
		return array(
			'tagline' => 'Waterfront living, <b>walkable</b> streets',
			'stats'   => array(
				array( 'label' => 'Median price', 'value' => '$640,000' ),
				array( 'label' => 'Homes for sale', 'value' => '112' ),
				array( 'label' => 'Third', 'value' => 'dropped' ),
			),
			'buttons' => array(
				array( 'label' => 'Search homes', 'url' => 'https://example.org/idx/?city=downtown' ),
				array( 'label' => 'Bad', 'url' => 'javascript:alert(1)' ),
				array( 'label' => 'Area guide', 'url' => 'https://example.org/guide/' ),
				array( 'label' => 'Fourth', 'url' => 'https://example.org/4/' ),
			),
		);
	}

	private function image(): int {
		$file = DIR_TESTDATA . '/images/canola.jpg';
		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'The WordPress test images are not available here.' );
		}
		return self::factory()->attachment->create_upload_object( $file );
	}

	public function test_a_map_without_cards_is_drawn_exactly_as_before() {
		$doc  = $this->doc();
		$html = $this->section( $doc );
		$this->assertStringNotContainsString( 'mappulse__card', $html );
		$this->assertStringNotContainsString( 'aria-describedby', $html );
		$this->assertStringNotContainsString( '-card-', $html );
		$this->assertStringEndsWith( '</svg></div></section>', $html );
		$this->assertSame( array( 'id', 'name', 'type', 'source', 'link', 'text' ), array_keys( $doc['zones'][0] ) );
		$this->assertSame( file_get_contents( __DIR__ . '/fixtures/sample.svg' ), Renderer::svg( $doc ) );
	}

	public function test_a_card_is_cleaned_and_capped() {
		$doc  = $this->with(
			function ( &$raw ) {
				$raw['zones'][0]['card'] = $this->card();
			}
		);
		$card = $doc['zones'][0]['card'];
		$this->assertSame( 'Waterfront living, walkable streets', $card['tagline'] );
		$this->assertCount( 2, $card['stats'] );
		$this->assertSame( array( 'Search homes', 'Area guide' ), wp_list_pluck( $card['buttons'], 'label' ) );
		$this->assertSame( 0, $card['image'] );

		$long = $this->with(
			static function ( &$raw ) {
				$raw['zones'][0]['card'] = array( 'tagline' => str_repeat( 'a', 300 ), 'stats' => array( array( 'label' => str_repeat( 'b', 80 ), 'value' => str_repeat( 'c', 80 ) ) ) );
			}
		);
		$this->assertSame( 120, strlen( $long['zones'][0]['card']['tagline'] ) );
		$this->assertSame( 24, strlen( $long['zones'][0]['card']['stats'][0]['label'] ) );
		$this->assertSame( 24, strlen( $long['zones'][0]['card']['stats'][0]['value'] ) );
	}

	public function test_a_fact_without_a_value_is_not_stored() {
		$doc = $this->with(
			static function ( &$raw ) {
				$raw['zones'][0]['card'] = array(
					'tagline' => 'Kept',
					'stats'   => array(
						array(
							'label' => 'Median price',
							'value' => '',
						),
						array(
							'label' => '',
							'value' => '112',
						),
					),
				);
			}
		);
		$this->assertSame(
			array(
				array(
					'label' => '',
					'value' => '112',
				),
			),
			$doc['zones'][0]['card']['stats']
		);

		$only_names = $this->with(
			static function ( &$raw ) {
				$raw['zones'][0]['card'] = array(
					'stats' => array(
						array(
							'label' => 'Median price',
							'value' => '',
						),
					),
				);
			}
		);
		$this->assertArrayNotHasKey( 'card', $only_names['zones'][0] );
	}

	public function test_an_empty_card_is_not_stored() {
		$doc = $this->with(
			static function ( &$raw ) {
				$raw['zones'][0]['card'] = array( 'tagline' => '  ', 'stats' => array( array( 'label' => '', 'value' => '' ) ), 'buttons' => array( array( 'label' => 'No address', 'url' => '' ) ), 'image' => 'x' );
				$raw['zones'][1]['card'] = 'nope';
			}
		);
		$this->assertArrayNotHasKey( 'card', $doc['zones'][0] );
		$this->assertArrayNotHasKey( 'card', $doc['zones'][1] );
	}

	public function test_the_card_is_real_markup_in_order_and_escaped() {
		$doc  = $this->with(
			function ( &$raw ) {
				$raw['zones'][0]['card'] = $this->card();
			}
		);
		$html = $this->section( $doc );
		$this->assertSame( 1, substr_count( $html, 'class="mappulse__card"' ) );
		preg_match( '/<div class="mappulse__card" id="([a-z0-9-]+)" data-zone="downtown" hidden>(.*?)<\/div><\/div><\/section>$/s', $html, $m );
		$this->assertNotEmpty( $m, $html );
		$card = $m[2];
		$this->assertStringContainsString( '<h3 class="mappulse__card-title">Downtown</h3>', $card );
		$this->assertStringContainsString( '<p class="mappulse__card-tagline">Waterfront living, walkable streets</p>', $card );
		$this->assertStringContainsString( '<dt>Median price</dt><dd>$640,000</dd>', $card );
		$this->assertStringContainsString( '<a class="mappulse__card-button" href="https://example.org/idx/?city=downtown">Search homes</a>', $card );
		$this->assertStringNotContainsString( 'javascript', $card );
		$this->assertLessThan( strpos( $card, 'mappulse__card-tagline' ), strpos( $card, 'mappulse__card-title' ) );
		$this->assertLessThan( strpos( $card, '<dl' ), strpos( $card, 'mappulse__card-tagline' ) );
		$this->assertLessThan( strpos( $card, 'mappulse__card-actions' ), strpos( $card, '<dl' ) );

		// The area's link, on the map and in the list, is described by its card.
		$this->assertSame( 2, substr_count( $html, 'aria-describedby="' . $m[1] . '"' ) );
		$this->assertMatchesRegularExpression( '/<a class="mappulse__zone"[^>]* data-zone="downtown" aria-describedby="' . $m[1] . '"/', $html );
	}

	public function test_an_area_with_a_link_and_no_buttons_gets_a_view_area_button() {
		$doc = $this->with(
			static function ( &$raw ) {
				$raw['zones'][0]['link'] = 'https://example.org/downtown/';
				$raw['zones'][0]['card'] = array( 'tagline' => 'Hello' );
			}
		);
		$this->assertStringContainsString( '<a class="mappulse__card-button" href="https://example.org/downtown/">View area</a>', $this->section( $doc ) );
	}

	public function test_an_area_without_a_link_can_still_be_reached_by_keyboard_when_it_has_a_card() {
		$doc  = $this->with(
			static function ( &$raw ) {
				$raw['zones'][0]['link'] = '';
				$raw['zones'][0]['card'] = array( 'tagline' => 'Hello' );
			}
		);
		$html = $this->section( $doc );
		$this->assertMatchesRegularExpression( '/<g class="mappulse__zone mappulse__zone--static" tabindex="0"[^>]* data-zone="downtown" aria-describedby="[a-z0-9-]+"/', $html );
		$this->assertStringNotContainsString( 'mappulse__card-actions', $html );
	}

	public function test_a_button_to_a_page_follows_the_page() {
		$this->set_permalink_structure( '/%postname%/' );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'guide' ) );
		$doc  = $this->with(
			static function ( &$raw ) use ( $page ) {
				$raw['zones'][0]['card'] = array( 'buttons' => array( array( 'label' => 'Guide', 'url' => 'https://example.org/stored/', 'post' => $page ) ) );
			}
		);
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $page ) ) . '">Guide</a>', $this->section( $doc ) );
		wp_trash_post( $page );
		$this->assertStringContainsString( 'href="https://example.org/stored/">Guide</a>', $this->section( $doc ) );
	}

	public function test_a_photo_is_lazy_sized_and_dropped_when_gone() {
		$image = $this->image();
		$doc   = $this->with(
			static function ( &$raw ) use ( $image ) {
				$raw['zones'][0]['card'] = array( 'image' => $image );
			}
		);
		$html = $this->section( $doc );
		$this->assertMatchesRegularExpression( '/<img[^>]+class="mappulse__card-image"/', $html );
		$this->assertMatchesRegularExpression( '/<img[^>]+loading="lazy"/', $html );
		$this->assertMatchesRegularExpression( '/<img[^>]+width="\d+"[^>]+height="\d+"/', $html );

		wp_delete_attachment( $image, true );
		$gone = $this->section( $doc );
		$this->assertStringNotContainsString( '<img', $gone );
		// Nothing else is in this card, and the sample zone links nowhere, so no card is printed at all.
		$this->assertStringNotContainsString( 'mappulse__card', $gone );

		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$not_img = $this->with(
			static function ( &$raw ) use ( $page ) {
				$raw['zones'][0]['card'] = array( 'image' => $page, 'tagline' => 'Hi' );
			}
		);
		$this->assertStringNotContainsString( '<img', $this->section( $not_img ) );
	}

	public function test_cards_can_be_switched_off_for_one_placement() {
		$doc = $this->with(
			function ( &$raw ) {
				$raw['zones'][0]['card'] = $this->card();
			}
		);
		$off = $this->section( $doc, array( 'cards' => false ) );
		$this->assertStringNotContainsString( 'mappulse__card', $off );
		$this->assertStringNotContainsString( 'aria-describedby', $off );

		$id = Map_Post_Type::save( $doc );
		$this->assertStringContainsString( 'mappulse__card', do_shortcode( '[mappulse id="' . $id . '"]' ) );
		$this->assertStringNotContainsString( 'mappulse__card', do_shortcode( '[mappulse id="' . $id . '" cards="no"]' ) );
		$this->assertStringNotContainsString( 'mappulse__card', render_block( array( 'blockName' => 'agentview/mappulse', 'attrs' => array( 'mapId' => $id, 'cards' => false ) ) ) );
		$this->assertStringContainsString( 'mappulse__card', render_block( array( 'blockName' => 'agentview/mappulse', 'attrs' => array( 'mapId' => $id ) ) ) );
	}

	public function test_two_maps_on_one_page_keep_their_cards_apart() {
		$doc = $this->with(
			function ( &$raw ) {
				$raw['zones'][0]['card'] = $this->card();
			}
		);
		preg_match( '/class="mappulse__card" id="([a-z0-9-]+)"/', $this->section( $doc ), $a );
		preg_match( '/class="mappulse__card" id="([a-z0-9-]+)"/', $this->section( $doc ), $b );
		$this->assertNotSame( $a[1], $b[1] );
	}

	public function test_card_colors_reach_the_section_only_when_changed() {
		$plain = $this->doc();
		$this->assertSame( '#FFFFFF', $plain['style']['cardBackground'] );
		$this->assertSame( '', $plain['style']['cardButton'] );
		$this->assertStringNotContainsString( '--mappulse-doc-card', $this->section( $plain ) );

		$doc = $this->with(
			static function ( &$raw ) {
				$raw['style']['cardBackground'] = '#101820';
				$raw['style']['cardText']       = '#ffffff';
				$raw['style']['cardButton']     = 'red;';
			}
		);
		$html = $this->section( $doc );
		$this->assertStringContainsString( '--mappulse-doc-card-background:#101820', $html );
		$this->assertStringContainsString( '--mappulse-doc-card-text:#ffffff', $html );
		$this->assertStringNotContainsString( '--mappulse-doc-card-button:', $html );
	}
}
