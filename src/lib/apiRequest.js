import { isAuthErrorCode } from './authErrors';
import { redactLogSecrets } from './logSecrets';
import {
	commitSettingsResponse,
	cloneSettingsPayload,
} from './settingsResponse';

// Audit #1354: the raw lookup Set stays module-private in
// authErrors.js — re-export only the frozen list and the lookup.
export { AUTH_ERROR_CODES, isAuthErrorCode } from './authErrors';

// P3-019: single response-commit contract re-exported for reuse so
// components commit server payloads instead of patching request echoes.
export {
	commitSettingsResponse,
	resolveSettingsPayload,
	isFullSettingsMap,
	FULL_SETTINGS_MAP_ACTIONS,
	NESTED_SETTINGS_ACTIONS,
} from './settingsResponse';

/**
 * Safe accessor for the global wppoSettings object injected by PHP via
 * wp_localize_script. Optional chaining alone does not protect against an
 * undeclared global (ReferenceError), so every direct read must go through
 * the typeof guard centralised here.
 *
 * Note: refreshNonce() and apiCall() below intentionally keep their own
 * typeof wppoSettings guards instead of routing through this helper. Those
 * paths must throw when the global is absent and mutate the live global
 * (wppoSettings.nonce / wppoSettings.settings); this helper returns a
 * fallback {} which would mask the absent-global case and break the live
 * mutation contract.
 *
 * @since 2.0.0
 * @since 2.3.0 Accepts an optional dot-path with fallback (getWppoSettings('settings.cache.enabled', false)).
 * @param {string} [path]     Optional dot-separated path (e.g. 'settings.cache').
 * @param {*}      [fallback] Optional fallback returned when the global or path is absent.
 * @return {*} The global settings object (or path value), or fallback/{} when absent.
 */
export const getWppoSettings = ( path, fallback = {} ) => {
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return fallback;
	}
	if ( typeof path !== 'string' || ! path ) {
		return wppoSettings;
	}
	const hasOwn = ( obj, key ) =>
		Object.hasOwn
			? Object.hasOwn( obj, key )
			: Object.prototype.hasOwnProperty.call( obj, key );
	let current = wppoSettings;
	for ( const key of path.split( '.' ) ) {
		if (
			! current ||
			typeof current !== 'object' ||
			! hasOwn( current, key )
		) {
			return fallback;
		}
		current = current[ key ];
	}
	return current === undefined ? fallback : current;
};

/**
 * Extract a safe log message from an error without leaking response bodies.
 *
 * Server error objects can embed response payloads (system info, settings);
 * console output persists in devtools/extensions, so only the message is
 * logged, never the full error/response object.
 *
 * @since 2.3.0
 * @param {*} error Caught error value.
 * @return {string} Safe message string.
 */
export const getErrorLogMessage = ( error ) => {
	let message;
	if ( error instanceof Error ) {
		message = error.message || 'Unknown error';
	} else if ( typeof error === 'string' ) {
		message = error.slice( 0, 500 ) || 'Unknown error';
	} else if ( error === null || typeof error === 'undefined' ) {
		return 'Unknown error';
	} else {
		try {
			message = String( error ).slice( 0, 500 );
		} catch {
			return 'Unknown error';
		}
	}
	try {
		const redacted = redactLogSecrets( message );
		return ( redacted || 'Unknown error' ).slice( 0, 500 );
	} catch {
		return 'Unknown error';
	}
};

