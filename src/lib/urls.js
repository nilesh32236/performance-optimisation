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
		const parsed = new URL( url );
		return 'http:' === parsed.protocol || 'https:' === parsed.protocol;
	} catch {
		return false;
	}
};
