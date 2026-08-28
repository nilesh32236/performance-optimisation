/**
 * Shared helper for API calls with loading + notice feedback (D-03).
 *
 * Centralises the 10-line `setIsLoading → dismiss → try apiCall → notify(success/error) → catch → console.error → finally setIsLoading(false)`
 * scaffold that was copy-pasted across FileOptimization, PluginSetting, DatabaseCleanup,
 * Dashboard, EdgeCachePanel, ImageOptimization, etc.
 *
 * Two entry points:
 *  - Hook: `useApiCallWithNotice({ notify, dismiss, setLoading })` → `callWithNotice(promiseOrFactory, successMsg, errorMsg, durationMs)`
 *    Stable via useCallback, captures notify/dismiss/setLoading.
 *  - Plain: `withApiNotice(promiseOrFactory, { successMessage, errorMessage, notify, dismiss, setLoading, durationMs })`
 *    For non-hook or one-off use.
 *
 * Both accept either a Promise or a thunk `() => Promise` (thunk is preferred
 * so setLoading(true) happens before the request starts — see F-06). They
 * normalise to `await (typeof factory === 'function' ? factory() : factory)`.
 *
 * @since NEXT
 */

import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Plain helper (no hooks) — call directly.
 *
 * @param {Promise|Function} promiseOrFactory    Promise or thunk returning a Promise.
 * @param {Object}           opts                Options.
 * @param {string}           opts.successMessage Success fallback message (used if res.message missing and res.success true).
 * @param {string}           opts.errorMessage   Error fallback message (used if res.message missing and res.success false or on throw).
 * @param {Function}         opts.notify         useNotice notify({type,message,durationMs}).
 * @param {Function}         [opts.dismiss]      useNotice dismiss().
 * @param {Function}         [opts.setLoading]   setIsLoading(bool).
 * @param {number}           [opts.durationMs]   Auto-dismiss delay (default 3000).
 * @return {Promise<*>} The API response (or throws).
 */
export const withApiNotice = async (
	promiseOrFactory,
	{
		successMessage,
		errorMessage,
		notify,
		dismiss,
		setLoading,
		durationMs = 3000,
	}
) => {
	if ( setLoading ) {
		setLoading( true );
	}
	if ( dismiss ) {
		dismiss();
	}
	try {
		const maybePromise =
			typeof promiseOrFactory === 'function'
				? promiseOrFactory()
				: promiseOrFactory;
		const res = await maybePromise;
		if ( res && res.success ) {
			notify( {
				type: 'success',
				message: res.message || successMessage,
				durationMs,
			} );
		} else {
			notify( {
				type: 'error',
				message: ( res && res.message ) || errorMessage,
				durationMs,
			} );
		}
		return res;
	} catch ( err ) {
		// Keep console.error signature matching the previous per-component style.
		console.error( errorMessage, err );
		notify( {
			type: 'error',
			message: __(
				'An unexpected error occurred.',
				'performance-optimisation'
			),
			durationMs,
		} );
		return undefined;
	} finally {
		if ( setLoading ) {
			setLoading( false );
		}
	}
};

/**
 * Hook that returns a stable callWithNotice callback (D-03).
 *
 * @param {Object}   opts              Options.
 * @param {Function} opts.notify       useNotice notify.
 * @param {Function} opts.dismiss      useNotice dismiss.
 * @param {Function} [opts.setLoading] setIsLoading.
 * @return {Function} callWithNotice(promiseOrFactory, successMessage, errorMessage, durationMs?)
 */
export const useApiCallWithNotice = ( { notify, dismiss, setLoading } ) => {
	const callWithNotice = useCallback(
		async (
			promiseOrFactory,
			successMessage,
			errorMessage,
			durationMs = 3000
		) => {
			return withApiNotice( promiseOrFactory, {
				successMessage,
				errorMessage,
				notify,
				dismiss,
				setLoading,
				durationMs,
			} );
		},
		[ notify, dismiss, setLoading ]
	);
	return callWithNotice;
};

export default useApiCallWithNotice;
