/**
 * Keeps the drawing in step with the zone list: fetches the base map when the zones need a wider
 * one, builds the document in the browser, and asks the server to draw it.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	buildDocument,
	bboxForBase,
	baseIsPartial,
	BASE_LAYERS,
} from '../../geometry/build-document';
import { planRebuild, signature } from './plan';
import { fetchBaseLayer, renderDocument, messageOf } from '../api';

const DELAY = 300;

export function useDocument( state, dispatch ) {
	const { zones, priority, legacy, doc } = state;
	const [ svg, setSvg ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ baseNote, setBaseNote ] = useState( '' );
	// What the last drawing was made from, and the base map held for it.
	const last = useRef( {
		signature: signature( { zones: [], priority } ),
		fetched: null,
		base: {},
	} );
	const run = useRef( 0 );

	// A saved map is drawn as stored when it opens, before any change.
	const opened = useRef( false );
	useEffect( () => {
		if ( opened.current || ! doc || ! state.mapId || state.dirty ) {
			return;
		}
		opened.current = true;
		last.current.signature = signature( { zones, priority } );
		renderDocument( doc )
			.then( ( answer ) => setSvg( answer.html ) )
			.catch( () => {} );
	}, [ doc, state.mapId, state.dirty, zones, priority ] );

	useEffect( () => {
		if ( legacy || state.loading ) {
			return;
		}
		const next = {
			signature: signature( { zones, priority } ),
			wanted: bboxForBase( zones ),
			fetched: last.current.fetched,
		};
		const plan = planRebuild( last.current, next );
		if ( ! plan.rebuild ) {
			return;
		}

		const id = ++run.current;
		const stale = () => id !== run.current;
		setBusy( true );
		const timer = setTimeout( async () => {
			let { base, fetched } = last.current;
			if ( plan.refetchBase ) {
				base = {};
				fetched = next.wanted;
				let failed = 0;
				// One layer at a time: each answer is cached on the server and small enough to pass through.
				for ( const layer of BASE_LAYERS ) {
					try {
						const answer = await fetchBaseLayer(
							next.wanted,
							layer
						);
						base[ layer ] = answer[ layer ];
					} catch ( e ) {
						failed++;
					}
					if ( stale() ) {
						return;
					}
				}
				let note = '';
				if ( failed ) {
					note = __(
						'Part of the base map (land, water or roads) could not be loaded, so the map is drawn without it. Change an area to try again.',
						'mappulse'
					);
				} else if ( baseIsPartial( zones ) ) {
					note = __(
						'These areas are far apart, so land, water and roads are drawn for the middle of the map only. Maps of one metro area work best.',
						'mappulse'
					);
				}
				setBaseNote( note );
				// A base map with gaps is not kept, so the next change asks for it again.
				if ( failed ) {
					fetched = null;
				}
			}

			let built;
			try {
				built = buildDocument( {
					title: state.title,
					zones,
					base,
					priority,
				} );
			} catch ( e ) {
				setError(
					__(
						'These areas could not be combined into a map. Remove the area added last and try again.',
						'mappulse'
					)
				);
				setBusy( false );
				return;
			}
			last.current = { signature: next.signature, fetched, base };
			dispatch( {
				type: 'SET_DOC',
				doc: built.doc,
				warnings: built.warnings,
			} );
			if ( ! built.doc ) {
				setSvg( '' );
				setError( '' );
				setBusy( false );
				return;
			}
			try {
				const answer = await renderDocument( built.doc );
				if ( stale() ) {
					return;
				}
				setSvg( answer.html );
				setError( '' );
			} catch ( e ) {
				if ( stale() ) {
					return;
				}
				// The last good drawing stays on screen.
				setError(
					messageOf(
						e,
						__( 'The map could not be drawn.', 'mappulse' )
					)
				);
			}
			setBusy( false );
		}, DELAY );
		return () => clearTimeout( timer );
		// The title is read when a build runs but does not start one.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ zones, priority, legacy, state.loading, dispatch ] );

	return { svg, busy, error, baseNote };
}
