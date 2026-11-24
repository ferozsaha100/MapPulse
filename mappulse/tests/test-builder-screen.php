<?php
use MapPulse\Builder_Screen;
use MapPulse\Map_Post_Type;

class Test_Builder_Screen extends MapPulse_Test_Case {

	private $build;
	private $made = array();

	public function set_up() {
		parent::set_up();
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
		// The compiled builder is not in the repository. Stand in for it where it is missing.
		$this->build = sys_get_temp_dir() . '/mappulse-build-' . wp_generate_password( 8, false );
		mkdir( $this->build );
		file_put_contents( $this->build . '/builder.asset.php', "<?php return array( 'dependencies' => array( 'wp-element' ), 'version' => 'abc' );" );
		file_put_contents( $this->build . '/builder.js', '' );
		file_put_contents( $this->build . '/builder.css', '' );
		add_filter( 'mappulse_build_dir', array( $this, 'build_dir' ) );
	}

	public function tear_down() {
		remove_filter( 'mappulse_build_dir', array( $this, 'build_dir' ) );
		array_map( 'unlink', glob( $this->build . '/*' ) );
		rmdir( $this->build );
		wp_dequeue_script( 'mappulse-builder' );
		wp_deregister_script( 'mappulse-builder' );
		wp_dequeue_style( 'mappulse-builder' );
		wp_deregister_style( 'mappulse-builder' );
		$_GET = array();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function build_dir(): string {
		return $this->build;
	}

	private function sign_in( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	private function page(): string {
		ob_start();
		( new Builder_Screen() )->render_page();
		return ob_get_clean();
	}

	public function test_the_url_names_the_map() {
		$this->assertStringEndsWith( 'edit.php?post_type=mappulse_map&page=mappulse-builder', Builder_Screen::url() );
		$this->assertStringEndsWith( '&map=7', Builder_Screen::url( 7 ) );
	}

	public function test_the_menu_needs_the_capability() {
		global $submenu;
		foreach ( array( 'editor' => false, 'administrator' => true ) as $role => $expected ) {
			$submenu = array();
			$this->sign_in( $role );
			( new Builder_Screen() )->add_menu();
			$pages = wp_list_pluck( $submenu[ 'edit.php?post_type=' . Map_Post_Type::POST_TYPE ] ?? array(), 2 );
			$this->assertSame( $expected, in_array( Builder_Screen::PAGE, $pages, true ), $role );
		}
	}

	public function test_the_script_loads_on_the_builder_only() {
		$this->sign_in( 'administrator' );
		$screen = new Builder_Screen();

		$screen->enqueue( 'edit.php' );
		$this->assertFalse( wp_script_is( 'mappulse-builder', 'enqueued' ) );
		$screen->enqueue( 'mappulse_map_page_mappulse-sources' );
		$this->assertFalse( wp_script_is( 'mappulse-builder', 'enqueued' ) );

		$_GET['map'] = '12';
		$screen->enqueue( 'mappulse_map_page_' . Builder_Screen::PAGE );
		$this->assertTrue( wp_script_is( 'mappulse-builder', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'mappulse-builder', 'before' );
		$json = implode( '', array_filter( (array) $data ) );
		$this->assertStringContainsString( '"mapId":12', $json );
		$this->assertStringContainsString( 'tile.openstreetmap.org', $json );
		$this->assertStringContainsString( '"maxZones":' . MAPPULSE_MAX_ZONES, $json );
		$this->assertStringContainsString( '{"code":"FL","name":"Florida"}', $json );
	}

	public function test_the_tile_source_can_be_changed() {
		$this->sign_in( 'administrator' );
		$swap = static function () {
			return 'https://tiles.example.com/{z}/{x}/{y}.png';
		};
		add_filter( 'mappulse_tile_url', $swap );
		$this->assertSame( 'https://tiles.example.com/{z}/{x}/{y}.png', Builder_Screen::settings()['tiles']['url'] );
		remove_filter( 'mappulse_tile_url', $swap );
	}

	public function test_the_page_holds_the_app_root_or_says_the_build_is_missing() {
		$this->sign_in( 'administrator' );
		$this->assertStringContainsString( 'id="mappulse-builder"', $this->page() );

		unlink( $this->build . '/builder.asset.php' );
		$html = $this->page();
		$this->assertStringNotContainsString( 'id="mappulse-builder"', $html );
		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_edit_links_and_the_list_button_point_at_the_builder() {
		$this->sign_in( 'administrator' );
		$id = Map_Post_Type::save( $this->doc() );
		$this->assertSame( Builder_Screen::url( $id ), get_edit_post_link( $id, 'raw' ) );

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertStringContainsString( 'post.php', get_edit_post_link( $page, 'raw' ) );

		ob_start();
		Builder_Screen::list_button( array() );
		$this->assertStringContainsString( esc_url( Builder_Screen::url() ), ob_get_clean() );
	}

	public function test_an_editor_cannot_open_the_page() {
		$this->sign_in( 'editor' );
		$this->expectException( WPDieException::class );
		( new Builder_Screen() )->render_page();
	}

	public function test_a_page_with_a_map_loads_no_builder_files() {
		$id = Map_Post_Type::save( $this->doc() );
		$this->go_to( home_url( '/' ) );
		$html = do_shortcode( '[mappulse id="' . $id . '"]' );
		$this->assertStringContainsString( 'data-mappulse', $html );

		$this->assertFalse( wp_script_is( 'mappulse-builder', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'mappulse-builder', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'mappulse', 'enqueued' ) );
		// The builder's loader is tied to the admin's own hook and to nothing a visitor triggers.
		global $wp_filter;
		foreach ( array( 'wp_enqueue_scripts', 'wp_head', 'wp_footer', 'init' ) as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$this->assertFalse( is_array( $callback['function'] ) && $callback['function'][0] instanceof Builder_Screen, $hook );
				}
			}
		}
	}

	public function test_site_colors_and_fonts_are_offered() {
		$this->sign_in( 'administrator' );
		$theme = static function ( $data ) {
			return $data->update_with(
				array(
					'version'  => 3,
					'settings' => array(
						'color'      => array(
							'palette' => array(
								array( 'slug' => 'brand', 'name' => 'Brand', 'color' => '#1f3a5f' ),
								array( 'slug' => 'brand-again', 'name' => 'Brand again', 'color' => '#1F3A5F' ),
								array( 'slug' => 'fancy', 'name' => 'Fancy', 'color' => 'var(--wp--preset--color--x)' ),
								array( 'slug' => 'short', 'name' => 'Short', 'color' => '#abc' ),
							),
						),
						'typography' => array(
							'fontFamilies' => array(
								array( 'slug' => 'heading', 'name' => 'Heading', 'fontFamily' => '"Playfair Display", serif' ),
								array( 'slug' => 'tricky', 'name' => 'Tricky', 'fontFamily' => 'var(--wp--preset--font-family--x)' ),
							),
						),
					),
				)
			);
		};
		add_filter( 'wp_theme_json_data_theme', $theme );
		wp_clean_theme_json_cache();
		$settings = Builder_Screen::settings();
		remove_filter( 'wp_theme_json_data_theme', $theme );
		wp_clean_theme_json_cache();

		$colors = wp_list_pluck( $settings['siteColors'], 'color' );
		$this->assertContains( '#1F3A5F', $colors );
		$this->assertContains( '#AABBCC', $colors );
		$this->assertSame( 1, count( array_keys( $colors, '#1F3A5F', true ) ) );
		foreach ( $colors as $color ) {
			$this->assertMatchesRegularExpression( '/^#[0-9A-F]{6}$/', $color );
		}
		$this->assertLessThanOrEqual( 24, count( $colors ) );

		$families = wp_list_pluck( $settings['siteFonts'], 'family' );
		$this->assertContains( 'Playfair Display, serif', $families );
		$this->assertContains( 'Georgia, Times New Roman, serif', $families );
		foreach ( $families as $family ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9 ,_\-]+$/', $family );
		}
		$this->assertSame( '#DCCFB6', $settings['styleDefaults']['zone'] );
		$this->assertSame( 'atlas', $settings['styleDefaults']['preset'] );
	}
}
