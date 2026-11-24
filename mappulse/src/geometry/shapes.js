/**
 * Shape helpers. A multipolygon (MP) is laid out as polygon-clipping and GeoJSON do:
 * MP = Polygon[], Polygon = Ring[], Ring = [x, y][]. The first ring of a polygon is its outline.
 */
import polygonClipping from 'polygon-clipping';

export function toMP( geometry ) {
	if ( ! geometry ) {
		return [];
	}
	if ( geometry.type === 'Polygon' ) {
		return [ geometry.coordinates ];
	}
	if ( geometry.type === 'MultiPolygon' ) {
		return geometry.coordinates;
	}
	if ( geometry.type === 'GeometryCollection' ) {
		return ( geometry.geometries || [] ).flatMap( toMP );
	}
	return [];
}

export function toLines( geometry ) {
	if ( ! geometry ) {
		return [];
	}
	if ( geometry.type === 'LineString' ) {
		return [ geometry.coordinates ];
	}
	if ( geometry.type === 'MultiLineString' ) {
		return geometry.coordinates;
	}
	if ( geometry.type === 'GeometryCollection' ) {
		return ( geometry.geometries || [] ).flatMap( toLines );
	}
	return [];
}

export const mapMP = ( mp, fn ) =>
	mp.map( ( poly ) => poly.map( ( ring ) => ring.map( fn ) ) );

export function ringArea( ring ) {
	let sum = 0;
	for ( let i = 0, n = ring.length, j = n - 1; i < n; j = i++ ) {
		sum +=
			( ring[ j ][ 0 ] - ring[ i ][ 0 ] ) *
			( ring[ j ][ 1 ] + ring[ i ][ 1 ] );
	}
	return sum / 2;
}

export const polyArea = ( poly ) =>
	poly.reduce(
		( sum, ring, i ) =>
			sum +
			( i === 0
				? Math.abs( ringArea( ring ) )
				: -Math.abs( ringArea( ring ) ) ),
		0
	);

export const mpArea = ( mp ) =>
	mp.reduce( ( sum, poly ) => sum + polyArea( poly ), 0 );

export function bboxOf(
	points,
	box = [ Infinity, Infinity, -Infinity, -Infinity ]
) {
	for ( const p of points ) {
		if ( p[ 0 ] < box[ 0 ] ) {
			box[ 0 ] = p[ 0 ];
		}
		if ( p[ 1 ] < box[ 1 ] ) {
			box[ 1 ] = p[ 1 ];
		}
		if ( p[ 0 ] > box[ 2 ] ) {
			box[ 2 ] = p[ 0 ];
		}
		if ( p[ 1 ] > box[ 3 ] ) {
			box[ 3 ] = p[ 1 ];
		}
	}
	return box;
}

export function mpBbox( mp ) {
	const box = [ Infinity, Infinity, -Infinity, -Infinity ];
	for ( const poly of mp ) {
		bboxOf( poly[ 0 ] || [], box );
	}
	return box;
}

export const boxesTouch = ( a, b ) =>
	a[ 0 ] <= b[ 2 ] &&
	a[ 2 ] >= b[ 0 ] &&
	a[ 1 ] <= b[ 3 ] &&
	a[ 3 ] >= b[ 1 ];

export const frameRing = ( w, h ) => [
	[
		[ 0, 0 ],
		[ w, 0 ],
		[ w, h ],
		[ 0, h ],
		[ 0, 0 ],
	],
];

// polygon-clipping can throw on degenerate input. A failed step returns the fallback so one bad shape never stops a map.
export function safe( op, fallback, ...args ) {
	try {
		return polygonClipping[ op ]( ...args );
	} catch ( err ) {
		return fallback;
	}
}

export const clipToFrame = ( mp, w, h ) =>
	mp.length ? safe( 'intersection', [], mp, [ frameRing( w, h ) ] ) : [];

export const subtract = ( mp, clips ) =>
	mp.length && clips.length ? safe( 'difference', mp, mp, ...clips ) : mp;

export function unionAll( mps ) {
	const all = mps.filter( ( mp ) => mp && mp.length );
	if ( ! all.length ) {
		return [];
	}
	return safe( 'union', all.flat(), all[ 0 ], ...all.slice( 1 ) );
}

// Drops holes too small to draw and polygons whose outline is too small or broken.
export const dropSpecks = ( mp, minArea = 0.5 ) =>
	mp
		.filter(
			( poly ) =>
				poly[ 0 ] &&
				poly[ 0 ].length >= 4 &&
				Math.abs( ringArea( poly[ 0 ] ) ) > minArea
		)
		.map( ( poly ) =>
			poly.filter(
				( ring, i ) =>
					i === 0 ||
					( ring.length >= 4 &&
						Math.abs( ringArea( ring ) ) > minArea )
			)
		);
