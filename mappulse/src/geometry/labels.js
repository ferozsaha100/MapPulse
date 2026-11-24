/**
 * Label placement.
 */
import polylabel from 'polylabel';
import { polyArea } from './shapes';

export const norm = ( s ) =>
	String( s )
		.toLowerCase()
		.normalize( 'NFKD' )
		.replace( /[^a-z0-9]+/g, ' ' )
		.trim();

export const slug = ( s ) => norm( s ).replace( / /g, '-' );

// A point well inside the largest part of a shape, with the room around it.
export function labelPoint( mp ) {
	let best = null;
	let bestArea = 0;
	for ( const poly of mp ) {
		const area = polyArea( poly );
		if ( area > bestArea ) {
			bestArea = area;
			best = poly;
		}
	}
	if ( ! best ) {
		return null;
	}
	const p = polylabel( best, 1.0 );
	return { x: p[ 0 ], y: p[ 1 ], room: p.distance || 0, area: bestArea };
}

// Upper-case label text, broken over two lines when the name is long.
export function labelLines( name ) {
	const text = String( name ).trim();
	const words = text.toUpperCase().replace( /-/g, '- ' ).split( /\s+/ );
	if ( words.length < 2 || text.length <= 9 ) {
		return [ text.toUpperCase() ];
	}
	const lines = [];
	let current = '';
	const target = Math.ceil( text.length / 2 );
	for ( const word of words ) {
		let next = word;
		if ( current ) {
			next = current.endsWith( '-' )
				? current + word
				: `${ current } ${ word }`;
		}
		if ( current && next.length > target + 2 && lines.length === 0 ) {
			lines.push( current );
			current = word;
		} else {
			current = next;
		}
	}
	lines.push( current );
	return lines;
}

/**
 * Pushes overlapping label boxes apart and keeps them in the frame. Items are moved in place.
 *
 * @param items              Boxes { x, y, w, h } with x, y at the centre.
 * @param options
 * @param options.width      Frame width.
 * @param options.height     Frame height.
 * @param options.pad        Space kept between two boxes.
 * @param options.iterations Upper limit of passes.
 */
export function separateLabels(
	items,
	{ width, height, pad = 3, iterations = 120 } = {}
) {
	const clamp = ( v, lo, hi ) =>
		Math.min( Math.max( v, lo ), Math.max( lo, hi ) );
	for ( let n = 0; n < iterations; n++ ) {
		let moved = false;
		for ( let i = 0; i < items.length; i++ ) {
			for ( let j = i + 1; j < items.length; j++ ) {
				const a = items[ i ];
				const b = items[ j ];
				const ox = ( a.w + b.w ) / 2 + pad - Math.abs( a.x - b.x );
				const oy = ( a.h + b.h ) / 2 + pad - Math.abs( a.y - b.y );
				if ( ox <= 0 || oy <= 0 ) {
					continue;
				}
				moved = true;
				if ( oy <= ox ) {
					const d = ( oy / 2 ) * ( a.y <= b.y ? -1 : 1 );
					a.y += d;
					b.y -= d;
				} else {
					const d = ( ox / 2 ) * ( a.x <= b.x ? -1 : 1 );
					a.x += d;
					b.x -= d;
				}
			}
		}
		for ( const a of items ) {
			a.x = clamp( a.x, a.w / 2 + 4, width - a.w / 2 - 4 );
			a.y = clamp( a.y, a.h / 2 + 4, height - a.h / 2 - 4 );
		}
		if ( ! moved ) {
			break;
		}
	}
	return items;
}
