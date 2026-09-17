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
 * URL that is same-origin with the site. Server-side host allowlisting +
 * per-user/IP rate limiting remains authoritative.
 *
 * Fails closed when wppoSettings.homeUrl is absent: without a known site
 * origin there is nothing to compare against, and failing open would let a
 * tampered-or-missing homeUrl forward off-origin URLs to expensive endpoints
 * before the server rejects them. PHP always localises homeUrl in the admin
 * SPA, so the closed gate only bites harnesses that must set homeUrl
 * themselves (see the apiRequest/FileOptimization test setups).
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
			typeof wppoSettings === 'undefined' ||
			! wppoSettings ||
			! wppoSettings.homeUrl
		) {
			return false;
		}
		const home = new URL( wppoSettings.homeUrl );
		if ( parsed.origin !== home.origin ) {
			return false;
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
	// Fail fast on non-object params: Object.entries() on a string/number
	// would iterate characters/indices into junk query keys. Arrays are
	// rejected too (typeof [] === 'object') so they cannot emit numeric keys.
	if ( ! params || typeof params !== 'object' || Array.isArray( params ) ) {
		return action;
	}
	const search = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( params ) ) {
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
