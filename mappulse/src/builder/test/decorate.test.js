import { decorate } from '../decorate';
import { signature, drawSignature } from '../areas/plan';

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
const built = () => ( {
	schema: 1,
	title: 't',
	style: { zone: '#DCCFB6', labelSize: 11.5 },
	zones: [
		{
			id: 'a',
			name: 'a',
			link: '',
			text: '',
			geometry: { type: 'Polygon' },
			built: true,
		},
		{ id: 'b', name: 'b', link: '', text: '', built: true, hidden: true },
	],
	topology: { zones: { a: { d: 'M0 0L1 1Z' } } },
} );

describe( 'decorate', () => {
	it( 'puts the style, links, descriptions and labels onto a built document', () => {
		const doc = decorate( built(), {
			style: { zone: '#112233', labelSize: 11.5, preset: 'brand-tint' },
			zones: [
				zone( 'a', {
					link: 'https://example.com/a/',
					linkPost: 12,
					text: 'Waterfront',
					label: { text: 'Alpha', dx: 8, dy: 0, hidden: false },
				} ),
				zone( 'b' ),
			],
		} );
		expect( doc.style ).toEqual( {
			zone: '#112233',
			labelSize: 11.5,
			preset: 'brand-tint',
		} );
		expect( doc.zones[ 0 ] ).toMatchObject( {
			link: 'https://example.com/a/',
			linkPost: 12,
			text: 'Waterfront',
			label: { text: 'Alpha', dx: 8, dy: 0, hidden: false },
			geometry: { type: 'Polygon' },
		} );
		expect( doc.zones[ 1 ] ).toEqual( {
			id: 'b',
			name: 'b',
			link: '',
			text: '',
			built: true,
			hidden: true,
		} );
		expect( doc.topology ).toEqual( built().topology );
	} );

	it( 'removes a link, a label and a page reference that were cleared', () => {
		const first = decorate( built(), {
			style: {},
			zones: [
				zone( 'a', {
					link: 'https://example.com/a/',
					linkPost: 12,
					label: { text: 'Alpha', dx: 0, dy: 0, hidden: false },
				} ),
				zone( 'b' ),
			],
		} );
		const second = decorate( first, {
			style: {},
			zones: [ zone( 'a' ), zone( 'b' ) ],
		} );
		expect( second.zones[ 0 ].link ).toBe( '' );
		expect( second.zones[ 0 ] ).not.toHaveProperty( 'linkPost' );
		expect( second.zones[ 0 ] ).not.toHaveProperty( 'label' );
	} );

	it( 'leaves the document it was given untouched and passes null through', () => {
		const doc = built();
		decorate( doc, {
			style: { zone: '#000000' },
			zones: [ zone( 'a', { link: 'https://example.com/' } ) ],
		} );
		expect( doc ).toEqual( built() );
		expect( decorate( null, { style: {}, zones: [] } ) ).toBeNull();
	} );
} );

describe( 'signatures', () => {
	const base = {
		zones: [ zone( 'a' ) ],
		priority: PRIORITY,
		style: { zone: '#DCCFB6', labelSize: 11.5 },
	};

	it( 'a color, a link, a description or a label changes the drawing but not the geometry', () => {
		for ( const changed of [
			{ ...base, style: { ...base.style, zone: '#000000' } },
			{
				...base,
				zones: [ zone( 'a', { link: 'https://example.com/' } ) ],
			},
			{ ...base, zones: [ zone( 'a', { text: 'Note' } ) ] },
			{
				...base,
				zones: [
					zone( 'a', {
						label: { text: 'A', dx: 0, dy: 0, hidden: false },
					} ),
				],
			},
		] ) {
			expect( signature( changed ) ).toBe( signature( base ) );
			expect( drawSignature( changed ) ).not.toBe(
				drawSignature( base )
			);
		}
	} );

	it( 'the label size changes the geometry, because label boxes are sized from it', () => {
		expect(
			signature( { ...base, style: { ...base.style, labelSize: 14 } } )
		).not.toBe( signature( base ) );
	} );

	it( 'works without a style', () => {
		expect( signature( { zones: [], priority: PRIORITY } ) ).toBe(
			signature( { zones: [], priority: PRIORITY, style: {} } )
		);
	} );
} );
