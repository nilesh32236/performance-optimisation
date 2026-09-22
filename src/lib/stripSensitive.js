/**
 * Shared secret-key redaction for settings JSON exports (audit #1493).
 *
 * Single home for the secret-key pattern previously duplicated between
 * PluginSetting.js (redactSecrets, mask-with-'REDACTED') and
 * OptimizationPresets.js (stripSensitiveSettings, 2-entry allowlist).
 * Both export paths now share this pattern so a newly added secret-bearing
 * key is stripped by default instead of leaking into a downloadable backup.
 *
 * @since NEXT
 */

/**
 * Key names that must never be copied during redaction. Assigning to
 * `__proto__` on a plain object mutates its prototype (prototype
 * pollution); `constructor`/`prototype` keys are the companion gadget path.
 *
 * @since NEXT
 * @param {string} key Raw object key.
 * @return {boolean} True when the key is a pollution vector.
 */
export const isSensitivePollutionKey = ( key ) =>
	key === '__proto__' || key === 'constructor' || key === 'prototype';

/**
 * Pattern matching secret-bearing setting keys redacted on export (Redis
 * password, Cloudflare/Bunny tokens, nonces, generic *key/*token/*secret/
 * password). The generic [_-]keys? suffix covers auth_key, consumer_key,
 * private_key, google_key, etc.; the separator requirement avoids matching
 * words like "monkey" that merely end in "key". The separator-optional
 * api[_-]?keys? alternative covers separator-less 'apikey'/'apiKey'
 * variants.
 *
 * @since NEXT
 */
export const SECRET_KEY_PATTERN =
	/(?:[_-]keys?|api[_-]?keys?|password|passwd|secret|api[_-]?token|auth[_-]?token|cloudflare|bunny|token|nonce)$/i;

/**
 * Deep-clone a value while deleting every nested key matching
 * SECRET_KEY_PATTERN. Non-string or empty-string secrets are deleted as
 * well so a future non-string credential shape cannot leak either.
 * Pollution-vector keys are never copied.
 *
 * @since NEXT
 * @param {*} value Value to strip.
 * @return {*} Stripped clone.
 */
export const stripSensitiveKeys = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value.map( stripSensitiveKeys );
	}
	if ( value && typeof value === 'object' ) {
		const out = {};
		Object.entries( value ).forEach( ( [ key, val ] ) => {
			if ( isSensitivePollutionKey( key ) ) {
				return;
			}
			if ( SECRET_KEY_PATTERN.test( key ) ) {
				return;
			}
			out[ key ] = stripSensitiveKeys( val );
		} );
		return out;
	}
	return value;
};
