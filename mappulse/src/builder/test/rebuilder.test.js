import { createRebuilder } from '../areas/rebuilder';

const PRIORITY = [ 'neighborhood', 'city', 'zip', 'county' ];
const square = ( x ) => ( {
	type: 'Feature',
	properties: {},
	geometry: {
		type: 'Polygon',
		coordinates: [
			[
				[ x, 0 ],
				[ x + 0.01, 0 ],
				[ x + 0.01, 0.01 ],
				[ x, 0.01 ],
				[ x, 0 ],
			],
		],
	},
} );
const zone = ( id, extra = {} ) => ( {
	id,
	name: id,
	type: 'city',
	source: 'census',
	record: id,
	feature: square( 0 ),
	backdrop: false,
	clean: false,
	...extra,
} );

// Lets every pending promise callback run.
const settle = async () => {
	for ( let i = 0; i < 20; i++ ) {
		await Promise.resolve();
	}
};

function setup( overrides = {} ) {
	const docs = [];
	const deps = {
		fetchBaseLayer: jest.fn( ( bbox, layer ) =>
			Promise.resolve( {
				[ layer ]: { type: 'FeatureCollection', features: [] },
			} )
		),
		renderDocument: jest.fn( ( doc ) =>
			Promise.resolve( {
				html: `<section>${ doc.zones
					.map( ( z ) => z.id )
					.join( ',' ) }</section>`,
			} )
		),
		build: jest.fn( ( { zones } ) => ( {
			doc: zones.length
				? {
						schema: 1,
						zones: zones.map( ( z ) => ( {
							id: z.id,
							geometry: z.feature.geometry,
						} ) ),
				  }
				: null,
			warnings: [],
		} ) ),
		onDoc: ( doc ) => docs.push( doc ),
		...overrides,
	};
	const rebuilder = createRebuilder( deps );
	const update = ( zones ) =>
		rebuilder.update( { zones, priority: PRIORITY, title: 't' } );
	const run = async () => {
		jest.advanceTimersByTime( 300 );
		await settle();
	};
	return { rebuilder, deps, docs, update, run };
}

