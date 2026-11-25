/**
 * The Zones and links step: where each area leads, and how its label reads and sits.
 */
import { useState } from '@wordpress/element';
import {
	Button,
	TextControl,
	ToggleControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import LinkField from './link-field';
import CardFields from './card-fields';
import { hasCard, copyLayout } from './card';
import { planMatches } from './auto-match';
import { clampOffset, atScale } from './label-offset';
import { matchLinks, messageOf } from '../api';
import MapPreview from '../areas/map-preview';

const EMPTY_LABEL = { text: '', dx: 0, dy: 0, hidden: false };
const STEP = 8;

export default function LinksStep( { state, dispatch, preview } ) {
	const { zones } = state;
	const [ selected, setSelected ] = useState( '' );
	const [ onlyUnlinked, setOnlyUnlinked ] = useState( false );
	const [ matching, setMatching ] = useState( false );
	// What the last "Match areas to pages" run found: the unclear cases still to choose, and a summary.
	const [ report, setReport ] = useState( null );
	// The area whose card fields are open.
	const [ cardOpen, setCardOpen ] = useState( '' );
	const withCard = zones.filter( hasCard ).length;

	const set = ( id, patch ) => dispatch( { type: 'SET_ZONE', id, patch } );
	const labelOf = ( zone ) => ( { ...EMPTY_LABEL, ...( zone.label || {} ) } );
	const setLabel = ( zone, patch ) =>
		set( zone.id, { label: { ...labelOf( zone ), ...patch } } );
	const nudge = ( zone, dx, dy ) => {
		const frame = state.doc && state.doc.frame;
		// The offset as it is drawn now: it may have been set when the map was drawn at another scale.
		const label = atScale( labelOf( zone ), frame && frame.scale );
		// Where the builder placed this label, and the frame it must stay in.
		const shape =
			state.doc &&
			state.doc.topology &&
			state.doc.topology.zones[ zone.id ];
		const moved = clampOffset( shape && shape.label, frame, {
			dx: label.dx + dx,
			dy: label.dy + dy,
		} );
		setLabel(
			zone,
			frame && frame.scale > 0 ? { ...moved, scale: frame.scale } : moved
		);
	};

	const linked = zones.filter( ( zone ) => zone.link ).length;
	const shown = onlyUnlinked
		? zones.filter( ( zone ) => ! zone.link )
		: zones;

	const match = () => {
		setMatching( true );
		setReport( null );
		matchLinks( [
			...new Set(
				zones
					.filter( ( zone ) => ! zone.link )
					.map( ( zone ) => zone.name )
			),
		] )
			.then( ( answer ) => {
				const plan = planMatches( zones, answer );
				plan.apply.forEach( ( { id, ...patch } ) => set( id, patch ) );
				setReport( {
					applied: plan.apply.length,
					ask: plan.ask,
					none: plan.none.length,
				} );
			} )
			.catch( ( e ) =>
				setReport( {
					error: messageOf(
						e,
						__( 'The pages could not be looked up.', 'mappulse' )
					),
				} )
			)
			.finally( () => setMatching( false ) );
	};

	const choose = ( item, choice ) => {
		set( item.id, {
			link: choice.url,
			linkPost: choice.id,
			linkTitle: choice.title,
		} );
		setReport(
			( current ) =>
				current && {
					...current,
					ask: current.ask.filter(
						( other ) => other.id !== item.id
					),
				}
		);
	};

	return (
		<div className="mappulse-builder__body">
			<div className="mappulse-builder__side">
				<section
					className="mappulse-panel"
					aria-labelledby="mappulse-links-title"
				>
					<h2 id="mappulse-links-title">
						{ sprintf(
							/* translators: 1: areas with a link, 2: all areas. */
							__( '%1$d of %2$d areas have a link', 'mappulse' ),
							linked,
							zones.length
						) }
					</h2>
					<p className="mappulse-panel__hint">
						{ __(
							'An area with a link is clickable on the map and in the area list. Link each one to its page, or to an IDX search for that area.',
							'mappulse'
						) }
					</p>
					<p className="mappulse-panel__hint">
						{ sprintf(
							/* translators: 1: areas with a hover card, 2: all areas. */
							__(
								'%1$d of %2$d areas have a hover card.',
								'mappulse'
							),
							withCard,
							zones.length
						) }
					</p>
					<div className="mappulse-links__tools">
						<Button
							variant="secondary"
							onClick={ match }
							isBusy={ matching }
							disabled={ matching || linked === zones.length }
						>
							{ __( 'Match areas to pages', 'mappulse' ) }
						</Button>
						{ matching && <Spinner /> }
						<ToggleControl
							label={ __(
								'Show only areas without a link',
								'mappulse'
							) }
							checked={ onlyUnlinked }
							onChange={ setOnlyUnlinked }
							__nextHasNoMarginBottom
						/>
					</div>
					<div aria-live="polite">
						{ report && report.error && (
							<Notice status="error" isDismissible={ false }>
								{ report.error }
							</Notice>
						) }
						{ report && ! report.error && (
							<Notice
								status={ report.applied ? 'success' : 'info' }
								onRemove={ () => setReport( null ) }
							>
								{ sprintf(
									/* translators: %d: number of areas. */
									_n(
										'%d area was linked to the page with its name.',
										'%d areas were linked to the page with their name.',
										report.applied,
										'mappulse'
									),
									report.applied
								) }{ ' ' }
								{ report.ask.length > 0 &&
									sprintf(
										/* translators: %d: number of areas. */
										_n(
											'%d needs your choice below.',
											'%d need your choice below.',
											report.ask.length,
											'mappulse'
										),
										report.ask.length
									) }{ ' ' }
								{ report.none > 0 &&
									sprintf(
										/* translators: %d: number of areas. */
										_n(
											'%d has no page with its name.',
											'%d have no page with their name.',
											report.none,
											'mappulse'
										),
										report.none
									) }
							</Notice>
						) }
						{ report && ! report.error && report.ask.length > 0 && (
							<ul className="mappulse-links__ask">
								{ report.ask.map( ( item ) => (
									<li key={ item.id }>
										<strong>{ item.name }</strong>
										{ item.choices.map( ( choice ) => (
											<Button
												key={ choice.id }
												variant="secondary"
												size="small"
												onClick={ () =>
													choose( item, choice )
												}
											>
												{ choice.title } (
												{ choice.type })
											</Button>
										) ) }
									</li>
								) ) }
							</ul>
						) }
					</div>
				</section>

				<section
					className="mappulse-panel"
					aria-label={ __( 'Areas', 'mappulse' ) }
				>
					{ shown.length === 0 && (
						<p className="mappulse-panel__hint">
							{ __( 'Every area has a link.', 'mappulse' ) }
						</p>
					) }
					<ol className="mappulse-zones__list">
						{ shown.map( ( zone ) => {
							const label = labelOf( zone );
							const moved = label.dx !== 0 || label.dy !== 0;
							return (
								// Focus anywhere in a row marks its area on the map.
								// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
								<li
									key={ zone.id }
									className={
										'mappulse-zone-row mappulse-link-row' +
										( selected === zone.id
											? ' is-selected'
											: '' )
									}
									onFocus={ () => setSelected( zone.id ) }
									onMouseEnter={ () =>
										setSelected( zone.id )
									}
								>
									<h3 className="mappulse-link-row__name">
										{ zone.name }
									</h3>
									<LinkField
										zone={ zone }
										onChange={ ( patch ) =>
											set( zone.id, patch )
										}
									/>
									<div className="mappulse-link-row__label">
										<TextControl
											label={ __(
												'Label on the map',
												'mappulse'
											) }
											placeholder={ zone.name }
											value={ label.text }
											maxLength={ 60 }
											disabled={ label.hidden }
											onChange={ ( text ) =>
												setLabel( zone, { text } )
											}
											__nextHasNoMarginBottom
										/>
										<ToggleControl
											label={ __(
												'Hide label',
												'mappulse'
											) }
											checked={ label.hidden }
											onChange={ ( hidden ) =>
												setLabel( zone, { hidden } )
											}
											__nextHasNoMarginBottom
										/>
									</div>
									{ ! label.hidden && (
										<div
											className="mappulse-link-row__move"
											role="group"
											aria-label={ sprintf(
												/* translators: %s: area name. */ __(
													'Move the label of %s',
													'mappulse'
												),
												zone.name
											) }
										>
											<span>
												{ __(
													'Move label',
													'mappulse'
												) }
											</span>
											{ [
												[
													'arrow-left-alt2',
													__( 'left', 'mappulse' ),
													-1,
													0,
												],
												[
													'arrow-up-alt2',
													__( 'up', 'mappulse' ),
													0,
													-1,
												],
												[
													'arrow-down-alt2',
													__( 'down', 'mappulse' ),
													0,
													1,
												],
												[
													'arrow-right-alt2',
													__( 'right', 'mappulse' ),
													1,
													0,
												],
											].map(
												( [
													icon,
													direction,
													x,
													y,
												] ) => (
													<Button
														key={ icon }
														icon={ icon }
														size="small"
														label={ sprintf(
															/* translators: 1: area name, 2: direction. */
															__(
																'Move the label of %1$s %2$s (hold Shift for a small step)',
																'mappulse'
															),
															zone.name,
															direction
														) }
														onClick={ ( event ) =>
															nudge(
																zone,
																x *
																	( event.shiftKey
																		? 1
																		: STEP ),
																y *
																	( event.shiftKey
																		? 1
																		: STEP )
															)
														}
													/>
												)
											) }
											{ moved && (
												<Button
													variant="link"
													onClick={ () =>
														setLabel( zone, {
															dx: 0,
															dy: 0,
														} )
													}
												>
													{ __(
														'Reset position',
														'mappulse'
													) }
												</Button>
											) }
										</div>
									) }
									<div className="mappulse-link-row__card">
										<Button
											variant="tertiary"
											size="small"
											aria-expanded={
												cardOpen === zone.id
											}
											onClick={ () =>
												setCardOpen(
													cardOpen === zone.id
														? ''
														: zone.id
												)
											}
										>
											{ hasCard( zone )
												? __(
														'Hover card: set',
														'mappulse'
												  )
												: __(
														'Hover card: none',
														'mappulse'
												  ) }
										</Button>
										{ cardOpen === zone.id && (
											<CardFields
												zone={ zone }
												onChange={ ( card ) =>
													set( zone.id, { card } )
												}
												canCopy={
													hasCard( zone ) &&
													zones.some(
														( other ) =>
															other.id !==
																zone.id &&
															! hasCard( other )
													)
												}
												onCopyLayout={ () =>
													copyLayout(
														zones,
														zone.id
													).forEach(
														( { id, card } ) =>
															set( id, { card } )
													)
												}
											/>
										) }
									</div>
								</li>
							);
						} ) }
					</ol>
				</section>
			</div>
			<MapPreview
				preview={ preview }
				empty={ false }
				selected={ selected }
				showCard
				onMoveLabel={ ( id, dx, dy ) => {
					const zone = zones.find( ( z ) => z.id === id );
					if ( zone ) {
						nudge( zone, dx, dy );
					}
				} }
				hint={ __(
					'Point at an area on the left, then drag its label on the map, or use the arrow buttons.',
					'mappulse'
				) }
			/>
		</div>
	);
}
