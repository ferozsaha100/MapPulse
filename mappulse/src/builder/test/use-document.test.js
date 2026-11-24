import { planRebuild, signature } from '../areas/plan';

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
const state = ( zones, wanted, fetched = null, title = 't' ) => ( {
	signature: signature( {
		zones,
		priority: [ 'neighborhood', 'city', 'zip', 'county' ],
		title,
	} ),
	wanted,
	fetched,
} );

describe( 'planRebuild', () => {
	const box = [ 0, 0, 10, 10 ];

	it( 'fetches the base map and builds for the first zone', () => {
		expect(
			planRebuild( state( [], null ), state( [ zone( 'a' ) ], box ) )
		).toEqual( { refetchBase: true, rebuild: true } );
	} );

	it( 'rebuilds without refetching when a zone is added inside the box already fetched', () => {
		const prev = state( [ zone( 'a' ) ], box, box );
		expect(
			planRebuild(
				prev,
				state( [ zone( 'a' ), zone( 'b' ) ], [ 1, 1, 9, 9 ], box )
			)
		).toEqual( { refetchBase: false, rebuild: true } );
	} );

	it( 'refetches when the zones reach outside the box already fetched', () => {
		const prev = state( [ zone( 'a' ) ], box, box );
		expect(
			planRebuild(
				prev,
				state( [ zone( 'a' ), zone( 'b' ) ], [ 0, 0, 12, 10 ], box )
			)
		).toEqual( { refetchBase: true, rebuild: true } );
	} );

	it( 'rebuilds when a zone becomes a backdrop, is renamed, cleaned or moved', () => {
		const prev = state( [ zone( 'a' ), zone( 'b' ) ], box, box );
		for ( const zones of [
			[ zone( 'a', { backdrop: true } ), zone( 'b' ) ],
			[ zone( 'a', { name: 'Alpha' } ), zone( 'b' ) ],
			[ zone( 'a', { clean: true } ), zone( 'b' ) ],
			[ zone( 'b' ), zone( 'a' ) ],
		] ) {
			expect( planRebuild( prev, state( zones, box, box ) ) ).toEqual( {
				refetchBase: false,
				rebuild: true,
			} );
		}
	} );

	it( 'does nothing for a title change', () => {
		const prev = state( [ zone( 'a' ) ], box, box, 'One' );
		expect(
			planRebuild( prev, state( [ zone( 'a' ) ], box, box, 'Two' ) )
		).toEqual( { refetchBase: false, rebuild: false } );
	} );

	it( 'does not ask for a base map when there are no zones', () => {
		expect(
			planRebuild(
				state( [ zone( 'a' ) ], box, box ),
				state( [], null, box )
			)
		).toEqual( { refetchBase: false, rebuild: true } );
	} );
} );
