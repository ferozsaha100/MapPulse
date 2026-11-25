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