let pendingRefresh = null;

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
	// 403s share one round-trip. Audit #1420: the shared fetch deliberately
	// takes NO caller signal — one component unmounting must not abort the
	// nonce refresh for all concurrent waiters.
	if ( pendingRefresh ) {
		return pendingRefresh;
	}
	if ( typeof wppoSettings === 'undefined' ) {
		throw new Error( 'wppoSettings is not defined' );
	}
	const refreshPromise = ( async () => {
		try {
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
 * Commit a settings payload to the shared `wppoSettings.settings` cache.
 *
 * Single choke point for the frozen-global mutation previously inlined in
 * apiCall() and copied across AiPanel/EdgeCachePanel/LlmsPanel. Freezing
 * keeps every component reading the live global on the same snapshot.
 *
 * Contract verified in includes/class-rest.php: both `update_settings` and
 * `restore_settings` respond with the full merged `wppo_settings` option
 * (via `send_response( $response_settings )` / `send_response( $merged_settings )`),
 * so a full replace here is correct. If an endpoint ever echoes only the
 * saved tab slice, callers must use patchSettingsCache() instead.
 *
 * @since 2.3.0
 * @param {*} payload Resolved settings payload (typically `data.data`).
 * @return {void}
 */
export const commitSettingsCache = ( payload ) => {
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return;
	}
	if ( payload && typeof payload === 'object' ) {
		wppoSettings.settings = Object.freeze(
			cloneSettingsPayload( payload )
		);
	}
};

/**
 * Patch a single settings tab into the shared `wppoSettings.settings` cache.
 *
 * Components that save one tab optimistically merge the saved slice into the
 * live global so sibling panels see the new value without a reload. The
 * merged tab and the top-level object are both frozen like commitSettingsCache().
 *
 * @since 2.3.0
 * @param {string} tab   Settings tab key (e.g. 'ai_adaptive').
 * @param {Object} patch Tab settings to merge.
 * @return {void}
 */
export const patchSettingsCache = ( tab, patch ) => {
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return;
	}
	if ( typeof tab !== 'string' || ! tab ) {
		return;
	}
	if ( ! patch || typeof patch !== 'object' ) {
		return;
	}
	const current =
		wppoSettings.settings && typeof wppoSettings.settings === 'object'
			? wppoSettings.settings
			: {};
	const base =
		current[ tab ] && typeof current[ tab ] === 'object'
			? current[ tab ]
			: {};
	wppoSettings.settings = Object.freeze( {
		...current,
		[ tab ]: Object.freeze( { ...base, ...patch } ),
	} );
};

/**
 * In-flight GET request registry for response sharing.
 *
 * Dashboard cards (e.g. GuidedNextStep and PerformanceAudit follow-ups)
 * can mount concurrently and ask for the same read-only endpoint. Sharing
 * one underlying fetch per action string avoids duplicate network trips on
 * every Dashboard load while keeping every caller on its own abort
 * semantics (see the per-caller race in apiCall()).
 *
 * Entries live only while the request is pending and are removed on
 * settle, so sequential calls (and tests) always hit the network.
 *
 * @since 2.3.0
 * @type {Map<string, Promise<Object>>}
 */
const inflightGets = new Map();

/**
 * Clear the in-flight GET registry (test seam).
 *
 * Production code never needs this — entries self-remove on settle. Tests
 * that intentionally leave a request pending can reset the module state.
 *
 * @since 2.3.0
 * @return {void}
 */
export const clearInflightGets = () => {
	inflightGets.clear();
};

/**
 * Reject when the caller's AbortSignal fires.
 *
 * Lets a caller sharing an in-flight GET observe its own abort without
 * cancelling the shared underlying fetch for the other waiters.
 *
 * @since 2.3.0
 * @param {AbortSignal} signal Caller's abort signal.
 * @return {Promise<never>} Rejects with an AbortError on abort.
 */
const onCallerAbort = ( signal ) => {
	let onAbort = null;
	const promise = new Promise( ( _, reject ) => {
		if ( signal.aborted ) {
			const aborted = new Error( 'Aborted' );
			aborted.name = 'AbortError';
			reject( aborted );
			return;
		}
		onAbort = () => {
			signal.removeEventListener( 'abort', onAbort );
			const aborted = new Error( 'Aborted' );
			aborted.name = 'AbortError';
			reject( aborted );
		};
		signal.addEventListener( 'abort', onAbort, { once: true } );
	} );
	// Removed once the shared GET settles so waiters do not leak a
	// listener for the signal lifetime.
	promise.cleanup = () => {
		if ( onAbort ) {
			signal.removeEventListener( 'abort', onAbort );
		}
	};
	return promise;
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

	// Share concurrent identical GETs: a second caller awaiting the same
	// action while the first is still pending joins the same promise
	// instead of issuing a duplicate request. Its own AbortSignal is
	// raced locally so aborting one waiter never cancels the shared
	// fetch for the others.
	if ( isGet ) {
		const shared = inflightGets.get( action );
		if ( shared ) {
			if ( ! signal ) {
				return shared;
			}
			const abortPromise = onCallerAbort( signal );
			try {
				return await Promise.race( [ shared, abortPromise ] );
			} finally {
				abortPromise.cleanup?.();
			}
		}
	}

	const doFetch = ( nonce, fetchSignal = signal ) =>
		fetch( wppoSettings.apiUrl + action, {
			method,
			headers: {
				...( ! isGet && { 'Content-Type': 'application/json' } ),
				'X-WP-Nonce': nonce || wppoSettings.nonce || '',
			},
			...( ! isGet && { body: JSON.stringify( body ) } ),
			signal: fetchSignal,
		} );

	// Shared GETs deliberately fetch WITHOUT any caller signal: aborting one
	// waiter must never cancel the underlying request for the other joiners.
	// Every waiter (including the first) races the shared promise locally
	// against its own abort promise instead (see below).
	const doSharedFetch = ( nonce ) => doFetch( nonce, undefined );

	const handleResponse = async (
		response,
		isRetrying = false,
		fetchFn = doFetch
	) => {
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
				const retryResponse = await fetchFn( freshNonce );
				return handleResponse( retryResponse, true, fetchFn );
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
			const retryResponse = await fetchFn( freshNonce );
			return handleResponse( retryResponse, true, fetchFn );
		}

		// Mutates the global wppoSettings.settings so all components reading from it
		// (e.g. WelcomePanel.STEPS.isEnabled) reflect the new state without re-rendering.
		// This is an implicit coupling — the global serves as a shared reactive store.
		// P3-019: one response-commit contract covers every settings-bearing
		// action (update/import/restore/sandbox_promote/safe_mode full maps,
		// apply_preset nested settings). Fail-safe no-op otherwise.
		if ( data.success ) {
			commitSettingsResponse( action, data.data );
		}
		return data;
	};

	try {
		let pending;
		if ( isGet ) {
			pending = ( async () => {
				const response = await doSharedFetch( null );
				return await handleResponse( response, false, doSharedFetch );
			} )();
			inflightGets.set( action, pending );
			try {
				if ( ! signal ) {
					return await pending;
				}
				// Race even the first waiter locally so its abort rejects
				// only itself while the shared fetch continues for others.
				const abortPromise = onCallerAbort( signal );
				try {
					return await Promise.race( [ pending, abortPromise ] );
				} finally {
					abortPromise.cleanup?.();
				}
			} finally {
				if ( inflightGets.get( action ) === pending ) {
					inflightGets.delete( action );
				}
			}
		}
		const response = await doFetch( null );
		return await handleResponse( response );
	} catch ( error ) {
		if ( error?.name !== 'AbortError' ) {
			console.error(
				'API call failed:',
				action,
				getErrorLogMessage( error )
			);
		}
		throw error;
	}
};

