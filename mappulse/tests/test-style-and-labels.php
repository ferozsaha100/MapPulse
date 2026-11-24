<?php
use MapPulse\Map_Document;
use MapPulse\Map_Post_Type;
use MapPulse\Renderer;

/**
 * 0.4.0: border and hover style values, hand-set labels, and links that follow their page.
 */
class Test_Style_And_Labels extends MapPulse_Test_Case {

	private function with( callable $change ): array {
		$raw = $this->fixture();
		$change( $raw );
		return Map_Document::sanitize( $raw );
	}

	private function section( array $doc ): string {
		return Renderer::section( $doc, Renderer::svg( $doc ), array() );
	}

	public function test_an_untouched_map_is_drawn_exactly_as_before() {
		$doc = $this->doc();
		$this->assertSame( file_get_contents( __DIR__ . '/fixtures/sample.svg' ), Renderer::svg( $doc ) );
		$html = $this->section( $doc );
		$this->assertStringNotContainsString( 'stroke-width', $html );
		$this->assertStringNotContainsString( '-dash', $html );
		$this->assertStringNotContainsString( 'mappulse--hover-outline', $html );
		$this->assertSame( array( 'id', 'name', 'type', 'source', 'link', 'text' ), array_keys( $doc['zones'][0] ) );
		$this->assertSame( 'atlas', $doc['style']['preset'] );
		$this->assertSame( 1.6, $doc['style']['zoneStrokeWidth'] );
		$this->assertSame( 'solid', $doc['style']['zoneStrokeStyle'] );
		$this->assertSame( 'fill', $doc['style']['hoverEffect'] );
		$this->assertSame( '', $doc['style']['brand'] );
	}

	public function test_border_and_hover_values_reach_the_section() {
		$doc = $this->with(
			static function ( &$raw ) {
				$raw['style']['zoneStrokeStyle'] = 'dashed';
				$raw['style']['zoneStrokeWidth'] = 9;
				$raw['style']['hoverEffect']     = 'outline';
				$raw['style']['preset']          = 'brand-tint';
				$raw['style']['brand']           = '#1f3a5f';
			}
		);
		$html = $this->section( $doc );
		$this->assertStringContainsString( '--mappulse-doc-zone-dash:6 4', $html );
		$this->assertStringContainsString( '--mappulse-doc-zone-stroke-width:4', $html );
		$this->assertStringContainsString( 'mappulse--hover-outline', $html );
		$this->assertSame( 'brand-tint', $doc['style']['preset'] );
		$this->assertSame( '#1f3a5f', $doc['style']['brand'] );
	}

	public function test_bad_style_values_fall_back() {
		$doc = $this->with(
			static function ( &$raw ) {
				$raw['style']['preset']          = 'evil"><script>';
				$raw['style']['zoneStrokeStyle'] = 'url(x)';
				$raw['style']['zoneStrokeWidth'] = 'wide';
				$raw['style']['hoverEffect']     = array( 'x' );
				$raw['style']['brand']           = 'red;';
			}
		);
		$this->assertSame( 'atlas', $doc['style']['preset'] );
		$this->assertSame( 'solid', $doc['style']['zoneStrokeStyle'] );
		$this->assertSame( 1.6, $doc['style']['zoneStrokeWidth'] );
		$this->assertSame( 'fill', $doc['style']['hoverEffect'] );
		$this->assertSame( '', $doc['style']['brand'] );
		$this->assertStringContainsString( '--mappulse-doc-zone-dash:1.5 3.5', $this->section( $this->with( static function ( &$raw ) { $raw['style']['zoneStrokeStyle'] = 'dotted'; } ) ) );
	}

	public function test_a_custom_label_replaces_the_name_and_breaks_over_two_lines() {
		$doc = $this->with(
			static function ( &$raw ) {
				$raw['zones'][0]['label'] = array( 'text' => 'Fisher <b>Island</b> Club' );
			}
		);
		$this->assertSame( 'Fisher Island Club', $doc['zones'][0]['label']['text'] );
		$svg = Renderer::svg( $doc );
		preg_match( '/<text class="mappulse__label" data-zone="downtown"[^>]*>(.*?)<\/text>/', $svg, $m );
		$this->assertSame( 2, substr_count( $m[1], '<tspan' ) );
		$this->assertStringContainsString( '>FISHER</tspan>', $m[1] );
		$this->assertStringContainsString( '>ISLAND CLUB</tspan>', $m[1] );
		// The accessible name and the list keep the area's own name.
		$this->assertStringContainsString( 'aria-label="Downtown"', $svg );
	}

	public function test_a_moved_label_stays_in_the_frame_and_gets_a_leader() {
		$plain = Renderer::svg( $this->doc() );
		$doc   = $this->with(
			static function ( &$raw ) {
				$raw['zones'][0]['label'] = array( 'dx' => 99999, 'dy' => -60 );
			}
		);
		$svg = Renderer::svg( $doc );
		preg_match( '/data-zone="downtown"[^>]*><tspan x="([0-9.]+)" y="([0-9.]+)"/', $svg, $moved );
		preg_match( '/class="mappulse__label" data-zone="downtown"[^>]*><tspan x="([0-9.]+)" y="([0-9.]+)"/', $plain, $before );
		$this->assertGreaterThan( (float) $before[1], (float) $moved[1] );
		$this->assertLessThan( $doc['frame']['width'], (float) $moved[1] );
		$this->assertLessThan( (float) $before[2], (float) $moved[2] );
		$this->assertSame( substr_count( $plain, '<line' ) + 1, substr_count( $svg, '<line' ) );
		// A small nudge needs no leader.
		$nudged = $this->with( static function ( &$raw ) { $raw['zones'][0]['label'] = array( 'dx' => 2 ); } );
		$this->assertSame( substr_count( $plain, '<line' ), substr_count( Renderer::svg( $nudged ), '<line' ) );
	}

