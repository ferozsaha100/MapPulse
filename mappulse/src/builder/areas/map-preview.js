/**
 * The map as it will appear on the site, drawn by the same renderer the front end uses.
 */
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function MapPreview( { preview, empty } ) {
	const { svg, busy, error, baseNote } = preview;
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
					className={
						'mappulse-preview__map' + ( busy ? ' is-busy' : '' )
					}
					dangerouslySetInnerHTML={ { __html: svg } }
				/>
			) }
		</section>
	);
}
