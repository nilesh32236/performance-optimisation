/**
 * Shared abortable-fetch helpers (audit #1401).
 *
 * Centralises the controller-lifecycle + AbortError-swallowing + stale-run
 * pattern quintuplicated across ObjectCache, DatabaseCleanup,
 * PreloadSettings, PerformanceAudit and WelcomePanel, so an abort-semantics
 * fix in one place reaches all five.
 *
 * @since NEXT
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * Mounted ref: false after unmount. Gate setState/notify behind it.
 *
 * @since NEXT
 * @deprecated Prefer AbortController signals / runAbortable() cancel in
 * effect cleanup over isMounted checks.
 * @return {Object} Ref object with .current boolean.
 */
export const useIsMounted = () => {
	const ref = useRef( true );
	useEffect( () => {
		return () => {
			ref.current = false;
		};
	}, [] );
	return ref;
};

/**
 * Whether an error is a request cancellation.
 *
 * @since NEXT
 * @param {*} error Caught error value.
 * @return {boolean} True for AbortError.
 */
export const isAbortError = ( error ) =>
	error?.name === 'AbortError' || error?.code === 20;

/**
 * Run an async task guarded by a fresh AbortController.
 *
 * Does not auto-abort on unmount by itself: the caller must call the
 * returned `cancel` in its effect cleanup. For an effect-bound variant
 * that auto-aborts, use `useAbortableEffect`.
 *
 * Example:
 * ```js
 * useEffect( () => {
 * 	const { promise, cancel } = runAbortable( ( signal ) =>
 * 		fetch( url, { signal } )
 * 	);
 * 	promise.catch( ( err ) => {
 * 		if ( ! isAbortError( err ) ) {
 * 			throw err;
 * 		}
 * 	} );
 * 	return cancel;
 * }, [ url ] );
 * ```
 *
 * @since NEXT
 * @param {Function} task Async task receiving the signal.
 * @return {{ promise: Promise, cancel: Function }} Task promise + cancel.
 */
export const runAbortable = ( task ) => {
	const controller =
		typeof AbortController !== 'undefined' ? new AbortController() : null;
	const promise = task( controller ? controller.signal : undefined );
	return {
		promise,
		cancel: () => {
			if ( controller ) {
				controller.abort();
			}
		},
	};
};

/**
 * Effect-bound abortable task that auto-aborts on cleanup/dep change.
 *
 * @since NEXT
 * @param {Function} task Async task receiving the signal.
 * @param {Array}    deps Effect dependencies.
 * @return {void}
 */
export const useAbortableEffect = ( task, deps ) => {
	useEffect( () => {
		const { promise, cancel } = runAbortable( task );
		promise.catch( () => {} );
		return cancel;
		// eslint-disable-next-line react-hooks/exhaustive-deps -- task is intentionally keyed by caller deps.
	}, deps );
};
