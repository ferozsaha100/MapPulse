/**
 * Style presets. A preset sets the colors; the font, label size, border and hover effect are the
 * person's own choices and are kept when a preset is applied.
 */
import { __ } from '@wordpress/i18n';
import { mix, normalHex, contrastRatio, AA } from './contrast';

export const COLOR_KEYS = [
	'zone',
	'zoneStroke',
	'zoneHover',
	'label',
	'labelHover',
	'land',
	'context',
	'contextStroke',
	'water',
	'coast',
	'waterLabel',
	'road',
	'roadCasing',
];

export const PRESETS = {
	atlas: {
		label: __( 'Atlas', 'mappulse' ),
		description: __(
			'Warm paper tones with a navy highlight. The built-in look.',
			'mappulse'
		),
	},
	'brand-tint': {
		label: __( 'Brand Tint', 'mappulse' ),
		description: __(
			'The whole map in tints of one brand color.',
			'mappulse'
		),
	},
};

const DEFAULT_BRAND = '#1F3A5F';
const INK = '#14171C';
const WHITE = '#FFFFFF';

// The darkest or lightest text that reads on a color.
const textOn = ( color ) =>
	contrastRatio( WHITE, color ) >= AA ? WHITE : INK;

function brandTint( brand ) {
	// The highlight is the brand color itself where white text reads on it. A lighter brand color is
	// deepened, a step at a time, until it does.
	let hover = brand;
	for (
		let step = 0;
		step < 12 && contrastRatio( WHITE, hover ) < AA;
		step++
	) {
		hover = mix( hover, INK, 0.15 );
	}
	const zone = mix( brand, WHITE, 0.74 );
	// Ink with a little of the brand in it; darkened further until it reads on the area color.
	let label = mix( brand, INK, 0.78 );
	if ( contrastRatio( label, zone ) < AA ) {
		label = INK;
	}
	return {
		zone,
		zoneStroke: WHITE,
		zoneHover: hover,
		label,
		labelHover: textOn( hover ),
		land: mix( brand, WHITE, 0.95 ),
		context: mix( brand, WHITE, 0.89 ),
		contextStroke: WHITE,
		water: mix( mix( brand, '#8FB3BD', 0.7 ), WHITE, 0.66 ),
		coast: mix( mix( brand, '#8FB3BD', 0.7 ), WHITE, 0.3 ),
		waterLabel: mix( mix( brand, '#8FB3BD', 0.5 ), INK, 0.45 ),
		road: WHITE,
		roadCasing: mix( brand, WHITE, 0.62 ),
	};
}

/**
 * @param name     'atlas' or 'brand-tint'. Anything else is treated as Atlas.
 * @param style    The current style block.
 * @param brand    The brand color, for Brand Tint.
 * @param defaults The built-in style block, as the server gives it.
 * @return A full style block.
 */
export function applyPreset( name, style, brand, defaults ) {
	const kept = { ...defaults, ...style };
	if ( name !== 'brand-tint' ) {
		const colors = Object.fromEntries(
			COLOR_KEYS.map( ( key ) => [ key, defaults[ key ] ] )
		);
		return { ...kept, ...colors, preset: 'atlas', brand: '' };
	}
	const color = normalHex( brand, normalHex( style.brand, DEFAULT_BRAND ) );
	return {
		...kept,
		...brandTint( color ),
		preset: 'brand-tint',
		brand: color,
	};
}
