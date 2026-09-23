/**
 * Server-rules data-fetch hook (REF-009).
 *
 * Owns the server-rules fetcher previously inlined in App.js: fetch
 * lifecycle (abort, retry trigger) decoupled from the App UI chrome so it
 * is testable without rendering the whole app. Fetch timing, abort
 * semantics, retry trigger, and parallelism with the sibling App fetchers
 * are preserved verbatim.
 *
 * @since NEXT
 * @return {{ serverRules: ?Object, serverRulesError: boolean, retry: Function }}
 *   - `serverRules`:      fetched rules payload or `null` while loading/failed.
 *   - `serverRulesError`: `true` when the last fetch failed.
 *   - `retry`:            `() => void` — resets state and refetches.
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { fetchServerRules, getErrorLogMessage } from './apiRequest';

const useServerRules = () => {
	const [ serverRules, setServerRules ] = useState( null );
	const [ serverRulesError, setServerRulesError ] = useState( false );
	const [ retryTrigger, setRetryTrigger ] = useState( 0 );
	const hasFetched = useRef( false );
	const controllerRef = useRef( null );

	/**
	 * Reset fetch state and trigger a refetch.
	 */
	const retry = useCallback( () => {
		hasFetched.current = false;
		setServerRulesError( false );
		setServerRules( null );
		setRetryTrigger( ( count ) => count + 1 );
	}, [] );

	useEffect( () => {
		if ( serverRules || hasFetched.current ) {
			return;
		}
		hasFetched.current = true;
		const controller = new AbortController();
		controllerRef.current = controller;

		const fetchRules = async () => {
			try {
				const res = await fetchServerRules( controller.signal );
				if ( ! controller.signal.aborted ) {
					if ( res.success ) {
						setServerRules( res.data );
						setServerRulesError( false );
					} else {
						hasFetched.current = false;
						setServerRulesError( true );
					}
				} else {
					hasFetched.current = false;
				}
			} catch ( rulesError ) {
				hasFetched.current = false;
				if ( ! controller.signal.aborted ) {
					console.error(
						'Failed fetching server rules',
						getErrorLogMessage( rulesError )
					);
					setServerRulesError( true );
				}
			}
		};

		void fetchRules();

		return () => {
			controller.abort();
		};
		// Intentionally minimal deps: the hasFetched ref (not state) gates
		// re-fetches, so effect-written state (serverRules) is read but not
		// depended on — matching the App.js effect this was extracted from.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ retryTrigger ] );

	return { serverRules, serverRulesError, retry };
};

export default useServerRules;
