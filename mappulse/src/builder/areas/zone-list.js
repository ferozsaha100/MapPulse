/**
 * The areas on the map, in the order that decides which one wins where two overlap.
 */
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { warningText } from '../messages';

export default function ZoneList( { settings, state, dispatch } ) {
	const { zones, warnings } = state;
	const typeLabel = ( value ) =>
		(
			( settings.types || [] ).find( ( t ) => t.value === value ) || {
				label: value,
			}
		).label;
	const set = ( id, patch ) => dispatch( { type: 'SET_ZONE', id, patch } );

	return (
		<section
			className="mappulse-zones"
			aria-labelledby="mappulse-zones-title"
		>
			<h2 id="mappulse-zones-title">
				{ sprintf(
					/* translators: %d: number of areas. */
					__( 'Areas on this map (%d)', 'mappulse' ),
					zones.length
				) }
			</h2>
			{ zones.length === 0 ? (
				<p className="mappulse-zones__empty">
					{ __(
						'None yet. Find an area above, preview it, and add it to the map.',
						'mappulse'
					) }
				</p>
			) : (
				<>
					<p className="mappulse-zones__hint">
						{ __(
							'Where areas overlap, a neighborhood is cut out of a city, a city out of a ZIP area, and a ZIP area out of a county. Between two areas of one kind, the one higher in this list wins.',
							'mappulse'
						) }
					</p>
					<ol className="mappulse-zones__list">
						{ zones.map( ( zone, i ) => {
							const warning = warnings.find(
								( w ) => w.zone === zone.id
							);
							return (
								<li
									key={ zone.id }
									className="mappulse-zone-row"
								>
									<div className="mappulse-zone-row__head">
										<TextControl
											label={ __(
												'Name on the map',
												'mappulse'
											) }
											hideLabelFromVision
											value={ zone.name }
											onChange={ ( name ) =>
												set( zone.id, { name } )
											}
											__nextHasNoMarginBottom
										/>
										<Button
											icon="arrow-up-alt2"
											size="small"
											label={ sprintf(
												/* translators: %s: area name. */ __(
													'Move %s up',
													'mappulse'
												),
												zone.name
											) }
											disabled={ i === 0 }
											accessibleWhenDisabled
											onClick={ () =>
												dispatch( {
													type: 'MOVE_ZONE',
													from: i,
													to: i - 1,
												} )
											}
										/>
										<Button
											icon="arrow-down-alt2"
											size="small"
											label={ sprintf(
												/* translators: %s: area name. */ __(
													'Move %s down',
													'mappulse'
												),
												zone.name
											) }
											disabled={ i === zones.length - 1 }
											accessibleWhenDisabled
											onClick={ () =>
												dispatch( {
													type: 'MOVE_ZONE',
													from: i,
													to: i + 1,
												} )
											}
										/>
										<Button
											icon="trash"
											size="small"
											isDestructive
											label={ sprintf(
												/* translators: %s: area name. */ __(
													'Remove %s',
													'mappulse'
												),
												zone.name
											) }
											onClick={ () =>
												dispatch( {
													type: 'REMOVE_ZONE',
													id: zone.id,
												} )
											}
										/>
									</div>
									<p className="mappulse-zone-row__meta">
										{ [
											typeLabel( zone.type ),
											zone.parent,
											zone.source === 'census'
												? __(
														'US Census Bureau',
														'mappulse'
												  )
												: zone.source,
										]
											.filter( Boolean )
											.join( ' · ' ) }
									</p>
									<div className="mappulse-zone-row__toggles">
										<ToggleControl
											label={ __(
												'Backdrop',
												'mappulse'
											) }
											help={ __(
												'Keep whole and draw underneath the others.',
												'mappulse'
											) }
											checked={ zone.backdrop }
											onChange={ ( backdrop ) =>
												set( zone.id, { backdrop } )
											}
											__nextHasNoMarginBottom
										/>
										{ ( zone.needsClean || zone.clean ) && (
											<ToggleControl
												label={ __(
													'Clean outline',
													'mappulse'
												) }
												help={ __(
													'Join separate pieces into one solid shape. It then keeps that shape where it overlaps areas of the same kind.',
													'mappulse'
												) }
												checked={ zone.clean }
												onChange={ ( clean ) =>
													set( zone.id, { clean } )
												}
												__nextHasNoMarginBottom
											/>
										) }
									</div>
									{ warning && (
										<p className="mappulse-zone-row__warning">
											{ warningText( warning.code ) }
										</p>
									) }
								</li>
							);
						} ) }
					</ol>
				</>
			) }
		</section>
	);
}
