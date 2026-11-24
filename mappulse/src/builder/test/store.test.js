import {
	reduce,
	initialState,
	zoneFromResult,
	toDocument,
	zonesFromDocument,
	saveActions,
} from '../store';
import fisher from '../../geometry/test/fixtures/fisher-island.json';
import gulfport from '../../geometry/test/fixtures/gulfport.json';

const result = ( name, record, type = 'city' ) => ( {
	source: 'census',
	record,
	name,
	type,
	parent: 'Florida',
	center: null,
} );
const fisherZone = () =>
	zoneFromResult( result( 'Fisher Island', 'cdp:1222375' ), {
		...fisher.features[ 0 ],
		type: 'Feature',
	} );
const gulfportZone = () =>
	zoneFromResult( result( 'Gulfport', 'place:1228175' ), {
		...gulfport.features[ 0 ],
		type: 'Feature',
	} );
const add = ( state, zone ) => reduce( state, { type: 'ADD_ZONE', zone } );

describe( 'builder store', () => {
	it( 'adds a zone once, with a slug id, and marks the map as changed', () => {
		let state = add( initialState(), gulfportZone() );
		state = add( state, gulfportZone() );
		expect( state.zones ).toHaveLength( 1 );
		expect( state.zones[ 0 ].id ).toBe( 'gulfport' );
		expect( state.dirty ).toBe( true );
	} );

	it( 'gives two areas with one name different ids', () => {
		let state = add( initialState(), fisherZone() );
		state = add( state, { ...fisherZone(), record: 'cdp:9999999' } );
		expect( state.zones.map( ( z ) => z.id ) ).toEqual( [
			'fisher-island',
			'fisher-island-2',
		] );
	} );

	it( 'turns clean outline on only for a shape that needs it', () => {
		expect( fisherZone().clean ).toBe( true );
		expect( fisherZone().needsClean ).toBe( true );
		expect( gulfportZone().clean ).toBe( false );
	} );

	it( 'refuses zones past the limit', () => {
		let state = { ...initialState(), maxZones: 1 };
		state = add( state, gulfportZone() );
		state = add( state, fisherZone() );
		expect( state.zones ).toHaveLength( 1 );
		expect( state.error ).toMatch( /1 areas/ );
	} );

	it( 'moves, changes and removes zones', () => {
		let state = add( add( initialState(), gulfportZone() ), fisherZone() );
		state = reduce(
			{ ...state, dirty: false },
			{ type: 'MOVE_ZONE', from: 1, to: 0 }
		);
		expect( state.zones.map( ( z ) => z.id ) ).toEqual( [
			'fisher-island',
			'gulfport',
		] );
		expect( state.dirty ).toBe( true );

		state = reduce( state, { type: 'MOVE_ZONE', from: 0, to: 5 } );
		expect( state.zones.map( ( z ) => z.id ) ).toEqual( [
			'fisher-island',
			'gulfport',
		] );

		state = reduce(
			{ ...state, dirty: false },
			{
				type: 'SET_ZONE',
				id: 'gulfport',
				patch: { backdrop: true, id: 'hacked' },
			}
		);
		expect( state.zones[ 1 ].backdrop ).toBe( true );
		expect( state.zones[ 1 ].id ).toBe( 'gulfport' );
		expect( state.dirty ).toBe( true );

		state = reduce( state, { type: 'REMOVE_ZONE', id: 'fisher-island' } );
		expect( state.zones.map( ( z ) => z.id ) ).toEqual( [ 'gulfport' ] );
	} );

	it( 'tracks saving', () => {
		let state = add( initialState(), gulfportZone() );
		state = reduce( state, { type: 'SAVING' } );
		expect( state.saving ).toBe( true );
		state = reduce( state, {
			type: 'SAVED',
			mapId: 12,
			status: 'publish',
		} );
		expect( state ).toMatchObject( {
			saving: false,
			dirty: false,
			mapId: 12,
			status: 'publish',
			error: '',
		} );
		state = reduce( reduce( state, { type: 'SAVING' } ), {
			type: 'FAILED',
			message: 'No.',
		} );
		expect( state ).toMatchObject( { saving: false, error: 'No.' } );
	} );

	it( 'a title change marks the map as changed, a new drawing does not', () => {
		let state = reduce( initialState(), {
			type: 'SET_TITLE',
			title: 'South',
		} );
		expect( state.dirty ).toBe( true );
		state = reduce(
			{ ...state, dirty: false },
			{ type: 'SET_DOC', doc: { schema: 1 }, warnings: [] }
		);
		expect( state.dirty ).toBe( false );
	} );

	it( 'writes the document to save and reads it back into the same zones', () => {
		let state = add( add( initialState(), gulfportZone() ), fisherZone() );
		state = reduce( state, { type: 'SET_TITLE', title: 'Two places' } );
		const built = {
			schema: 1,
			title: '',
			priority: state.priority,
			zones: state.zones.map( ( z ) => ( {
				id: z.id,
				name: z.name,
				type: z.type,
				source: { layer: z.source, record: z.record },
				geometry: z.feature.geometry,
				backdrop: z.backdrop,
				clean: z.clean,
			} ) ),
		};
		state = reduce( state, { type: 'SET_DOC', doc: built, warnings: [] } );
		expect( toDocument( state ).title ).toBe( 'Two places' );

		const loaded = reduce( initialState(), {
			type: 'LOADED',
			map: {
				id: 7,
				title: 'Two places',
				status: 'draft',
				document: toDocument( state ),
			},
		} );
		expect( loaded ).toMatchObject( {
			mapId: 7,
			title: 'Two places',
			status: 'draft',
			dirty: false,
			legacy: false,
		} );
		expect(
			loaded.zones.map( ( z ) => [ z.id, z.source, z.record, z.clean ] )
		).toEqual( [
			[ 'gulfport', 'census', 'place:1228175', false ],
			[ 'fisher-island', 'census', 'cdp:1222375', true ],
		] );
		expect( loaded.zones[ 1 ].feature.geometry ).toEqual(
			fisher.features[ 0 ].geometry
		);
	} );

	it( 'opens a map made before the builder as read-only', () => {
		const old = {
			schema: 1,
			title: 'Old',
			zones: [
				{
					id: 'a',
					name: 'A',
					type: 'neighborhood',
					source: { layer: 'x', record: 'y' },
				},
			],
		};
		const loaded = reduce( initialState(), {
			type: 'LOADED',
			map: { id: 3, title: 'Old', status: 'publish', document: old },
		} );
		expect( loaded.legacy ).toBe( true );
		expect( zonesFromDocument( old ) ).toEqual( [] );
		expect( loaded.legacyZones ).toEqual( [
			{ id: 'a', name: 'A', type: 'neighborhood' },
		] );
		expect( add( loaded, gulfportZone() ).zones ).toEqual( [] );
	} );

	it( 'keeps a zone link and text through reopening', () => {
		const doc = {
			schema: 1,
			zones: [
				{
					id: 'a',
					name: 'A',
					type: 'city',
					source: { layer: 'census', record: 'place:1' },
					link: 'https://example.com/a',
					text: 'Note',
					geometry: gulfport.features[ 0 ].geometry,
				},
			],
		};
		expect( zonesFromDocument( doc )[ 0 ] ).toMatchObject( {
			link: 'https://example.com/a',
			text: 'Note',
		} );
	} );

	it( 'turns clean outline on by itself only where it clearly helps', () => {
		// A city with an enclave: cleaning is offered but off, because it would swallow the enclave.
		const enclave = {
			type: 'Feature',
			properties: {},
			geometry: {
				type: 'Polygon',
				coordinates: [
					[
						[ 0, 0 ],
						[ 0.2, 0 ],
						[ 0.2, 0.2 ],
						[ 0, 0.2 ],
						[ 0, 0 ],
					],
					[
						[ 0.08, 0.08 ],
						[ 0.08, 0.12 ],
						[ 0.12, 0.12 ],
						[ 0.12, 0.08 ],
						[ 0.08, 0.08 ],
					],
				],
			},
		};
		const city = zoneFromResult(
			result( 'Ring City', 'place:2' ),
			enclave
		);
		expect( city.needsClean ).toBe( true );
		expect( city.clean ).toBe( false );
	} );
} );

