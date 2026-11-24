/**
 * MapPulse view script.
 *
 * Keeps a zone, its label and its list item in step: hovering or focusing any one of them
 * marks all three as active. Listeners sit on the document, so maps added to the page later
 * (editor previews, lazy-loaded content) work without setup.
 */
( function () {
	'use strict';

	var EVENTS = {
		pointerenter: true,
		focusin: true,
		pointerleave: false,
		focusout: false
	};

	function onEvent( event ) {
		var target = event.target;
		if ( ! target || 'function' !== typeof target.closest ) {
			return;
		}
		var item = target.closest( '[data-zone]' );
		var root = item && item.closest( '[data-mappulse]' );
		if ( ! root ) {
			return;
		}
		var zone = item.getAttribute( 'data-zone' );
		var active = EVENTS[ event.type ];
		var matches = root.querySelectorAll( '[data-zone]' );
		for ( var i = 0; i < matches.length; i++ ) {
			if ( matches[ i ].getAttribute( 'data-zone' ) === zone ) {
				matches[ i ].classList.toggle( 'is-active', active );
			}
		}
	}

	Object.keys( EVENTS ).forEach( function ( type ) {
		document.addEventListener( type, onEvent, true );
	} );
}() );
