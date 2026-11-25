/**
 * The hover card of one area: a photo, a tagline, two facts and two buttons.
 */
import { useEffect, useState } from '@wordpress/element';
import { Button, TextControl, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import LinkField from './link-field';
import { editableCard } from './card';

// Opens the WordPress media library for one image.
function chooseImage( onChoose ) {
	const media = window.wp && window.wp.media;
	if ( ! media ) {
		return;
	}
	const frame = media( {
		title: __( 'Choose a photo for this card', 'mappulse' ),
		library: { type: 'image' },
		multiple: false,
		button: { text: __( 'Use this photo', 'mappulse' ) },
	} );
	frame.on( 'select', () => {
		const picked = frame.state().get( 'selection' ).first();
		if ( ! picked ) {
			return;
		}
		const file = picked.toJSON();
		const sizes = file.sizes || {};
		onChoose( {
			image: file.id,
			imageUrl:
				( sizes.medium || sizes.thumbnail || sizes.full || {} ).url ||
				file.url ||
				'',
		} );
	} );
	frame.open();
}

export default function CardFields( {
	zone,
	onChange,
	onCopyLayout,
	canCopy,
} ) {
	const card = editableCard( zone.card );
	const set = ( patch ) => onChange( { ...card, ...patch } );
	const setRow = ( list, index, patch ) =>
		set( {
			[ list ]: card[ list ].map( ( row, i ) =>
				i === index ? { ...row, ...patch } : row
			),
		} );

	// A saved card holds only the photo's ID. Its thumbnail is looked up once for display.
	const [ thumb, setThumb ] = useState( card.imageUrl );
	useEffect( () => {
		if ( card.imageUrl || ! card.image ) {
			setThumb( card.imageUrl );
			return;
		}
		let live = true;
		apiFetch( {
			path: `/wp/v2/media/${ card.image }?_fields=id,source_url,media_details`,
		} )
			.then( ( file ) => {
				const sizes =
					( file.media_details && file.media_details.sizes ) || {};
				if ( live ) {
					setThumb(
						( sizes.medium || sizes.thumbnail || {} ).source_url ||
							file.source_url ||
							''
					);
				}
			} )
			.catch( () => live && setThumb( '' ) );
		return () => {
			live = false;
		};
	}, [ card.image, card.imageUrl ] );

	return (
		<div className="mappulse-card-fields">
			<div className="mappulse-card-fields__photo">
				{ card.image > 0 && thumb && <img src={ thumb } alt="" /> }
				{ card.image > 0 && ! thumb && (
					<span className="mappulse-card-fields__missing">
						{ __(
							'This photo is no longer in the media library.',
							'mappulse'
						) }
					</span>
				) }
				<Button
					variant="secondary"
					size="small"
					onClick={ () => chooseImage( set ) }
				>
					{ card.image > 0
						? __( 'Replace photo', 'mappulse' )
						: __( 'Choose a photo', 'mappulse' ) }
				</Button>
				{ card.image > 0 && (
					<Button
						variant="tertiary"
						size="small"
						isDestructive
						onClick={ () => set( { image: 0, imageUrl: '' } ) }
					>
						{ __( 'Remove photo', 'mappulse' ) }
					</Button>
				) }
			</div>

			<TextareaControl
				label={ __( 'Tagline', 'mappulse' ) }
				help={ sprintf(
					/* translators: %d: characters left. */
					__(
						'One or two lines about the area. %d characters left.',
						'mappulse'
					),
					Math.max( 0, 120 - card.tagline.length )
				) }
				rows={ 2 }
				maxLength={ 120 }
				value={ card.tagline }
				onChange={ ( tagline ) => set( { tagline } ) }
				__nextHasNoMarginBottom
			/>

			<fieldset className="mappulse-card-fields__group">
				<legend>{ __( 'Facts', 'mappulse' ) }</legend>
				{ card.stats.map( ( row, i ) => (
					<div key={ i } className="mappulse-card-fields__pair">
						<TextControl
							label={ sprintf(
								/* translators: %d: 1 or 2. */ __(
									'Fact %d name',
									'mappulse'
								),
								i + 1
							) }
							placeholder={
								i === 0
									? __( 'Median price', 'mappulse' )
									: __( 'Homes for sale', 'mappulse' )
							}
							maxLength={ 24 }
							value={ row.label }
							onChange={ ( label ) =>
								setRow( 'stats', i, { label } )
							}
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ sprintf(
								/* translators: %d: 1 or 2. */ __(
									'Fact %d value',
									'mappulse'
								),
								i + 1
							) }
							placeholder={ i === 0 ? '$640,000' : '112' }
							maxLength={ 24 }
							value={ row.value }
							onChange={ ( value ) =>
								setRow( 'stats', i, { value } )
							}
							__nextHasNoMarginBottom
						/>
					</div>
				) ) }
			</fieldset>

			<fieldset className="mappulse-card-fields__group">
				<legend>{ __( 'Buttons', 'mappulse' ) }</legend>
				<p className="mappulse-panel__hint">
					{ __(
						'With no button here, a linked area gets a "View area" button to its link.',
						'mappulse'
					) }
				</p>
				{ card.buttons.map( ( row, i ) => (
					<div key={ i } className="mappulse-card-fields__button">
						<TextControl
							label={ sprintf(
								/* translators: %d: 1 or 2. */ __(
									'Button %d text',
									'mappulse'
								),
								i + 1
							) }
							placeholder={
								i === 0
									? __( 'Search homes', 'mappulse' )
									: __( 'Area guide', 'mappulse' )
							}
							maxLength={ 24 }
							value={ row.label }
							onChange={ ( label ) =>
								setRow( 'buttons', i, { label } )
							}
							__nextHasNoMarginBottom
						/>
						<LinkField
							zone={ {
								name:
									row.label ||
									sprintf(
										/* translators: %d: 1 or 2. */ __(
											'button %d',
											'mappulse'
										),
										i + 1
									),
								link: row.url,
								linkPost: row.post,
								linkTitle: row.title,
							} }
							onChange={ ( patch ) =>
								setRow( 'buttons', i, {
									url: patch.link,
									post: patch.linkPost,
									title: patch.linkTitle,
								} )
							}
						/>
					</div>
				) ) }
			</fieldset>

			{ canCopy && (
				<Button variant="link" onClick={ onCopyLayout }>
					{ __(
						"Start the other areas' cards with these fact and button names",
						'mappulse'
					) }
				</Button>
			) }
		</div>
	);
}
