/**
 * Overlap resolution. Region types overlap by nature: a ZIP area holds neighborhoods, a city holds both.
 */
import { mpArea, mpBbox, boxesTouch, safe } from './shapes';

export const DEFAULT_PRIORITY = [ 'neighborhood', 'city', 'zip', 'county' ];

/**
 * Cuts each zone out of every lower-priority zone beneath it. Zones of one type keep their list order,
 * so an earlier zone wins over a later one, except that a zone marked "first" goes before the others
 * of its type. A backdrop zone is left whole and cuts nothing.
 *
 * @param zones    Items with type, mp and optionally backdrop and first. They are changed in place.
 * @param priority Area types, most important first.
 * @return The zones in priority order, each with cutShare (0 to 1) and parts.
 */
export function resolveOverlaps( zones, priority = DEFAULT_PRIORITY ) {
	const rank = ( z ) => {
		const i = priority.indexOf( z.type );
		return i < 0 ? priority.length : i;
	};
	// Zones marked first go before the others of their type, the smaller before the larger, so a small
	// cleaned shape inside a large one is not cut away. The rest keep list order.
	const before = ( a, b ) => {
		const firstA = !! a.z.first;
		const firstB = !! b.z.first;
		if ( firstA !== firstB ) {
			return firstA ? -1 : 1;
		}
		if ( firstA && firstB ) {
			return a.area - b.area || a.i - b.i;
		}
		return a.i - b.i;
	};
	const ordered = zones
		.map( ( z, i ) => ( { z, i, area: z.first ? mpArea( z.mp ) : 0 } ) )
		.sort( ( a, b ) => rank( a.z ) - rank( b.z ) || before( a, b ) )
		.map( ( o ) => o.z );
	const done = [];
	for ( const z of ordered ) {
		const whole = mpArea( z.mp );
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
		z.cutShare = whole > 0 ? Math.max( 0, 1 - after / whole ) : 0;
		z.parts = z.mp.length;
	}
	return ordered;
}
