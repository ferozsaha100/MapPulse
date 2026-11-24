/**
 * Shows a boundary on live street tiles so a person can confirm it is the area they mean.
 */
import { useEffect, useRef, useState, useMemo } from '@wordpress/element';
import { Modal, Button, CheckboxControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { toMP } from '../../geometry/shapes';
import { cleanOutline, needsCleaning } from '../../geometry/clean';

const geometryOf = ( mp ) => ( { type: 'MultiPolygon', coordinates: mp } );

export default function StreetPreview( {
	settings,
	candidate,
	onClose,
	onAdd,
} ) {
	const { result, feature } = candidate;
	const holder = useRef( null );
	const raw = useMemo( () => toMP( feature.geometry ), [ feature ] );
	const fragmented = useMemo( () => needsCleaning( raw ), [ raw ] );
	const cleaned = useMemo(
		() => ( fragmented ? cleanOutline( raw ) : null ),
		[ raw, fragmented ]
	);
	const [ clean, setClean ] = useState( fragmented );

	useEffect( () => {
		if ( ! holder.current ) {
			return;
		}
		const map = L.map( holder.current, {
			scrollWheelZoom: false,
			attributionControl: true,
		} );
		const tiles = settings.tiles || {};
		if ( tiles.url ) {
			L.tileLayer( tiles.url, {
				maxZoom: 19,
				attribution: tiles.attribution || '',
			} ).addTo( map );
		}
		const rawLayer = L.geoJSON( geometryOf( raw ), {
			style: clean
				? {
						color: '#8a5a00',
						weight: 1.5,
						dashArray: '5 4',
						fillOpacity: 0.04,
				  }
				: {
						color: '#1f3a5f',
						weight: 2.5,
						fillColor: '#1f3a5f',
						fillOpacity: 0.18,
				  },
		} ).addTo( map );
		if ( clean && cleaned ) {
			L.geoJSON( geometryOf( cleaned.mp ), {
				style: {
					color: '#1f3a5f',
					weight: 2.5,
					fillColor: '#1f3a5f',
					fillOpacity: 0.18,
				},
			} ).addTo( map );
		}
		const bounds = rawLayer.getBounds();
		if ( bounds.isValid() ) {
			map.fitBounds( bounds, { padding: [ 24, 24 ] } );
		} else {
			map.setView( [ 39, -96 ], 4 );
		}
		// The modal animates in; the map measures itself once it has its final size.
		const timer = setTimeout( () => map.invalidateSize(), 250 );
		return () => {
			clearTimeout( timer );
			map.remove();
		};
	}, [ raw, cleaned, clean, settings.tiles ] );

	return (
		<Modal
			className="mappulse-street"
			title={ sprintf(
				/* translators: %s: area name. */ __(
					'Is this %s?',
					'mappulse'
				),
				result.name
			) }
			onRequestClose={ onClose }
			size="large"
		>
			<p className="mappulse-street__where">
				{ [
					result.parent,
					result.source === 'census'
						? __( 'Boundary from the US Census Bureau', 'mappulse' )
						: result.source,
				]
					.filter( Boolean )
					.join( ' · ' ) }
			</p>
			<div
				className="mappulse-street__map"
				ref={ holder }
				role="img"
				aria-label={ sprintf(
					/* translators: %s: area name. */ __(
						'Street map with the outline of %s',
						'mappulse'
					),
					result.name
				) }
			/>
			{ fragmented && (
				<div className="mappulse-street__clean">
					<CheckboxControl
						label={ __( 'Use clean outline', 'mappulse' ) }
						help={ __(
							'The source draws this place as separate pieces or with gaps. A clean outline joins them into one solid shape (solid line). The source shape is the dashed line.',
							'mappulse'
						) }
						checked={ clean }
						onChange={ setClean }
						__nextHasNoMarginBottom
					/>
				</div>
			) }
			<div className="mappulse-street__actions">
				<Button
					variant="tertiary"
					onClick={ onClose }
					__next40pxDefaultSize
				>
					{ __( 'Not this one', 'mappulse' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ () => onAdd( clean ) }
					__next40pxDefaultSize
				>
					{ __( 'Add to map', 'mappulse' ) }
				</Button>
			</div>
		</Modal>
	);
}
