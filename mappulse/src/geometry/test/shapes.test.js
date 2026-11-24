import {
	toMP,
	toLines,
	mpArea,
	mpBbox,
	boxesTouch,
	unionAll,
	subtract,
	clipToFrame,
} from '../shapes';
import { square } from '../test-helpers';

describe( 'shapes', () => {
	it( 'reads polygons, multipolygons and collections', () => {
		expect(
			toMP( { type: 'Polygon', coordinates: square( 0, 0, 1 )[ 0 ] } )
		).toHaveLength( 1 );
		expect(
			toMP( {
				type: 'MultiPolygon',
				coordinates: [ ...square( 0, 0, 1 ), ...square( 5, 5, 1 ) ],
			} )
		).toHaveLength( 2 );
		expect( toMP( { type: 'Point', coordinates: [ 0, 0 ] } ) ).toEqual(
			[]
		);
		expect( toMP( null ) ).toEqual( [] );
		expect(
			toLines( {
				type: 'LineString',
				coordinates: [
					[ 0, 0 ],
					[ 1, 1 ],
				],
			} )
		).toHaveLength( 1 );
	} );

	it( 'measures area and bounds', () => {
		expect( mpArea( square( 0, 0, 2 ) ) ).toBeCloseTo( 4 );
		expect( mpBbox( square( 1, 2, 3 ) ) ).toEqual( [ 1, 2, 4, 5 ] );
		expect( boxesTouch( [ 0, 0, 1, 1 ], [ 2, 2, 3, 3 ] ) ).toBe( false );
	} );

	it( 'unions, subtracts and clips', () => {
		expect(
			mpArea( unionAll( [ square( 0, 0, 2 ), square( 1, 0, 2 ) ] ) )
		).toBeCloseTo( 6 );
		expect(
			mpArea( subtract( square( 0, 0, 2 ), [ square( 1, 0, 2 ) ] ) )
		).toBeCloseTo( 2 );
		expect(
			mpArea( clipToFrame( square( -1, -1, 2 ), 10, 10 ) )
		).toBeCloseTo( 1 );
		expect( unionAll( [] ) ).toEqual( [] );
	} );
} );
