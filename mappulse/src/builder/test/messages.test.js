import { warningText } from '../messages';

describe( 'warningText', () => {
	it( 'has words for every warning the map builder gives', () => {
		for ( const code of [
			'no_shape',
			'mostly_cut',
			'in_pieces',
			'tiny',
			'under_water',
			'too_small',
		] ) {
			expect( warningText( code ).length ).toBeGreaterThan( 20 );
		}
		expect(
			new Set(
				[
					'no_shape',
					'mostly_cut',
					'in_pieces',
					'tiny',
					'under_water',
					'too_small',
				].map( warningText )
			).size
		).toBe( 6 );
	} );

	it( 'still says something for a code it does not know', () => {
		expect( warningText( 'other' ) ).not.toBe( '' );
	} );
} );
