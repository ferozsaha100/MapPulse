<?php
/**
 * Server render for the agentview/mappulse block.
 *
 * @package MapPulse
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$mappulse_html = mappulse_render_map(
	isset( $attributes['mapId'] ) ? (int) $attributes['mapId'] : 0,
	array(
		'layout'    => isset( $attributes['layout'] ) ? (string) $attributes['layout'] : '',
		'heading'   => isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '',
		'intro'     => isset( $attributes['intro'] ) ? (string) $attributes['intro'] : '',
		'show_list' => ! isset( $attributes['showList'] ) || $attributes['showList'],
	)
);

if ( '' === $mappulse_html ) {
	return;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts are escaped where they are built.
printf( '<div %s>%s</div>', get_block_wrapper_attributes(), $mappulse_html );
