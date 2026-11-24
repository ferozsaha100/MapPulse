import { pathOfMP, pathOfLines } from '../paths';
import { square } from '../test-helpers';

// The pattern Map_Document::PATH_PATTERN enforces on the PHP side.
const ALLOWED = /^[MmLlHhVvCcSsQqTtAaZz0-9 ,.\-]*$/;

describe( 'paths', () => {
	it( 'writes closed rings with one decimal place', () => {
		const d = pathOfMP( square( 0, 0, 10.26 ) );
		expect( d ).toBe( 'M0 0L10.3 0L10.3 10.3L0 10.3Z' );
		expect( d ).toMatch( ALLOWED );
	} );

	it( 'writes negative and tiny numbers safely', () => {
		const d = pathOfMP( [
			[
				[
					[ -1.04, 1e-9 ],
					[ 5, 5 ],
					[ -3, 8 ],
					[ -1.04, 1e-9 ],
				],
			],
		] );
		expect( d ).toMatch( ALLOWED );
		expect( d ).not.toMatch( /e/i );
		expect( d.startsWith( 'M-1 0L' ) ).toBe( true );
	} );

	it( 'writes open lines and drops single points', () => {
		expect(
			pathOfLines( [
				[
					[ 0, 0 ],
					[ 1, 1 ],
				],
				[ [ 5, 5 ] ],
			] )
		).toBe( 'M0 0L1 1' );
		expect( pathOfMP( [] ) ).toBe( '' );
	} );
} );
