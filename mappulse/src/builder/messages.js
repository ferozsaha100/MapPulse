/**
 * Words for the warnings the map builder gives. The geometry library reports codes only, so it
 * stays free of WordPress and its text can be translated here.
 */
import { __ } from '@wordpress/i18n';

export function warningText( code ) {
	switch ( code ) {
		case 'no_shape':
			return __(
				'This area has no outline that can be drawn.',
				'mappulse'
			);
		case 'mostly_cut':
			return __(
				'Other areas on this map cover almost all of this one. Move it up the list, or make it a backdrop.',
				'mappulse'
			);
		case 'in_pieces':
			return __(
				'Other areas split this one into separate pieces.',
				'mappulse'
			);
		case 'tiny':
			return __(
				'This area is very small on this map and may be hard to click.',
				'mappulse'
			);
		case 'under_water':
			return __(
				'Mapped water covers almost all of this area, so it may be hidden under it.',
				'mappulse'
			);
		case 'too_small':
			return __(
				'This area is too small to draw on a map of this extent, so it is left out.',
				'mappulse'
			);
		default:
			return __( 'This area may not be drawn as expected.', 'mappulse' );
	}
}
