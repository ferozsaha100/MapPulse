import { makeProjection, fitFrame } from '../project';

describe( 'projection and frame', () => {
	it( 'squeezes longitude by the cosine of the latitude and points north up', () => {
		const project = makeProjection( 60 );
		const [ x, y ] = project( [ 10, 20 ] );
		expect( x ).toBeCloseTo( 5 );
		expect( y ).toBeCloseTo( -20 );
	} );

	it( 'caps a wide box at an aspect of 1.6', () => {
		const frame = fitFrame( [ 0, 0, 2, 1 ], { padding: 0 } );
		expect( frame.width ).toBe( 1000 );
		expect( frame.height ).toBe( 625 );
		expect( frame.toPx( [ 1, 0.5 ] )[ 0 ] ).toBeCloseTo( 500 );
		expect( frame.toPx( [ 1, 0.5 ] )[ 1 ] ).toBeCloseTo( 312.5 );
	} );

	it( 'widens a tall box to a square', () => {
		const frame = fitFrame( [ 0, 0, 1, 3 ], { padding: 0 } );
		expect( frame.height ).toBe( 1000 );
	} );
} );
