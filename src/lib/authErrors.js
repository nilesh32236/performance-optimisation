/**
 * Shared auth-error contract for the standalone frontend bundles.
 *
 * `src/lib/apiRequest.js` (SPA), `src/main.js` (admin bar) and `src/esi.js`
 * (ESI hydrator) are intentionally standalone runtime bundles — they must not
 * import each other — but they must agree on which server payload codes mean
 * "nonce expired, refresh once and retry". This module is the single source
 * of truth for the SPA import graph; `main.js`/`esi.js` keep local copies for
 * bundle independence and `src/lib/__tests__/authSync.test.js` asserts the
 * three sets stay in sync.
 *
 * @since NEXT
 */

/**
 * Payload codes indicating an expired/invalid nonce even on HTTP 200.
 *
 * @since NEXT
 * @type {string[]}
 */
export const AUTH_ERROR_CODES = Object.freeze( [
	'rest_forbidden',
	'rest_cookie_invalid_nonce',
	'rest_cookie_nonce_invalid',
] );

/**
 * Live set backing the read-only AUTH_ERROR_CODE_SET view below.
 *
 * @since NEXT
 * @type {Set<string>}
 */
const authErrorCodeSet = new Set( AUTH_ERROR_CODES );

/**
 * Read-only view of AUTH_ERROR_CODES for O(1) lookup.
 *
 * Exposed as a frozen wrapper instead of the live Set so importers cannot
 * add/delete codes and silently break the nonce-refresh contract the sync
 * test guards (audit #1354). Supports the `has`/`size` members lookup
 * callers need; use isAuthErrorCode() for membership tests.
 *
 * @since NEXT
 * @type {{has: Function, size: number}}
 */
export const AUTH_ERROR_CODE_SET = Object.freeze( {
	has: ( code ) => authErrorCodeSet.has( code ),
	get size() {
		return authErrorCodeSet.size;
	},
} );

/**
 * Whether a server payload code signals an auth/nonce failure.
 *
 * @since NEXT
 * @param {*} code Payload `code` value.
 * @return {boolean} True when the code means "refresh nonce and retry once".
 */
export const isAuthErrorCode = ( code ) =>
	'string' === typeof code && authErrorCodeSet.has( code );
