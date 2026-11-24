/**
 * Color helpers: mixing, and the WCAG contrast ratio between two colors.
 */

// A #RRGGBB or #RGB color as [ r, g, b ], or null.
export function parseHex( hex ) {
	const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec( String( hex ).trim() );
	if ( ! m ) {
		return null;
	}
	const h = m[ 1 ].length === 3 ? m[ 1 ].replace( /./g, '$&$&' ) : m[ 1 ];
	return [ 0, 2, 4 ].map( ( i ) => parseInt( h.slice( i, i + 2 ), 16 ) );
}

export const toHex = ( rgb ) =>
	'#' +
	rgb
		.map( ( n ) =>
			Math.round( Math.min( 255, Math.max( 0, n ) ) )
				.toString( 16 )
				.padStart( 2, '0' )
		)
		.join( '' )
		.toUpperCase();

// A color as upper-case #RRGGBB, or the fallback when it is not a hex color.
export const normalHex = ( hex, fallback = '' ) => {
	const rgb = parseHex( hex );
	return rgb ? toHex( rgb ) : fallback;
};

// Moves a color toward another by a share from 0 (unchanged) to 1 (the other color).
export function mix( hex, toward, share ) {
	const a = parseHex( hex ) || [ 0, 0, 0 ];
	const b = parseHex( toward ) || [ 0, 0, 0 ];
	return toHex( a.map( ( v, i ) => v + ( b[ i ] - v ) * share ) );
}

export function luminance( hex ) {
	const rgb = parseHex( hex );
	if ( ! rgb ) {
		return null;
	}
	const [ r, g, b ] = rgb.map( ( v ) => {
		const c = v / 255;
		return c <= 0.03928
			? c / 12.92
			: Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

// The WCAG contrast ratio, from 1 (none) to 21. 1 when either color cannot be read.
export function contrastRatio( a, b ) {
	const la = luminance( a );
	const lb = luminance( b );
	if ( la === null || lb === null ) {
		return 1;
	}
	return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
}

export const AA = 4.5;

/**
 * Whether the labels can be read: the label on the area color, and the label on the hover color.
 *
 * @param style A style block.
 * @return ok, and the two ratios.
 */
export function readable( style ) {
	const label = contrastRatio( style.label, style.zone );
	// With the outline effect the area keeps its color on hover, so the hover label color is not used.
	const hover =
		style.hoverEffect === 'outline'
			? 21
			: contrastRatio( style.labelHover, style.zoneHover );
	return { ok: label >= AA && hover >= AA, label, hover };
}
