<?php
use MapPulse\Map_Post_Type;

class Test_Elementor extends MapPulse_Test_Case {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			if ( getenv( 'MAPPULSE_REQUIRE_ELEMENTOR' ) ) {
				$this->fail( 'Elementor must be loaded in this environment, and it is not.' );
			}
			$this->markTestSkipped( 'Elementor is not installed in this environment.' );
		}
	}

	public function test_widget_is_registered() {
		$this->assertArrayHasKey( 'mappulse', \Elementor\Plugin::$instance->widgets_manager->get_widget_types() );
	}

	public function test_widget_declares_the_shared_assets() {
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( 'mappulse' );
		$this->assertSame( array( 'mappulse' ), $widget->get_style_depends() );
		$this->assertSame( array( 'mappulse' ), $widget->get_script_depends() );
	}

	public function test_widget_class_is_not_loaded_before_elementor_asks_for_it() {
		$this->assertFalse( $GLOBALS['mappulse_widget_loaded_early'] );
		\Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		$this->assertTrue( class_exists( 'MapPulse\Integrations\Elementor_Widget', false ) );
	}

	public function test_widget_renders_the_chosen_map_with_its_layout() {
		$map_id  = Map_Post_Type::save( $this->fixture() );
		$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
			array(
				'id'         => 'a1b2c3d',
				'elType'     => 'widget',
				'widgetType' => 'mappulse',
				'settings'   => array(
					'map_id' => (string) $map_id,
					'layout' => 'list-below',
				),
				'elements'   => array(),
			)
		);
		$this->assertNotNull( $element );
		ob_start();
		$element->print_element();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'mappulse--list-below', $html );
		$this->assertSame( 3, preg_match_all( '/class="mappulse__zone[ "]/', $html ) );
	}

	public function test_widget_without_a_map_prints_nothing_on_the_front_end() {
		$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
			array(
				'id'         => 'e4f5a6b',
				'elType'     => 'widget',
				'widgetType' => 'mappulse',
				'settings'   => array(),
				'elements'   => array(),
			)
		);
		$this->assertNotNull( $element );
		ob_start();
		$element->print_element();
		$this->assertStringNotContainsString( 'mappulse', (string) preg_replace( '/elementor-widget-mappulse|data-widget_type="mappulse[^"]*"/', '', ob_get_clean() ) );
	}

	private function render_widget( int $map_id, array $settings ): string {
		$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
			array(
				'id'         => 'c4d5e6f',
				'elType'     => 'widget',
				'widgetType' => 'mappulse',
				'settings'   => array( 'map_id' => (string) $map_id ) + $settings,
				'elements'   => array(),
			)
		);
		ob_start();
		$element->print_element();
		return ob_get_clean();
	}

	public function test_widget_can_switch_hover_cards_off() {
		$raw                     = $this->fixture();
		$raw['zones'][0]['card'] = array( 'tagline' => 'Waterfront living' );
		$map_id                  = Map_Post_Type::save( $raw );

		$this->assertStringContainsString( 'mappulse__card', $this->render_widget( $map_id, array() ) );
		$this->assertStringNotContainsString( 'mappulse__card', $this->render_widget( $map_id, array( 'cards' => '' ) ) );
	}

	public function test_widget_has_card_radius_and_shadow_controls_that_set_the_card_variables() {
		$widget   = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( 'mappulse' );
		$controls = $widget->get_controls();
		$this->assertArrayHasKey( 'cards', $controls );
		$this->assertSame( 'yes', $controls['cards']['default'] );
		$this->assertSame( array( '{{WRAPPER}} .mappulse' => '--mappulse-card-radius: {{SIZE}}{{UNIT}};' ), $controls['card_radius']['selectors'] );
		$this->assertStringContainsString( '--mappulse-card-shadow:', implode( '', $controls['card_shadow']['selectors'] ) );
	}
}
