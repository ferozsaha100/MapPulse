/**
 * The Areas step: find areas on the left, the map being built on the right.
 */
import { useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SearchPanel from './search-panel';
import StreetPreview from './street-preview';
import ZoneList from './zone-list';
import MapPreview from './map-preview';
import { zoneFromResult } from '../store';

export default function AreasStep( { settings, state, dispatch, preview } ) {
	// The search result being checked against the street map, with its fetched boundary.
	const [ candidate, setCandidate ] = useState( null );

	if ( state.legacy ) {
		return (
			<div className="mappulse-builder__body">
				<div className="mappulse-builder__side">
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This map was imported from a file, so it does not hold the boundaries its areas came from. It keeps working where it is placed, but its areas cannot be changed here. Build a new map to choose areas.',
							'mappulse'
						) }
					</Notice>
					<ul className="mappulse-builder__legacy">
						{ state.legacyZones.map( ( zone ) => (
							<li key={ zone.id }>{ zone.name }</li>
						) ) }
					</ul>
				</div>
				<MapPreview preview={ preview } empty={ false } />
			</div>
		);
	}

	const taken = new Set(
		state.zones.map( ( z ) => `${ z.source }|${ z.record }` )
	);

	return (
		<div className="mappulse-builder__body">
			<div className="mappulse-builder__side">
				<SearchPanel
					settings={ settings }
					taken={ taken }
					onPreview={ setCandidate }
				/>
				<ZoneList
					settings={ settings }
					state={ state }
					dispatch={ dispatch }
				/>
			</div>
			<MapPreview
				preview={ preview }
				empty={ state.zones.length === 0 }
			/>
			{ candidate && (
				<StreetPreview
					settings={ settings }
					candidate={ candidate }
					onClose={ () => setCandidate( null ) }
					onAdd={ ( clean ) => {
						dispatch( {
							type: 'ADD_ZONE',
							zone: {
								...zoneFromResult(
									candidate.result,
									candidate.feature
								),
								clean,
							},
						} );
						setCandidate( null );
					} }
				/>
			) }
		</div>
	);
}
