/**
 * HeaderActions — lets the active screen publish its primary action into the
 * page head.
 *
 * The approved reference (ui/instrument/stitch/03-assets-scripts.html) puts a
 * single "Save Settings" in the page head, top right, level with the H1 — not
 * inside whichever card happens to own the first form. Every screen that owns
 * settings has its own save handler though, and those handlers live two levels
 * below the page head, so the button has to travel upward.
 *
 * This is a render-publication channel only. It holds no settings state, issues
 * no requests, and does not touch the App.js tab contract or the wppoSettings
 * shape: a screen publishes an already-constructed node, and SectionShell
 * renders whatever the most recent screen published.
 *
 * WHY THE CALLER PASSES DEPS EXPLICITLY. The node is a fresh React element on
 * every render, so an effect that depended on the node itself would call
 * setActions, re-render the provider, re-render the screen, produce a fresh
 * node, and loop forever — which hangs the test suite rather than failing it.
 * The node is therefore read from a ref, and the effect keys on the caller's
 * own deps instead, so publication happens on mount, on unmount, and when the
 * screen says explicitly that the node's appearance changed.
 *
 * @package
 */

import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useReducer,
	useRef,
} from '@wordpress/element';

const HeaderActionsContext = createContext( {
	actions: null,
	setActions: () => {},
	hasProvider: false,
} );

export const HeaderActionsProvider = ( { children } ) => {
	// The node lives in a ref; a reducer counter is only the re-render signal.
	// Holding the node in state instead would require an identity-stable value,
	// which a React element never is.
	const nodeRef = useRef( null );
	const [ , bump ] = useReducer( ( x ) => x + 1, 0 );

	const setActions = useCallback( ( node ) => {
		nodeRef.current = node ?? null;
		bump();
	}, [] );

	return (
		<HeaderActionsContext.Provider
			value={ {
				actions: nodeRef.current,
				setActions,
				hasProvider: true,
			} }
		>
			{ children }
		</HeaderActionsContext.Provider>
	);
};

/**
 * Publish a node to be rendered in the page head.
 *
 * Returns whether a provider is actually listening. A screen mounted outside
 * the provider — a unit test, or any isolated render — gets false and must
 * render the control itself, because a silent no-op provider would otherwise
 * swallow the button and the screen would appear to have no primary action.
 *
 * @param {import('react').ReactNode} node   The element to render in the page head.
 * @param {Array}                     [deps] State that changes the node's appearance,
 *                                           e.g. `[ isSaving ]`. Must be passed
 *                                           explicitly; omitting it means the head
 *                                           keeps the node as first built.
 * @return {boolean} True when the node was published to a real provider.
 */
export const useHeaderActions = ( node, deps = [] ) => {
	const { setActions, hasProvider } = useContext( HeaderActionsContext );

	// Read on every render so what setActions receives is current, but never
	// used as an effect dependency.
	const nodeRef = useRef( node );
	nodeRef.current = node;

	useEffect( () => {
		if ( ! hasProvider ) {
			return undefined;
		}
		setActions( nodeRef.current );
		return () => setActions( null );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ hasProvider, setActions, ...deps ] );

	return hasProvider;
};

/** The node the active screen published, if any. */
export const usePublishedHeaderActions = () => {
	const { actions } = useContext( HeaderActionsContext );
	return actions;
};