/**
 * Fetch paginated recent activity log entries.
 *
 * The page number is coerced to a finite positive integer and URL-encoded so
 * caller-supplied values cannot inject additional query parameters (e.g. `&`
 * or `#` from user-controlled input).
 *
 * @since 1.0.0
 * @since 2.0.0 Page is validated as a positive integer and URL-encoded.
 * @param {number}      page     Page number (defaults to 1).
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved activities data.
 */
export const fetchRecentActivities = ( page = 1, signal ) => {
	const safePage = Math.max( 1, Number.parseInt( page, 10 ) || 1 );
	return apiCall(
		buildAction( 'recent_activities', { page: safePage } ),
		{},
		'GET',
		signal
	);
};

/**
 * Validate a scan URL client-side before it reaches the resource-intensive
 * performance_scan / pagespeed_scan endpoints. Requires an absolute http(s)
 * URL and, when wppoSettings.homeUrl is available, same-origin with the site.
 * Server-side host allowlisting + per-user/IP rate limiting remains
 * authoritative.
 *
 * When homeUrl is absent (e.g. a test harness or a direct mount before
 * localisation), the origin check is skipped and any absolute http(s) URL is
 * accepted: failing closed here would brick legitimate scans, and the server
 * gate remains authoritative. Callers needing a strict gate should assert
 * homeUrl presence themselves.
 *
 * @since 2.0.0
 * @param {string} url Raw scan URL.
 * @return {boolean} True when the URL is safe to forward to the server.
 */
export const isValidScanUrl = ( url ) => {
	if ( ! url || typeof url !== 'string' ) {
		return false;
	}
	let parsed;
	try {
		// Absolute URLs only — no base, so relative paths and protocol-
		// relative values are rejected.
		parsed = new URL( url );
	} catch {
		return false;
	}
	if ( 'http:' !== parsed.protocol && 'https:' !== parsed.protocol ) {
		return false;
	}
	try {
		if (
			typeof wppoSettings !== 'undefined' &&
			wppoSettings &&
			wppoSettings.homeUrl
		) {
			const home = new URL( wppoSettings.homeUrl );
			if ( parsed.origin !== home.origin ) {
				return false;
			}
		}
	} catch {
		return false;
	}
	return true;
};

/**
 * Allowed PageSpeed scan strategies (server-side allowlisting remains
 * authoritative).
 *
 * @since 2.3.0
 * @type {string[]}
 */
export const SCAN_STRATEGIES = [ 'mobile', 'desktop' ];

