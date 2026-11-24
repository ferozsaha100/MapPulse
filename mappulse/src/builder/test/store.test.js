import {
	reduce,
	initialState,
	zoneFromResult,
	toDocument,
	zonesFromDocument,
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
} );
