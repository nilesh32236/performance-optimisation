/**
 * Shared secret-key redaction for settings JSON exports (audit #1493).
 *
 * Single home for the secret-key pattern previously duplicated between
 * PluginSetting.js (redactSecrets, mask-with-'REDACTED') and
 * OptimizationPresets.js (stripSensitiveSettings, 2-entry allowlist).
 * Both export paths now share this pattern so a newly added secret-bearing
 * key is stripped by default instead of leaking into a downloadable backup.
 *
 * @since 2.3.0
 */

/**
 * Key names that must never be copied during redaction. Assigning to
 * `__proto__` on a plain object mutates its prototype (prototype
 * pollution); `constructor`/`prototype` keys are the companion gadget path.
 *
 * @since 2.3.0
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
 * variants, and the (private|secret|public|client|consumer)keys?
 * alternative covers separator-less camelCase '*Key' names (secretKey,
 * privateKey, clientKey) — the explicit prefix list keeps bare words like
 * "monkey" unmatched.
 *
 * @since 2.3.0
 */
export const SECRET_KEY_PATTERN =
	/(?:[_-]keys?|api[_-]?keys?|(?:private|secret|public|client|consumer)keys?|password|passwd|secret|api[_-]?token|auth[_-]?token|cloudflare|bunny|token|nonce)$/i;

/**
 * Shared walker for secret-key redaction.
 *
 * Single traversal home for the pollution guard + SECRET_KEY_PATTERN check
 * previously duplicated between PluginSetting.js (mask-with-'REDACTED') and
 * this module (delete). `mask` selects the mode: null deletes matching keys,
 * a string masks them (only non-empty string values are masked; non-string
 * secrets are still deleted so a future non-string credential cannot leak).
 *
 * @since 2.4.0
 * @param {*}           value Value to walk.
 * @param {string|null} mask  Mask string or null for delete mode.
 * @return {*} Walked clone.
 */
const walkSensitiveKeys = ( value, mask ) => {
	if ( Array.isArray( value ) ) {
		return value.map( ( item ) => walkSensitiveKeys( item, mask ) );
	}
	if ( value && typeof value === 'object' ) {
		const out = {};
		Object.entries( value ).forEach( ( [ key, val ] ) => {
			if ( isSensitivePollutionKey( key ) ) {
				return;
			}
			if ( SECRET_KEY_PATTERN.test( key ) ) {
				if (
					typeof mask === 'string' &&
					typeof val === 'string' &&
					val
				) {
					out[ key ] = mask;
					return;
				}
				return;
			}
			out[ key ] = walkSensitiveKeys( val, mask );
		} );
		return out;
	}
	return value;
};

/**
 * Deep-clone a value while deleting every nested key matching
 * SECRET_KEY_PATTERN. Non-string or empty-string secrets are deleted as
 * well so a future non-string credential shape cannot leak either.
 * Pollution-vector keys are never copied.
 *
 * @since 2.3.0
 * @param {*} value Value to strip.
 * @return {*} Stripped clone.
 */
export const stripSensitiveKeys = ( value ) => walkSensitiveKeys( value, null );

/**
 * Deep-clone a value while masking every nested non-empty string key
 * matching SECRET_KEY_PATTERN with `mask` (default 'REDACTED').
 * Non-string secrets are deleted, matching the delete-mode contract.
 * Pollution-vector keys are never copied.
 *
 * Shared with PluginSetting.js so the export-redaction path and the
 * preset-strip path share one walker and one pattern.
 *
 * @since 2.4.0
 * @param {*}      value  Value to redact.
 * @param {string} [mask] Mask string.
 * @return {*} Redacted clone.
 */
export const redactSecrets = ( value, mask = 'REDACTED' ) =>
	walkSensitiveKeys( value, mask );
