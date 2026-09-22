/**
 * Shared abortable-fetch helpers (audit #1401).
 *
 * Centralises the controller-lifecycle + AbortError-swallowing + stale-run
 * pattern quintuplicated across ObjectCache, DatabaseCleanup,
 * PreloadSettings, PerformanceAudit and WelcomePanel, so an abort-semantics
 * fix in one place reaches all five.
 *
 * @since 2.3.0
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * Mounted ref: false after unmount. Gate setState/notify behind it.
 *
 * Prefer AbortController cancellation (runAbortable/cancel in cleanup)
 * for in-flight work; use this only for non-abortable completions
 * (audit #1420).
 *
 * @since 2.3.0
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
 * @since 2.3.0
 * @param {*} error Caught error value.
 * @return {boolean} True for AbortError.
 */
export const isAbortError = ( error ) =>
	error?.name === 'AbortError' || error?.code === 20; // Audit #1420: DOMException ABORT_ERR.

/**
 * Run an async task guarded by a fresh AbortController.
 *
 * Creates the controller and runs the task. Callers MUST wire cancel()
 * into effect cleanup — this helper cannot auto-abort (audit #1420).
 * Rejections other than abort propagate to the caller.
 *
 * @since 2.3.0
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
