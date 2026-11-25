/**
 * The hover card of one area, as the builder holds it and as it is stored. Pure, so it is tested in Node.
 */
import { validLink } from './auto-match';

const MAX = { tagline: 120, label: 24, value: 24, rows: 2 };

const text = ( value, max ) =>
	String( value === null || value === undefined ? '' : value )
		.replace( /\s+/g, ' ' )
		.trim()
		.slice( 0, max );

// A card with nothing in it, with both fact rows and both button rows ready to type in.
export const emptyCard = () => ( {
	image: 0,
	imageUrl: '',
	tagline: '',
	stats: [
		{ label: '', value: '' },
		{ label: '', value: '' },
	],
	buttons: [
		{ label: '', url: '', post: 0, title: '' },
		{ label: '', url: '', post: 0, title: '' },
	],
} );

// A stored card as the builder edits it: always two fact rows and two button rows.
export function editableCard( card ) {
	const blank = emptyCard();
	if ( ! card ) {
		return blank;
	}
	const rows = ( list, fallback ) =>
		fallback.map( ( row, i ) => ( {
			...row,
			...( Array.isArray( list ) && list[ i ] ? list[ i ] : {} ),
		} ) );
	return {
		image: Number( card.image ) > 0 ? Number( card.image ) : 0,
		imageUrl: card.imageUrl || '',
		tagline: card.tagline || '',
		stats: rows( card.stats, blank.stats ),
		buttons: rows( card.buttons, blank.buttons ),
	};
}

/**
 * The card as it is stored: trimmed, capped, without rows that cannot be shown. Null when nothing is left.
 *
 * @param card The builder's card.
 * @return The stored card, or null.
 */
export function cleanCard( card ) {
	if ( ! card || typeof card !== 'object' ) {
		return null;
	}
	const image =
		Number( card.image ) > 0 ? Math.floor( Number( card.image ) ) : 0;
	const tagline = text( card.tagline, MAX.tagline );
	const stats = ( Array.isArray( card.stats ) ? card.stats : [] )
		.map( ( row ) => ( {
			label: text( row && row.label, MAX.label ),
			value: text( row && row.value, MAX.value ),
		} ) )
		// A fact needs its value. A name alone is a draft, as a button without an address is.
		.filter( ( row ) => row.value )
		.slice( 0, MAX.rows );
	const buttons = ( Array.isArray( card.buttons ) ? card.buttons : [] )
		.map( ( row ) => ( {
			label: text( row && row.label, MAX.label ),
			url: validLink( row && row.url ),
			post:
				Number( row && row.post ) > 0
					? Math.floor( Number( row.post ) )
					: 0,
		} ) )
		.filter( ( row ) => row.label && row.url )
		.slice( 0, MAX.rows );
	if ( ! image && ! tagline && ! stats.length && ! buttons.length ) {
		return null;
	}
	return { image, tagline, stats, buttons };
}

export const hasCard = ( zone ) => cleanCard( zone && zone.card ) !== null;

/**
 * Starts a card on every area that has none, with the fact names and button names of one area's
 * card, so the same card is filled in quickly for each area.
 *
 * @param zones The builder's zones.
 * @param from  The id of the area whose card is the model.
 * @return The areas to change: id and the new card.
 */
export function copyLayout( zones, from ) {
	const model = zones.find( ( zone ) => zone.id === from );
	if ( ! model || ! model.card ) {
		return [];
	}
	const source = editableCard( model.card );
	return zones
		.filter( ( zone ) => zone.id !== from && ! hasCard( zone ) )
		.map( ( zone ) => ( {
			id: zone.id,
			card: {
				...emptyCard(),
				stats: source.stats.map( ( row ) => ( {
					label: row.label,
					value: '',
				} ) ),
				buttons: source.buttons.map( ( row ) => ( {
					label: row.label,
					url: '',
					post: 0,
					title: '',
				} ) ),
			},
		} ) );
}
