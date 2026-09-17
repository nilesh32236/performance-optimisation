/**
 * Whether a URL is safe to render as an external link href (http(s) only).
 *
 * Single shared implementation (replaces the per-component copies in
 * Dashboard and FileOptimization). A tampered server-provided URL such as
 * javascript:alert(1) must never reach <a href>.
 *
 * @param {string} url Raw URL.
 * @return {boolean} True when the URL parses as http(s).
 */
export const isSafeHttpUrl = ( url ) => {
	if ( ! url || typeof url !== 'string' ) {
		return false;
	}
	try {
		// Strip ASCII control characters before parsing (same convention as
		// the esi.js / lazyload.js sanitizers): browsers ignore embedded
		// tab/newline tricks when parsing schemes, so normalize first.
		const cleaned = url.replace( /[\u0000-\u0020\u007f]/g, '' );
		const parsed = new URL( cleaned );
		return 'http:' === parsed.protocol || 'https:' === parsed.protocol;
	} catch {
		return false;
	}
};
