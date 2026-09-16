/**
 * Shared auth-error contract for the standalone frontend bundles.
 *
 * `src/lib/apiRequest.js` (SPA) and `src/main.js` (admin bar) are
 * intentionally standalone runtime bundles — they must not import each
 * other — but they must agree on which server payload codes mean
 * "nonce expired, refresh once and retry". This module is the single source
 * of truth for the SPA import graph; `main.js` keeps a local copy for
 * bundle independence and `src/lib/__tests__/authSync.test.js` asserts the
 * sets stay in sync.
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
 * Set view of AUTH_ERROR_CODES for O(1) lookup.
 *
 * @since NEXT
 * @type {Set<string>}
 */
export const AUTH_ERROR_CODE_SET = new Set( AUTH_ERROR_CODES );

/**
 * Whether a server payload code signals an auth/nonce failure.
 *
 * @since NEXT
 * @param {*} code Payload `code` value.
 * @return {boolean} True when the code means "refresh nonce and retry once".
 */
export const isAuthErrorCode = ( code ) =>
	'string' === typeof code && AUTH_ERROR_CODE_SET.has( code );
