/**
 * The link of one area: search the site's pages and posts, or paste any address.
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { Button, TextControl, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { searchLinks, messageOf } from '../api';
import { validLink } from './auto-match';

export default function LinkField( { zone, onChange } ) {
	const [ text, setText ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ note, setNote ] = useState( '' );
	const latest = useRef( 0 );

	const looksLikeAddress = /^https?:/i.test( text.trim() );

	// Search as the person types, once they pause.
	useEffect( () => {
		const query = text.trim();
		if ( looksLikeAddress || query.length < 2 ) {
			setResults( null );
			setBusy( false );
			return;
		}
		const id = ++latest.current;
		setBusy( true );
		const timer = setTimeout( () => {
			searchLinks( query )
				.then( ( found ) => {
					if ( id === latest.current ) {
						setResults( found );
						setNote( '' );
					}
				} )
				.catch( ( e ) => {
					if ( id === latest.current ) {
						setResults( null );
						setNote(
							messageOf(
								e,
								__( 'The search could not be run.', 'mappulse' )
							)
						);
					}
				} )
				.finally( () => id === latest.current && setBusy( false ) );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ text, looksLikeAddress ] );

	const applyAddress = () => {
		const link = validLink( text );
		if ( ! link ) {
			setNote(
				__(
					'That is not a full web address. It should start with https://',
					'mappulse'
				)
			);
			return;
		}
		onChange( { link, linkPost: 0, linkTitle: '' } );
		setText( '' );
		setNote( '' );
	};

	if ( zone.link ) {
		return (
			<div className="mappulse-link is-set">
				<span className="mappulse-link__current">
					<strong>
						{ zone.linkTitle ||
							( zone.linkPost
								? __( 'A page on this site', 'mappulse' )
								: __( 'Web address', 'mappulse' ) ) }
					</strong>
					<a href={ zone.link } target="_blank" rel="noreferrer">
						{ zone.link }
					</a>
				</span>
				<Button
					variant="secondary"
					size="small"
					onClick={ () =>
						onChange( { link: '', linkPost: 0, linkTitle: '' } )
					}
					aria-label={ sprintf(
						/* translators: %s: area name. */ __(
							'Remove the link of %s',
							'mappulse'
						),
						zone.name
					) }
				>
					{ __( 'Remove', 'mappulse' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="mappulse-link">
			<div className="mappulse-link__entry">
				<TextControl
					label={ sprintf(
						/* translators: %s: area name. */ __(
							'Link for %s',
							'mappulse'
						),
						zone.name
					) }
					hideLabelFromVision
					placeholder={ __(
						'Search pages, or paste a web address',
						'mappulse'
					) }
					value={ text }
					onChange={ ( value ) => {
						setText( value );
						setNote( '' );
					} }
					onKeyDown={ ( event ) => {
						if ( event.key === 'Enter' && looksLikeAddress ) {
							event.preventDefault();
							applyAddress();
						}
					} }
					__nextHasNoMarginBottom
				/>
				{ looksLikeAddress && (
					<Button variant="secondary" onClick={ applyAddress }>
						{ __( 'Use this address', 'mappulse' ) }
					</Button>
				) }
				{ busy && <Spinner /> }
			</div>
			<div aria-live="polite">
				{ note && <p className="mappulse-link__note">{ note }</p> }
				{ results && results.length === 0 && (
					<p className="mappulse-link__none">
						{ __(
							'No published page or post has that in its title.',
							'mappulse'
						) }
					</p>
				) }
				{ results && results.length > 0 && (
					<ul className="mappulse-link__results">
						{ results.map( ( found ) => (
							<li key={ found.id }>
								<Button
									variant="tertiary"
									onClick={ () => {
										onChange( {
											link: found.url,
											linkPost: found.id,
											linkTitle: found.title,
										} );
										setText( '' );
										setResults( null );
									} }
								>
									<span>{ found.title }</span>
									<span className="mappulse-link__type">
										{ found.type }
									</span>
								</Button>
							</li>
						) ) }
					</ul>
				) }
			</div>
		</div>
	);
}