describe( 'rebuilder', () => {
	beforeEach( () => jest.useFakeTimers() );
	afterEach( () => jest.useRealTimers() );

	it( 'fetches the base map, builds and draws after a change', async () => {
		const { rebuilder, deps, docs, update, run } = setup();
		const zones = [ zone( 'a' ) ];
		update( zones );
		expect( rebuilder.get().busy ).toBe( true );
		expect( rebuilder.isCurrent( { zones, priority: PRIORITY } ) ).toBe(
			false
		);
		await run();
		expect( deps.fetchBaseLayer ).toHaveBeenCalledTimes( 6 );
		expect( docs ).toHaveLength( 1 );
		expect( rebuilder.get() ).toMatchObject( {
			busy: false,
			html: '<section>a</section>',
			error: '',
		} );
		expect( rebuilder.isCurrent( { zones, priority: PRIORITY } ) ).toBe(
			true
		);
	} );

	it( 'does not send source boundaries to the preview', async () => {
		const { deps, update, run } = setup();
		update( [ zone( 'a' ) ] );
		await run();
		const sent = deps.renderDocument.mock.calls[ 0 ][ 0 ].zones[ 0 ];
		expect( sent.id ).toBe( 'a' );
		expect( sent ).not.toHaveProperty( 'geometry' );
	} );

	it( 'stops being busy when a change is undone before the drawing starts', async () => {
		const { rebuilder, deps, update, run } = setup();
		const zones = [ zone( 'a' ) ];
		update( zones );
		await run();
		deps.build.mockClear();

		update( [ zone( 'a', { name: 'ab' } ) ] );
		expect( rebuilder.get().busy ).toBe( true );
		update( zones );
		expect( rebuilder.get().busy ).toBe( false );
		await run();
		expect( deps.build ).not.toHaveBeenCalled();
		expect( rebuilder.isCurrent( { zones, priority: PRIORITY } ) ).toBe(
			true
		);
	} );

	it( 'drops a drawing whose change was undone while the base map was loading', async () => {
		let release;
		const gate = new Promise( ( resolve ) => ( release = resolve ) );
		const { rebuilder, deps, docs, update, run } = setup();
		const one = [ zone( 'a' ) ];
		update( one );
		await run();

		// Adding b needs a wider base map, and that request hangs.
		deps.fetchBaseLayer.mockImplementation( () =>
			gate.then( () => ( {} ) )
		);
		update( [ zone( 'a' ), zone( 'b', { feature: square( 5 ) } ) ] );
		jest.advanceTimersByTime( 300 );
		await settle();
		expect( rebuilder.get().busy ).toBe( true );

		update( one );
		expect( rebuilder.get().busy ).toBe( false );
		release();
		await settle();
		expect( docs ).toHaveLength( 1 );
		expect( rebuilder.get().html ).toBe( '<section>a</section>' );
		expect(
			rebuilder.isCurrent( { zones: one, priority: PRIORITY } )
		).toBe( true );
	} );

	it( 'is not current after a build fails, and recovers on the next change', async () => {
		const { rebuilder, deps, docs, update, run } = setup();
		const one = [ zone( 'a' ) ];
		update( one );
		await run();

		deps.build.mockImplementationOnce( () => {
			throw new Error( 'bad shape' );
		} );
		const two = [ zone( 'a' ), zone( 'b' ) ];
		update( two );
		await run();
		expect( rebuilder.get().busy ).toBe( false );
		expect( rebuilder.get().error ).not.toBe( '' );
		expect(
			rebuilder.isCurrent( { zones: two, priority: PRIORITY } )
		).toBe( false );
		expect( docs ).toHaveLength( 1 );

		update( one );
		expect(
			rebuilder.isCurrent( { zones: one, priority: PRIORITY } )
		).toBe( true );
		expect( rebuilder.get().error ).toBe( '' );
	} );

	it( 'keeps the last drawing and says so when the server cannot draw', async () => {
		const { rebuilder, deps, update, run } = setup();
		update( [ zone( 'a' ) ] );
		await run();
		deps.renderDocument.mockImplementationOnce( () =>
			Promise.reject( { message: 'Server said no.' } )
		);
		update( [ zone( 'a' ), zone( 'b' ) ] );
		await run();
		expect( rebuilder.get() ).toMatchObject( {
			busy: false,
			html: '<section>a</section>',
			error: 'Server said no.',
		} );
	} );

	it( 'builds without the base layers that fail, notes it, and asks again next time', async () => {
		const { rebuilder, deps, update, run } = setup();
		deps.fetchBaseLayer.mockImplementation( ( bbox, layer ) =>
			layer === 'water'
				? Promise.reject( new Error( 'x' ) )
				: Promise.resolve( {
						[ layer ]: { type: 'FeatureCollection', features: [] },
				  } )
		);
		update( [ zone( 'a' ) ] );
		await run();
		expect( rebuilder.get().baseNote ).not.toBe( '' );
		expect( rebuilder.get().html ).toBe( '<section>a</section>' );
		expect( deps.build.mock.calls[ 0 ][ 0 ].base.water ).toBeUndefined();

		deps.fetchBaseLayer.mockClear();
		update( [ zone( 'a', { name: 'renamed' } ) ] );
		await run();
		expect( deps.fetchBaseLayer ).toHaveBeenCalledTimes( 6 );
	} );

	it( 'adopts a saved map as current and draws it without building', async () => {
		const { rebuilder, deps, docs, run } = setup();
		const zones = [ zone( 'a' ) ];
		rebuilder.adopt( {
			doc: { schema: 1, zones: [ { id: 'a', geometry: {} } ] },
			zones,
			priority: PRIORITY,
		} );
		await run();
		expect( deps.build ).not.toHaveBeenCalled();
		expect( docs ).toHaveLength( 0 );
		expect( rebuilder.get().html ).toBe( '<section>a</section>' );
		expect( rebuilder.isCurrent( { zones, priority: PRIORITY } ) ).toBe(
			true
		);
	} );

	it( 'still draws an adopted map when the unchanged area list is reported right after', async () => {
		const { rebuilder, deps, update, run } = setup();
		const zones = [ zone( 'a' ) ];
		rebuilder.adopt( {
			doc: { schema: 1, zones: [ { id: 'a' } ] },
			zones,
			priority: PRIORITY,
		} );
		update( zones );
		await run();
		expect( rebuilder.get() ).toMatchObject( {
			busy: false,
			html: '<section>a</section>',
		} );
		expect( deps.build ).not.toHaveBeenCalled();
	} );

	it( 'clears the drawing when the last area is removed', async () => {
		const { rebuilder, docs, update, run } = setup();
		update( [ zone( 'a' ) ] );
		await run();
		update( [] );
		await run();
		expect( rebuilder.get() ).toMatchObject( { busy: false, html: '' } );
		expect( docs[ 1 ] ).toBeNull();
	} );

	it( 'redraws for a style or link change without rebuilding or fetching', async () => {
		const { rebuilder, deps, docs, run } = setup();
		const zones = [ zone( 'a' ) ];
		const style = { zone: '#DCCFB6', labelSize: 11.5 };
		rebuilder.update( { zones, priority: PRIORITY, title: 't', style } );
		await run();
		deps.build.mockClear();
		deps.fetchBaseLayer.mockClear();
		deps.renderDocument.mockClear();

		const tinted = { ...style, zone: '#112233' };
		rebuilder.update( {
			zones,
			priority: PRIORITY,
			title: 't',
			style: tinted,
		} );
		expect(
			rebuilder.isCurrent( { zones, priority: PRIORITY, style: tinted } )
		).toBe( false );
		await run();
		expect( deps.build ).not.toHaveBeenCalled();
		expect( deps.fetchBaseLayer ).not.toHaveBeenCalled();
		expect( deps.renderDocument ).toHaveBeenCalledTimes( 1 );
		expect( deps.renderDocument.mock.calls[ 0 ][ 0 ].style.zone ).toBe(
			'#112233'
		);
		expect( docs[ docs.length - 1 ].style.zone ).toBe( '#112233' );
		expect(
			rebuilder.isCurrent( { zones, priority: PRIORITY, style: tinted } )
		).toBe( true );

		const linked = [
			zone( 'a', { link: 'https://example.com/a/', linkPost: 5 } ),
		];
		rebuilder.update( {
			zones: linked,
			priority: PRIORITY,
			title: 't',
			style: tinted,
		} );
		await run();
		expect( deps.build ).not.toHaveBeenCalled();
		expect( docs[ docs.length - 1 ].zones[ 0 ] ).toMatchObject( {
			link: 'https://example.com/a/',
			linkPost: 5,
		} );
	} );

	it( 'rebuilds when the label size changes', async () => {
		const { rebuilder, deps, run } = setup();
		const zones = [ zone( 'a' ) ];
		rebuilder.update( {
			zones,
			priority: PRIORITY,
			title: 't',
			style: { labelSize: 11.5 },
		} );
		await run();
		deps.build.mockClear();
		rebuilder.update( {
			zones,
			priority: PRIORITY,
			title: 't',
			style: { labelSize: 14 },
		} );
		await run();
		expect( deps.build ).toHaveBeenCalledTimes( 1 );
		expect( deps.build.mock.calls[ 0 ][ 0 ].style.labelSize ).toBe( 14 );
	} );

	it( 'applies links and style to a full build as well', async () => {
		const { docs, rebuilder, run } = setup();
		rebuilder.update( {
			zones: [ zone( 'a', { link: 'https://example.com/a/' } ) ],
			priority: PRIORITY,
			title: 't',
			style: { zone: '#112233' },
		} );
		await run();
		expect( docs[ 0 ].zones[ 0 ].link ).toBe( 'https://example.com/a/' );
		expect( docs[ 0 ].style.zone ).toBe( '#112233' );
	} );
} );
