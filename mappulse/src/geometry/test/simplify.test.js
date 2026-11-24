import { simplifyTogether, polyFeature } from '../simplify';
import { square } from '../test-helpers';

describe( 'simplifyTogether', () => {
	it( 'keeps a border shared by two shapes identical in both', () => {
		// Two squares side by side with a wobbly shared edge at x = 100.
		const wobble = [
			[ 100, 0 ],
			[ 100.2, 25 ],
			[ 99.9, 50 ],
			[ 100.1, 75 ],
			[ 100, 100 ],
		];
		const left = [ [ [ [ 0, 0 ], ...wobble, [ 0, 100 ], [ 0, 0 ] ] ] ];
		const right = [
			[
				[
					[ 200, 0 ],
					[ 200, 100 ],
					...[ ...wobble ].reverse(),
					[ 200, 0 ],
				],
			],
		];
		const out = simplifyTogether(
			{
				zones: [
					polyFeature( left, { i: 0 } ),
					polyFeature( right, { i: 1 } ),
				],
			},
			{ minAreaPx: 30 }
		);
		// The points of a shape that lie on the shared border, without the repeat that closes a ring.
		const onEdge = ( f ) =>
			[
				...new Set(
					f.geometry.coordinates
						.flat( 2 )
						.filter( ( p ) => p[ 0 ] > 50 && p[ 0 ] < 150 )
						.map( ( p ) => p.join( ',' ) )
				),
			].sort();
		expect( out.zones ).toHaveLength( 2 );
		expect( onEdge( out.zones[ 0 ] ) ).toEqual( onEdge( out.zones[ 1 ] ) );
		expect( onEdge( out.zones[ 0 ] ).length ).toBeLessThan( 5 );
	} );

	it( 'returns every layer it was given', () => {
		const out = simplifyTogether( {
			a: [ polyFeature( square( 0, 0, 10 ), {} ) ],
			b: [],
		} );
		expect( out.a ).toHaveLength( 1 );
		expect( out.b ).toEqual( [] );
	} );
} );
