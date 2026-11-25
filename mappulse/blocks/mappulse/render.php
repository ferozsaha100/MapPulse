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
		'cards'     => ! isset( $attributes['cards'] ) || $attributes['cards'],
	)
);

if ( '' === $mappulse_html ) {
	return;
}

// WordPress versions that handle the HTML anchor of a dynamic block add the id themselves. Older ones do not.
$mappulse_wrapper = get_block_wrapper_attributes();
$mappulse_anchor  = isset( $attributes['anchor'] ) ? sanitize_title( (string) $attributes['anchor'] ) : '';
if ( '' !== $mappulse_anchor && ! preg_match( '/(^|\s)id="/', $mappulse_wrapper ) ) {
	$mappulse_wrapper = get_block_wrapper_attributes( array( 'id' => $mappulse_anchor ) );
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts are escaped where they are built.
printf( '<div %s>%s</div>', $mappulse_wrapper, $mappulse_html );
