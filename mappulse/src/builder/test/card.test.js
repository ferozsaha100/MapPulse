import { cleanCard, emptyCard, hasCard, copyLayout } from '../links/card';
import { decorate } from '../decorate';
import { signature, drawSignature } from '../areas/plan';
import { reduce, initialState } from '../store';

const PRIORITY = [ 'neighborhood', 'city', 'zip', 'county' ];
const zone = ( id, extra = {} ) => ( {
	id,
	name: id,
	type: 'city',
	source: 'census',
	record: id,
	backdrop: false,
	clean: false,
	...extra,
} );
const full = () => ( {
	image: 12,
	imageUrl: 'https://example.com/thumb.jpg',
	tagline: '  Waterfront living  ',
	stats: [
		{ label: 'Median price', value: '$640,000' },
		{ label: '', value: '' },
		{ label: 'Third', value: 'x' },
	],
	buttons: [
		{ label: 'Search homes', url: 'https://example.com/idx/', post: 0 },
		{ label: 'No address yet', url: '', post: 0 },
		{ label: '', url: 'https://example.com/unnamed/', post: 0 },
	],
} );

describe( 'cleanCard', () => {
	it( 'keeps what can be shown and drops the rest', () => {
		expect( cleanCard( full() ) ).toEqual( {
			image: 12,
			tagline: 'Waterfront living',
			// An empty row between two filled ones is skipped, as the server does.
			stats: [
				{ label: 'Median price', value: '$640,000' },
				{ label: 'Third', value: 'x' },
			],
			buttons: [
				{
					label: 'Search homes',
					url: 'https://example.com/idx/',
					post: 0,
				},
			],
		} );
	} );

	it( 'keeps a fact that has only a label or only a value', () => {
		expect(
			cleanCard( {
				stats: [
					{ label: 'Schools', value: '' },
					{ label: '', value: 'A+' },
				],
			} ).stats
		).toEqual( [
			{ label: 'Schools', value: '' },
			{ label: '', value: 'A+' },
		] );
	} );

	it( 'caps counts and lengths', () => {
		const card = cleanCard( {
			tagline: 'a'.repeat( 300 ),
			stats: [ 1, 2, 3 ].map( ( n ) => ( {
				label: 'b'.repeat( 40 ),
				value: String( n ),
			} ) ),
			buttons: [ 1, 2, 3 ].map( ( n ) => ( {
				label: `B${ n }`,
				url: `https://example.com/${ n }/`,
				post: n,
			} ) ),
		} );
		expect( card.tagline ).toHaveLength( 120 );
		expect( card.stats ).toHaveLength( 2 );
		expect( card.stats[ 0 ].label ).toHaveLength( 24 );
		expect( card.buttons.map( ( b ) => b.post ) ).toEqual( [ 1, 2 ] );
	} );

	it( 'is null when nothing is left', () => {
		expect( cleanCard( emptyCard() ) ).toBeNull();
		expect( cleanCard( null ) ).toBeNull();
		expect(
			cleanCard( {
				tagline: '   ',
				buttons: [ { label: 'x', url: 'javascript:alert(1)' } ],
			} )
		).toBeNull();
		expect( hasCard( { card: emptyCard() } ) ).toBe( false );
		expect( hasCard( { card: full() } ) ).toBe( true );
	} );
} );

describe( 'cards in the builder state', () => {
	it( 'a card changes the drawing but not the shapes', () => {
		const base = { zones: [ zone( 'a' ) ], priority: PRIORITY, style: {} };
		const withCard = { ...base, zones: [ zone( 'a', { card: full() } ) ] };
		expect( signature( withCard ) ).toBe( signature( base ) );
		expect( drawSignature( withCard ) ).not.toBe( drawSignature( base ) );
		// A half-typed button changes nothing that is drawn or stored.
		const draft = {
			...base,
			zones: [
				zone( 'a', {
					card: {
						...emptyCard(),
						buttons: [ { label: 'Soon', url: '', post: 0 } ],
					},
				} ),
			],
		};
		expect( drawSignature( draft ) ).toBe( drawSignature( base ) );
	} );

	it( 'is written onto the document clean, and removed when emptied', () => {
		const doc = {
			schema: 1,
			style: {},
			zones: [ { id: 'a', name: 'a', link: '', text: '' } ],
		};
		const out = decorate( doc, {
			style: {},
			zones: [ zone( 'a', { card: full() } ) ],
		} );
		expect( out.zones[ 0 ].card ).toEqual( cleanCard( full() ) );
		expect( out.zones[ 0 ].card ).not.toHaveProperty( 'imageUrl' );
		expect(
			decorate( out, {
				style: {},
				zones: [ zone( 'a', { card: emptyCard() } ) ],
			} ).zones[ 0 ]
		).not.toHaveProperty( 'card' );
	} );

	it( 'comes back when a saved map is opened', () => {
		const geometry = {
			type: 'Polygon',
			coordinates: [
				[
					[ 0, 0 ],
					[ 1, 0 ],
					[ 1, 1 ],
					[ 0, 0 ],
				],
			],
		};
		const document = {
			schema: 1,
			title: 'T',
			style: {},
			zones: [
				{
					id: 'a',
					name: 'A',
					type: 'city',
					source: { layer: 'census', record: 'place:1' },
					geometry,
					card: cleanCard( full() ),
				},
			],
		};
		const state = reduce( initialState(), {
			type: 'LOADED',
			map: { id: 5, title: 'T', status: 'draft', document },
		} );
		expect( state.zones[ 0 ].card ).toMatchObject( {
			image: 12,
			tagline: 'Waterfront living',
		} );
	} );
} );

describe( 'copyLayout', () => {
	it( 'gives areas without a card the fact and button labels, not the values, photo or links', () => {
		const zones = [
			zone( 'a', { card: full() } ),
			zone( 'b' ),
			zone( 'c', { card: { ...emptyCard(), tagline: 'Mine' } } ),
		];
		const out = copyLayout( zones, 'a' );
		expect( out ).toHaveLength( 1 );
		expect( out[ 0 ].id ).toBe( 'b' );
		expect( out[ 0 ].card ).toMatchObject( { image: 0, tagline: '' } );
		expect(
			out[ 0 ].card.stats.map( ( s ) => [ s.label, s.value ] )
		).toEqual( [
			[ 'Median price', '' ],
			[ '', '' ],
		] );
		expect(
			out[ 0 ].card.buttons.map( ( b ) => [ b.label, b.url ] )
		).toEqual( [
			[ 'Search homes', '' ],
			[ 'No address yet', '' ],
		] );
	} );
} );
