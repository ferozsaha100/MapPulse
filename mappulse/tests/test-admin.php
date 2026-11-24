<?php
use MapPulse\Importer;
use MapPulse\Map_Post_Type;

class Test_Admin extends MapPulse_Test_Case {

	public function test_maps_list_has_a_shortcode_column() {
		$columns = apply_filters(
			'manage_mappulse_map_posts_columns',
			array(
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);
		$this->assertSame( array( 'cb', 'title', 'mappulse_shortcode', 'date' ), array_keys( $columns ) );
		$id = Map_Post_Type::save( $this->fixture() );
		ob_start();
		do_action( 'manage_mappulse_map_posts_custom_column', 'mappulse_shortcode', $id );
		$this->assertStringContainsString( '[mappulse id=&quot;' . $id . '&quot;]', ob_get_clean() );
	}

	public function test_import_page_url_points_at_the_import_screen() {
		$this->assertStringContainsString( 'edit.php?post_type=mappulse_map&page=mappulse-import', Importer::page_url() );
	}

	public function test_maps_list_gets_an_import_button() {
		ob_start();
		$views = Importer::list_button( array( 'all' => 'All' ) );
		$html  = ob_get_clean();
		$this->assertSame( array( 'all' => 'All' ), $views );
		$this->assertStringContainsString( 'class="page-title-action"', $html );
		$this->assertStringContainsString( esc_url( Importer::page_url() ), $html );
	}

	public function test_empty_widget_prompt_links_to_the_import_screen() {
		$html = Importer::prompt_html();
		$this->assertStringContainsString( 'class="mappulse-notice"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Importer::page_url() ) . '"', $html );
	}
}
