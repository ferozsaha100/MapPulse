import { writeFileSync, mkdirSync } from 'fs';
import { join } from 'path';
import { buildDocument, bboxForBase, clipLine } from '../build-document';
import { square, feature, collection } from '../test-helpers';
import fisher from './fixtures/fisher-island.json';
import gulfport from './fixtures/gulfport.json';

// The pattern Map_Document::PATH_PATTERN enforces on the PHP side.
const ALLOWED = /^[MmLlHhVvCcSsQqTtAaZz0-9 ,.\-]*$/;
const count = ( d, letter ) => d.split( letter ).length - 1;

// Gulfport, FL spans about -82.731..-82.685 by 27.727..27.762.
const zone = ( id, type, feat, extra = {} ) => ( {
	id,
	name: extra.name || id,
	type,
	source: 'census',
	record: `${ type }:${ id }`,
	feature: feat,
	backdrop: false,
	clean: false,
	...extra,
} );
const gulfportZone = () =>
	zone( 'gulfport', 'city', gulfport.features[ 0 ], {
		name: 'Gulfport',
		record: 'place:1228175',
	} );
const hood = () =>
	zone( 'marina', 'neighborhood', feature( square( -82.72, 27.74, 0.008 ) ), {
		name: 'Marina District',
	} );
const zip = () =>
	zone( '33707', 'zip', feature( square( -82.74, 27.72, 0.06 ) ), {
		name: '33707',
	} );

const base = () => ( {
	land: collection( [
		feature( square( -82.9, 27.6, 0.4 ), { BASENAME: 'Pinellas' } ),
	] ),
	cities: collection( [
		feature( square( -82.7, 27.75, 0.1 ), { BASENAME: 'St. Petersburg' } ),
	] ),
	areas: collection( [] ),
	water: collection( [
		feature( square( -82.9, 27.6, 0.172 ), { BASENAME: 'Boca Ciega Bay' } ),
	] ),
	roadsMajor: collection( [
		{
			type: 'Feature',
			properties: {},
			geometry: {
				type: 'LineString',
				coordinates: [
					[ -82.9, 27.75 ],
					[ -82.6, 27.75 ],
				],
			},
		},
	] ),
	roadsMinor: collection( [] ),
} );

