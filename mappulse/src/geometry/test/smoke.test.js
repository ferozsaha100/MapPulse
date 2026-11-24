import { VERSION } from '../index';

describe( 'geometry library', () => {
	it( 'loads', () => {
		expect( typeof VERSION ).toBe( 'string' );
	} );
} );
