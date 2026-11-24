/**
 * Search for an area by name or ZIP code. Every result names its source.
 */
import { useState } from '@wordpress/element';
import {
	Button,
	TextControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { searchAreas, fetchArea, messageOf } from '../api';

export default function SearchPanel( { settings, taken, onPreview } ) {
	const [ query, setQuery ] = useState( '' );
	const [ type, setType ] = useState( 'city' );
	const [ place, setPlace ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ answer, setAnswer ] = useState( null );
	const [ error, setError ] = useState( '' );
	// The record whose boundary is being fetched, so only its button shows as busy.
	const [ opening, setOpening ] = useState( '' );

	const types = settings.types || [];
	const typeLabel = ( value ) =>
		( types.find( ( t ) => t.value === value ) || { label: value } ).label;

	const find = ( event ) => {
		event.preventDefault();
		if ( query.trim().length < 2 ) {
			setError( __( 'Type at least two characters.', 'mappulse' ) );
			return;
		}
		setBusy( true );
		setError( '' );
		searchAreas( query.trim(), type, place )
			.then( ( data ) =>
				setAnswer( {
					results: data.results || [],
					errors: Object.values( data.errors || {} ),
					more: !! data.more,
				} )
			)
			.catch( ( e ) => {
				setAnswer( null );
				setError(
					messageOf(
						e,
						__( 'The search could not be run.', 'mappulse' )
					)
				);
			} )
			.finally( () => setBusy( false ) );
	};

	const open = ( result ) => {
		const key = `${ result.source }|${ result.record }`;
		setOpening( key );
		setError( '' );
		fetchArea( result.source, result.record )
			.then( ( feature ) => onPreview( { result, feature } ) )
			.catch( ( e ) =>
				setError(
					sprintf(
						/* translators: 1: area name, 2: reason. */
						__(
							'The boundary of %1$s could not be loaded. %2$s',
							'mappulse'
						),
						result.name,
						messageOf( e, '' )
					)
				)
			)
			.finally( () => setOpening( '' ) );
	};

	return (
		<section
			className="mappulse-search"
			aria-labelledby="mappulse-search-title"
		>
			<h2 id="mappulse-search-title">
				{ __( 'Find an area', 'mappulse' ) }
			</h2>
			<form className="mappulse-search__form" onSubmit={ find }>
				<TextControl
					label={ __( 'Name or ZIP code', 'mappulse' ) }
					value={ query }
					onChange={ setQuery }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Kind of area', 'mappulse' ) }
					value={ type }
					options={ types }
					onChange={ setType }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'State', 'mappulse' ) }
					value={ place }
					options={ [
						{ value: '', label: __( 'All states', 'mappulse' ) },
						...( settings.states || [] ).map( ( s ) => ( {
							value: s.code,
							label: s.name,
						} ) ),
					] }
					onChange={ setPlace }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Find', 'mappulse' ) }
				</Button>
			</form>

			<div aria-live="polite">
				{ error && <p className="mappulse-search__error">{ error }</p> }
				{ answer &&
					answer.results.length === 0 &&
					! answer.errors.length && (
						<p className="mappulse-search__none">
							{ type === 'neighborhood'
								? __(
										'Nothing found. Neighborhoods come from city layers you register under MapPulse, Sources.',
										'mappulse'
								  )
								: __(
										'Nothing found. Check the spelling, or try another kind of area or state.',
										'mappulse'
								  ) }
						</p>
					) }
				{ answer && answer.results.length > 0 && (
					<ul className="mappulse-search__results">
						{ answer.results.map( ( result ) => {
							const key = `${ result.source }|${ result.record }`;
							return (
								<li key={ key }>
									<span className="mappulse-search__name">
										<strong>{ result.name }</strong>
										<span>
											{ [
												typeLabel( result.type ),
												result.parent,
												result.source === 'census'
													? __(
															'US Census Bureau',
															'mappulse'
													  )
													: result.source,
											]
												.filter( Boolean )
												.join( ' · ' ) }
										</span>
									</span>
									{ taken.has( key ) ? (
										<span className="mappulse-search__added">
											{ __( 'On the map', 'mappulse' ) }
										</span>
									) : (
										<Button
											variant="secondary"
											size="small"
											onClick={ () => open( result ) }
											disabled={ !! opening }
											aria-label={ sprintf(
												/* translators: %s: area name. */ __(
													'Preview %s',
													'mappulse'
												),
												result.name
											) }
										>
											{ opening === key ? (
												<Spinner />
											) : (
												__( 'Preview', 'mappulse' )
											) }
										</Button>
									) }
								</li>
							);
						} ) }
					</ul>
				) }
				{ answer && answer.more && (
					<p className="mappulse-search__none">
						{ __(
							'There are more matches. Add a state or type more of the name to narrow them.',
							'mappulse'
						) }
					</p>
				) }
				{ answer &&
					answer.errors.map( ( message, i ) => (
						<p key={ i } className="mappulse-search__error">
							{ message }
						</p>
					) ) }
			</div>
		</section>
	);
}
