/**
 * The builder: a header with the map name and save buttons, the step tabs, and the current step.
 */
import { useReducer, useEffect, useCallback } from '@wordpress/element';
import { Button, TextControl, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { reduce, initialState, toDocument, saveActions } from './store';
import { loadMap, saveMap, messageOf } from './api';
import { useDocument } from './areas/use-document';
import AreasStep from './areas/areas-step';
import StyleStep from './style/style-step';
import LinksStep from './links/links-step';

const STEPS = [
	{ id: 'areas', label: __( 'Areas', 'mappulse' ), ready: true },
	{ id: 'style', label: __( 'Style', 'mappulse' ), ready: true },
	{ id: 'links', label: __( 'Zones and links', 'mappulse' ), ready: true },
	{ id: 'decorate', label: __( 'Decorate', 'mappulse' ) },
	{ id: 'publish', label: __( 'Publish', 'mappulse' ) },
];

export default function App( { settings } ) {
	const [ state, dispatch ] = useReducer( reduce, settings, initialState );
	const preview = useDocument( state, dispatch );

	// Open the map named in the address.
	useEffect( () => {
		if ( ! settings.mapId ) {
			return;
		}
		loadMap( settings.mapId )
			.then( ( map ) => dispatch( { type: 'LOADED', map } ) )
			.catch( ( error ) =>
				dispatch( {
					type: 'FAILED',
					message: messageOf(
						error,
						__( 'This map could not be opened.', 'mappulse' )
					),
				} )
			);
	}, [ settings.mapId ] );

	// Warn before leaving with work that is not saved.
	useEffect( () => {
		if ( ! state.dirty ) {
			return;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ state.dirty ] );

	const save = useCallback(
		( status ) => {
			const document = toDocument( state );
			if ( ! document ) {
				return;
			}
			dispatch( { type: 'SAVING' } );
			saveMap( state.mapId, document, status )
				.then( ( map ) => {
					dispatch( {
						type: 'SAVED',
						mapId: map.id,
						status: map.status,
					} );
					// Keep the address in step, so a reload opens this map.
					if (
						settings.editUrl &&
						window.history &&
						window.history.replaceState
					) {
						window.history.replaceState(
							null,
							'',
							`${ settings.editUrl }&map=${ map.id }`
						);
					}
				} )
				.catch( ( error ) =>
					dispatch( {
						type: 'FAILED',
						message: messageOf(
							error,
							__( 'The map could not be saved.', 'mappulse' )
						),
					} )
				);
		},
		[ state, settings.editUrl ]
	);

	// Saving waits for the drawing to catch up with the last change, so what is stored is what is shown.
	const { canSave, showDraft, primary } = saveActions( state, preview );
	let status = __( 'Not saved yet', 'mappulse' );
	if ( state.saving ) {
		status = __( 'Saving…', 'mappulse' );
	} else if ( state.dirty ) {
		status = __( 'Unsaved changes', 'mappulse' );
	} else if ( state.mapId ) {
		status =
			state.status === 'publish'
				? __( 'Published', 'mappulse' )
				: __( 'Draft saved', 'mappulse' );
	}

	return (
		<div className="mappulse-builder">
			<header className="mappulse-builder__header">
				<a className="mappulse-builder__back" href={ settings.listUrl }>
					{ __( '← All maps', 'mappulse' ) }
				</a>
				<TextControl
					className="mappulse-builder__title"
					label={ __( 'Map name', 'mappulse' ) }
					hideLabelFromVision
					placeholder={ __( 'Map name', 'mappulse' ) }
					value={ state.title }
					onChange={ ( title ) =>
						dispatch( { type: 'SET_TITLE', title } )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<span className="mappulse-builder__status" role="status">
					{ status }
				</span>
				{ showDraft && (
					<Button
						variant="secondary"
						disabled={ ! canSave }
						onClick={ () => save( 'draft' ) }
						__next40pxDefaultSize
					>
						{ __( 'Save draft', 'mappulse' ) }
					</Button>
				) }
				<Button
					variant="primary"
					disabled={ ! canSave }
					onClick={ () => save( 'publish' ) }
					__next40pxDefaultSize
				>
					{ primary === 'update'
						? __( 'Update', 'mappulse' )
						: __( 'Publish', 'mappulse' ) }
				</Button>
			</header>

			<nav
				className="mappulse-builder__steps"
				aria-label={ __( 'Builder steps', 'mappulse' ) }
			>
				<ol>
					{ STEPS.map( ( step, i ) => {
						const current = state.step === step.id;
						// The steps after Areas work on the map's areas, so they wait for the first one.
						const open =
							step.ready &&
							( step.id === 'areas' || state.zones.length > 0 );
						if ( ! step.ready ) {
							return (
								<li key={ step.id } className="is-later">
									<span className="mappulse-builder__step-number">
										{ i + 1 }
									</span>
									{ step.label }
									<span className="mappulse-builder__soon">
										{ __( 'Coming later', 'mappulse' ) }
									</span>
								</li>
							);
						}
						return (
							<li
								key={ step.id }
								className={ current ? 'is-current' : '' }
							>
								<button
									type="button"
									className="mappulse-builder__step"
									aria-current={
										current ? 'step' : undefined
									}
									disabled={ ! open }
									title={
										open
											? undefined
											: __(
													'Add an area first',
													'mappulse'
											  )
									}
									onClick={ () =>
										dispatch( {
											type: 'SET_STEP',
											step: step.id,
										} )
									}
								>
									<span className="mappulse-builder__step-number">
										{ i + 1 }
									</span>
									{ step.label }
								</button>
							</li>
						);
					} ) }
				</ol>
			</nav>

			{ state.error && (
				<Notice
					status="error"
					onRemove={ () => dispatch( { type: 'CLEAR_ERROR' } ) }
				>
					{ state.error }
				</Notice>
			) }
			{ state.mapId > 0 && ! state.dirty && ! state.saving && (
				<p className="mappulse-builder__shortcode">
					{ sprintf(
						/* translators: %s: shortcode. */
						__(
							'Place this map with the MapPulse widget or block, or with the shortcode %s',
							'mappulse'
						),
						`[mappulse id="${ state.mapId }"]`
					) }
					{ state.status !== 'publish' &&
						' ' + __( '(publish it first).', 'mappulse' ) }
				</p>
			) }

			{ state.blocked && (
				<p className="mappulse-builder__loading">
					{ __(
						'Go back to the list of maps and open it again. Nothing was changed.',
						'mappulse'
					) }
				</p>
			) }
			{ ! state.blocked && state.loading && (
				<p className="mappulse-builder__loading">
					<Spinner /> { __( 'Opening the map…', 'mappulse' ) }
				</p>
			) }
			{ ! state.blocked && ! state.loading && state.step === 'areas' && (
				<AreasStep
					settings={ settings }
					state={ state }
					dispatch={ dispatch }
					preview={ preview }
				/>
			) }
			{ ! state.blocked && ! state.loading && state.step === 'style' && (
				<StyleStep
					settings={ settings }
					state={ state }
					dispatch={ dispatch }
					preview={ preview }
				/>
			) }
			{ ! state.blocked && ! state.loading && state.step === 'links' && (
				<LinksStep
					state={ state }
					dispatch={ dispatch }
					preview={ preview }
				/>
			) }
		</div>
	);
}
