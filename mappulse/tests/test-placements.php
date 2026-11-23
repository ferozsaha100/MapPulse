<?php
use MapPulse\Map_Post_Type;

class Test_Placements extends MapPulse_Test_Case {

	private $map_id;

	public function set_up() {
		parent::set_up();
		$this->map_id = Map_Post_Type::save( $this->fixture() );
	}

	public function test_shortcode_renders_the_map_with_its_layout() {
		$html = do_shortcode( '[mappulse id="' . $this->map_id . '" layout="list-below"]' );
		$this->assertStringContainsString( 'mappulse--list-below', $html );
	}

	public function test_shortcode_without_a_valid_id_outputs_nothing() {
		$this->assertSame( '', do_shortcode( '[mappulse]' ) );
		$this->assertSame( '', do_shortcode( '[mappulse id="abc"]' ) );
	}

	public function test_shortcode_list_no_hides_the_area_list() {
		$html = do_shortcode( '[mappulse id="' . $this->map_id . '" list="no"]' );
		$this->assertStringContainsString( 'data-mappulse', $html );
		$this->assertStringNotContainsString( 'mappulse__list', $html );
	}

	public function test_block_is_registered_with_server_render() {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'agentview/mappulse' );
		$this->assertNotNull( $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertArrayHasKey( 'mapId', $block->attributes );
	}

	public function test_block_markup_renders_the_same_section_as_the_shortcode() {
		$block = do_blocks( '<!-- wp:agentview/mappulse {"mapId":' . $this->map_id . ',"layout":"map-only","align":"wide"} /-->' );
		$this->assertStringContainsString( 'mappulse--map-only', $block );
		$this->assertStringContainsString( 'alignwide', $block );
		$this->assertSame( substr_count( do_shortcode( '[mappulse id="' . $this->map_id . '"]' ), 'mappulse__zone' ), substr_count( $block, 'mappulse__zone' ) );
	}

	public function test_block_without_a_map_outputs_nothing_for_visitors() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'agentview/mappulse' ) );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:agentview/mappulse /-->' ) ) );
	}

	public function test_block_prints_its_html_anchor_once() {
		$block = do_blocks( '<!-- wp:agentview/mappulse {"mapId":' . $this->map_id . ',"anchor":"areas"} /-->' );
		$this->assertSame( 1, substr_count( $block, 'id="areas"' ) );
	}

	public function test_block_preview_request_accepts_an_anchor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/agentview/mappulse' );
		$request->set_param( 'context', 'edit' );
		$request->set_param(
			'attributes',
			array(
				'mapId'  => $this->map_id,
				'anchor' => 'areas',
			)
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_choices_do_not_load_map_documents_into_memory() {
		clean_post_cache( $this->map_id );
		Map_Post_Type::choices();
		$this->assertFalse( wp_cache_get( $this->map_id, 'post_meta' ) );
	}

	public function test_choices_list_published_maps_only() {
		$draft = Map_Post_Type::save( $this->fixture(), 0, 'draft' );
		$choices = Map_Post_Type::choices();
		$this->assertSame( 'Test Market', $choices[ $this->map_id ] ?? null );
		$this->assertArrayNotHasKey( $draft, $choices );
	}
}
