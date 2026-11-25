<?php
use MapPulse\Map_Document;
use MapPulse\Map_Post_Type;

class Test_Map_Store extends MapPulse_Test_Case {

	public function set_up() {
		parent::set_up();
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
	}

	public function test_save_stores_document_svg_and_hash() {
		$id = Map_Post_Type::save( $this->fixture() );
		$this->assertSame( 'mappulse_map', get_post_type( $id ) );
		$this->assertStringStartsWith( '<svg', get_post_meta( $id, '_mappulse_svg', true ) );
		$this->assertNotSame( '', get_post_meta( $id, '_mappulse_hash', true ) );
	}

	public function test_forged_cache_is_not_trusted() {
		$id  = Map_Post_Type::save( $this->fixture() );
		$doc = json_decode( get_post_meta( $id, '_mappulse_map', true ), true );
		update_post_meta( $id, '_mappulse_svg', '<svg><script>alert(1)</script></svg>' );
		update_post_meta( $id, '_mappulse_hash', md5( wp_json_encode( $doc ) . MAPPULSE_VERSION ) );
		$html = mappulse_render_map( $id );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'mappulse__zone', $html );
	}

	public function test_tampered_stored_document_is_cleaned_before_drawing() {
		$id                   = Map_Post_Type::save( $this->fixture() );
		$doc                  = json_decode( get_post_meta( $id, '_mappulse_map', true ), true );
		$doc['style']['zone'] = 'red;background:url(x)';
		$doc['topology']['zones']['downtown']['label']['lines'] = 'DOWNTOWN';
		update_post_meta( $id, '_mappulse_map', wp_slash( wp_json_encode( $doc ) ) );
		$html = mappulse_render_map( $id );
		$this->assertStringNotContainsString( 'background:url', $html );
		$this->assertStringContainsString( 'data-mappulse', $html );
	}

	public function test_stored_document_with_bad_shape_data_renders_nothing() {
		$id  = Map_Post_Type::save( $this->fixture() );
		$doc = json_decode( get_post_meta( $id, '_mappulse_map', true ), true );
		$doc['topology']['zones']['downtown']['d'] = 'M0 0"/><script>alert(1)</script>';
		update_post_meta( $id, '_mappulse_map', wp_slash( wp_json_encode( $doc ) ) );
		$this->assertSame( '', mappulse_render_map( $id ) );
	}

	public function test_invalid_document_creates_no_post() {
		$raw           = $this->fixture();
		$raw['schema'] = 9;
		$this->assertWPError( Map_Post_Type::save( $raw ) );
		$this->assertCount( 0, get_posts( array( 'post_type' => 'mappulse_map', 'post_status' => 'any' ) ) );
	}

	public function test_stale_svg_is_rebuilt() {
		$id = Map_Post_Type::save( $this->fixture() );
		update_post_meta( $id, '_mappulse_hash', 'stale' );
		update_post_meta( $id, '_mappulse_svg', '<svg>old</svg>' );
		$this->assertStringContainsString( 'mappulse__zone', Map_Post_Type::svg( $id ) );
	}

	public function test_render_outputs_section_and_enqueues_assets() {
		$html = mappulse_render_map( Map_Post_Type::save( $this->fixture() ) );
		$this->assertStringContainsString( 'data-mappulse', $html );
		$this->assertTrue( wp_style_is( 'mappulse', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'mappulse', 'enqueued' ) );
	}

	public function test_missing_draft_and_trashed_maps_render_nothing_for_visitors() {
		$draft = Map_Post_Type::save( $this->fixture(), 0, 'draft' );
		$gone  = Map_Post_Type::save( $this->fixture() );
		wp_trash_post( $gone );
		foreach ( array( 999999, $draft, $gone, 0 ) as $id ) {
			$this->assertSame( '', mappulse_render_map( $id ) );
		}
	}

	public function test_map_managers_see_a_notice_instead() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'class="mappulse-notice"', mappulse_render_map( 999999 ) );
	}

	public function test_only_administrators_get_the_capability() {
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_mappulse_maps' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'manage_mappulse_maps' ) );
	}

	public function test_a_document_that_does_not_reach_the_database_is_reported() {
		$block = static function ( $check, $object_id, $meta_key ) {
			return \MapPulse\Map_Post_Type::META_DOC === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $block, 10, 3 );
		$result = \MapPulse\Map_Post_Type::save( $this->doc() );
		remove_filter( 'update_post_metadata', $block, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'mappulse_not_stored', $result->get_error_code() );
	}
}
