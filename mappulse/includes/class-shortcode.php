<?php
/**
 * The [mappulse] shortcode, for classic themes and page builders without a MapPulse widget.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders [mappulse id="12" layout="list-left"].
 */
class Shortcode {

	const TAG = 'mappulse';

	/**
	 * Registers the shortcode.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes: id, layout, heading, intro, list (yes or no).
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'layout'  => Renderer::LAYOUTS[0],
				'heading' => '',
				'intro'   => '',
				'list'    => 'yes',
			),
			$atts,
			self::TAG
		);

		return mappulse_render_map(
			absint( $atts['id'] ),
			array(
				'layout'    => (string) $atts['layout'],
				'heading'   => (string) $atts['heading'],
				'intro'     => (string) $atts['intro'],
				'show_list' => 'no' !== strtolower( trim( (string) $atts['list'] ) ),
			)
		);
	}
}
