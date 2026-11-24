// SVG path writers. Output uses only M, L, Z, digits, spaces, dots and minus signs, which is what
// the PHP side accepts, and one decimal place.
const fmt = ( n ) => {
	const s = ( Math.round( n * 10 ) / 10 ).toFixed( 1 );
	if ( s === '-0.0' ) {
		return '0';
	}
	return s.endsWith( '.0' ) ? s.slice( 0, -2 ) : s;
};

const point = ( p ) => `${ fmt( p[ 0 ] ) } ${ fmt( p[ 1 ] ) }`;

export const pathOfMP = ( mp ) =>
	mp
		.map( ( poly ) =>
			poly
				.filter( ( ring ) => ring.length >= 4 )
				.map(
					( ring ) =>
						'M' + ring.slice( 0, -1 ).map( point ).join( 'L' ) + 'Z'
				)
				.join( '' )
		)
		.join( '' );

export const pathOfLines = ( lines ) =>
	lines
		.filter( ( line ) => line.length > 1 )
		.map( ( line ) => 'M' + line.map( point ).join( 'L' ) )
		.join( '' );
