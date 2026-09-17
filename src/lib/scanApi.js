/**
 * Scan endpoint wrappers (audit maintainability).
 *
 * Split out of `lib/apiRequest.js`, which mixed transport + nonce retry,
 * settings-cache mutation, scan validation, and endpoint wrappers in one
 * 660-line module. `apiRequest.js` re-exports everything here so existing
 * imports keep working.
 *
 * @since NEXT
 */

import { apiCall } from './apiClient';
import {
	assertScanStrategy,
	assertScanUrl,
	buildAction,
} from './scanValidation';

/**
 * Fetch paginated recent activity log entries.
 *
 * The page number is coerced to a finite positive integer and URL-encoded so
 * caller-supplied values cannot inject additional query parameters (e.g. `&`
 * or `#` from user-controlled input).
 *
 * @since 1.0.0
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
 * toggle state, per-URL pass/fail entries, preload-skip probes (faceted
 * filter URLs), guest-cart survival probes, and the fail-closed
 * force_exclude recommendation.
 *
 * @since 2.0.0
 * @since NEXT Added preload_checks, cart_checks and force_exclude to the result (issue #1256).
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved self-test result data.
 */
export const fetchWooCacheSelfTest = ( signal ) => {
	return apiCall( 'woo_cache_self_test', {}, 'GET', signal );
};
