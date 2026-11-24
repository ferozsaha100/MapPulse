import { resolveOverlaps, DEFAULT_PRIORITY } from '../overlaps';
import { mpArea } from '../shapes';
import polygonClipping from 'polygon-clipping';
import { square } from '../test-helpers';

const zone = ( id, type, mp, extra = {} ) => ( { id, type, mp, ...extra } );

describe( 'resolveOverlaps', () => {
	it( 'cuts the higher-priority zone out of the lower one', () => {
		const out = resolveOverlaps(
			[
				zone( 'zip', 'zip', square( 0, 0, 1 ) ),
				zone( 'hood', 'neighborhood', square( 0.5, 0, 1 ) ),
			],
			DEFAULT_PRIORITY
		);
		const zip = out.find( ( z ) => z.id === 'zip' );
		expect( out[ 0 ].id ).toBe( 'hood' );
		expect( mpArea( zip.mp ) ).toBeCloseTo( 0.5 );
		expect( zip.cutShare ).toBeCloseTo( 0.5 );
		expect( mpArea( out[ 0 ].mp ) ).toBeCloseTo( 1 );
	} );

	it( 'leaves a backdrop whole and does not cut with it', () => {
		const out = resolveOverlaps(
			[
				zone( 'zip', 'zip', square( 0, 0, 1 ), { backdrop: true } ),
				zone( 'hood', 'neighborhood', square( 0.5, 0, 1 ) ),
				zone( 'county', 'county', square( 0, 0, 1 ) ),
			],
			DEFAULT_PRIORITY
		);
		const byId = Object.fromEntries( out.map( ( z ) => [ z.id, z ] ) );
		expect( mpArea( byId.zip.mp ) ).toBeCloseTo( 1 );
		expect( byId.zip.cutShare ).toBe( 0 );
		expect( mpArea( byId.county.mp ) ).toBeCloseTo( 0.5 );
	} );

	it( 'keeps list order between zones of one type and leaves no overlap', () => {
		const out = resolveOverlaps(
			[
				zone( 'a', 'neighborhood', square( 0, 0, 2 ) ),
				zone( 'b', 'neighborhood', square( 1, 1, 2 ) ),
				zone( 'c', 'city', square( 0, 0, 4 ) ),
			],
			DEFAULT_PRIORITY
		);
		expect( mpArea( out.find( ( z ) => z.id === 'a' ).mp ) ).toBeCloseTo(
			4
		);
		expect( mpArea( out.find( ( z ) => z.id === 'b' ).mp ) ).toBeCloseTo(
			3
		);
		for ( let i = 0; i < out.length; i++ ) {
			for ( let j = i + 1; j < out.length; j++ ) {
				expect(
					mpArea(
						polygonClipping.intersection( out[ i ].mp, out[ j ].mp )
					)
				).toBeCloseTo( 0 );
			}
		}
	} );

	it( 'counts the parts a cut leaves', () => {
		const out = resolveOverlaps(
			[
				zone( 'bar', 'neighborhood', [
					[
						[
							[ 1, -1 ],
							[ 2, -1 ],
							[ 2, 4 ],
							[ 1, 4 ],
							[ 1, -1 ],
						],
					],
				] ),
				zone( 'wide', 'zip', square( 0, 0, 3 ) ),
			],
			DEFAULT_PRIORITY
		);
		expect( out.find( ( z ) => z.id === 'wide' ).parts ).toBe( 2 );
	} );

	it( 'lets a zone marked first win over an earlier zone of the same type, but not over a higher type', () => {
		const out = resolveOverlaps(
			[
				zone( 'early', 'city', square( 0, 0, 2 ) ),
				zone( 'solid', 'city', square( 1, 0, 2 ), { first: true } ),
				zone( 'hood', 'neighborhood', square( 2, 0, 0.5 ) ),
			],
			DEFAULT_PRIORITY
		);
		const byId = Object.fromEntries( out.map( ( z ) => [ z.id, z ] ) );
		expect( mpArea( byId.early.mp ) ).toBeCloseTo( 2 );
		expect( mpArea( byId.solid.mp ) ).toBeCloseTo( 4 - 0.25 );
		expect( mpArea( byId.hood.mp ) ).toBeCloseTo( 0.25 );
	} );
} );
