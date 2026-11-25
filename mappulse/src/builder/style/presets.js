/**
 * Style presets. A preset sets the colors; the font, label size, border and hover effect are the
 * person's own choices and are kept when a preset is applied.
 */
import { __ } from '@wordpress/i18n';
import { mix, normalHex, contrastRatio, luminance, AA } from './contrast';

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
	'minimal-line': {
		label: __( 'Minimal Line', 'mappulse' ),
		description: __(
			'White land with the areas drawn as outlines.',
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
	// The areas are a mid tint: clearly darker than the land and water around them, light enough for dark labels.
	let zone = mix( brand, WHITE, 0.55 );
	for ( let step = 0; step < 12 && luminance( zone ) > 0.4; step++ ) {
		zone = mix( zone, INK, 0.08 );
	}
	// Ink with a little of the brand in it; darkened further until it reads on the area color.
	let label = mix( brand, INK, 0.78 );
	if ( contrastRatio( label, zone ) < AA ) {
		label = INK;
	}
	// The surroundings are tints of the highlight color, which is never too light to tint with.
	// Land is near white, nearby areas a step darker, water a clear cool tone with a firmer shoreline.
	// Each is far enough from the others to tell apart at a glance.
	return {
		zone,
		zoneStroke: WHITE,
		zoneHover: hover,
		label,
		labelHover: textOn( hover ),
		land: mix( hover, WHITE, 0.97 ),
		context: mix( hover, WHITE, 0.8 ),
		contextStroke: WHITE,
		water: mix( mix( hover, '#7FA9B8', 0.75 ), WHITE, 0.68 ),
		coast: mix( mix( hover, '#7FA9B8', 0.75 ), WHITE, 0.2 ),
		waterLabel: mix( mix( brand, '#7FA9B8', 0.5 ), INK, 0.5 ),
		road: WHITE,
		roadCasing: mix( hover, WHITE, 0.55 ),
	};
}

// White land with the areas drawn as outlines in one ink color.
const MINIMAL_LINE = {
	zone: '#FFFFFF',
	zoneStroke: '#2B2F36',
	zoneHover: '#2B2F36',
	label: '#2B2F36',
	labelHover: '#FFFFFF',
	land: '#FFFFFF',
	context: '#FFFFFF',
	contextStroke: '#D5D8DC',
	water: '#EEF3F5',
	coast: '#B7C4CA',
	waterLabel: '#5F6F78',
	road: '#FFFFFF',
	roadCasing: '#D5D8DC',
};

const colorsOf = ( name, brand, defaults ) => {
	if ( name === 'brand-tint' ) {
		return brandTint( brand );
	}
	if ( name === 'minimal-line' ) {
		return MINIMAL_LINE;
	}
	return Object.fromEntries(
		COLOR_KEYS.map( ( key ) => [ key, defaults[ key ] ] )
	);
};

/**
 * @param name                'atlas', 'brand-tint' or 'minimal-line'. Anything else is treated as Atlas.
 * @param style               The current style block.
 * @param brand               The brand color, for Brand Tint.
 * @param defaults            The built-in style block, as the server gives it.
 * @param options
 * @param options.keepHandSet Keep each color the person changed away from the current preset's own.
 * @return A full style block.
 */
export function applyPreset(
	name,
	style,
	brand,
	defaults,
	{ keepHandSet = false } = {}
) {
	const kept = { ...defaults, ...style };
	const preset = PRESETS[ name ] ? name : 'atlas';
	const color =
		preset === 'brand-tint'
			? normalHex( brand, normalHex( style.brand, DEFAULT_BRAND ) )
			: '';
	const colors = { ...colorsOf( preset, color, defaults ) };
	if ( keepHandSet ) {
		// A color that differs from what the preset in use would give was set by hand.
		const current = PRESETS[ kept.preset ] ? kept.preset : 'atlas';
		const before = colorsOf(
			current,
			normalHex( kept.brand, DEFAULT_BRAND ),
			defaults
		);
		for ( const key of COLOR_KEYS ) {
			if ( normalHex( kept[ key ] ) !== normalHex( before[ key ] ) ) {
				colors[ key ] = kept[ key ];
			}
		}
	}
	return { ...kept, ...colors, preset, brand: color };
}
