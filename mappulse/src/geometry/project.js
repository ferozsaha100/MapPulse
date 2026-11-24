/**
 * Projection and frame fitting.
 */

// Equirectangular projection scaled by the cosine of one latitude. Accurate enough for one metro area.
export function makeProjection( lat0 ) {
	const k = Math.max( 0.01, Math.cos( ( lat0 * Math.PI ) / 180 ) );
	const project = ( [ lon, lat ] ) => [ lon * k, -lat ];
	project.invert = ( [ x, y ] ) => [ x / k, -y ];
	return project;
}

// Fits a box of projected units into a frame 'width' units wide, with padding and aspect limits.
export function fitFrame(
	box,
	{ width = 1000, padding = 0.07, minAspect = 1, maxAspect = 1.6 } = {}
) {
	let [ x0, y0, x1, y1 ] = box;
	const pad = padding * Math.max( x1 - x0, y1 - y0 );
	x0 -= pad;
	y0 -= pad;
	x1 += pad;
	y1 += pad;
	let w = Math.max( x1 - x0, 1e-9 );
	let h = Math.max( y1 - y0, 1e-9 );
	if ( w / h < minAspect ) {
		const add = ( minAspect * h - w ) / 2;
		x0 -= add;
		x1 += add;
		w = x1 - x0;
	}
	if ( w / h > maxAspect ) {
		const add = ( w / maxAspect - h ) / 2;
		y0 -= add;
		y1 += add;
		h = y1 - y0;
	}
	const scale = width / w;
	return {
		width,
		height: Math.round( h * scale ),
		scale,
		box: [ x0, y0, x1, y1 ],
		toPx: ( [ x, y ] ) => [ ( x - x0 ) * scale, ( y - y0 ) * scale ],
	};
}