	public function test_a_hidden_label_is_left_out_and_its_zone_stays() {
		$doc = $this->with( static function ( &$raw ) { $raw['zones'][0]['label'] = array( 'hidden' => 1 ); } );
		$svg = Renderer::svg( $doc );
		$this->assertStringNotContainsString( 'class="mappulse__label" data-zone="downtown"', $svg );
		$this->assertStringContainsString( 'data-zone="downtown" aria-label="Downtown"', $svg );
		$this->assertTrue( $doc['zones'][0]['label']['hidden'] );
	}

	public function test_an_empty_label_override_is_not_stored() {
		$doc = $this->with( static function ( &$raw ) { $raw['zones'][0]['label'] = array( 'text' => '  ', 'dx' => 0, 'dy' => 'x' ); } );
		$this->assertArrayNotHasKey( 'label', $doc['zones'][0] );
	}

	public function test_a_link_to_a_page_follows_the_page() {
		$this->set_permalink_structure( '/%postname%/' );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Downtown', 'post_name' => 'downtown' ) );
		$id   = Map_Post_Type::save(
			$this->with(
				static function ( &$raw ) use ( $page ) {
					$raw['zones'][0]['link']     = 'https://example.org/stored/';
					$raw['zones'][0]['linkPost'] = $page;
				}
			)
		);
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $page ) ) . '"', mappulse_render_map( $id ) );
		$this->assertStringNotContainsString( 'example.org/stored', mappulse_render_map( $id ) );

		wp_update_post( array( 'ID' => $page, 'post_name' => 'downtown-st-pete' ) );
		$html = mappulse_render_map( $id );
		$this->assertStringContainsString( 'downtown-st-pete', get_permalink( $page ) );
		$this->assertSame( 2, substr_count( $html, 'href="' . esc_url( get_permalink( $page ) ) . '"' ) );

		wp_trash_post( $page );
		$html = mappulse_render_map( $id );
		$this->assertSame( 2, substr_count( $html, 'href="https://example.org/stored/"' ) );
	}

	public function test_a_link_to_a_draft_or_a_missing_post_uses_the_stored_address() {
		$draft = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		foreach ( array( $draft, 987654 ) as $post_id ) {
			$doc = $this->with(
				static function ( &$raw ) use ( $post_id ) {
					$raw['zones'][0]['link']     = 'https://example.org/stored/';
					$raw['zones'][0]['linkPost'] = $post_id;
				}
			);
			$this->assertStringContainsString( 'href="https://example.org/stored/"', Renderer::svg( $doc ) );
		}
		$none = $this->with( static function ( &$raw ) { $raw['zones'][0]['linkPost'] = 'abc'; } );
		$this->assertArrayNotHasKey( 'linkPost', $none['zones'][0] );
	}

	public function test_a_link_follows_every_kind_of_address_change() {
		$this->set_permalink_structure( '/%year%/%monthnum%/%postname%/' );
		$post = self::factory()->post->create( array( 'post_title' => 'Downtown guide', 'post_name' => 'downtown-guide', 'post_date' => '2020-01-05 10:00:00' ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Old Northeast', 'post_name' => 'old-northeast' ) );
		$id   = Map_Post_Type::save(
			$this->with(
				static function ( &$raw ) use ( $post, $page ) {
					$raw['zones'][0]['link']     = 'https://example.org/stored/';
					$raw['zones'][0]['linkPost'] = $post;
					$raw['zones'][1]['link']     = 'https://example.org/stored-2/';
					$raw['zones'][1]['linkPost'] = $page;
				}
			)
		);
		$both = function ( int $linked ) use ( $id ) {
			// Once in the cached drawing, once in the area list.
			$this->assertSame( 2, substr_count( mappulse_render_map( $id ), 'href="' . esc_url( get_permalink( $linked ) ) . '"' ), get_permalink( $linked ) );
		};
		$both( $post );

		wp_update_post( array( 'ID' => $post, 'post_date' => '2021-03-09 10:00:00', 'post_date_gmt' => '2021-03-09 10:00:00' ) );
		$this->assertStringContainsString( '/2021/03/', get_permalink( $post ) );
		$both( $post );

		$this->set_permalink_structure( '/blog/%postname%/' );
		$this->assertStringContainsString( '/blog/downtown-guide/', get_permalink( $post ) );
		$both( $post );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );
		$this->assertSame( home_url( '/' ), get_permalink( $page ) );
		$both( $page );
		update_option( 'show_on_front', 'posts' );

		// The site address. A filter stands in for the change, because a test site may fix the address in its configuration.
		$moved = static function () {
			return 'http://moved.example';
		};
		add_filter( 'option_home', $moved, 99 );
		$this->assertStringContainsString( 'moved.example', get_permalink( $post ) );
		$both( $post );
		remove_filter( 'option_home', $moved, 99 );
	}

	public function test_unrelated_post_changes_do_not_redraw_maps() {
		$id = Map_Post_Type::save( $this->doc() );
		Map_Post_Type::svg( $id );
		$hash = get_post_meta( $id, Map_Post_Type::META_HASH, true );
		$other = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_publish_post( $other );
		wp_delete_post( $other, true );
		Map_Post_Type::svg( $id );
		$this->assertSame( $hash, get_post_meta( $id, Map_Post_Type::META_HASH, true ) );
		$this->assertFalse( get_option( 'mappulse_link_epoch' ) );
	}
}
