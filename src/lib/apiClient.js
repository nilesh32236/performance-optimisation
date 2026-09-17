/**
 * Transport + nonce retry (audit maintainability).
 *
 * Split out of `lib/apiRequest.js`, which mixed transport + nonce retry,
 * settings-cache mutation, scan validation, and endpoint wrappers in one
 * 660-line module. `apiRequest.js` re-exports `apiCall` so existing imports
 * keep working; `lib/scanApi.js` imports it from here (no import cycle).
 *
 * @since NEXT
 */

import { isAuthErrorCode } from './authErrors';
import { getLogMessage as getErrorLogMessage } from './logMessage';
import { commitSettingsCache } from './settingsCache';

let pendingRefresh = null;

// Note: refreshNonce() and apiCall() below intentionally keep their own
// typeof wppoSettings guards instead of routing through getWppoSettings()
// (lib/settingsCache.js). Those paths must throw when the global is absent
// and mutate the live global (wppoSettings.nonce / wppoSettings.settings);
// the helper returns a fallback {} which would mask the absent-global case
// and break the live mutation contract.

/**
 * Refresh the REST nonce from the admin-ajax endpoint.
 *
 * WordPress nonces have a 24-hour lifetime by default. If the admin page
 * is left open across multiple days, the SPA needs a fresh nonce to keep
 * making write requests.
 *
 * Deduplicates concurrent refreshes via a shared promise (thundering-herd
 * guard) so multiple simultaneous 403s share a single admin-ajax round-trip.
 *
 * @since 1.6.0
 * @return {Promise<string>} The refreshed nonce string.
 */
const refreshNonce = async () => {
	// Audit #1354 review: restore the shared-promise guard — concurrent
	// 403s share one round-trip. (A caller-specific signal cannot abort
	// the shared fetch; callers needing cancellation pass their signal
	// to apiCall(), which aborts its own request.)
	if ( pendingRefresh ) {
		return pendingRefresh;
	}
	if ( typeof wppoSettings === 'undefined' ) {
		throw new Error( 'wppoSettings is not defined' );
	}
	const refreshPromise = ( async () => {
		try {
			// Intentionally NOT forwarding the caller's AbortSignal here:
			// the promise is shared across concurrent 403 retries, so one
			// caller aborting must not reject the refresh for other live
			// callers. Callers needing cancellation abort their own apiCall
			// request via the signal passed to doFetch().
			const res = await fetch( wppoSettings.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams( {
					action: 'wppo_get_nonce',
					nonce: wppoSettings.nonce_refresh,
				} ),
			} );
			if ( ! res.ok ) {
				throw new Error(
					'Nonce refresh failed with status ' + res.status
				);
			}
			const data = await res.json();
			if ( data.success && data.data?.nonce ) {
				if (
					typeof data.data.nonce === 'string' &&
					data.data.nonce.length > 0
				) {
					wppoSettings.nonce = data.data.nonce;
					return data.data.nonce;
				}
			}
			throw new Error( 'Nonce refresh returned invalid response' );
		} catch ( e ) {
			console.error( 'Nonce refresh failed:', getErrorLogMessage( e ) );
			throw e;
		}
	} )();

	pendingRefresh = refreshPromise.finally( () => {
		pendingRefresh = null;
	} );

	return pendingRefresh;
};

/**
 * Make a REST API call to the Performance Optimisation plugin.
 *
 * Mutates wppoSettings.settings globally on successful `update_settings` or
 * `restore_settings` calls.
 *
 * @since 1.0.0
 * @param {string}      action   The REST endpoint action (e.g. 'update_settings').
 * @param {Object|null} body     Request body payload.
 * @param {string}      method   HTTP method ('POST' or 'GET'). Defaults to 'POST'.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved JSON response data.
 */
export const apiCall = async ( action, body, method = 'POST', signal ) => {
	if ( typeof wppoSettings === 'undefined' ) {
		throw new Error( 'wppoSettings is not defined' );
	}
	const isGet = 'GET' === method;

	const doFetch = ( nonce ) =>
		fetch( wppoSettings.apiUrl + action, {
			method,
			headers: {
				...( ! isGet && { 'Content-Type': 'application/json' } ),
				'X-WP-Nonce': nonce || wppoSettings.nonce || '',
			},
			...( ! isGet && { body: JSON.stringify( body ) } ),
			signal,
		} );

	const handleResponse = async ( response, isRetrying = false ) => {
		let data;
		try {
			data = await response.json();
		} catch ( parseError ) {
			// An expired nonce can surface as a non-JSON (HTML/login) 401/403
			// body, which never reaches the data.code check below. Retry once
			// with a fresh nonce on those statuses before giving up.
			if (
				! isRetrying &&
				( response.status === 401 || response.status === 403 )
			) {
				const freshNonce = await refreshNonce();
				const retryResponse = await doFetch( freshNonce );
				return handleResponse( retryResponse, true );
			}
			throw new Error(
				`Invalid JSON response from ${ action }: ${ parseError.message }`
			);
		}

		// Detect expired nonce (rest_forbidden, rest_cookie_invalid_nonce, etc.).
		// The code list lives in ./authErrors.js; main.js/esi.js mirror it
		// (see authSync.test.js). An HTTP 401/403 with a JSON body but no
		// recognised code falls back to the same single retry.
		if (
			( data.code && isAuthErrorCode( data.code ) ) ||
			( ( response.status === 401 || response.status === 403 ) &&
				! data.success )
		) {
			if ( isRetrying ) {
				throw new Error(
					'Nonce retry failed — authentication error persists.'
				);
			}
			const freshNonce = await refreshNonce();
			const retryResponse = await doFetch( freshNonce );
			return handleResponse( retryResponse, true );
		}

		// Mutates the global wppoSettings.settings so all components reading from it
		// (e.g. WelcomePanel.STEPS.isEnabled) reflect the new state without re-rendering.
		// This is an implicit coupling — the global serves as a shared reactive store.
		// restore_settings returns the full restored settings payload, so it syncs too.
		if (
			( 'update_settings' === action || 'restore_settings' === action ) &&
			data.success &&
			data.data
		) {
			commitSettingsCache( data.data );
		}
		return data;
	};

	try {
		const response = await doFetch( null );
		return await handleResponse( response );
	} catch ( error ) {
		console.error(
			'API call failed:',
			action,
			getErrorLogMessage( error )
		);
		throw error;
	}
};
