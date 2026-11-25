/**
 * How the card behaves in a page. The script wires itself to the document when it loads.
 */
let hoverNone = false;
window.matchMedia = ( query ) => ( {
	matches: hoverNone && query.indexOf( 'hover: none' ) !== -1,
} );
require( '../mappulse' );

const card = ( zone ) =>
	`<div class="mappulse__card" data-zone="${ zone }" hidden><h3>${ zone }</h3><p><a class="mappulse__card-button" href="/idx-${ zone }">Search</a></p></div>`;

const fire = ( node, type, pointerType ) => {
	const event = new window.MouseEvent( type, {
		bubbles: 'pointerenter' !== type && 'pointerleave' !== type,
		cancelable: true,
	} );
	if ( pointerType ) {
		Object.defineProperty( event, 'pointerType', { value: pointerType } );
	}
	node.dispatchEvent( event );
	return event;
};
const key = ( name ) =>
	document.dispatchEvent(
		new window.KeyboardEvent( 'keydown', {
			key: name,
			bubbles: true,
			cancelable: true,
		} )
	);
const link = ( zone ) =>
	document.querySelector( `.mappulse__list a[data-zone="${ zone }"]` );
const cardFor = ( zone ) =>
	document.querySelector( `.mappulse__card[data-zone="${ zone }"]` );
const tap = ( node ) => {
	fire( node, 'pointerenter', 'touch' );
	fire( node, 'pointerdown', 'touch' );
	node.focus();
	return fire( node, 'click', 'touch' );
};

beforeEach( () => {
	jest.useFakeTimers();
	hoverNone = false;
	document.body.innerHTML = `<div class="mappulse" data-mappulse data-close-label="Close">
		<ul class="mappulse__list">
			<li><a data-zone="a" href="/a">A</a></li>
			<li><a data-zone="b" href="/b">B</a></li>
			<li><a data-zone="plain" href="/plain">Plain</a></li>
		</ul>
		<div class="mappulse__cards">${ card( 'a' ) }${ card( 'b' ) }</div>
	</div>`;
} );

afterEach( () => {
	key( 'Escape' );
	jest.runOnlyPendingTimers();
	jest.useRealTimers();
} );

describe( 'with a mouse', () => {
	it( 'opens the card of the area pointed at and closes it after the pointer leaves', () => {
		fire( link( 'a' ), 'pointerenter', 'mouse' );
		expect( cardFor( 'a' ).hidden ).toBe( false );
		fire( link( 'a' ), 'pointerleave', 'mouse' );
		jest.advanceTimersByTime( 400 );
		expect( cardFor( 'a' ).hidden ).toBe( true );
	} );

	it( 'keeps the card open when the pointer crosses a neighbouring area on its way to the card', () => {
		fire( link( 'a' ), 'pointerenter', 'mouse' );
		fire( link( 'a' ), 'pointerleave', 'mouse' );
		fire( link( 'b' ), 'pointerenter', 'mouse' );
		jest.advanceTimersByTime( 80 );
		fire( link( 'b' ), 'pointerleave', 'mouse' );
		fire( cardFor( 'a' ), 'pointerenter', 'mouse' );
		jest.advanceTimersByTime( 1000 );
		expect( cardFor( 'a' ).hidden ).toBe( false );
		expect( cardFor( 'b' ).hidden ).toBe( true );
	} );

	it( 'moves on to the neighbouring area when the pointer stays there', () => {
		fire( link( 'a' ), 'pointerenter', 'mouse' );
		fire( link( 'a' ), 'pointerleave', 'mouse' );
		fire( link( 'b' ), 'pointerenter', 'mouse' );
		jest.advanceTimersByTime( 400 );
		expect( cardFor( 'a' ).hidden ).toBe( true );
		expect( cardFor( 'b' ).hidden ).toBe( false );
	} );

	it( 'closes the card when the pointer crosses a neighbouring area and leaves the map', () => {
		fire( link( 'a' ), 'pointerenter', 'mouse' );
		fire( link( 'a' ), 'pointerleave', 'mouse' );
		fire( link( 'b' ), 'pointerenter', 'mouse' );
		fire( link( 'b' ), 'pointerleave', 'mouse' );
		jest.advanceTimersByTime( 1000 );
		expect( cardFor( 'a' ).hidden ).toBe( true );
		expect( cardFor( 'b' ).hidden ).toBe( true );
	} );
} );

describe( 'with a keyboard', () => {
	it( 'does not bring the card back when Escape returns focus to the area', () => {
		link( 'a' ).focus();
		expect( cardFor( 'a' ).hidden ).toBe( false );
		key( 'Tab' );
		expect( document.activeElement.className ).toBe(
			'mappulse__card-button'
		);
		key( 'Escape' );
		expect( document.activeElement ).toBe( link( 'a' ) );
		expect( cardFor( 'a' ).hidden ).toBe( true );
	} );

	it( 'follows the link on Enter on a device without hover', () => {
		hoverNone = true;
		key( 'Tab' );
		link( 'a' ).focus();
		expect( cardFor( 'a' ).hidden ).toBe( false );
		key( 'Enter' );
		expect( fire( link( 'a' ), 'click' ).defaultPrevented ).toBe( false );
	} );
} );

describe( 'with a finger', () => {
	it( 'opens the card as a sheet on the first tap and follows the link on the second', () => {
		const first = tap( link( 'a' ) );
		expect( first.defaultPrevented ).toBe( true );
		expect( cardFor( 'a' ).hidden ).toBe( false );
		expect(
			cardFor( 'a' ).classList.contains( 'mappulse__card--sheet' )
		).toBe( true );
		expect( fire( link( 'a' ), 'click', 'touch' ).defaultPrevented ).toBe(
			false
		);
	} );

	it( 'lets a tap on an area without a card follow its link', () => {
		expect( tap( link( 'plain' ) ).defaultPrevented ).toBe( false );
	} );

	it( 'puts the sheet away on a tap on the dimmed page behind it', () => {
		tap( link( 'a' ) );
		const backdrop = document.querySelector( '.mappulse__backdrop' );
		expect( backdrop ).not.toBeNull();
		fire( backdrop, 'click', 'touch' );
		expect( cardFor( 'a' ).hidden ).toBe( true );
		expect( document.querySelector( '.mappulse__backdrop' ) ).toBeNull();
		expect( document.documentElement.style.overflow ).toBe( '' );
	} );
} );