/**
 * Build an action path with URL-encoded query params via URLSearchParams.
 *
 * Single choke point so callers cannot forget encodeURIComponent and inject
 * `&`/`#` through user-controlled values.
 *
 * @since 2.3.0
 * @param {string} action REST action (e.g. 'pagespeed_results').
 * @param {Object} params Query params.
 * @return {string} Action path with query string.
 */
export const buildAction = ( action, params = {} ) => {
	const search = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( params ?? {} ) ) {
		if ( value === undefined || value === null || value === '' ) {
			continue;
		}
		search.set( key, String( value ) );
	}
	const qs = search.toString();
	return qs ? `${ action }?${ qs }` : action;
};

/**
 * Throw when a scan URL fails client-side validation.
 *
 * @since 2.3.0
 * @param {string}  url        Raw scan URL.
 * @param {boolean} allowEmpty Whether '' is accepted (list endpoints).
 * @return {void}
 */
/**
 * User-facing validation message with translations lookup (audit #1420).
 *
 * Reads wppoSettings.translations with an English fallback so validation
 * rejections are localizable like other UI notice strings.
 *
 * @since 2.3.0
 * @param {string} key      Translations key.
 * @param {string} fallback English fallback.
 * @return {string} Translated or fallback message.
 */
const translatedError = ( key, fallback ) => {
	try {
		const map =
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.translations
				: null;
		if ( map && typeof map[ key ] === 'string' && map[ key ] !== '' ) {
			return map[ key ];
		}
	} catch {
		// Fall through to English.
	}
	return fallback;
};

export const assertScanUrl = ( url, allowEmpty = false ) => {
	if ( allowEmpty && ( url === '' || url === undefined || url === null ) ) {
		return;
	}
	if ( ! isValidScanUrl( url ) ) {
		throw new Error(
			translatedError(
				'invalidScanUrl',
				'Invalid scan URL: must be a same-origin http(s) URL.'
			)
		);
	}
};

/**
 * Throw when a scan strategy fails client-side validation.
 *
 * @since 2.3.0
 * @param {string}  strategy   Raw strategy value.
 * @param {boolean} allowEmpty Whether '' is accepted (list endpoints).
 * @return {void}
 */
export const assertScanStrategy = ( strategy, allowEmpty = false ) => {
	if ( ! isValidScanStrategy( strategy, allowEmpty ) ) {
		throw new Error(
			allowEmpty
				? translatedError(
						'invalidScanStrategyEmpty',
						"Invalid strategy: must be 'mobile', 'desktop' or ''."
				  )
				: translatedError(
						'invalidScanStrategy',
						"Invalid strategy: must be 'mobile' or 'desktop'."
				  )
		);
	}
};

/**
 * Validate a PageSpeed scan strategy client-side before it reaches the
 * resource-intensive pagespeed_scan / pagespeed_results / web_vitals_trends
 * endpoints. Server-side allowlisting remains authoritative.
 *
 * @since 2.0.0
 * @param {string}  strategy   Raw strategy value.
 * @param {boolean} allowEmpty Whether '' is accepted (list endpoints).
 * @return {boolean} True when the strategy is safe to forward to the server.
 */
export const isValidScanStrategy = ( strategy, allowEmpty = false ) => {
	if (
		allowEmpty &&
		( strategy === '' || strategy === undefined || strategy === null )
	) {
		return true;
	}
	if ( typeof strategy !== 'string' ) {
		return false;
	}
	if ( allowEmpty && '' === strategy ) {
		return true;
	}
	return SCAN_STRATEGIES.includes( strategy );
};

/**
 * Run a local telemetry scan on the given URL.
 *
 * @since 1.5.0
 * @since 2.0.0 Scan URL is validated client-side (http(s), same-origin) before the request.
 * @param {string}      url      The URL to scan.
 * @param {boolean}     force    Whether to force the scan.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved scan result data.
 */
export const runPerformanceScan = ( url, force = false, signal ) => {
	try {
		assertScanUrl( url );
	} catch ( error ) {
		return Promise.reject( error );
	}
	return apiCall( 'performance_scan', { url, force }, 'POST', signal );
};

/**
 * Fetch system information (PHP, DB, WordPress, server, cache).
 *
 * @since 1.5.0
 * @since 2.0.0 Accepts an optional AbortSignal for request cancellation.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved system info data.
 */
export const fetchSystemInfo = ( signal ) => {
	return apiCall( 'system_info', {}, 'GET', signal );
};

/**
 * Queue a Google PageSpeed Insights scan as a background job.
 *
 * @since 1.6.0
 * @since 2.0.0 Scan URL and strategy are validated client-side before the request.
 * @since 2.0.0 Accepts an optional AbortSignal for request cancellation.
 * @param {string}      url      The URL to scan.
 * @param {string}      strategy 'mobile' or 'desktop'.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved response with job_id.
 */
