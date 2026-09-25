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
import { useCallback, useEffect, useRef } from '@wordpress/element';

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

/**
 * Sequenced async workflow for save/promote/discard-style handlers (P3-019).
 *
 * Bundles the three guards otherwise copied per handler: a fresh
 * AbortController per run (previous run aborted first), a monotonic
 * sequence so a slow earlier response can never overwrite a newer one,
 * and a mounted ref so unmount mid-request cannot setState/notify.
 *
 * run() hands the task `{ signal, isStale }`; the task must thread
 * `signal` into apiCall/fetch and check `isStale()` before committing
 * state. Abort rejections propagate — callers swallow AbortError.
 * Unmount aborts the in-flight run automatically.
 *
 * No router, no store, no polling — just the abort/seq/mounted contract.
 *
 * @since NEXT
 * @return {{ run: Function, cancel: Function, mountedRef: Object }} Workflow controls.
 */
export const useAsyncWorkflow = () => {
	const seqRef = useRef( 0 );
	const controllerRef = useRef( null );
	const mountedRef = useIsMounted();

	const cancel = useCallback( () => {
		if ( controllerRef.current ) {
			controllerRef.current.abort();
			controllerRef.current = null;
		}
	}, [] );

	useEffect( () => {
		return () => {
			if ( controllerRef.current ) {
				controllerRef.current.abort();
				controllerRef.current = null;
			}
		};
	}, [] );

	const run = useCallback(
		async ( task ) => {
			if ( controllerRef.current ) {
				controllerRef.current.abort();
			}
			const controller =
				typeof AbortController !== 'undefined'
					? new AbortController()
					: null;
			controllerRef.current = controller;
			const seq = ++seqRef.current;
			const signal = controller ? controller.signal : undefined;
			const isStale = () =>
				seq !== seqRef.current ||
				!! controller?.signal?.aborted ||
				! mountedRef.current;
			try {
				return await task( { signal, isStale, seq } );
			} finally {
				if ( controllerRef.current === controller ) {
					controllerRef.current = null;
				}
			}
		},
		[ mountedRef ]
	);

	return { run, cancel, mountedRef };
};
