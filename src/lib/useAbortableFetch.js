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
export const isAbortError = ( error ) => error?.name === 'AbortError';

/**
 * Run an async task guarded by a fresh AbortController.
 *
 * Creates the controller, runs the task, and aborts on cleanup. Returns
 * the task promise so callers can await it; rejections other than abort
 * propagate to the caller.
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
