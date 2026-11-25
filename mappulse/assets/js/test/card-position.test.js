const { place } = require( '../mappulse' );

const viewport = { width: 1200, height: 800 };
const card = { width: 280, height: 300 };
const rect = ( left, top, width, height ) => ( { left, top, width, height } );
const overlaps = ( a, b ) =>
	a.left < b.left + b.width &&
	a.left + a.width > b.left &&
	a.top < b.top + b.height &&
	a.top + a.height > b.top;
const inside = ( box ) =>
	box.left >= 8 &&
	box.top >= 8 &&
	box.left + card.width <= viewport.width - 8 &&
	box.top + card.height <= viewport.height - 8;
const boxOf = ( spot ) => ( {
	left: spot.left,
	top: spot.top,
	width: card.width,
	height: card.height,
} );

describe( 'place', () => {
	it( 'puts the card to the right of an area on the left', () => {
		const anchor = rect( 100, 300, 120, 80 );
		const spot = place( anchor, card, viewport );
		expect( spot.side ).toBe( 'right' );
		expect( spot.left ).toBe( 232 );
		expect( overlaps( boxOf( spot ), anchor ) ).toBe( false );
		expect( inside( spot ) ).toBe( true );
	} );

	it( 'flips to the left near the right edge', () => {
		const anchor = rect( 1000, 300, 120, 80 );
		const spot = place( anchor, card, viewport );
		expect( spot.side ).toBe( 'left' );
		expect( spot.left + card.width ).toBe( 988 );
		expect( inside( spot ) ).toBe( true );
	} );

	it( 'goes below, then above, in a narrow viewport', () => {
		const narrow = { width: 390, height: 800 };
		const below = place( rect( 60, 100, 270, 120 ), card, narrow );
		expect( below.side ).toBe( 'below' );
		expect( below.top ).toBe( 232 );
		const above = place( rect( 60, 560, 270, 120 ), card, narrow );
		expect( above.side ).toBe( 'above' );
		expect( above.top + card.height ).toBe( 548 );
	} );

	it( 'keeps the card level with the area but inside the viewport', () => {
		const top = place( rect( 100, 0, 120, 40 ), card, viewport );
		expect( top.top ).toBe( 8 );
		const bottom = place( rect( 100, 770, 120, 40 ), card, viewport );
		expect( bottom.top + card.height ).toBe( 792 );
	} );

	it( 'stays fully inside the viewport when no side has room', () => {
		const spot = place( rect( 20, 20, 1160, 760 ), card, viewport );
		expect( spot.side ).toBe( 'over' );
		expect( inside( spot ) ).toBe( true );
	} );

	it( 'never covers the area when any side fits', () => {
		for ( let x = 0; x <= 1080; x += 90 ) {
			for ( let y = 0; y <= 720; y += 90 ) {
				const anchor = rect( x, y, 120, 80 );
				const spot = place( anchor, card, viewport );
				expect( inside( spot ) ).toBe( true );
				if ( spot.side !== 'over' ) {
					expect( overlaps( boxOf( spot ), anchor ) ).toBe( false );
				}
			}
		}
	} );
} );
