/**
 * One color: a swatch button that opens the site's colors and a custom picker.
 */
import { Dropdown, Button, ColorPalette } from '@wordpress/components';
import { sprintf, __ } from '@wordpress/i18n';

export default function ColorField( { label, value, colors, onChange } ) {
	return (
		<Dropdown
			className="mappulse-color"
			popoverProps={ { placement: 'left-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					className="mappulse-color__toggle"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					aria-label={ sprintf(
						/* translators: 1: what the color is for, 2: the color value. */
						__( '%1$s: %2$s. Change color', 'mappulse' ),
						label,
						value
					) }
				>
					<span
						className="mappulse-color__swatch"
						style={ { background: value } }
					/>
					<span className="mappulse-color__label">{ label }</span>
					<span className="mappulse-color__value">{ value }</span>
				</Button>
			) }
			renderContent={ () => (
				<div className="mappulse-color__picker">
					<ColorPalette
						colors={ colors }
						value={ value }
						clearable={ false }
						enableAlpha={ false }
						onChange={ ( color ) =>
							color && onChange( color.toUpperCase() )
						}
					/>
				</div>
			) }
		/>
	);
}
