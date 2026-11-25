/**
 * Turns confirmed boundaries and base-map layers into a map document (schema 1). The PHP renderer
 * draws only from the document's "topology" block; source shapes are stored untouched beside it.
 */
import {
	toMP,
	toLines,
	mapMP,
	mpArea,
	mpBbox,
	boxesTouch,
	clipToFrame,
	subtract,
	unionAll,
	frameRing,
	dropSpecks,
} from './shapes';
import { makeProjection, fitFrame } from './project';
import { resolveOverlaps, DEFAULT_PRIORITY } from './overlaps';
import { simplifyTogether, polyFeature, lineFeature } from './simplify';
import { labelPoint, labelLines, separateLabels } from './labels';
import { pathOfMP, pathOfLines } from './paths';
import { cleanOutline } from './clean';
import { VERSION } from './index';

export const BASE_LAYERS = [
	'land',
	'cities',
	'areas',
	'water',
	'roadsMajor',
	'roadsMinor',
];

const LABEL_SIZE = 11.5;
const LABEL_SIZE_MIN = 9.5;
// Water smaller than this many square frame units is not drawn; larger bodies also trim the zones.
const WATER_MIN = 30;
const WATER_CLIP_MIN = 60;
const TINY_ZONE = 60;
// The base-map route takes boxes up to 1.5 degrees a side. Staying just under avoids a rounding refusal.
const MAX_BASE_SIDE = 1.49;

const r1 = ( n ) => Math.round( n * 10 ) / 10;
const features = ( layer ) =>
	layer && Array.isArray( layer.features ) ? layer.features : [];
const nameOf = ( f ) =>
	String(
		( f.properties &&
			( f.properties.BASENAME ||
				f.properties.NAME ||
				f.properties.name ) ) ||
			''
	);

// The part of one segment that lies inside a box (Liang-Barsky), or null.
function clipSegment( a, b, [ x0, y0, x1, y1 ] ) {
	const dx = b[ 0 ] - a[ 0 ];
	const dy = b[ 1 ] - a[ 1 ];
	let t0 = 0;
	let t1 = 1;
	const edges = [
		[ -dx, a[ 0 ] - x0 ],
		[ dx, x1 - a[ 0 ] ],
		[ -dy, a[ 1 ] - y0 ],
		[ dy, y1 - a[ 1 ] ],
	];
	for ( const [ p, q ] of edges ) {
		if ( p === 0 ) {
			if ( q < 0 ) {
				return null;
			}
			continue;
		}
		const t = q / p;
		if ( p < 0 ) {
			if ( t > t1 ) {
				return null;
			}
			t0 = Math.max( t0, t );
		} else {
			if ( t < t0 ) {
				return null;
			}
			t1 = Math.min( t1, t );
		}
	}
	return [
		[ a[ 0 ] + t0 * dx, a[ 1 ] + t0 * dy ],
		[ a[ 0 ] + t1 * dx, a[ 1 ] + t1 * dy ],
	];
}

/**
 * Cuts a line to a box. A road that only crosses the box, with no point of its own inside, is kept.
 *
 * @param line Points [x, y].
 * @param box  x0, y0, x1, y1.
 * @return The pieces of the line inside the box.
 */
export function clipLine( line, box ) {
	const out = [];
	let current = [];
	for ( let i = 1; i < line.length; i++ ) {
		const piece = clipSegment( line[ i - 1 ], line[ i ], box );
		if ( ! piece ) {
			if ( current.length > 1 ) {
				out.push( current );
			}
			current = [];
			continue;
		}
		const last = current[ current.length - 1 ];
		if (
			! last ||
			last[ 0 ] !== piece[ 0 ][ 0 ] ||
			last[ 1 ] !== piece[ 0 ][ 1 ]
		) {
			if ( current.length > 1 ) {
				out.push( current );
			}
			current = [ piece[ 0 ] ];
		}
		current.push( piece[ 1 ] );
	}
	if ( current.length > 1 ) {
		out.push( current );
	}
	return out;
}

const validPriority = ( priority ) =>
	Array.isArray( priority ) &&
	priority.length === DEFAULT_PRIORITY.length &&
	DEFAULT_PRIORITY.every( ( type ) => priority.includes( type ) )
		? priority
		: DEFAULT_PRIORITY;

