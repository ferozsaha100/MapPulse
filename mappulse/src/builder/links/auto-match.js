/**
 * Pairs areas with the site's pages by name. Pure, so it is tested in Node.
 */

// A full http or https address, tidied, or an empty string.
export function validLink( text ) {
	const value = String( text || '' ).trim();
	if ( ! /^https?:\/\/[^\s/]/i.test( value ) ) {
		return '';
	}
	try {
		const url = new URL( value );
		return url.protocol === 'http:' || url.protocol === 'https:'
			? url.href
			: '';
	} catch ( e ) {
		return '';
	}
}

/**
 * @param zones  The builder's zones: id, name, link.
 * @param answer The /links/match answer: area name => choices { id, title, url, type }.
 * @return apply (clear matches), ask (the person must choose), none (ids of areas with no page).
 */
export function planMatches( zones, answer ) {
	const open = zones.filter( ( zone ) => ! zone.link );
	const choicesOf = ( zone ) =>
		answer && Array.isArray( answer[ zone.name ] )
			? answer[ zone.name ]
			: [];

	// How many open areas each page is the only match for. A page claimed twice is never applied by itself.
	const claims = new Map();
	for ( const zone of open ) {
		const choices = choicesOf( zone );
		if ( choices.length === 1 ) {
			claims.set(
				choices[ 0 ].id,
				( claims.get( choices[ 0 ].id ) || 0 ) + 1
			);
		}
	}

	const plan = { apply: [], ask: [], none: [] };
	for ( const zone of open ) {
		const choices = choicesOf( zone );
		if ( ! choices.length ) {
			plan.none.push( zone.id );
		} else if (
			choices.length === 1 &&
			claims.get( choices[ 0 ].id ) === 1
		) {
			plan.apply.push( {
				id: zone.id,
				link: choices[ 0 ].url,
				linkPost: choices[ 0 ].id,
				linkTitle: choices[ 0 ].title,
			} );
		} else {
			plan.ask.push( { id: zone.id, name: zone.name, choices } );
		}
	}
	return plan;
}
