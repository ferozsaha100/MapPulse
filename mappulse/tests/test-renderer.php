<?php
use MapPulse\Renderer;

class Test_Renderer extends MapPulse_Test_Case {

	public function test_svg_has_viewbox_and_one_shape_per_zone() {
		$svg = Renderer::svg( $this->doc() );
		$this->assertStringContainsString( 'viewBox="0 0 1000 800"', $svg );
		$this->assertSame( 3, preg_match_all( '/class="mappulse__zone[ "]/', $svg ) );
	}

	public function test_svg_has_no_ids_styles_scripts_or_handlers() {
		$svg = Renderer::svg( $this->doc() );
		$this->assertDoesNotMatchRegularExpression( '/\sid=|<style|<script|\son[a-z]+=/i', $svg );
	}

	public function test_groups_are_in_draw_order() {
		$svg    = Renderer::svg( $this->doc() );
		$pos    = array_map( fn( $g ) => strpos( $svg, 'class="mappulse__' . $g . '"' ), array( 'land', 'cities', 'areas', 'zones', 'water', 'roads', 'labels' ) );
		$sorted = $pos;
		sort( $sorted );
		$this->assertSame( $sorted, $pos );
		$this->assertNotContains( false, $pos, true );
	}

	public function test_zone_without_link_is_not_an_anchor() {
		$svg = Renderer::svg( $this->doc() );
		$this->assertStringContainsString( '<g class="mappulse__zone mappulse__zone--static" tabindex="0" role="img" data-zone="old-northeast"', $svg );
		$html = Renderer::section( $this->doc(), $svg, array() );
		$this->assertStringContainsString( '<span data-zone="old-northeast">Old Northeast</span>', $html );
	}

	public function test_layout_and_style_variables_are_on_the_section() {
		$html = Renderer::section( $this->doc(), Renderer::svg( $this->doc() ), array( 'layout' => 'list-right' ) );
		$this->assertStringContainsString( 'class="mappulse mappulse--list-right"', $html );
		$this->assertStringContainsString( '--mappulse-doc-zone-hover:#1F3A5F', $html );
	}

	public function test_section_sets_only_document_defaults_so_placements_can_override() {
		$html = Renderer::section( $this->doc(), Renderer::svg( $this->doc() ), array() );
		$this->assertDoesNotMatchRegularExpression( '/--mappulse-(?!doc-)[a-z-]+:/', $html );
		$css = file_get_contents( MAPPULSE_DIR . 'assets/css/mappulse.css' );
		foreach ( array( 'water', 'land', 'zone', 'zone-hover', 'label', 'label-hover', 'font' ) as $name ) {
			$this->assertStringContainsString( 'var(--mappulse-' . $name . ', var(--mappulse-doc-' . $name . '))', $css );
		}
	}

	public function test_unknown_layout_falls_back_and_map_only_hides_the_list() {
		$svg = Renderer::svg( $this->doc() );
		$this->assertStringContainsString( 'mappulse--list-left', Renderer::section( $this->doc(), $svg, array( 'layout' => 'x"><b' ) ) );
		$this->assertStringNotContainsString( 'mappulse__list', Renderer::section( $this->doc(), $svg, array( 'layout' => 'map-only' ) ) );
	}

	public function test_two_sections_on_one_page_share_no_ids() {
		$svg  = Renderer::svg( $this->doc() );
		$page = Renderer::section( $this->doc(), $svg, array() ) . Renderer::section( $this->doc(), $svg, array() );
		$this->assertSame( 2, substr_count( $page, 'data-mappulse' ) );
		$this->assertDoesNotMatchRegularExpression( '/\sid=/', $page );
	}

	public function test_svg_matches_the_stored_snapshot() {
		$file = __DIR__ . '/fixtures/sample.svg';
		if ( getenv( 'MAPPULSE_UPDATE_SNAPSHOTS' ) ) {
			file_put_contents( $file, Renderer::svg( $this->doc() ) );
		}
		$this->assertSame( file_get_contents( $file ), Renderer::svg( $this->doc() ) );
	}

	public function test_heading_and_long_names_are_escaped_not_cut() {
		$html = Renderer::section( $this->doc(), Renderer::svg( $this->doc() ), array( 'heading' => 'A <b>& B' ) );
		$this->assertStringContainsString( 'A &lt;b&gt;&amp; B', $html );
		$this->assertStringContainsString( $this->doc()['zones'][2]['name'], $html );
	}
}