function degreesBox( zones ) {
	const box = [ Infinity, Infinity, -Infinity, -Infinity ];
	for ( const zone of zones ) {
		const b = mpBbox( toMP( zone.feature && zone.feature.geometry ) );
		box[ 0 ] = Math.min( box[ 0 ], b[ 0 ] );
		box[ 1 ] = Math.min( box[ 1 ], b[ 1 ] );
		box[ 2 ] = Math.max( box[ 2 ], b[ 2 ] );
		box[ 3 ] = Math.max( box[ 3 ], b[ 3 ] );
	}
	return Number.isFinite( box[ 0 ] ) ? box : null;
}

/**
 * The box to ask the base map for: the zones' box grown by 15% a side, at most 1.5 degrees a side.
 *
 * @param zones Items with a GeoJSON feature.
 * @return West, south, east, north, or null without shapes.
 */
export function bboxForBase( zones ) {
	const box = degreesBox( zones );
	if ( ! box ) {
		return null;
	}
	const grow = ( lo, hi ) => {
		const half = Math.min( ( ( hi - lo ) * 1.3 ) / 2, MAX_BASE_SIDE / 2 );
		const mid = ( lo + hi ) / 2;
		return [ mid - half, mid + half ];
	};
	const [ west, east ] = grow( box[ 0 ], box[ 2 ] );
	const [ south, north ] = grow( box[ 1 ], box[ 3 ] );
	return [ west, south, east, north ];
}

// True when the zones lie too far apart for one base-map request, so land, water and roads cover only the middle.
export function baseIsPartial( zones ) {
	const box = degreesBox( zones );
	return (
		!! box &&
		( ( box[ 2 ] - box[ 0 ] ) * 1.3 > MAX_BASE_SIDE ||
			( box[ 3 ] - box[ 1 ] ) * 1.3 > MAX_BASE_SIDE )
	);
}

/**
 * @param input
 * @param input.title    Map title.
 * @param input.zones    Items { id, name, type, source, record, feature, backdrop, clean }.
 * @param input.base     Base layers as the /boundaries/base route returns them. Optional.
 * @param input.priority Area types, most important first. Optional.
 * @param input.style    Style overrides. Optional.
 * @return doc (null without drawable zones) and warnings { zone, code, message }.
 */
