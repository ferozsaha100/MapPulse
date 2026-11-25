/**
 * MapPulse view script.
 *
 * Keeps a zone, its label and its list item in step: hovering or focusing any one of them marks
 * all three as active. Shows an area's hover card: beside the area for a mouse or the keyboard,
 * as a sheet at the bottom of the screen for a finger. Listeners sit on the document, so maps
 * added to the page later (editor previews, lazy-loaded content) work without setup.
 *
 * No library, no request. With this script off, areas are still plain links.
 */
( function () {
	'use strict';

	var EDGE = 8;
	var CLOSE_DELAY = 150;
	// Custom properties a card needs once it is lifted out of its map section.
	var CARD_VARS = [ 'background', 'text', 'button', 'button-text', 'radius', 'shadow' ];

	/**
	 * Where a card goes: right of its area, else left, below, above. When no side has room the
	 * card is laid over the area, inside the viewport.
	 *
	 * @param {Object} anchor   The area: left, top, width, height in viewport pixels.
	 * @param {Object} card     The card: width, height.
	 * @param {Object} viewport width, height.
	 * @param {number} gap      Space between area and card.
	 * @return {Object} left, top, side.
	 */
	function place( anchor, card, viewport, gap ) {
		gap = 'number' === typeof gap ? gap : 12;
		var maxLeft = Math.max( EDGE, viewport.width - card.width - EDGE );
		var maxTop = Math.max( EDGE, viewport.height - card.height - EDGE );
		var clampLeft = function ( x ) {
			return Math.min( Math.max( x, EDGE ), maxLeft );
		};
		var clampTop = function ( y ) {
			return Math.min( Math.max( y, EDGE ), maxTop );
		};
		var level = clampTop( anchor.top + anchor.height / 2 - card.height / 2 );
		var centred = clampLeft( anchor.left + anchor.width / 2 - card.width / 2 );
		var right = anchor.left + anchor.width + gap;
		var left = anchor.left - gap - card.width;
		var below = anchor.top + anchor.height + gap;
		var above = anchor.top - gap - card.height;

		if ( right <= maxLeft ) {
			return { left: right, top: level, side: 'right' };
		}
		if ( left >= EDGE ) {
			return { left: left, top: level, side: 'left' };
		}
		if ( below <= maxTop ) {
			return { left: centred, top: below, side: 'below' };
		}
		if ( above >= EDGE ) {
			return { left: centred, top: above, side: 'above' };
		}
		return { left: clampLeft( right ), top: level, side: 'over' };
	}

	if ( 'undefined' !== typeof module && module.exports ) {
		module.exports = { place: place };
	}
	if ( 'undefined' === typeof document ) {
		return;
	}

	// The open card, if any: { card, home, root, zone, anchor, sheet, focusBack }.
	var open = null;
	var closeTimer = 0;
	var quiet = false;
	var lastPointer = 'mouse';

	function closest( node, selector ) {
		return node && 'function' === typeof node.closest ? node.closest( selector ) : null;
	}

	function mark( root, zone, active ) {
		var matches = root.querySelectorAll( '[data-zone]' );
		for ( var i = 0; i < matches.length; i++ ) {
			if ( matches[ i ].getAttribute( 'data-zone' ) === zone ) {
				matches[ i ].classList.toggle( 'is-active', active );
			}
		}
	}

	function cardOf( root, zone ) {
		if ( open && open.root === root && open.zone === zone ) {
			return open.card;
		}
		var cards = root.querySelectorAll( '.mappulse__card' );
		for ( var i = 0; i < cards.length; i++ ) {
			if ( cards[ i ].getAttribute( 'data-zone' ) === zone ) {
				return cards[ i ];
			}
		}
		return null;
	}

	// The area's shape on the map, which the card is placed beside.
	function shapeOf( root, zone ) {
		var shapes = root.querySelectorAll( '.mappulse__zone' );
		for ( var i = 0; i < shapes.length; i++ ) {
			if ( shapes[ i ].getAttribute( 'data-zone' ) === zone ) {
				return shapes[ i ];
			}
		}
		return null;
	}

	function rectOf( node ) {
		var r = node.getBoundingClientRect();
		return { left: r.left, top: r.top, width: r.width, height: r.height };
	}

	function position() {
		if ( ! open || open.sheet ) {
			return;
		}
		var viewport = { width: document.documentElement.clientWidth, height: window.innerHeight };
		var size = { width: open.card.offsetWidth, height: open.card.offsetHeight };
		var shape = shapeOf( open.root, open.zone );
		var spot = shape ? place( rectOf( shape ), size, viewport ) : null;
		// An area too large to stand beside: the card goes by the pointer, or by the element in focus.
		if ( ! spot || 'over' === spot.side ) {
			spot = place( open.point || rectOf( open.anchor ), size, viewport );
		}
		open.card.style.left = Math.round( spot.left ) + 'px';
		open.card.style.top = Math.round( spot.top ) + 'px';
	}

	function close( restoreFocus ) {
		clearTimeout( closeTimer );
		if ( ! open ) {
			return;
		}
		var was = open;
		open = null;
		was.card.hidden = true;
		was.card.classList.remove( 'mappulse__card--sheet', 'mappulse__card--open' );
		was.card.removeAttribute( 'style' );
		was.card.removeAttribute( 'tabindex' );
		was.home.appendChild( was.card );
		mark( was.root, was.zone, false );
		if ( was.sheet ) {
			document.documentElement.style.overflow = was.overflow;
		}
		if ( restoreFocus && was.anchor && 'function' === typeof was.anchor.focus ) {
			// Putting focus back must not bring the card straight back.
			quiet = true;
			was.anchor.focus();
			quiet = false;
		}
	}

	function show( root, zone, anchor, options ) {
		var card = cardOf( root, zone );
		clearTimeout( closeTimer );
		if ( ! card ) {
			close( false );
			return false;
		}
		if ( open && open.card === card ) {
			open.anchor = anchor;
			return true;
		}
		close( false );

		var sheet = !! options.sheet;
		open = { card: card, home: card.parentNode, root: root, zone: zone, anchor: anchor, sheet: sheet, point: options.point || null, overflow: '' };

		// Lifted out of the section, the card would lose the section's colors. Carry them along.
		var computed = window.getComputedStyle( root );
		for ( var i = 0; i < CARD_VARS.length; i++ ) {
			var name = '--mappulse-card-' + CARD_VARS[ i ] + '-used';
			var value = computed.getPropertyValue( name );
			if ( value ) {
				card.style.setProperty( name, value.trim() );
			}
		}
		card.style.fontFamily = computed.fontFamily;

		if ( sheet && ! card.querySelector( '.mappulse__card-close' ) ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'mappulse__card-close';
			button.setAttribute( 'aria-label', root.getAttribute( 'data-close-label' ) || 'Close' );
			button.textContent = '×';
			card.insertBefore( button, card.firstChild );
		}

		card.classList.toggle( 'mappulse__card--sheet', sheet );
		document.body.appendChild( card );
		card.hidden = false;
		mark( root, zone, true );
		if ( sheet ) {
			open.overflow = document.documentElement.style.overflow;
			document.documentElement.style.overflow = 'hidden';
			card.setAttribute( 'tabindex', '-1' );
			card.focus();
		} else {
			position();
		}
		// The class is added after the card has a place, so its fade starts from there.
		card.classList.add( 'mappulse__card--open' );
		return true;
	}

	function closeSoon() {
		clearTimeout( closeTimer );
		closeTimer = setTimeout( function () {
			close( false );
		}, CLOSE_DELAY );
	}

	function focusable( node ) {
		return node.querySelectorAll( 'a[href], button:not([disabled])' );
	}

	// Everything in the page a Tab press can land on, in order.
	function tabStops() {
		var all = document.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' );
		var list = [];
		for ( var i = 0; i < all.length; i++ ) {
			if ( ! closest( all[ i ], '.mappulse__card' ) && ( all[ i ].offsetWidth || all[ i ].offsetHeight || all[ i ].getClientRects().length ) ) {
				list.push( all[ i ] );
			}
		}
		return list;
	}

	function onHover( event ) {
		var target = event.target;
		if ( event.pointerType ) {
			lastPointer = event.pointerType;
		}
		var entering = 'pointerenter' === event.type || 'focusin' === event.type;

		// Moving onto the card keeps it open; leaving it lets it close.
		if ( closest( target, '.mappulse__card' ) ) {
			if ( open && ! open.sheet ) {
				if ( entering ) {
					clearTimeout( closeTimer );
				} else if ( 'pointerleave' === event.type && target === open.card ) {
					closeSoon();
				} else if ( 'focusout' === event.type && ! closest( event.relatedTarget, '.mappulse__card' ) && event.relatedTarget !== open.anchor ) {
					closeSoon();
				}
			}
			return;
		}

		var item = closest( target, '[data-zone]' );
		var root = closest( item, '[data-mappulse]' );
		if ( ! root || item !== target ) {
			return;
		}
		var zone = item.getAttribute( 'data-zone' );
		// A finger does not hover. Its taps are handled as clicks.
		// Nor does the focus a tap leaves behind count as hovering.
		var touch = 'touch' === event.pointerType || ( 'focusin' === event.type && 'touch' === lastPointer );

		if ( entering ) {
			mark( root, zone, true );
			if ( ! quiet && ! touch && ! ( open && open.sheet ) ) {
				var byPointer = 'pointerenter' === event.type;
				show( root, zone, item, { point: byPointer ? { left: event.clientX, top: event.clientY, width: 1, height: 1 } : null } );
			}
			return;
		}

		if ( ! ( open && open.root === root && open.zone === zone ) ) {
			mark( root, zone, false );
			return;
		}
		if ( open.sheet ) {
			return;
		}
		if ( 'focusout' === event.type && closest( event.relatedTarget, '.mappulse__card' ) ) {
			return;
		}
		closeSoon();
	}

	function onClick( event ) {
		var target = event.target;
		if ( closest( target, '.mappulse__card-close' ) ) {
			close( true );
			return;
		}
		if ( closest( target, '.mappulse__card' ) ) {
			return;
		}
		var item = closest( target, '[data-zone]' );
		var root = closest( item, '[data-mappulse]' );
		var coarse = 'touch' === lastPointer || ( window.matchMedia && window.matchMedia( '(hover: none)' ).matches );

		if ( root && coarse ) {
			var zone = item.getAttribute( 'data-zone' );
			var alreadyOpen = open && open.sheet && open.root === root && open.zone === zone;
			// The first tap opens the card. A second tap on the same area follows its link.
			if ( ! alreadyOpen && show( root, zone, closest( target, 'a, [tabindex]' ) || item, { sheet: true } ) ) {
				event.preventDefault();
			}
			return;
		}
		// A tap or click anywhere else puts a sheet away.
		if ( open && open.sheet ) {
			close( false );
		}
	}

	function onKey( event ) {
		// A key press means a keyboard is in use, whatever pointed last.
		lastPointer = 'key';
		if ( ! open ) {
			return;
		}
		if ( 'Escape' === event.key || 'Esc' === event.key ) {
			var inCard = !! closest( document.activeElement, '.mappulse__card' );
			close( inCard || open.sheet );
			return;
		}
		if ( 'Tab' !== event.key ) {
			return;
		}
		var stops = focusable( open.card );
		var active = document.activeElement;
		if ( ! stops.length ) {
			return;
		}
		var anchor = open.anchor;
		if ( active === anchor && ! event.shiftKey ) {
			// From the area into its card, so the card's buttons come before the next area.
			event.preventDefault();
			stops[ 0 ].focus();
		} else if ( active === stops[ 0 ] && event.shiftKey || ( active === open.card && event.shiftKey ) ) {
			event.preventDefault();
			anchor.focus();
		} else if ( active === stops[ stops.length - 1 ] && ! event.shiftKey ) {
			// Out of the card, on to whatever follows the area in the page.
			var all = tabStops();
			var next = all[ all.indexOf( anchor ) + 1 ];
			if ( next ) {
				event.preventDefault();
				close( false );
				next.focus();
			}
		}
	}

	[ 'pointerenter', 'pointerleave', 'focusin', 'focusout' ].forEach( function ( type ) {
		document.addEventListener( type, onHover, true );
	} );
	document.addEventListener( 'pointerdown', function ( event ) {
		lastPointer = event.pointerType || 'mouse';
	}, true );
	document.addEventListener( 'click', onClick, true );
	document.addEventListener( 'keydown', onKey, true );
	window.addEventListener( 'scroll', position, true );
	window.addEventListener( 'resize', position );
}() );