export const queuePagespeedScan = ( url, strategy = 'mobile', signal ) => {
	try {
		assertScanUrl( url );
		assertScanStrategy( strategy );
	} catch ( error ) {
		return Promise.reject( error );
	}
	return apiCall( 'pagespeed_scan', { url, strategy }, 'POST', signal );
};

/**
 * Retrieve cached PageSpeed Insights results for a URL and strategy.
 *
 * Returns { status: 'not_ready' } with HTTP 202 if the background job
 * has not yet completed.
 *
 * @since 1.6.0
 * @since 2.0.0 Accepts an optional AbortSignal for request cancellation.
 * @since 2.0.0 Scan URL and strategy are validated client-side before the request.
 * @param {string}      url      The scanned URL.
 * @param {string}      strategy 'mobile' or 'desktop'.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved result data or not_ready status.
 */
export const getPagespeedResults = ( url, strategy = 'mobile', signal ) => {
	try {
		assertScanUrl( url );
		assertScanStrategy( strategy );
	} catch ( error ) {
		return Promise.reject( error );
	}
	return apiCall(
		buildAction( 'pagespeed_results', { url, strategy } ),
		{},
		'GET',
		signal
	);
};

/**
 * Retrieve the stored Web Vitals trend history.
 *
 * Optionally scopes to a URL and strategy via query params.
 *
 * @since 2.14.0
 * @since 2.0.0 Accepts an optional AbortSignal for request cancellation.
 * @since 2.0.0 Scan URL and strategy are validated client-side before the request.
 * @param {string}      url      The scanned URL.
 * @param {string}      strategy 'mobile', 'desktop' or ''.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved trends data.
 */
export const fetchWebVitalsTrends = ( url = '', strategy = '', signal ) => {
	try {
		assertScanUrl( url, true );
		assertScanStrategy( strategy, true );
	} catch ( error ) {
		return Promise.reject( error );
	}
	const qs = buildAction( 'web_vitals_trends', { url, strategy } );
	return apiCall( qs, {}, 'GET', signal );
};

/**
 * Retrieve Suggestion_Engine output for a cached telemetry scan.
 *
 * @since 1.6.0
 * @since 2.0.0 Scan URL is validated client-side before the request.
 * @param {string}      url      The scanned URL.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved suggestions array.
 */
export const fetchSuggestions = ( url, signal ) => {
	try {
		assertScanUrl( url );
	} catch ( error ) {
		return Promise.reject( error );
	}
	return apiCall( buildAction( 'suggestions', { url } ), {}, 'GET', signal );
};

/**
 * Retrieve server-level performance rules (Apache/Nginx).
 *
 * @since 1.6.0
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved server rules data.
 */
export const fetchServerRules = ( signal ) => {
	return apiCall( 'server_rules', {}, 'GET', signal );
};

/**
 * Retrieve the Safe / Balanced / Aggressive preset definitions with a diff
 * preview of each against the current settings.
 *
 * @since 2.3.0
 * @param {string}      [preset] Optional preset name to narrow the response.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved presets payload.
 */
export const fetchOptimizationPresets = ( preset = '', signal ) => {
	const action =
		preset && typeof preset === 'string'
			? buildAction( 'optimization_presets', { preset } )
			: 'optimization_presets';
	return apiCall( action, {}, 'GET', signal );
};

/**
 * Apply a Safe / Balanced / Aggressive preset in one click.
 *
 * The server snapshots a restore point after a successful overwrite; a failed apply
 * leaves the prior settings intact.
 *
 * @since 2.3.0
 * @param {string} preset Preset name (safe|balanced|aggressive).
 * @return {Promise<Object>} Resolved apply payload ({preset, settings, diff}).
 */
export const applyOptimizationPreset = ( preset ) => {
	return apiCall( 'apply_preset', { preset } );
};

/**
 * Run the verifiable WooCommerce cart/checkout cache-exclusion self-test.
 *
 * Read-only GET proving cart/checkout/account bypass the static HTML cache
 * with DONOTCACHEPAGE honored. Returns the detected Woo paths, safe-mode
 * toggle state, per-URL pass/fail entries, preload-skip probes (faceted
 * filter URLs), guest-cart survival probes, and the fail-closed
 * force_exclude recommendation.
 *
 * @since 2.0.0
 * @since 2.3.0 Added preload_checks, cart_checks and force_exclude to the result (issue #1256).
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved self-test result data.
 */
export const fetchWooCacheSelfTest = ( signal ) => {
	return apiCall( 'woo_cache_self_test', {}, 'GET', signal );
};
