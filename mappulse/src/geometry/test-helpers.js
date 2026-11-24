// Shared shapes for the geometry tests.
export const square = ( x, y, size ) => [
	[
		[
			[ x, y ],
			[ x + size, y ],
			[ x + size, y + size ],
			[ x, y + size ],
			[ x, y ],
		],
	],
];

export const withHole = ( outer, hole ) => [
	[ outer[ 0 ][ 0 ], hole[ 0 ][ 0 ] ],
];

// About 111 320 m per degree of latitude; these helpers place shapes near latitude 0 so one degree is the same both ways.
export const metres = ( m ) => m / 111320;

export const feature = ( mp, properties = {} ) => ( {
	type: 'Feature',
	properties,
	geometry: { type: 'MultiPolygon', coordinates: mp },
} );

export const collection = ( features = [] ) => ( {
	type: 'FeatureCollection',
	features,
} );
