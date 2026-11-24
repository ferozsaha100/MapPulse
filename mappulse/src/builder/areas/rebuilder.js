/**
 * Keeps the drawing in step with the area list: fetches the base map when the areas need a wider
 * one, builds the document in the browser, and asks the server to draw it.
 *
 * It has no React in it. The hook in use-document.js wires it to the builder; the tests drive it directly.
 */
import { __ } from '@wordpress/i18n';
import {
	buildDocument,
	bboxForBase,
	baseIsPartial,
	BASE_LAYERS,
} from '../../geometry/build-document';
import { planRebuild, signature } from './plan';
import { messageOf } from '../message';

// The server draws from the document's paths. The source boundaries are large and it does not need them.
const forPreview = ( doc ) => ( {
	...doc,
	zones: doc.zones.map( ( { geometry, ...zone } ) => zone ),
} );

/**
 * @param deps
 * @param deps.fetchBaseLayer ( bbox, layer ) => Promise of { [ layer ]: FeatureCollection }.
 * @param deps.renderDocument ( doc ) => Promise of { html }.
 * @param deps.build          buildDocument, replaceable in tests.
 * @param deps.onDoc          Called with ( doc, warnings ) after each build.
 * @param deps.onChange       Called with the view state whenever it changes.
 * @param deps.delay          Milliseconds to wait after a change before drawing.
 */
export function createRebuilder( {
	fetchBaseLayer,
	renderDocument,
	build = buildDocument,
	onDoc = () => {},
	onChange = () => {},
	delay = 300,
} ) {
	let view = { busy: false, html: '', error: '', baseNote: '' };
	// What the last drawing was made from, and the base map held for it.
	let last = {
		signature: signature( { zones: [], priority: [] } ),
		fetched: null,
		base: {},
	};
	// The signature of the area list the current document was built from. Saving is allowed only while it matches.
	let built = last.signature;
	let run = 0;
	let timer = null;

	const set = ( patch ) => {
		view = { ...view, ...patch };
		onChange( view );
	};

	async function draw( id, input, next, plan ) {
		const stale = () => id !== run;
		let { base, fetched } = last;
		let baseNote = view.baseNote;

		if ( plan.refetchBase ) {
			base = {};
			fetched = next.wanted;
			let failed = 0;
			// One layer at a time: each answer is cached on the server and small enough to pass through.
			for ( const layer of BASE_LAYERS ) {
				try {
					const answer = await fetchBaseLayer( next.wanted, layer );
					if ( answer && answer[ layer ] ) {
						base[ layer ] = answer[ layer ];
					}
				} catch ( e ) {
					failed++;
				}
				if ( stale() ) {
					return;
				}
			}
			baseNote = '';
			if ( failed ) {
				baseNote = __(
					'Part of the base map (land, water or roads) could not be loaded, so the map is drawn without it. Change an area to try again.',
					'mappulse'
				);
				// A base map with gaps is not kept, so the next change asks for it again.
				fetched = null;
			} else if ( baseIsPartial( input.zones ) ) {
				baseNote = __(
					'These areas are far apart, so land, water and roads are drawn for the middle of the map only. Maps of one metro area work best.',
					'mappulse'
				);
			}
		}

		let result;
		try {
			result = build( {
				title: input.title,
				zones: input.zones,
				base,
				priority: input.priority,
			} );
		} catch ( e ) {
			// The document on hand no longer matches the list, so it must not be saved as if it did.
			set( {
				busy: false,
				baseNote,
				error: __(
					'These areas could not be combined into a map. Remove the area added last and try again.',
					'mappulse'
				),
			} );
			return;
		}

		last = { signature: next.signature, fetched, base };
		built = next.signature;
		onDoc( result.doc, result.warnings );
		if ( ! result.doc ) {
			set( { busy: false, html: '', error: '', baseNote: '' } );
			return;
		}
		try {
			const answer = await renderDocument( forPreview( result.doc ) );
			if ( stale() ) {
				return;
			}
			set( { busy: false, html: answer.html, error: '', baseNote } );
		} catch ( e ) {
			if ( stale() ) {
				return;
			}
			// The last good drawing stays on screen.
			set( {
				busy: false,
				baseNote,
				error: messageOf(
					e,
					__( 'The map could not be drawn.', 'mappulse' )
				),
			} );
		}
	}

	return {
		get: () => view,

		// True when the document on hand was built from exactly this area list.
		isCurrent: ( { zones, priority } ) =>
			built === signature( { zones, priority } ),

		// Call after every change to the area list.
		update( input ) {
			// Any change, including one that undoes the last, cancels a drawing still on its way.
			const id = ++run;
			clearTimeout( timer );
			const next = {
				signature: signature( input ),
				wanted: bboxForBase( input.zones ),
				fetched: last.fetched,
			};
			const plan = planRebuild( last, next );
			if ( ! plan.rebuild ) {
				// The list is back to what is drawn. A failed build of some other list no longer matters.
				set( {
					busy: false,
					error: built === next.signature ? '' : view.error,
				} );
				return;
			}
			set( { busy: true } );
			timer = setTimeout( () => draw( id, input, next, plan ), delay );
		},

		// A saved map is shown as stored when it opens, without building it again.
		adopt( { doc, zones, priority } ) {
			const id = ++run;
			clearTimeout( timer );
			last = { ...last, signature: signature( { zones, priority } ) };
			built = last.signature;
			renderDocument( forPreview( doc ) )
				.then(
					( answer ) =>
						id === run && set( { html: answer.html, error: '' } )
				)
				.catch(
					( e ) =>
						id === run &&
						set( {
							error: messageOf(
								e,
								__( 'The map could not be drawn.', 'mappulse' )
							),
						} )
				);
		},

		dispose() {
			run++;
			clearTimeout( timer );
		},
	};
}
