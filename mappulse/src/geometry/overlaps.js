/**
 * Overlap resolution. Region types overlap by nature: a ZIP area holds neighborhoods, a city holds both.
 */
import { mpArea, mpBbox, boxesTouch, safe } from './shapes';

export const DEFAULT_PRIORITY = [ 'neighborhood', 'city', 'zip', 'county' ];

/**
 * Cuts each zone out of every lower-priority zone beneath it. Zones of one type keep their list order,
 * so an earlier zone wins over a later one. A backdrop zone is left whole and cuts nothing.
 *
 * @param zones    Items with type, mp and optionally backdrop. They are changed in place.
 * @param priority Area types, most important first.
 * @return The zones in priority order, each with cutShare (0 to 1) and parts.
 */
export function resolveOverlaps( zones, priority = DEFAULT_PRIORITY ) {
	const rank = ( z ) => {
		const i = priority.indexOf( z.type );
		return i < 0 ? priority.length : i;
	};
	const ordered = zones
		.map( ( z, i ) => ( { z, i } ) )
		.sort( ( a, b ) => rank( a.z ) - rank( b.z ) || a.i - b.i )
		.map( ( o ) => o.z );
	const done = [];
	for ( const z of ordered ) {
		const before = mpArea( z.mp );
		if ( ! z.backdrop ) {
			const box = mpBbox( z.mp );
			const clips = done
				.filter( ( d ) => boxesTouch( box, d.box ) )
				.map( ( d ) => d.mp );
			if ( clips.length ) {
				z.mp = safe( 'difference', z.mp, z.mp, ...clips );
			}
			done.push( { mp: z.mp, box: mpBbox( z.mp ) } );
		}
		const after = mpArea( z.mp );
		z.cutShare = before > 0 ? Math.max( 0, 1 - after / before ) : 0;
		z.parts = z.mp.length;
	}
	return ordered;
}
