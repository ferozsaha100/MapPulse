/**
 * Shared-edge simplification. Every layer goes into one topology, so a border two shapes share is
 * simplified once and stays identical in both.
 */
import { topology } from 'topojson-server';
import { feature } from 'topojson-client';
import { presimplify, simplify } from 'topojson-simplify';

const collection = ( items ) => ( {
	type: 'FeatureCollection',
	features: items.map( ( it ) => ( {
		type: 'Feature',
		properties: it.properties || {},
		geometry: it.geometry,
	} ) ),
} );

export const polyFeature = ( mp, properties ) => ( {
	properties,
	geometry: { type: 'MultiPolygon', coordinates: mp },
} );

export const lineFeature = ( lines, properties ) => ( {
	properties,
	geometry: { type: 'MultiLineString', coordinates: lines },
} );

/**
 * @param layers               Layer name => items made with polyFeature or lineFeature.
 * @param options
 * @param options.quantization Grid size topojson snaps coordinates to.
 * @param options.minAreaPx    Points whose triangle is smaller than this are dropped.
 * @return Layer name => GeoJSON features, in input order, plus arcCount.
 */
export function simplifyTogether(
	layers,
	{ quantization = 1e5, minAreaPx = 0.9 } = {}
) {
	const objects = {};
	for ( const [ name, items ] of Object.entries( layers ) ) {
		objects[ name ] = collection( items );
	}
	const simple = simplify(
		presimplify( topology( objects, quantization ) ),
		minAreaPx
	);
	const out = {};
	for ( const name of Object.keys( layers ) ) {
		out[ name ] = feature( simple, simple.objects[ name ] ).features;
	}
	out.arcCount = simple.arcs.length;
	return out;
}
