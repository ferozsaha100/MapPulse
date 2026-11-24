/**
 * Puts everything that does not change the shapes onto a built document: the style, and each area's
 * link, description and hand-set label. Kept apart from the geometry so a color or link change is
 * redrawn without rebuilding the map.
 */

/**
 * @param doc         A document from buildDocument, or null.
 * @param input
 * @param input.style The style block.
 * @param input.zones The builder's zones, with link, linkPost, text and label.
 * @return A new document; the one given is left as it was.
 */
export function decorate( doc, { style = {}, zones = [] } ) {
	if ( ! doc ) {
		return null;
	}
	const byId = new Map( zones.map( ( zone ) => [ zone.id, zone ] ) );
	return {
		...doc,
		style: { ...style },
		zones: doc.zones.map( ( stored ) => {
			const zone = byId.get( stored.id );
			if ( ! zone ) {
				return stored;
			}
			const { linkPost, label, ...rest } = stored;
			const next = {
				...rest,
				link: zone.link || '',
				text: zone.text || '',
			};
			if ( zone.linkPost > 0 && next.link ) {
				next.linkPost = zone.linkPost;
			}
			if (
				zone.label &&
				( zone.label.text ||
					zone.label.dx ||
					zone.label.dy ||
					zone.label.hidden )
			) {
				next.label = {
					text: zone.label.text || '',
					dx: zone.label.dx || 0,
					dy: zone.label.dy || 0,
					hidden: !! zone.label.hidden,
				};
			}
			return next;
		} ),
	};
}
