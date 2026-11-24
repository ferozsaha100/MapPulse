/**
 * The REST calls the builder makes. All of them go to mappulse/v1 with the signed-in user's nonce.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const ROOT = '/mappulse/v1';

export const searchAreas = ( q, type, state ) =>
	apiFetch( {
		path: addQueryArgs( `${ ROOT }/boundaries/search`, { q, type, state } ),
	} );

export const fetchArea = ( source, record ) =>
	apiFetch( {
		path: addQueryArgs( `${ ROOT }/boundaries/fetch`, { source, record } ),
	} );

export const fetchBaseLayer = ( bbox, layer ) =>
	apiFetch( {
		path: addQueryArgs( `${ ROOT }/boundaries/base`, {
			bbox: bbox.map( ( n ) => n.toFixed( 5 ) ).join( ',' ),
			layer,
		} ),
	} );

export const loadMap = ( id ) => apiFetch( { path: `${ ROOT }/maps/${ id }` } );

export const saveMap = ( id, document, status ) =>
	apiFetch( {
		path: id ? `${ ROOT }/maps/${ id }` : `${ ROOT }/maps`,
		method: id ? 'PUT' : 'POST',
		data: { document, status },
	} );

export const renderDocument = ( document ) =>
	apiFetch( {
		path: `${ ROOT }/render`,
		method: 'POST',
		data: { document },
	} );

// A message a person can act on, whatever shape the failure has.
export const messageOf = ( error, fallback ) =>
	( error && typeof error.message === 'string' && error.message ) || fallback;
