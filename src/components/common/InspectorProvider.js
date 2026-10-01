/**
 * Inspector provider.
 *
 * A thin wrapper around the pure reducer in `lib/InspectorContext.js`. It owns
 * the two pieces of React state — the current subject and whether the panel is
 * open — and exposes them with stable callbacks so consumers can depend on the
 * functions without re-rendering on every panel change.
 *
 * @since x-release-please-version
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useReducer,
	useState,
} from '@wordpress/element';

import InspectorContext, {
	EMPTY_INSPECTOR_STATE,
	reduceInspector,
} from '../../lib/InspectorContext';

/**
 * Provide inspector state to the tree.
 *
 * @param {Object} props          Component props.
 * @param {string} props.resetKey Changing this clears the panel. Pass the
 *                                current route, so an explanation of a control
 *                                on the screen you just left does not linger.
 * @param {Node}   props.children The app.
 * @return {Element} The provider.
 */
export default function InspectorProvider( { resetKey = null, children } ) {
	const [ state, dispatch ] = useReducer(
		reduceInspector,
		EMPTY_INSPECTOR_STATE
	);
	const [ isOpen, setOpen ] = useState( true );

	const show = useCallback(
		( subject, source = 'focus' ) => {
			dispatch( { type: 'show', subject, source } );
		},
		[ dispatch ]
	);

	const hide = useCallback(
		( source = 'focus' ) => {
			dispatch( { type: 'hide', source } );
		},
		[ dispatch ]
	);

	const clear = useCallback( () => {
		dispatch( { type: 'clear' } );
	}, [ dispatch ] );

	// Leaving a screen must not leave its explanation behind: the panel would
	// describe a control that is no longer on the page.
	useEffect( () => {
		dispatch( { type: 'clear' } );
	}, [ resetKey ] );

	const value = useMemo(
		() => ( {
			subject: state.subject,
			source: state.source,
			isOpen,
			setOpen,
			show,
			hide,
			clear,
		} ),
		[ state.subject, state.source, isOpen, show, hide, clear ]
	);

	return (
		<InspectorContext.Provider value={ value }>
			{ children }
		</InspectorContext.Provider>
	);
}