export function buildDocument( {
	title = '',
	zones = [],
	base = {},
	priority,
	style = {},
} = {} ) {
	const warnings = [];

	// 1. Source shapes, cleaned where asked. The stored geometry stays the source one.
	const work = [];
	for ( const zone of zones ) {
		let mp = toMP( zone.feature && zone.feature.geometry );
		if ( zone.clean && mp.length ) {
			mp = cleanOutline( mp ).mp;
		}
		if ( ! mp.length ) {
			warnings.push( {
				zone: zone.id,
				code: 'no_shape',
			} );
			continue;
		}
		work.push( { zone, degrees: mp } );
	}
	if ( ! work.length ) {
		return { doc: null, warnings };
	}

	const order = validPriority( priority );

	// 2. One projection and one frame, fitted to the zones.
	const all = [ Infinity, Infinity, -Infinity, -Infinity ];
	for ( const item of work ) {
		const b = mpBbox( item.degrees );
		all[ 1 ] = Math.min( all[ 1 ], b[ 1 ] );
		all[ 3 ] = Math.max( all[ 3 ], b[ 3 ] );
	}
	const project = makeProjection( ( all[ 1 ] + all[ 3 ] ) / 2 );
	const box = [ Infinity, Infinity, -Infinity, -Infinity ];
	for ( const item of work ) {
		item.flat = mapMP( item.degrees, project );
		const b = mpBbox( item.flat );
		box[ 0 ] = Math.min( box[ 0 ], b[ 0 ] );
		box[ 1 ] = Math.min( box[ 1 ], b[ 1 ] );
		box[ 2 ] = Math.max( box[ 2 ], b[ 2 ] );
		box[ 3 ] = Math.max( box[ 3 ], b[ 3 ] );
	}
	const frame = fitFrame( box );
	const W = frame.width;
	const H = frame.height;
	const toFrame = ( geometry ) =>
		clipToFrame(
			mapMP( mapMP( toMP( geometry ), project ), frame.toPx ),
			W,
			H
		);

	// 3. Zones into the frame, then overlaps cut by priority.
	for ( const item of work ) {
		item.type = item.zone.type;
		item.backdrop = !! item.zone.backdrop;
		// A cleaned outline is a deliberate solid shape. Slivers of a neighbour of the same kind that lie
		// inside it (streets a city boundary runs along, say) must not cut it back into pieces.
		item.first = !! item.zone.clean;
		item.mp = mapMP( item.flat, frame.toPx );
	}
	resolveOverlaps( work, order );

	// 4. Water. Larger bodies also trim the zones, which gives them a real coastline.
	const water = [];
	for ( const f of features( base.water ) ) {
		const mp = toFrame( f.geometry );
		const area = mpArea( mp );
		if ( area >= WATER_MIN ) {
			water.push( { mp, area, name: nameOf( f ), box: mpBbox( mp ) } );
		}
	}
	const bigWater = water.filter( ( w ) => w.area >= WATER_CLIP_MIN );
	for ( const item of work ) {
		const b = mpBbox( item.mp );
		const before = mpArea( item.mp );
		const trimmed = subtract(
			item.mp,
			bigWater
				.filter( ( w ) => boxesTouch( b, w.box ) )
				.map( ( w ) => w.mp )
		);
		// A zone that lies fully in mapped water (a pier, a sandbar) is kept as it was rather than erased.
		if ( mpArea( trimmed ) > before * 0.02 ) {
			item.mp = trimmed;
		} else if ( before > 0 ) {
			item.underWater = true;
		}
	}

	// 5. Surrounding cities and areas, land, roads.
	const context = ( layer, minArea ) =>
		features( layer )
			.map( ( f ) => ( { mp: toFrame( f.geometry ) } ) )
			.filter( ( c ) => mpArea( c.mp ) >= minArea );
	const cities = context( base.cities, 20 );
	const areas = context( base.areas, 6 );
	let land = context( base.land, 1 );
	if ( ! land.length ) {
		land = [ { mp: [ frameRing( W, H ) ], whole: true } ];
	}
	const roadLines = ( layer ) =>
		features( layer ).flatMap( ( f ) =>
			toLines( f.geometry ).flatMap( ( line ) =>
				clipLine(
					line.map( ( pt ) => frame.toPx( project( pt ) ) ),
					[ -40, -40, W + 40, H + 40 ]
				)
			)
		);

	// 6. Everything is simplified in one topology so shared borders stay shared.
	const index = ( items ) =>
		items.map( ( it, i ) => polyFeature( it.mp, { i } ) );
	const simple = simplifyTogether( {
		zones: index( work ),
		cities: index( cities ),
		areas: index( areas ),
		land: index( land.filter( ( l ) => ! l.whole ) ),
		water: index( water ),
		roadsMajor: [ lineFeature( roadLines( base.roadsMajor ), {} ) ],
		roadsMinor: [ lineFeature( roadLines( base.roadsMinor ), {} ) ],
	} );
	const back = ( list, items ) =>
		list.forEach(
			( f ) =>
				( items[ f.properties.i ].mp = dropSpecks(
					toMP( f.geometry )
				) )
		);
	back( simple.zones, work );
	back( simple.cities, cities );
	back( simple.areas, areas );
	back(
		simple.land,
		land.filter( ( l ) => ! l.whole )
	);
	back( simple.water, water );
	const roadsMajor = toLines(
		simple.roadsMajor[ 0 ] && simple.roadsMajor[ 0 ].geometry
	);
	const roadsMinor = toLines(
		simple.roadsMinor[ 0 ] && simple.roadsMinor[ 0 ].geometry
	);

	// 7. Warnings, then labels. A zone narrower than its name gets smaller type, down to a floor that still reads.
	const labelSize =
		Number( style.labelSize ) > 0 ? Number( style.labelSize ) : LABEL_SIZE;
	for ( const item of work ) {
		const { zone } = item;
		const area = mpArea( item.mp );
		item.point = labelPoint( item.mp );
		if ( item.cutShare > 0.8 ) {
			item.hidden = ! item.point || area < 1;
			warnings.push( { zone: zone.id, code: 'mostly_cut' } );
		} else if ( ! item.point || area < 1 ) {
			// Nothing cut it away: it is simply smaller than the map can show.
			item.hidden = true;
			warnings.push( { zone: zone.id, code: 'too_small' } );
		} else if ( item.underWater ) {
			warnings.push( { zone: zone.id, code: 'under_water' } );
		} else if ( item.mp.length > 1 && item.cutShare > 0.02 ) {
			warnings.push( {
				zone: zone.id,
				code: 'in_pieces',
			} );
		} else if ( area < TINY_ZONE ) {
			warnings.push( {
				zone: zone.id,
				code: 'tiny',
			} );
		}
		if ( item.hidden ) {
			continue;
		}
		item.lines = labelLines( zone.name || zone.id );
		const chars = Math.max( ...item.lines.map( ( line ) => line.length ) );
		const b = mpBbox( item.mp );
		const zoneWidth = b[ 2 ] - b[ 0 ];
		item.fontSize = r1(
			Math.min(
				labelSize,
				Math.max(
					LABEL_SIZE_MIN,
					( labelSize * zoneWidth * 1.2 ) /
						( chars * labelSize * 0.72 )
				)
			)
		);
		item.labelBox = {
			x: item.point.x,
			y: item.point.y,
			w: chars * item.fontSize * 0.8 + 6,
			h: item.lines.length * item.fontSize * 1.18 + 3,
		};
	}
	const drawn = work.filter( ( item ) => ! item.hidden );
	separateLabels(
		drawn.map( ( item ) => item.labelBox ),
		{ width: W, height: H }
	);
	for ( const item of drawn ) {
		const { labelBox: lb, point } = item;
		const leader =
			Math.abs( point.x - lb.x ) > lb.w / 2 + 3 ||
			Math.abs( point.y - lb.y ) > lb.h / 2 + 3;
		item.label = {
			x: r1( lb.x ),
			y: r1( lb.y ),
			size: item.fontSize,
			lines: item.lines,
			anchor: leader ? { x: r1( point.x ), y: r1( point.y ) } : null,
		};
	}

	// 8. Water names, for bodies with room for one.
	const named = new Map();
	for ( const w of water ) {
		if ( ! w.name || w.area < W * H * 0.012 ) {
			continue;
		}
		const lp = labelPoint( w.mp );
		if ( ! lp || lp.room < 34 ) {
			continue;
		}
		const prev = named.get( w.name );
		if ( prev && prev.area >= lp.area ) {
			continue;
		}
		const textWidth = w.name.length * 10.4;
		const wb = mpBbox( w.mp );
		const vertical =
			wb[ 3 ] - wb[ 1 ] > ( wb[ 2 ] - wb[ 0 ] ) * 1.8 &&
			wb[ 3 ] - wb[ 1 ] > textWidth + 24;
		const half = textWidth / 2 + 10;
		named.set( w.name, {
			text: w.name.toUpperCase(),
			x: r1(
				vertical ? lp.x : Math.min( Math.max( lp.x, half ), W - half )
			),
			y: r1(
				vertical ? Math.min( Math.max( lp.y, half ), H - half ) : lp.y
			),
			vertical,
			area: lp.area,
		} );
	}

	// 9. The document. Backdrops come first so they are drawn underneath.
	const join = ( items ) =>
		items.map( ( it ) => pathOfMP( it.mp ) ).join( '' );
	const listed = [
		...work.filter( ( item ) => item.backdrop ),
		...work.filter( ( item ) => ! item.backdrop ),
	];
	// Zones are listed in the order they were given, not in cutting order.
	listed.sort(
		( a, b ) =>
			Number( b.backdrop ) - Number( a.backdrop ) ||
			zones.indexOf( a.zone ) - zones.indexOf( b.zone )
	);
	const doc = {
		schema: 1,
		title: String( title ),
		// The scale lets a hand-set label offset keep its distance on the ground when the map is redrawn.
		frame: {
			width: W,
			height: H,
			scale: Math.round( frame.scale * 1000 ) / 1000,
		},
		style: { ...style },
		priority: order,
		zones: listed.map( ( { zone, backdrop, hidden } ) => ( {
			id: zone.id,
			name: zone.name || zone.id,
			type: zone.type,
			source: {
				layer: String( zone.source || '' ),
				record: String( zone.record || '' ),
			},
			link: zone.link || '',
			text: zone.text || '',
			geometry: zone.feature.geometry,
			built: true,
			backdrop,
			clean: !! zone.clean,
			...( hidden ? { hidden: true } : {} ),
		} ) ),
		topology: {
			layers: {
				land: join( land ),
				cities: join( cities ),
				areas: join( areas ),
				water: pathOfMP( unionAll( water.map( ( w ) => w.mp ) ) ),
				roadsMinor: pathOfLines( roadsMinor ),
				roadsMajor: pathOfLines( roadsMajor ),
			},
			zones: Object.fromEntries(
				listed
					.filter( ( item ) => ! item.hidden )
					.map( ( item ) => [
						item.zone.id,
						{ d: pathOfMP( item.mp ), label: item.label },
					] )
			),
			waterLabels: [ ...named.values() ].map(
				( { text, x, y, vertical } ) => ( { text, x, y, vertical } )
			),
		},
		meta: { generator: `mappulse-builder/${ VERSION }` },
	};
	return { doc, warnings };
}
