/**
 * Keeps a hand-moved label inside the map, by the same rule the renderer draws with, so the offset
 * stored is the offset shown and a label at the edge moves back at the first step.
 */

/**
 * @param placed The label as the builder placed it: x, y, size, lines.
 * @param frame  The map frame: width, height.
 * @param offset The wanted offset: dx, dy.
 * @return The offset that will actually be drawn, rounded to a tenth.
 */
export function clampOffset( placed, frame, offset ) {
	const { dx, dy } = offset;
	const round = ( n ) => Math.round( n * 10 ) / 10 || 0;
	if ( ! placed || ! frame ) {
		return { dx: round( dx ), dy: round( dy ) };
	}
	const size = Number( placed.size ) || 11.5;
	const lines =
		Array.isArray( placed.lines ) && placed.lines.length
			? placed.lines
			: [ '' ];
	const halfW =
		( Math.max( ...lines.map( ( line ) => String( line ).length ) ) *
			size *
			0.8 +
			6 ) /
		2;
	const halfH = ( lines.length * size * 1.18 + 3 ) / 2;
	const within = ( value, half, side ) =>
		Math.min(
			Math.max( value, Math.min( half + 4, side / 2 ) ),
			Math.max( side - half - 4, side / 2 )
		);
	return {
		dx: round( within( placed.x + dx, halfW, frame.width ) - placed.x ),
		dy: round( within( placed.y + dy, halfH, frame.height ) - placed.y ),
	};
}

/**
 * A hand-set label as it should be drawn at the map's current scale. An offset is a distance on the
 * ground, so when areas are added and the map is drawn smaller, the offset shrinks with it.
 *
 * @param label A zone's label override, or null.
 * @param scale Frame units per projected unit of the map being drawn.
 * @return The label with dx and dy at that scale, or what was given when there is nothing to convert.
 */
export function atScale( label, scale ) {
	if ( ! label || ! ( scale > 0 ) ) {
		return label;
	}
	const round = ( n ) => Math.round( n * 10 ) / 10 || 0;
	const factor = label.scale > 0 ? scale / label.scale : 1;
	return {
		...label,
		dx: round( ( label.dx || 0 ) * factor ),
		dy: round( ( label.dy || 0 ) * factor ),
		scale,
	};
}