describe( 'a map that fails to open', () => {
	it( 'blocks the builder, so the map cannot be overwritten with an empty one', () => {
		const state = reduce( initialState( { mapId: 9 } ), {
			type: 'FAILED',
			message: 'Gone.',
		} );
		expect( state ).toMatchObject( {
			blocked: true,
			loading: false,
			error: 'Gone.',
		} );
		expect(
			saveActions(
				{ ...state, doc: { schema: 1 }, zones: [ {} ] },
				{ busy: false, current: true }
			).canSave
		).toBe( false );
	} );

	it( 'does not block after a failed save', () => {
		let state = reduce( initialState(), { type: 'SAVING' } );
		state = reduce( state, { type: 'FAILED', message: 'No.' } );
		expect( state.blocked ).toBe( false );
	} );
} );

describe( 'saveActions', () => {
	const ready = {
		doc: { schema: 1 },
		zones: [ {} ],
		saving: false,
		legacy: false,
		mapId: 0,
		status: 'draft',
	};
	const drawn = { busy: false, current: true };

	it( 'allows saving only when the drawing matches the area list', () => {
		expect( saveActions( ready, drawn ).canSave ).toBe( true );
		expect(
			saveActions( ready, { busy: true, current: true } ).canSave
		).toBe( false );
		expect(
			saveActions( ready, { busy: false, current: false } ).canSave
		).toBe( false );
		expect( saveActions( { ...ready, zones: [] }, drawn ).canSave ).toBe(
			false
		);
		expect( saveActions( { ...ready, saving: true }, drawn ).canSave ).toBe(
			false
		);
		expect( saveActions( { ...ready, doc: null }, drawn ).canSave ).toBe(
			false
		);
	} );

	it( 'does not offer Save draft on a published map, which would take it off the site', () => {
		expect( saveActions( ready, drawn ).showDraft ).toBe( true );
		expect(
			saveActions( { ...ready, mapId: 4, status: 'draft' }, drawn )
				.showDraft
		).toBe( true );
		expect(
			saveActions( { ...ready, mapId: 4, status: 'publish' }, drawn )
		).toMatchObject( { showDraft: false, primary: 'update' } );
	} );

	it( 'lets an imported map be renamed', () => {
		const legacy = {
			...ready,
			legacy: true,
			zones: [],
			mapId: 3,
			status: 'publish',
		};
		expect(
			saveActions( legacy, { busy: false, current: false } ).canSave
		).toBe( true );
	} );
} );

