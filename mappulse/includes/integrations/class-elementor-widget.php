<?php
/**
 * The MapPulse Map widget for Elementor.
 *
 * A classic widget. Its output is the same server-rendered section the block and the shortcode use,
 * so the editor preview is the real thing.
 *
 * @package MapPulse
 */

namespace MapPulse\Integrations;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use MapPulse\Map_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget: MapPulse Map.
 */
class Elementor_Widget extends Widget_Base {

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'mappulse';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return esc_html__( 'MapPulse Map', 'mappulse' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-google-maps';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords.
	 */
	public function get_keywords() {
		return array( 'map', 'neighborhood', 'area', 'real estate' );
	}

	/**
	 * Stylesheets the widget needs.
	 */
	public function get_style_depends(): array {
		return array( 'mappulse' );
	}

	/**
	 * Scripts the widget needs.
	 */
	public function get_script_depends(): array {
		return array( 'mappulse' );
	}

	/**
	 * Registers the widget controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_map',
			array(
				'label' => esc_html__( 'Map', 'mappulse' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'map_id',
			array(
				'label'       => esc_html__( 'Map', 'mappulse' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array( '' => esc_html__( 'Choose a map', 'mappulse' ) ) + Map_Post_Type::choices(),
				'default'     => '',
				'description' => esc_html__( 'Maps are added under MapPulse, Import.', 'mappulse' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => esc_html__( 'Layout', 'mappulse' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'list-left'  => esc_html__( 'List on the left', 'mappulse' ),
					'list-right' => esc_html__( 'List on the right', 'mappulse' ),
					'list-below' => esc_html__( 'List below the map', 'mappulse' ),
					'map-only'   => esc_html__( 'Map only', 'mappulse' ),
				),
				'default' => 'list-left',
			)
		);

		$this->add_control(
			'show_list',
			array(
				'label'        => esc_html__( 'Area list', 'mappulse' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'mappulse' ),
				'label_off'    => esc_html__( 'Hide', 'mappulse' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array(
					'layout!' => 'map-only',
				),
			)
		);

		$this->add_control(
			'cards',
			array(
				'label'        => esc_html__( 'Hover cards', 'mappulse' ),
				'description'  => esc_html__( 'Cards are set per area in the map builder.', 'mappulse' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'mappulse' ),
				'label_off'    => esc_html__( 'Hide', 'mappulse' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'heading',
			array(
				'label'       => esc_html__( 'Heading', 'mappulse' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'intro',
			array(
				'label'   => esc_html__( 'Intro', 'mappulse' ),
				'type'    => Controls_Manager::TEXTAREA,
				'default' => '',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_map_style',
			array(
				'label' => esc_html__( 'Map', 'mappulse' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$colors = array(
			'zone_color'       => array( esc_html__( 'Area color', 'mappulse' ), '--mappulse-zone' ),
			'zone_hover_color' => array( esc_html__( 'Area hover color', 'mappulse' ), '--mappulse-zone-hover' ),
			'label_color'      => array( esc_html__( 'Label color', 'mappulse' ), '--mappulse-label' ),
			'water_color'      => array( esc_html__( 'Water color', 'mappulse' ), '--mappulse-water' ),
			'land_color'       => array( esc_html__( 'Land color', 'mappulse' ), '--mappulse-land' ),
		);
		foreach ( $colors as $control => $color ) {
			$this->add_control(
				$control,
				array(
					'label'     => $color[0],
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						'{{WRAPPER}} .mappulse' => $color[1] . ': {{VALUE}};',
					),
				)
			);
		}

		$this->add_responsive_control(
			'max_height',
			array(
				'label'      => esc_html__( 'Maximum map height', 'mappulse' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vh' ),
				'range'      => array(
					'px' => array(
						'min' => 200,
						'max' => 1200,
					),
					'vh' => array(
						'min' => 20,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .mappulse' => '--mappulse-max-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'card_radius',
			array(
				'label'      => esc_html__( 'Card radius', 'mappulse' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 24,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .mappulse' => '--mappulse-card-radius: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'cards' => 'yes',
				),
			)
		);

		$this->add_control(
			'card_shadow',
			array(
				'label'     => esc_html__( 'Card shadow', 'mappulse' ),
				'type'      => Controls_Manager::BOX_SHADOW,
				'selectors' => array(
					'{{WRAPPER}} .mappulse' => '--mappulse-card-shadow: {{HORIZONTAL}}px {{VERTICAL}}px {{BLUR}}px {{SPREAD}}px {{COLOR}};',
				),
				'condition' => array(
					'cards' => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Prints the widget.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$map_id   = isset( $settings['map_id'] ) ? absint( $settings['map_id'] ) : 0;

		if ( ! $map_id ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo wp_kses_post( \MapPulse\Importer::prompt_html() );
			}
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where the markup is built.
		echo mappulse_render_map(
			$map_id,
			array(
				'layout'    => isset( $settings['layout'] ) ? (string) $settings['layout'] : '',
				'heading'   => isset( $settings['heading'] ) ? (string) $settings['heading'] : '',
				'intro'     => isset( $settings['intro'] ) ? (string) $settings['intro'] : '',
				'show_list' => ! isset( $settings['show_list'] ) || 'yes' === $settings['show_list'],
				'cards'     => ! isset( $settings['cards'] ) || 'yes' === $settings['cards'],
			)
		);
	}
}
