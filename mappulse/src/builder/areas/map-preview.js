/**
 * The map as it will appear on the site, drawn by the same renderer the front end uses.
 * In the Zones and links step one area can be marked, and its label dragged to a new place.
 */
import { useEffect, useRef } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function MapPreview( {
	preview,
	empty,
	selected = '',
	onMoveLabel = null,
	hint = '',
	showCard = false,
} ) {
	const { svg, busy, error, baseNote } = preview;
	const holder = useRef( null );
	// The handler is read at drag time, so a redraw during a drag does not strand it.
	const move = useRef( onMoveLabel );
	move.current = onMoveLabel;

	// Mark the chosen area and its label.
	useEffect( () => {
		const root = holder.current;
		if ( ! root ) {
			return;
		}
		root.querySelectorAll( '.is-active' ).forEach( ( el ) =>
			el.classList.remove( 'is-active' )
		);
		if ( selected ) {
			root.querySelectorAll( '[data-zone]' ).forEach(
				( el ) =>
					el.getAttribute( 'data-zone' ) === selected &&
					el.classList.add( 'is-active' )
			);
		}
	}, [ selected, svg ] );

	// Show the chosen area's hover card under the map, as the site will draw it.
	useEffect( () => {
		const root = holder.current;
		if ( ! root ) {
			return;
		}
		root.querySelectorAll( '.mappulse__card' ).forEach( ( card ) => {
			card.hidden =
				! showCard || card.getAttribute( 'data-zone' ) !== selected;
		} );
	}, [ selected, svg, showCard ] );

	// A linked area is a real link in the drawing. In the builder a click on it must not leave the page.
	useEffect( () => {
		const root = holder.current;
		if ( ! root ) {
			return;
		}
		const stay = ( event ) => {
			if ( event.target.closest && event.target.closest( 'a' ) ) {
				event.preventDefault();
			}
		};
		root.addEventListener( 'click', stay );
		return () => root.removeEventListener( 'click', stay );
	}, [ svg ] );

	// Drag the chosen area's label.
	useEffect( () => {
		const root = holder.current;
		if ( ! root || ! selected || ! move.current ) {
			return;
		}
		let drag = null;
		const down = ( event ) => {
			const label =
				event.target.closest &&
				event.target.closest( '.mappulse__label' );
			const drawing = root.querySelector( 'svg' );
			if (
				! label ||
				! drawing ||
				label.getAttribute( 'data-zone' ) !== selected
			) {
				return;
			}
			const box = drawing.viewBox.baseVal;
			const rect = drawing.getBoundingClientRect();
			drag = {
				label,
				x: event.clientX,
				y: event.clientY,
				unit: rect.width > 0 ? box.width / rect.width : 1,
				dx: 0,
				dy: 0,
			};
			label.setPointerCapture( event.pointerId );
			event.preventDefault();
		};
		const during = ( event ) => {
			if ( ! drag ) {
				return;
			}
			drag.dx = ( event.clientX - drag.x ) * drag.unit;
			drag.dy = ( event.clientY - drag.y ) * drag.unit;
			drag.label.setAttribute(
				'transform',
				`translate(${ drag.dx } ${ drag.dy })`
			);
		};
		const up = () => {
			if ( ! drag ) {
				return;
			}
			const { dx, dy } = drag;
			drag = null;
			if (
				move.current &&
				( Math.abs( dx ) >= 0.5 || Math.abs( dy ) >= 0.5 )
			) {
				move.current(
					selected,
					Math.round( dx * 10 ) / 10,
					Math.round( dy * 10 ) / 10
				);
			}
		};
		root.addEventListener( 'pointerdown', down );
		root.addEventListener( 'pointermove', during );
		root.addEventListener( 'pointerup', up );
		root.addEventListener( 'pointercancel', up );
		return () => {
			root.removeEventListener( 'pointerdown', down );
			root.removeEventListener( 'pointermove', during );
			root.removeEventListener( 'pointerup', up );
			root.removeEventListener( 'pointercancel', up );
		};
	}, [ selected, svg ] );

	return (
		<section
			className="mappulse-preview"
			aria-labelledby="mappulse-preview-title"
		>
			<h2 id="mappulse-preview-title">
				{ __( 'Map preview', 'mappulse' ) }
				{ busy && <Spinner /> }
			</h2>
			<div aria-live="polite">
				{ error && (
					<p className="mappulse-preview__error">{ error }</p>
				) }
				{ baseNote && (
					<p className="mappulse-preview__note">{ baseNote }</p>
				) }
			</div>
			{ hint && svg && ! empty && (
				<p className="mappulse-preview__note">{ hint }</p>
			) }
			{ empty && ! busy && (
				<p className="mappulse-preview__empty">
					{ __(
						'Your map appears here once it has an area.',
						'mappulse'
					) }
				</p>
			) }
			{ svg && ! empty && (
				// The markup comes from the plugin's own renderer, which escapes every value it writes.
				<div
					ref={ holder }
					className={
						'mappulse-preview__map' +
						( busy ? ' is-busy' : '' ) +
						( selected && onMoveLabel ? ' can-move' : '' )
					}
					dangerouslySetInnerHTML={ { __html: svg } }
				/>
			) }
		</section>
	);
}
