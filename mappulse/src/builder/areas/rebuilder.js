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
import { planRebuild, signature, drawSignature } from './plan';
import { decorate } from '../decorate';
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
 * @param deps.onDoc          Called with ( doc, warnings ) whenever the document changes.
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
	// What the shapes were last built from, the base map held for them, and the document that came out.
	let last = {
		signature: signature( { zones: [], priority: [] } ),
		fetched: null,
		base: {},
		doc: null,
		warnings: [],
	};
	// The signature of everything the document on hand was made from. Saving is allowed only while it matches.
	let built = drawSignature( { zones: [], priority: [] } );
	let run = 0;
	let timer = null;
	// True from a change that needs a new drawing until that drawing is done.
	let pending = false;

	const set = ( patch ) => {
		view = { ...view, ...patch };
		onChange( view );
	};

	// Hands the document over and asks the server to draw it.
	async function show( id, doc, warnings, baseNote ) {
		onDoc( doc, warnings );
		if ( ! doc ) {
			set( { busy: false, html: '', error: '', baseNote: '' } );
			return;
		}
		try {
			const answer = await renderDocument( forPreview( doc ) );
			if ( id === run ) {
				set( { busy: false, html: answer.html, error: '', baseNote } );
			}
		} catch ( e ) {
			if ( id === run ) {
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
	}

	// A change to the areas: fetch a wider base map if needed, build the shapes, then show.
	async function rebuild( id, input, next, plan ) {
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
				style: input.style || {},
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

		last = {
			signature: next.signature,
			fetched,
			base,
			doc: result.doc,
			warnings: result.warnings,
		};
		built = next.draw;
		await show(
			id,
			decorate( result.doc, input ),
			result.warnings,
			baseNote
		);
	}

	return {
		get: () => view,

		// True when the document on hand was made from exactly these areas, style, links and labels.
		isCurrent: ( input ) => built === drawSignature( input ),

		// Call after every change to the areas, the style, a link or a label.
		update( input ) {
			const next = {
				signature: signature( input ),
				draw: drawSignature( input ),
				wanted: bboxForBase( input.zones ),
				fetched: last.fetched,
			};
			const plan = planRebuild( last, next );
			if ( ! plan.rebuild && next.draw === built ) {
				// Everything is back to what is drawn. A drawing of something else still on its way is dropped.
				if ( pending ) {
					run++;
					clearTimeout( timer );
					pending = false;
				}
				// A failed build of some other list no longer matters either.
				set( { busy: false, error: '' } );
				return;
			}
			const id = ++run;
			clearTimeout( timer );
			pending = true;
			set( { busy: true } );
			timer = setTimeout(
				async () => {
					if ( plan.rebuild ) {
						await rebuild( id, input, next, plan );
					} else {
						// Only colors, links or labels changed: the shapes stand, and the server just draws again.
						built = next.draw;
						await show(
							id,
							decorate( last.doc, input ),
							last.warnings,
							view.baseNote
						);
					}
					if ( id === run ) {
						pending = false;
					}
				},
				plan.rebuild ? delay : Math.min( delay, 150 )
			);
		},

		// A saved map is shown as stored when it opens, without building it again.
		adopt( { doc, zones, priority, style } ) {
			const id = ++run;
			clearTimeout( timer );
			pending = false;
			last = {
				...last,
				signature: signature( { zones, priority, style } ),
				doc,
				warnings: [],
			};
			built = drawSignature( { zones, priority, style } );
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
