/**
 * Clean outline. Some Census places arrive as many separate pieces (blocks with the streets left out,
 * a frame around a hollow). A map zone needs one solid shape, so this closes gaps narrower than
 * twice the closing distance and fills holes. Parts farther apart, such as real islands, stay apart.
 */
import ClipperLib from 'clipper-lib';
import { mpArea, mpBbox, unionAll, ringArea, safe } from './shapes';

const METRES_PER_DEGREE = 111320;
// Clipper works in whole numbers. One unit is one centimetre.
const UNITS_PER_METRE = 100;

const toPath = ( ring, k ) =>
	ring.slice( 0, -1 ).map( ( [ lon, lat ] ) => ( {
		X: Math.round( lon * k * METRES_PER_DEGREE * UNITS_PER_METRE ),
		Y: Math.round( lat * METRES_PER_DEGREE * UNITS_PER_METRE ),
	} ) );

const toRing = ( path, k ) => {
	const ring = path.map( ( p ) => [
		p.X / UNITS_PER_METRE / METRES_PER_DEGREE / k,
		p.Y / UNITS_PER_METRE / METRES_PER_DEGREE,
	] );
	ring.push( ring[ 0 ] );
	return ring;
};

function offset( paths, distance ) {
	const tool = new ClipperLib.ClipperOffset( 2, 0.25 * UNITS_PER_METRE );
	tool.AddPaths(
		paths,
		ClipperLib.JoinType.jtRound,
		ClipperLib.EndType.etClosedPolygon
	);
	const out = new ClipperLib.Paths();
	tool.Execute( out, distance * UNITS_PER_METRE );
	return out;
}

// Six decimal places of a degree is about 0.1 m: enough for a map and it keeps stored shapes small.
const round = ( mp ) =>
	mp.map( ( poly ) =>
		poly.map( ( ring ) =>
			ring.map( ( [ x, y ] ) => [
				Math.round( x * 1e6 ) / 1e6,
				Math.round( y * 1e6 ) / 1e6,
			] )
		)
	);

export const needsCleaning = ( mp ) =>
	mp.length > 1 || mp.some( ( poly ) => poly.length > 1 );

/**
 * @param mp                   A multipolygon in degrees.
 * @param options
 * @param options.closeMeters  Gaps up to twice this wide are closed.
 * @param options.minHoleShare A hole smaller than this share of the shape's area is filled. 1 fills every hole.
 * @return The cleaned shape with changed, partsBefore and partsAfter.
 */
export function cleanOutline(
	mp,
	{ closeMeters = 30, minHoleShare = 1 } = {}
) {
	const partsBefore = mp.length;
	if ( ! partsBefore ) {
		return { mp: [], changed: false, partsBefore: 0, partsAfter: 0 };
	}
	const merged = unionAll( mp.map( ( poly ) => [ poly ] ) );
	const box = mpBbox( merged );
	const k = Math.max(
		0.01,
		Math.cos( ( ( ( box[ 1 ] + box[ 3 ] ) / 2 ) * Math.PI ) / 180 )
	);

	// polygon-clipping winds outlines one way and holes the other, which is what the offsetter expects.
	const paths = merged.flatMap( ( poly ) =>
		poly.map( ( ring ) => toPath( ring, k ) )
	);
	const closed = offset( offset( paths, closeMeters ), -closeMeters );
	if ( ! closed.length ) {
		return { mp, changed: false, partsBefore, partsAfter: partsBefore };
	}

	// After offsetting, the largest ring is always an outline. Rings wound the other way are holes.
	const rings = closed
		.map( ( path ) => toRing( path, k ) )
		.filter( ( ring ) => ring.length >= 4 );
	const sign = Math.sign(
		ringArea(
			rings.reduce( ( a, b ) =>
				Math.abs( ringArea( b ) ) > Math.abs( ringArea( a ) ) ? b : a
			)
		)
	);
	const outlines = rings.filter(
		( ring ) => Math.sign( ringArea( ring ) ) === sign
	);
	let shape = unionAll( outlines.map( ( ring ) => [ [ ring ] ] ) );
	if ( minHoleShare < 1 ) {
		const total = mpArea( shape );
		const kept = rings.filter(
			( ring ) =>
				Math.sign( ringArea( ring ) ) !== sign &&
				Math.abs( ringArea( ring ) ) >= minHoleShare * total
		);
		if ( kept.length ) {
			shape = safe(
				'difference',
				shape,
				shape,
				...kept.map( ( ring ) => [ [ ring ] ] )
			);
		}
	}
	shape = round( shape );

	const before = mpArea( merged );
	const changed =
		shape.length !== partsBefore ||
		merged.some( ( poly ) => poly.length > 1 ) ||
		Math.abs( mpArea( shape ) - before ) > before * 0.001;
	return { mp: shape, changed, partsBefore, partsAfter: shape.length };
}
