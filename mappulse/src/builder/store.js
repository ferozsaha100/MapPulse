/**
 * Builder state. A plain reducer with no WordPress or browser dependency, so it is tested in Node.
 */
import { toMP } from '../geometry/shapes';
import { needsCleaning } from '../geometry/clean';
import { slug } from '../geometry/labels';
import { DEFAULT_PRIORITY } from '../geometry/overlaps';

export const initialState = ( settings = {} ) => ( {
	mapId: settings.mapId || 0,
	title: '',
	status: 'draft',
	zones: [],
	priority: DEFAULT_PRIORITY,
	doc: null,
	warnings: [],
	dirty: false,
	saving: false,
	loading: !! settings.mapId,
	error: '',
	// A map made before the builder has no source boundaries, so its areas cannot be edited here.
	legacy: false,
	legacyZones: [],
	maxZones: settings.maxZones || 200,
} );

/**
 * Makes a zone from a search result and the boundary fetched for it.
 *
 * @param result  A search result: source, record, name, type, parent.
 * @param feature The GeoJSON Feature of its boundary.
 * @return A zone without an id; ADD_ZONE gives it one.
 */
export function zoneFromResult( result, feature ) {
	const needsClean = needsCleaning( toMP( feature && feature.geometry ) );
	return {
		name: result.name,
		type: result.type,
		source: result.source,
		record: result.record,
		parent: result.parent || '',
		feature: {
			type: 'Feature',
			properties: {},
			geometry: feature.geometry,
		},
		backdrop: false,
		clean: needsClean,
		needsClean,
	};
}

// The zones of a saved map, rebuilt from the source boundaries stored in its document.
export function zonesFromDocument( doc ) {
	const zones = ( doc && Array.isArray( doc.zones ) ? doc.zones : [] ).filter(
		( z ) => z.geometry
	);
	return zones.map( ( z ) => ( {
		id: z.id,
		name: z.name,
		type: z.type,
		source: ( z.source && z.source.layer ) || '',
		record: ( z.source && z.source.record ) || '',
		parent: '',
		feature: { type: 'Feature', properties: {}, geometry: z.geometry },
		backdrop: !! z.backdrop,
		clean: !! z.clean,
		needsClean: needsCleaning( toMP( z.geometry ) ),
	} ) );
}

// The document to save: the last one built, under the current title.
export const toDocument = ( state ) =>
	state.doc ? { ...state.doc, title: state.title } : null;

function uniqueId( name, zones ) {
	const base = slug( name ) || 'area';
	const taken = new Set( zones.map( ( z ) => z.id ) );
	let id = base;
	for ( let n = 2; taken.has( id ); n++ ) {
		id = `${ base }-${ n }`;
	}
	return id;
}

export function reduce( state, action ) {
	switch ( action.type ) {
		case 'ADD_ZONE': {
			if (
				state.legacy ||
				state.zones.some(
					( z ) =>
						z.source === action.zone.source &&
						z.record === action.zone.record
				)
			) {
				return state;
			}
			if ( state.zones.length >= state.maxZones ) {
				return {
					...state,
					error: `A map can hold at most ${ state.maxZones } areas.`,
				};
			}
			const zone = {
				...action.zone,
				id: uniqueId( action.zone.name, state.zones ),
			};
			return {
				...state,
				zones: [ ...state.zones, zone ],
				dirty: true,
				error: '',
			};
		}
		case 'REMOVE_ZONE':
			return {
				...state,
				zones: state.zones.filter( ( z ) => z.id !== action.id ),
				dirty: true,
			};
		case 'MOVE_ZONE': {
			const { from, to } = action;
			if (
				from === to ||
				from < 0 ||
				to < 0 ||
				from >= state.zones.length ||
				to >= state.zones.length
			) {
				return state;
			}
			const zones = [ ...state.zones ];
			zones.splice( to, 0, zones.splice( from, 1 )[ 0 ] );
			return { ...state, zones, dirty: true };
		}
		case 'SET_ZONE': {
			// The id and the boundary of a zone never change after it is added.
			const { id, feature, source, record, ...patch } = action.patch;
			return {
				...state,
				zones: state.zones.map( ( z ) =>
					z.id === action.id ? { ...z, ...patch } : z
				),
				dirty: true,
			};
		}
		case 'SET_TITLE':
			return { ...state, title: action.title, dirty: true };
		case 'SET_PRIORITY':
			return { ...state, priority: action.priority, dirty: true };
		case 'SET_DOC':
			return {
				...state,
				doc: action.doc,
				warnings: action.warnings || [],
			};
		case 'LOADED': {
			const { map } = action;
			const zones = zonesFromDocument( map.document );
			const legacy =
				zones.length === 0 && ( map.document.zones || [] ).length > 0;
			return {
				...state,
				mapId: map.id,
				title: map.title || map.document.title || '',
				status: map.status,
				zones,
				priority: Array.isArray( map.document.priority )
					? map.document.priority
					: DEFAULT_PRIORITY,
				doc: map.document,
				legacy,
				legacyZones: legacy
					? map.document.zones.map( ( z ) => ( {
							id: z.id,
							name: z.name,
							type: z.type,
					  } ) )
					: [],
				loading: false,
				dirty: false,
				error: '',
			};
		}
		case 'SAVING':
			return { ...state, saving: true, error: '' };
		case 'SAVED':
			return {
				...state,
				saving: false,
				dirty: false,
				mapId: action.mapId,
				status: action.status,
				error: '',
			};
		case 'FAILED':
			return {
				...state,
				saving: false,
				loading: false,
				error: action.message,
			};
		case 'CLEAR_ERROR':
			return { ...state, error: '' };
		default:
			return state;
	}
}
