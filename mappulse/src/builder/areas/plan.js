/**
 * Decides what the builder must redo after a change. Kept free of React so it is tested in Node.
 */

// Everything about the zones that changes the drawing. The title does not.
export const signature = ( { zones, priority } ) =>
	JSON.stringify( [
		priority,
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
