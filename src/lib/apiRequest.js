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
 * @since NEXT
 * @return {Object} The global settings object, or an empty object when absent.
 */
export const getWppoSettings = () => {
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return {};
	}
	return wppoSettings;
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
			console.error( 'Nonce refresh failed:', e );
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
 * Mutates wppoSettings.settings globally on successful `update_settings` calls.
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
		if (
			data.code &&
			( data.code === 'rest_forbidden' ||
				data.code === 'rest_cookie_invalid_nonce' ||
				data.code === 'rest_cookie_nonce_invalid' )
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
		if ( 'update_settings' === action && data.success && data.data ) {
			wppoSettings.settings = Object.freeze( data.data );
		}
		return data;
	};

	try {
		const response = await doFetch( null );
		return await handleResponse( response );
	} catch ( error ) {
		console.error( 'API call failed:', action, error );
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
 * @since NEXT Page is validated as a positive integer and URL-encoded.
 * @param {number}      page     Page number (defaults to 1).
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved activities data.
 */
export const fetchRecentActivities = ( page = 1, signal ) => {
	const safePage = Math.max( 1, Number.parseInt( page, 10 ) || 1 );
	return apiCall(
		`recent_activities?page=${ encodeURIComponent( safePage ) }`,
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
 * @since NEXT
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
 * Validate a PageSpeed scan strategy client-side before it reaches the
 * resource-intensive pagespeed_scan / pagespeed_results / web_vitals_trends
 * endpoints. Server-side allowlisting remains authoritative.
 *
 * @since NEXT
 * @param {string}  strategy   Raw strategy value.
 * @param {boolean} allowEmpty Whether '' is accepted (list endpoints).
 * @return {boolean} True when the strategy is safe to forward to the server.
 */
export const isValidScanStrategy = ( strategy, allowEmpty = false ) => {
	if ( allowEmpty && '' === strategy ) {
		return true;
	}
	return 'mobile' === strategy || 'desktop' === strategy;
};

/**
 * Run a local telemetry scan on the given URL.
 *
 * @since 1.5.0
 * @since NEXT Scan URL is validated client-side (http(s), same-origin) before the request.
 * @param {string}      url      The URL to scan.
 * @param {boolean}     force    Whether to force the scan.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved scan result data.
 */
export const runPerformanceScan = ( url, force = false, signal ) => {
	if ( ! isValidScanUrl( url ) ) {
		return Promise.reject(
			new Error( 'Invalid scan URL: must be a same-origin http(s) URL.' )
		);
	}
	return apiCall( 'performance_scan', { url, force }, 'POST', signal );
};

/**
 * Fetch system information (PHP, DB, WordPress, server, cache).
 *
 * @since 1.5.0
 * @return {Promise<Object>} Resolved system info data.
 */
export const fetchSystemInfo = () => {
	return apiCall( 'system_info', {}, 'GET' );
};

/**
 * Queue a Google PageSpeed Insights scan as a background job.
 *
 * @since 1.6.0
 * @since NEXT Scan URL and strategy are validated client-side before the request.
 * @param {string} url      The URL to scan.
 * @param {string} strategy 'mobile' or 'desktop'.
 * @return {Promise<Object>} Resolved response with job_id.
 */
export const queuePagespeedScan = ( url, strategy = 'mobile' ) => {
	if ( ! isValidScanUrl( url ) ) {
		return Promise.reject(
			new Error( 'Invalid scan URL: must be a same-origin http(s) URL.' )
		);
	}
	if ( ! isValidScanStrategy( strategy ) ) {
		return Promise.reject(
			new Error( "Invalid strategy: must be 'mobile' or 'desktop'." )
		);
	}
	return apiCall( 'pagespeed_scan', { url, strategy } );
};

/**
 * Retrieve cached PageSpeed Insights results for a URL and strategy.
 *
 * Returns { status: 'not_ready' } with HTTP 202 if the background job
 * has not yet completed.
 *
 * @since 1.6.0
 * @since NEXT Accepts an optional AbortSignal for request cancellation.
 * @since NEXT Scan URL and strategy are validated client-side before the request.
 * @param {string}      url      The scanned URL.
 * @param {string}      strategy 'mobile' or 'desktop'.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved result data or not_ready status.
 */
export const getPagespeedResults = ( url, strategy = 'mobile', signal ) => {
	if ( ! isValidScanUrl( url ) ) {
		return Promise.reject(
			new Error( 'Invalid scan URL: must be a same-origin http(s) URL.' )
		);
	}
	if ( ! isValidScanStrategy( strategy ) ) {
		return Promise.reject(
			new Error( "Invalid strategy: must be 'mobile' or 'desktop'." )
		);
	}
	return apiCall(
		`pagespeed_results?url=${ encodeURIComponent(
			url
		) }&strategy=${ encodeURIComponent( strategy ) }`,
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
 * @since NEXT Accepts an optional AbortSignal for request cancellation.
 * @since NEXT Scan URL and strategy are validated client-side before the request.
 * @param {string}      url      The scanned URL.
 * @param {string}      strategy 'mobile', 'desktop' or ''.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved trends data.
 */
export const fetchWebVitalsTrends = ( url = '', strategy = '', signal ) => {
	if ( url && ! isValidScanUrl( url ) ) {
		return Promise.reject(
			new Error( 'Invalid scan URL: must be a same-origin http(s) URL.' )
		);
	}
	if ( ! isValidScanStrategy( strategy, true ) ) {
		return Promise.reject(
			new Error( "Invalid strategy: must be 'mobile', 'desktop' or ''." )
		);
	}
	const params = new URLSearchParams();
	if ( url ) {
		params.set( 'url', url );
	}
	if ( strategy ) {
		params.set( 'strategy', strategy );
	}
	const qs = params.toString();
	return apiCall(
		`web_vitals_trends${ qs ? `?${ qs }` : '' }`,
		{},
		'GET',
		signal
	);
};

/**
 * Retrieve Suggestion_Engine output for a cached telemetry scan.
 *
 * @since 1.6.0
 * @since NEXT Scan URL is validated client-side before the request.
 * @param {string}      url      The scanned URL.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved suggestions array.
 */
export const fetchSuggestions = ( url, signal ) => {
	if ( ! isValidScanUrl( url ) ) {
		return Promise.reject(
			new Error( 'Invalid scan URL: must be a same-origin http(s) URL.' )
		);
	}
	return apiCall(
		`suggestions?url=${ encodeURIComponent( url ) }`,
		{},
		'GET',
		signal
	);
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