describe( 'style and steps', () => {
	const DEFAULTS = {
		zone: '#DCCFB6',
		zoneHover: '#1F3A5F',
		label: '#2B2F36',
		labelHover: '#FFFFFF',
		labelFont: 'Inter, sans-serif',
		labelSize: 11.5,
		preset: 'atlas',
		brand: '',
		zoneStrokeWidth: 1.6,
		zoneStrokeStyle: 'solid',
		hoverEffect: 'fill',
	};
	const start = () => initialState( { styleDefaults: DEFAULTS } );

	it( 'starts with the built-in style on the Areas step', () => {
		expect( start() ).toMatchObject( { step: 'areas', style: DEFAULTS } );
	} );

	it( 'opens the other steps only once the map has an area', () => {
		expect(
			reduce( start(), { type: 'SET_STEP', step: 'style' } ).step
		).toBe( 'areas' );
		const withArea = add( start(), gulfportZone() );
		expect(
			reduce( withArea, { type: 'SET_STEP', step: 'style' } ).step
		).toBe( 'style' );
		expect(
			reduce( withArea, { type: 'SET_STEP', step: 'links' } ).step
		).toBe( 'links' );
		expect(
			reduce( withArea, { type: 'SET_STEP', step: 'nope' } ).step
		).toBe( 'areas' );
		// Removing the last area returns to Areas.
		const onStyle = reduce( withArea, { type: 'SET_STEP', step: 'style' } );
		expect(
			reduce( onStyle, { type: 'REMOVE_ZONE', id: 'gulfport' } ).step
		).toBe( 'areas' );
	} );

	it( 'changes one style value and marks the map as changed', () => {
		const state = reduce(
			{ ...start(), dirty: false },
			{ type: 'SET_STYLE', patch: { zone: '#000000' } }
		);
		expect( state.style ).toEqual( { ...DEFAULTS, zone: '#000000' } );
		expect( state.dirty ).toBe( true );
	} );

	it( 'applies a preset to the colors and keeps the font and size', () => {
		let state = reduce( start(), {
			type: 'SET_STYLE',
			patch: { labelFont: 'Georgia, serif', labelSize: 14 },
		} );
		state = reduce( state, {
			type: 'APPLY_PRESET',
			name: 'brand-tint',
			brand: '#7A1F3D',
		} );
		expect( state.style ).toMatchObject( {
			preset: 'brand-tint',
			brand: '#7A1F3D',
			zoneHover: '#7A1F3D',
			labelFont: 'Georgia, serif',
			labelSize: 14,
		} );
		state = reduce( state, { type: 'APPLY_PRESET', name: 'atlas' } );
		expect( state.style ).toMatchObject( {
			preset: 'atlas',
			zone: '#DCCFB6',
			labelFont: 'Georgia, serif',
		} );
	} );

	it( 'sets a link, a description and a label on a zone, and marks the map as changed', () => {
		let state = { ...add( start(), gulfportZone() ), dirty: false };
		state = reduce( state, {
			type: 'SET_ZONE',
			id: 'gulfport',
			patch: {
				link: 'https://example.com/g/',
				linkPost: 4,
				text: 'Bay town',
				label: { text: 'GP', dx: 8, dy: 0, hidden: false },
			},
		} );
		expect( state.zones[ 0 ] ).toMatchObject( {
			link: 'https://example.com/g/',
			linkPost: 4,
			text: 'Bay town',
			label: { text: 'GP', dx: 8 },
		} );
		expect( state.dirty ).toBe( true );
	} );

	it( 'reads the style, links and labels back from a saved map', () => {
		const document = {
			schema: 1,
			title: 'T',
			style: { zone: '#112233', preset: 'brand-tint', brand: '#112233' },
			zones: [
				{
					id: 'gulfport',
					name: 'Gulfport',
					type: 'city',
					source: { layer: 'census', record: 'place:1228175' },
					link: 'https://example.com/g/',
					linkPost: 4,
					text: 'Bay town',
					label: { text: 'GP', dx: 8, dy: 0, hidden: false },
					geometry: gulfport.features[ 0 ].geometry,
					built: true,
				},
			],
		};
		const state = reduce( start(), {
			type: 'LOADED',
			map: { id: 5, title: 'T', status: 'draft', document },
		} );
		expect( state.style ).toEqual( {
			...DEFAULTS,
			zone: '#112233',
			preset: 'brand-tint',
			brand: '#112233',
		} );
		expect( state.zones[ 0 ] ).toMatchObject( {
			link: 'https://example.com/g/',
			linkPost: 4,
			text: 'Bay town',
			label: { text: 'GP', dx: 8, dy: 0, hidden: false },
		} );
		expect( state.dirty ).toBe( false );
	} );
} );
