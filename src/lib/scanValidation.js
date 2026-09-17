/**
 * Client-side scan validation (audit maintainability).
 *
 * Split out of `lib/apiRequest.js`, which mixed transport + nonce retry,
 * settings-cache mutation, scan validation, and endpoint wrappers in one
 * 660-line module. `apiRequest.js` re-exports everything here so existing
 * imports keep working. Server-side allowlisting remains authoritative for
 * every check below.
 *
 * @since NEXT
 */

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
	if (
		allowEmpty &&
		( strategy === '' || strategy === undefined || strategy === null )
	) {
		return true;
	}
	if ( typeof strategy !== 'string' ) {
		return false;
	}
	return SCAN_STRATEGIES.includes( strategy );
};
