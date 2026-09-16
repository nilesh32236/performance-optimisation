import { isAuthErrorCode } from './authErrors';

export {
	AUTH_ERROR_CODES,
	AUTH_ERROR_CODE_SET,
	isAuthErrorCode,
} from './authErrors';

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
 * @since NEXT Accepts an optional dot-path with fallback (getWppoSettings('settings.cache.enabled', false)).
 * @param {string} [path]     Optional dot-separated path (e.g. 'settings.cache').
 * @param {*}      [fallback] Optional fallback returned when the global or path is absent.
 * @return {*} The global settings object (or path value), or fallback/{} when absent.
 */
export const getWppoSettings = ( path, fallback = {} ) => {
	const fallbackValue = fallback;
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return fallbackValue;
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
			return fallbackValue;
		}
		current = current[ key ];
	}
	return current === undefined ? fallbackValue : current;
};

/**
 * Extract a safe log message from an error without leaking response bodies.
 *
 * Server error objects can embed response payloads (system info, settings);
 * console output persists in devtools/extensions, so only the message is
 * logged, never the full error/response object.
 *
 * @since NEXT
 * @param {*} error Caught error value.
 * @return {string} Safe message string.
 */
export const getErrorLogMessage = ( error ) => {
	if ( error instanceof Error ) {
		return error.message || 'Unknown error';
	}
	if ( typeof error === 'string' ) {
		return error.slice( 0, 500 ) || 'Unknown error';
	}
	if ( error === null || typeof error === 'undefined' ) {
		return 'Unknown error';
	}
	try {
		return String( error ).slice( 0, 500 );
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
				wppoSettings.nonce = data.data.nonce;
				return data.data.nonce;
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
 * @since NEXT
 * @param {*} payload Resolved settings payload (typically `data.data`).
 * @return {void}
 */
export const commitSettingsCache = ( payload ) => {
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return;
	}
	if ( payload && typeof payload === 'object' ) {
		wppoSettings.settings = Object.freeze( payload );
	}
};

/**
 * Patch a single settings tab into the shared `wppoSettings.settings` cache.
 *
 * Components that save one tab optimistically merge the saved slice into the
 * live global so sibling panels see the new value without a reload. The
 * merged tab and the top-level object are both frozen like commitSettingsCache().
 *
 * @since NEXT
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
	wppoSettings.settings = Object.freeze( {
		...current,
		[ tab ]: Object.freeze( { ...( current[ tab ] || {} ), ...patch } ),
	} );
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
			throw new Error(
				`Invalid JSON response from ${ action }: ${ parseError.message }`
			);
		}

		// Detect expired nonce (rest_forbidden, rest_cookie_invalid_nonce, etc.).
		// The code list lives in ./authErrors.js; main.js/esi.js mirror it
		// (see authSync.test.js).
		if ( data.code && isAuthErrorCode( data.code ) ) {
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
 * @since NEXT
 * @type {string[]}
 */
export const SCAN_STRATEGIES = [ 'mobile', 'desktop' ];

/**
 * Build an action path with URL-encoded query params via URLSearchParams.
 *
 * Single choke point so callers cannot forget encodeURIComponent and inject
 * `&`/`#` through user-controlled values.
 *
 * @since NEXT
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
 * @since NEXT
 * @param {string}  url        Raw scan URL.
 * @param {boolean} allowEmpty Whether '' is accepted (list endpoints).
 * @return {void}
 */
export const assertScanUrl = ( url, allowEmpty = false ) => {
	if ( allowEmpty && ( url === '' || url === undefined || url === null ) ) {
		return;
	}
	if ( ! isValidScanUrl( url ) ) {
		throw new Error(
			'Invalid scan URL: must be a same-origin http(s) URL.'
		);
	}
};

/**
 * Throw when a scan strategy fails client-side validation.
 *
 * @since NEXT
 * @param {string}  strategy   Raw strategy value.
 * @param {boolean} allowEmpty Whether '' is accepted (list endpoints).
 * @return {void}
 */
export const assertScanStrategy = ( strategy, allowEmpty = false ) => {
	if ( ! isValidScanStrategy( strategy, allowEmpty ) ) {
		throw new Error(
			allowEmpty
				? "Invalid strategy: must be 'mobile', 'desktop' or ''."
				: "Invalid strategy: must be 'mobile' or 'desktop'."
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
 * Run the verifiable WooCommerce cart/checkout cache-exclusion self-test.
 *
 * Read-only GET proving cart/checkout/account bypass the static HTML cache
 * with DONOTCACHEPAGE honored. Returns the detected Woo paths, safe-mode
 * toggle state, and per-URL pass/fail entries.
 *
 * @since 2.0.0
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved self-test result data.
 */
export const fetchWooCacheSelfTest = ( signal ) => {
	return apiCall( 'woo_cache_self_test', {}, 'GET', signal );
};
