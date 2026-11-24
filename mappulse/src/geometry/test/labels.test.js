import { labelPoint, labelLines, separateLabels, slug } from '../labels';
import polygonClipping from 'polygon-clipping';
import { square } from '../test-helpers';

describe( 'labels', () => {
	it( 'puts the label point inside an L-shaped zone', () => {
		const shape = polygonClipping.difference(
			square( 0, 0, 100 ),
			square( 40, 40, 60 )
		);
		const point = labelPoint( shape );
		const dot = square( point.x - 0.5, point.y - 0.5, 1 );
		expect( polygonClipping.intersection( shape, dot )[ 0 ] ).toBeDefined();
		expect( polygonClipping.difference( dot, shape ) ).toEqual( [] );
	} );

	it( 'returns null for an empty shape', () => {
		expect( labelPoint( [] ) ).toBeNull();
	} );

	it( 'breaks a long name over two lines and leaves a short one alone', () => {
		expect( labelLines( 'Old Northeast' ) ).toEqual( [
			'OLD',
			'NORTHEAST',
		] );
		expect( labelLines( 'Kenwood' ) ).toEqual( [ 'KENWOOD' ] );
	} );

	it( 'pushes two stacked labels apart and keeps them in the frame', () => {
		const items = separateLabels(
			[
				{ x: 50, y: 50, w: 40, h: 12 },
				{ x: 52, y: 51, w: 40, h: 12 },
			],
			{ width: 200, height: 100 }
		);
		expect(
			Math.abs( items[ 0 ].y - items[ 1 ].y )
		).toBeGreaterThanOrEqual( 12 );
	} );

	it( 'makes a slug', () => {
		expect( slug( 'St. Pete Beach' ) ).toBe( 'st-pete-beach' );
	} );
} );
