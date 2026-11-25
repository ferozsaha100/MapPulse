/**
 * Decides what the builder must redo after a change. Kept free of React so it is tested in Node.
 */

// Everything that changes the shapes and where labels sit. The title does not, nor do colors or links.
// The label size does: label boxes are sized from it.
export const signature = ( { zones, priority, style } ) =>
	JSON.stringify( [
		priority,
		Number( ( style && style.labelSize ) || 0 ),
		zones.map( ( z ) => [
			z.id,
			z.source,
			z.record,
			z.name,
			z.type,
			!! z.backdrop,
			!! z.clean,
		] ),
	] );

// Everything that changes what is drawn or stored: the above, plus the style and each area's link,
// description and hand-set label.
export const drawSignature = ( input ) =>
	JSON.stringify( [
		signature( input ),
		input.style || {},
		input.zones.map( ( z ) => [
			z.link || '',
			z.linkPost || 0,
			z.text || '',
			z.label
				? [
						z.label.text || '',
						z.label.dx || 0,
						z.label.dy || 0,
						!! z.label.hidden,
				  ]
				: 0,
		] ),
	] );

const contains = ( outer, inner ) =>
	outer[ 0 ] <= inner[ 0 ] &&
	outer[ 1 ] <= inner[ 1 ] &&
	outer[ 2 ] >= inner[ 2 ] &&
	outer[ 3 ] >= inner[ 3 ];

/**
 * @param prev The state the last drawing was made from: signature, and the base box fetched for it.
 * @param next The current state: signature, wanted (the base box the zones need now), fetched.
 * @return refetchBase and rebuild.
 */
export function planRebuild( prev, next ) {
	const rebuild = prev.signature !== next.signature;
	const refetchBase =
		!! next.wanted &&
		( ! next.fetched || ! contains( next.fetched, next.wanted ) );
	return { refetchBase: rebuild && refetchBase, rebuild };
}
