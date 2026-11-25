import { planMatches, validLink } from '../links/auto-match';

const zone = ( id, name, link = '' ) => ( { id, name, link } );
const page = ( id, title, type = 'Page' ) => ( {
	id,
	title,
	url: `https://example.com/${ id }/`,
	type,
} );

describe( 'planMatches', () => {
	it( 'applies a single clear match', () => {
		const plan = planMatches( [ zone( 'a', 'Aventura' ) ], {
			Aventura: [ page( 7, 'Aventura' ) ],
		} );
		expect( plan ).toEqual( {
			apply: [
				{
					id: 'a',
					link: 'https://example.com/7/',
					linkPost: 7,
					linkTitle: 'Aventura',
				},
			],
			ask: [],
			none: [],
		} );
	} );

	it( 'asks when a name matches a page and a post, page first', () => {
		const choices = [
			page( 7, 'Aventura' ),
			page( 9, 'Aventura', 'Post' ),
		];
		const plan = planMatches( [ zone( 'a', 'Aventura' ) ], {
			Aventura: choices,
		} );
		expect( plan.apply ).toEqual( [] );
		expect( plan.ask ).toEqual( [
			{ id: 'a', name: 'Aventura', choices },
		] );
	} );

	it( 'asks when two areas would get the same page', () => {
		const plan = planMatches(
			[
				zone( 'a', 'Hollywood' ),
				zone( 'b', 'Hollywood' ),
				zone( 'c', 'Miramar' ),
			],
			{
				Hollywood: [ page( 3, 'Hollywood' ) ],
				Miramar: [ page( 4, 'Miramar' ) ],
			}
		);
		expect( plan.ask.map( ( item ) => item.id ) ).toEqual( [ 'a', 'b' ] );
		expect( plan.apply.map( ( item ) => item.id ) ).toEqual( [ 'c' ] );
	} );

	it( 'leaves an area that already has a link alone', () => {
		const plan = planMatches(
			[ zone( 'a', 'Aventura', 'https://example.com/mine/' ) ],
			{ Aventura: [ page( 7, 'Aventura' ) ] }
		);
		expect( plan ).toEqual( { apply: [], ask: [], none: [] } );
	} );

	it( 'lists the areas with no page', () => {
		const plan = planMatches(
			[ zone( 'a', 'Nowhere' ), zone( 'b', 'Elsewhere' ) ],
			{ Nowhere: [] }
		);
		expect( plan.none ).toEqual( [ 'a', 'b' ] );
	} );
} );

describe( 'validLink', () => {
	it( 'accepts http and https addresses and nothing else', () => {
		expect(
			validLink( 'https://example.com/idx/?city=Aventura&min=500000' )
		).toBe( 'https://example.com/idx/?city=Aventura&min=500000' );
		expect( validLink( '  http://example.com  ' ) ).toBe(
			'http://example.com/'
		);
		for ( const bad of [
			'javascript:alert(1)',
			'example.com',
			'ftp://example.com',
			'',
			'https://',
			'mailto:a@b.c',
		] ) {
			expect( validLink( bad ) ).toBe( '' );
		}
	} );
} );