describe( 'buildDocument', () => {
	it( 'builds a schema 1 document from three zones and a base map', () => {
		const { doc, warnings } = buildDocument( {
			title: 'Gulfport area',
			zones: [ gulfportZone(), hood(), zip() ],
			base: base(),
		} );
		expect( doc.schema ).toBe( 1 );
		expect( doc.title ).toBe( 'Gulfport area' );
		expect( doc.frame.width ).toBe( 1000 );
		expect( doc.frame.height ).toBeGreaterThanOrEqual( 625 );
		expect( doc.zones.map( ( z ) => z.id ).sort() ).toEqual( [
			'33707',
			'gulfport',
			'marina',
		] );
		expect( Object.keys( doc.topology.zones ).sort() ).toEqual( [
			'33707',
			'gulfport',
			'marina',
		] );
		for ( const z of doc.zones ) {
			const shape = doc.topology.zones[ z.id ];
			expect( shape.d ).not.toBe( '' );
			expect( shape.d ).toMatch( ALLOWED );
			expect( shape.label.x ).toBeGreaterThan( 0 );
			expect( shape.label.x ).toBeLessThan( doc.frame.width );
			expect( shape.label.y ).toBeGreaterThan( 0 );
			expect( shape.label.y ).toBeLessThan( doc.frame.height );
			expect( shape.label.lines.length ).toBeGreaterThan( 0 );
			expect( z.link ).toBe( '' );
		}
		for ( const d of Object.values( doc.topology.layers ) ) {
			expect( d ).toMatch( ALLOWED );
		}
		expect( doc.topology.layers.land ).not.toBe( '' );
		expect( doc.topology.layers.water ).not.toBe( '' );
		expect( doc.topology.layers.roadsMajor ).not.toBe( '' );
		expect( doc.zones.find( ( z ) => z.id === 'gulfport' ).source ).toEqual(
			{ layer: 'census', record: 'place:1228175' }
		);
		expect(
			doc.zones.find( ( z ) => z.id === 'gulfport' ).geometry
		).toEqual( gulfport.features[ 0 ].geometry );
		expect( doc.priority ).toEqual( [
			'neighborhood',
			'city',
			'zip',
			'county',
		] );
		expect( doc.meta.generator ).toMatch( /^mappulse-builder\// );
		expect( warnings.filter( ( w ) => w.zone === 'marina' ) ).toEqual( [] );

		if ( process.env.UPDATE_FIXTURES ) {
			const dir = join(
				__dirname,
				'..',
				'..',
				'..',
				'tests',
				'fixtures'
			);
			mkdirSync( dir, { recursive: true } );
			writeFileSync(
				join( dir, 'built-document.json' ),
				JSON.stringify( doc )
			);
		}
	} );

	it( 'keeps drawing a ZIP area that has a neighborhood cut out of it', () => {
		const { doc } = buildDocument( {
			title: 't',
			zones: [ zip(), hood() ],
			base: base(),
		} );
		// The cut leaves a hole, so the ZIP path has two rings.
		expect( count( doc.topology.zones[ '33707' ].d, 'M' ) ).toBe( 2 );
	} );

	it( 'draws a backdrop whole and first', () => {
		const { doc } = buildDocument( {
			title: 't',
			zones: [ hood(), zip() ].map( ( z ) =>
				z.id === '33707' ? { ...z, backdrop: true } : z
			),
			base: base(),
		} );
		expect( count( doc.topology.zones[ '33707' ].d, 'M' ) ).toBe( 1 );
		expect( doc.zones[ 0 ].id ).toBe( '33707' );
		expect( doc.zones[ 0 ].backdrop ).toBe( true );
	} );

	it( 'warns about a zone that is almost fully covered and still returns it', () => {
		const covered = zone(
			'inner',
			'zip',
			feature( square( -82.719, 27.741, 0.006 ) )
		);
		const { doc, warnings } = buildDocument( {
			title: 't',
			zones: [ hood(), covered ],
			base: base(),
		} );
		expect( warnings.find( ( w ) => w.zone === 'inner' ).code ).toBe(
			'mostly_cut'
		);
		expect( doc.zones.find( ( z ) => z.id === 'inner' ).hidden ).toBe(
			true
		);
		expect( doc.topology.zones.inner ).toBeUndefined();
	} );

	it( 'warns when a cut leaves a zone in pieces', () => {
		const bar = zone(
			'bar',
			'neighborhood',
			feature( [
				[
					[
						[ -82.72, 27.7 ],
						[ -82.715, 27.7 ],
						[ -82.715, 27.8 ],
						[ -82.72, 27.8 ],
						[ -82.72, 27.7 ],
					],
				],
			] )
		);
		const wide = zone(
			'wide',
			'zip',
			feature( square( -82.74, 27.73, 0.04 ) )
		);
		const { warnings } = buildDocument( {
			title: 't',
			zones: [ bar, wide ],
			base: base(),
		} );
		expect( warnings.find( ( w ) => w.zone === 'wide' ).code ).toBe(
			'in_pieces'
		);
	} );

	it( 'draws Fisher Island as one shape when cleaned and as many when not', () => {
		const island = ( clean ) =>
			zone( 'fisher-island', 'city', fisher.features[ 0 ], {
				name: 'Fisher Island',
				clean,
			} );
		const emptyBase = {
			land: collection(),
			cities: collection(),
			areas: collection(),
			water: collection(),
			roadsMajor: collection(),
			roadsMinor: collection(),
		};
		const cleaned = buildDocument( {
			title: 't',
			zones: [ island( true ) ],
			base: emptyBase,
		} ).doc;
		const raw = buildDocument( {
			title: 't',
			zones: [ island( false ) ],
			base: emptyBase,
		} ).doc;
		expect(
			count( cleaned.topology.zones[ 'fisher-island' ].d, 'M' )
		).toBe( 1 );
		expect(
			count( raw.topology.zones[ 'fisher-island' ].d, 'M' )
		).toBeGreaterThanOrEqual( 14 );
		// The stored source shape is never the cleaned one.
		expect( cleaned.zones[ 0 ].geometry ).toEqual(
			fisher.features[ 0 ].geometry
		);
		expect( cleaned.zones[ 0 ].clean ).toBe( true );
		// Without a county outline the land is the whole frame.
		expect( cleaned.topology.layers.land ).toBe(
			`M0 0L1000 0L1000 ${ cleaned.frame.height }L0 ${ cleaned.frame.height }Z`
		);
	} );

	it( 'works without a base map and with no zones', () => {
		expect(
			buildDocument( { title: 't', zones: [], base: base() } )
		).toEqual( { doc: null, warnings: [] } );
		const { doc } = buildDocument( {
			title: 't',
			zones: [ gulfportZone() ],
		} );
		expect( doc.topology.zones.gulfport.d ).not.toBe( '' );
	} );

	it( 'skips a zone whose shape is not a polygon', () => {
		const broken = zone( 'broken', 'city', {
			type: 'Feature',
			properties: {},
			geometry: { type: 'Point', coordinates: [ -82.7, 27.74 ] },
		} );
		const { doc, warnings } = buildDocument( {
			title: 't',
			zones: [ gulfportZone(), broken ],
		} );
		expect( warnings.find( ( w ) => w.zone === 'broken' ).code ).toBe(
			'no_shape'
		);
		expect( doc.zones.map( ( z ) => z.id ) ).toEqual( [ 'gulfport' ] );
	} );
} );

describe( 'bboxForBase', () => {
	it( 'grows the zones box by 15% a side', () => {
		const box = bboxForBase( [
			{ feature: feature( square( 10, 20, 1 ) ) },
		] );
		expect( box[ 0 ] ).toBeCloseTo( 9.85 );
		expect( box[ 3 ] ).toBeCloseTo( 21.15 );
	} );

	it( 'caps each side at 1.5 degrees around the middle', () => {
		const box = bboxForBase( [
			{ feature: feature( square( 10, 20, 4 ) ) },
		] );
		expect( box[ 2 ] - box[ 0 ] ).toBeCloseTo( 1.5 );
		expect( ( box[ 0 ] + box[ 2 ] ) / 2 ).toBeCloseTo( 12 );
	} );

	it( 'returns null without zones', () => {
		expect( bboxForBase( [] ) ).toBeNull();
	} );
} );

describe( 'clipLine', () => {
	const box = [ 0, 0, 10, 10 ];

	it( 'keeps a line that only crosses the box', () => {
		expect(
			clipLine(
				[
					[ -5, 5 ],
					[ 15, 5 ],
				],
				box
			)
		).toEqual( [
			[
				[ 0, 5 ],
				[ 10, 5 ],
			],
		] );
	} );

	it( 'splits a line that leaves and comes back', () => {
		const pieces = clipLine(
			[
				[ 2, 2 ],
				[ 2, 20 ],
				[ 8, 20 ],
				[ 8, 2 ],
			],
			box
		);
		expect( pieces ).toHaveLength( 2 );
		expect( pieces[ 0 ] ).toEqual( [
			[ 2, 2 ],
			[ 2, 10 ],
		] );
	} );

	it( 'drops a line outside the box', () => {
		expect(
			clipLine(
				[
					[ -5, -5 ],
					[ -1, 20 ],
				],
				box
			)
		).toEqual( [] );
	} );
} );
