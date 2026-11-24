/**
 * Wires the rebuilder to the builder's state.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { createRebuilder } from './rebuilder';
import { fetchBaseLayer, renderDocument } from '../api';

export function useDocument( state, dispatch ) {
	const {
		zones,
		priority,
		legacy,
		loading,
		doc,
		mapId,
		dirty,
		title,
		style,
	} = state;
	const [ view, setView ] = useState( {
		busy: false,
		html: '',
		error: '',
		baseNote: '',
	} );
	const holder = useRef( null );
	if ( ! holder.current ) {
		holder.current = createRebuilder( {
			fetchBaseLayer,
			renderDocument,
			onChange: setView,
			onDoc: ( built, warnings ) =>
				dispatch( { type: 'SET_DOC', doc: built, warnings } ),
		} );
	}
	const rebuilder = holder.current;
	useEffect( () => () => rebuilder.dispose(), [ rebuilder ] );

	// A saved map is drawn as stored when it opens, before any change.
	const opened = useRef( false );
	useEffect( () => {
		if ( opened.current || ! doc || ! mapId || dirty || loading ) {
			return;
		}
		opened.current = true;
		rebuilder.adopt( { doc, zones, priority, style } );
	}, [ rebuilder, doc, mapId, dirty, loading, zones, priority, style ] );

	useEffect( () => {
		if ( legacy || loading ) {
			return;
		}
		// A map being opened is adopted above first; until then there is nothing to rebuild.
		if ( mapId && ! opened.current && ! dirty ) {
			return;
		}
		rebuilder.update( { zones, priority, title, style } );
		// The title is read when a build runs but does not start one.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ rebuilder, zones, priority, style, legacy, loading ] );

	return {
		...view,
		svg: view.html,
		current: rebuilder.isCurrent( { zones, priority, style } ),
	};
}
