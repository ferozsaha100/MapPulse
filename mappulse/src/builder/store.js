/**
 * Builder state. A plain reducer with no WordPress or browser dependency, so it is tested in Node.
 */
import { toMP } from '../geometry/shapes';
import { cleanAdvice, needsCleaning } from '../geometry/clean';
import { slug } from '../geometry/labels';
import { DEFAULT_PRIORITY } from '../geometry/overlaps';
import { applyPreset } from './style/presets';

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
	// Set when a saved map could not be opened. Nothing can be edited or saved then, so it is not overwritten.
	blocked: false,
	// Counts changes, so a save knows whether anything changed while it was on its way.
	revision: 0,
	savingRevision: 0,
	maxZones: settings.maxZones || 200,
	// The builder's steps: areas, style, links.
	step: 'areas',
	styleDefaults: settings.styleDefaults || {},
	style: { ...( settings.styleDefaults || {} ) },
} );

/**
 * Makes a zone from a search result and the boundary fetched for it.
 *
 * @param result  A search result: source, record, name, type, parent.
 * @param feature The GeoJSON Feature of its boundary.
 * @return A zone without an id; ADD_ZONE gives it one.
 */
export function zoneFromResult( result, feature ) {
	const advice = cleanAdvice( toMP( feature && feature.geometry ) );
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
		link: '',
		linkPost: 0,
		text: '',
		label: null,
		backdrop: false,
		clean: advice.recommended,
		needsClean: advice.available,
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
		link: z.link || '',
		linkPost: z.linkPost || 0,
		text: z.text || '',
		label: z.label || null,
		feature: { type: 'Feature', properties: {}, geometry: z.geometry },
		backdrop: !! z.backdrop,
		clean: !! z.clean,
		needsClean: needsCleaning( toMP( z.geometry ) ),
	} ) );
}

// The document to save: the last one built, under the current title.
export const toDocument = ( state ) =>
	state.doc ? { ...state.doc, title: state.title } : null;

/**
 * What the save buttons may do.
 *
 * @param state   Builder state.
 * @param preview The rebuilder's view: busy, and current (the document matches the area list).
 * @return canSave, showDraft and primary ('publish' or 'update').
 */
export function saveActions( state, preview ) {
	const published = !! state.mapId && state.status === 'publish';
	// An imported map has no areas to rebuild; it can still be renamed.
	const drawn =
		state.legacy ||
		( state.zones.length > 0 && preview.current && ! preview.busy );
	return {
		canSave: !! state.doc && ! state.saving && ! state.blocked && drawn,
		// Saving a published map as a draft would take it off every page it is placed on.
		showDraft: ! published,
		primary: published ? 'update' : 'publish',
	};
}

function uniqueId( name, zones ) {
	const base = slug( name ) || 'area';
	const taken = new Set( zones.map( ( z ) => z.id ) );
	let id = base;
	for ( let n = 2; taken.has( id ); n++ ) {
		id = `${ base }-${ n }`;
	}
	return id;
}

export const STEPS = [ 'areas', 'style', 'links' ];

export function reduce( state, action ) {
	const next = step( state, action );
	// Every action that marks the map as changed also counts as a change.
	return next !== state && next.dirty && CHANGES.includes( action.type )
		? { ...next, revision: state.revision + 1 }
		: next;
}

const CHANGES = [
	'ADD_ZONE',
	'REMOVE_ZONE',
	'MOVE_ZONE',
	'SET_ZONE',
	'SET_TITLE',
	'SET_PRIORITY',
	'SET_STYLE',
	'APPLY_PRESET',
];

function step( state, action ) {
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
		case 'REMOVE_ZONE': {
			const zones = state.zones.filter( ( z ) => z.id !== action.id );
			// The other steps need an area to work on.
			return {
				...state,
				zones,
				dirty: true,
				step: zones.length ? state.step : 'areas',
			};
		}
		case 'SET_STEP':
			if (
				! STEPS.includes( action.step ) ||
				( action.step !== 'areas' && ! state.zones.length )
			) {
				return state;
			}
			return { ...state, step: action.step };
		case 'SET_STYLE':
			return {
				...state,
				style: { ...state.style, ...action.patch },
				dirty: true,
			};
		case 'APPLY_PRESET':
			return {
				...state,
				style: applyPreset(
					action.name,
					state.style,
					action.brand,
					state.styleDefaults,
					{ keepHandSet: !! action.keep }
				),
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
				style: {
					...state.styleDefaults,
					...( map.document.style || {} ),
				},
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
			return {
				...state,
				saving: true,
				error: '',
				savingRevision: state.revision,
			};
		case 'SAVED':
			return {
				...state,
				saving: false,
				// What was sent is saved. Anything changed since is not.
				dirty: state.revision !== state.savingRevision,
				mapId: action.mapId,
				status: action.status,
				error: '',
			};
		case 'FAILED':
			return {
				...state,
				saving: false,
				loading: false,
				blocked: state.blocked || state.loading,
				error: action.message,
			};
		case 'CLEAR_ERROR':
			return { ...state, error: '' };
		default:
			return state;
	}
}
