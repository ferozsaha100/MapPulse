import { cleanOutline, needsCleaning, cleanAdvice } from '../clean';
import { toMP, mpArea, mapMP } from '../shapes';
import { makeProjection } from '../project';
import { square, metres } from '../test-helpers';
import fisher from './fixtures/fisher-island.json';
import gulfport from './fixtures/gulfport.json';

const shapeOf = ( collection ) => toMP( collection.features[ 0 ].geometry );
// Area in projected units, so shapes at one latitude compare fairly.
const area = ( mp, lat ) => mpArea( mapMP( mp, makeProjection( lat ) ) );
const holes = ( mp ) => mp.reduce( ( n, poly ) => n + poly.length - 1, 0 );

describe( 'cleanOutline', () => {
	it( 'turns the fragmented Fisher Island record into one solid shape', () => {
		const raw = shapeOf( fisher );
		expect( raw ).toHaveLength( 14 );
		const out = cleanOutline( raw );
		expect( out.partsBefore ).toBe( 14 );
		expect( out.partsAfter ).toBe( 1 );
		expect( out.mp ).toHaveLength( 1 );
		expect( holes( out.mp ) ).toBe( 0 );
		expect( out.changed ).toBe( true );
		const ratio = area( out.mp, 25.76 ) / area( raw, 25.76 );
		expect( ratio ).toBeGreaterThan( 1 );
		expect( ratio ).toBeLessThan( 1.6 );
	} );

	// Gulfport is one piece with no holes, so it is not cleaned by default. Cleaning it anyway fills one
	// narrow-necked inlet, which is 1.4% of the city.
	it( 'changes an ordinary city by less than 2% of its area', () => {
		const raw = shapeOf( gulfport );
		const out = cleanOutline( raw );
		const ratio = area( out.mp, 27.75 ) / area( raw, 27.75 );
		expect( out.partsAfter ).toBe( raw.length );
		expect( ratio ).toBeGreaterThan( 0.999 );
		expect( ratio ).toBeLessThan( 1.02 );
	} );

	it( 'does not bridge open water between two islands', () => {
		const islands = [
			...square( 0, 0, metres( 400 ) ),
			...square( metres( 1000 ), 0, metres( 400 ) ),
		];
		const out = cleanOutline( islands );
		expect( out.partsAfter ).toBe( 2 );
		expect( area( out.mp, 0 ) / area( islands, 0 ) ).toBeCloseTo( 1, 1 );
	} );

	it( 'joins two blocks a street apart', () => {
		const blocks = [
			...square( 0, 0, metres( 200 ) ),
			...square( metres( 240 ), 0, metres( 200 ) ),
		];
		expect( cleanOutline( blocks ).partsAfter ).toBe( 1 );
	} );

	it( 'fills a hole', () => {
		const ring = [
			[
				square( 0, 0, metres( 500 ) )[ 0 ][ 0 ],
				[
					...square(
						metres( 200 ),
						metres( 200 ),
						metres( 100 )
					)[ 0 ][ 0 ],
				].reverse(),
			],
		];
		const out = cleanOutline( ring );
		expect( holes( out.mp ) ).toBe( 0 );
		expect( out.changed ).toBe( true );
	} );

	it( 'keeps a hole larger than the share asked for', () => {
		const ring = [
			[
				square( 0, 0, metres( 1000 ) )[ 0 ][ 0 ],
				[
					...square(
						metres( 200 ),
						metres( 200 ),
						metres( 600 )
					)[ 0 ][ 0 ],
				].reverse(),
			],
		];
		expect( holes( cleanOutline( ring, { minHoleShare: 0.1 } ).mp ) ).toBe(
			1
		);
	} );

	it( 'answers an empty shape with an empty result', () => {
		expect( cleanOutline( [] ) ).toEqual( {
			mp: [],
			changed: false,
			partsBefore: 0,
			partsAfter: 0,
		} );
	} );
} );

describe( 'needsCleaning', () => {
	it( 'is true for a shape in pieces or with holes and false for a plain one', () => {
		expect( needsCleaning( shapeOf( fisher ) ) ).toBe( true );
		expect( needsCleaning( square( 0, 0, 1 ) ) ).toBe( false );
		expect( needsCleaning( shapeOf( gulfport ) ) ).toBe( false );
		expect( needsCleaning( [] ) ).toBe( false );
	} );
} );

describe( 'cleanAdvice', () => {
	const enclave = [
		[
			square( 0, 0, metres( 2000 ) )[ 0 ][ 0 ],
			[
				...square(
					metres( 800 ),
					metres( 800 ),
					metres( 400 )
				)[ 0 ][ 0 ],
			].reverse(),
		],
	];

	it( 'recommends cleaning a place drawn as many pieces', () => {
		expect( cleanAdvice( shapeOf( fisher ) ) ).toEqual( {
			available: true,
			recommended: true,
		} );
	} );

	it( 'offers but does not recommend it for a city with an enclave', () => {
		expect( cleanAdvice( enclave ) ).toEqual( {
			available: true,
			recommended: false,
		} );
	} );

	it( 'does not recommend it for islands that stay apart', () => {
		const islands = [
			...square( 0, 0, metres( 400 ) ),
			...square( metres( 1000 ), 0, metres( 400 ) ),
		];
		expect( cleanAdvice( islands ) ).toEqual( {
			available: true,
			recommended: false,
		} );
	} );

	it( 'recommends joining two blocks a street apart', () => {
		const blocks = [
			...square( 0, 0, metres( 200 ) ),
			...square( metres( 240 ), 0, metres( 200 ) ),
		];
		expect( cleanAdvice( blocks ).recommended ).toBe( false );
		const many = [ 0, 1, 2, 3 ].map(
			( i ) => square( metres( i * 240 ), 0, metres( 200 ) )[ 0 ]
		);
		expect( cleanAdvice( many ).recommended ).toBe( true );
	} );

	it( 'is not offered for a plain shape', () => {
		expect( cleanAdvice( shapeOf( gulfport ) ) ).toEqual( {
			available: false,
			recommended: false,
		} );
		expect( cleanAdvice( [] ) ).toEqual( {
			available: false,
			recommended: false,
		} );
	} );
} );
