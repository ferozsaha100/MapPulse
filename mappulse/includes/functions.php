<?php
/**
 * Public functions.
 *
 * @package MapPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the markup for one map: heading, area list and the inline SVG.
 *
 * The shortcode, the block and the Elementor widget all call this.
 *
 * @param int   $map_id ID of a published map.
 * @param array $args   Optional. layout (list-left, list-right, list-below, map-only), heading, intro, show_list, cards (false to leave the hover cards out).
 */
function mappulse_render_map( int $map_id, array $args = array() ): string {
	$post = $map_id > 0 ? get_post( $map_id ) : null;
	$doc  = $post && MapPulse\Map_Post_Type::POST_TYPE === $post->post_type && 'publish' === $post->post_status
		? MapPulse\Map_Post_Type::get( $map_id )
		: null;

	if ( null === $doc ) {
		if ( ! current_user_can( MapPulse\Map_Post_Type::CAPABILITY ) ) {
			return '';
		}
		wp_enqueue_style( 'mappulse' );
		/* translators: %d: map ID. */
		return '<p class="mappulse-notice">' . esc_html( sprintf( __( 'MapPulse: map %d was not found or is not published. Visitors see nothing here.', 'mappulse' ), $map_id ) ) . '</p>';
	}

	wp_enqueue_style( 'mappulse' );
	wp_enqueue_script( 'mappulse' );

	return MapPulse\Renderer::section( $doc, MapPulse\Map_Post_Type::svg( $map_id ), $args );
}
