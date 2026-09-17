/**
 * Shared safe log-message helpers (audit maintainability).
 *
 * Single dependency-free implementation for extracting a safe log message
 * without leaking response bodies or credentials. The SPA imports it via
 * `lib/apiRequest.js` (re-exported as `getErrorLogMessage`); the standalone
 * frontend bundles (`lazyload.js`, `esi.js`, `main.js`) cannot import the
 * SPA bundle, so they carry a dependency-free mirror — the mirror contract
 * (message-only, redact-then-truncate, 500-char cap, never full objects) is
 * pinned by `src/__tests__/logMirrorSync.test.js`, which must be updated
 * alongside this file.
 *
 * @since NEXT
 */

/**
 * Redact secret-looking substrings from a log message (defense-in-depth).
 *
 * Server messages are trusted, but a message that ever embeds a credential
 * (Redis AUTH, PageSpeed API key) would otherwise persist verbatim in
 * devtools. Redaction runs before truncation.
 *
 * @since NEXT
 * @param {string} raw Raw message.
 * @return {string} Redacted message.
 */
export const redactLogSecrets = ( raw ) => {
	if ( typeof raw !== 'string' || '' === raw ) {
		return raw;
	}
	return raw
		.replace( /AIza[0-9A-Za-z\-_]{10,}/g, '[redacted-key]' )
		.replace(
			/(api[_-]?key|auth[_-]?token)\s*[:=]\s*\S+/gi,
			'$1=[redacted]'
		)
		.replace( /\b(bearer)\s+([A-Za-z0-9\-._~+/=]{8,})/gi, '$1=[redacted]' )
		.replace(
			/([?&](?:key|api[_-]?key|token|secret|password|pwd)\s*=)[^&\s]*/gi,
			'$1[redacted]'
		)
		.replace(
			/\b(password|passwd|pwd|secret|token)\b\s*[:=\s]\s*(['"]?)\S+\2/gi,
			'$1=[redacted]'
		);
};

/**
 * Extract a safe log message from an error without leaking response bodies.
 *
 * Server error objects can embed response payloads (system info, settings);
 * console output persists in devtools/extensions, so only the message is
 * logged, never the full error/response object.
 *
 * @since NEXT
 * @param {*} error Caught error value.
 * @return {string} Safe message string.
 */
export const getLogMessage = ( error ) => {
	let message;
	if ( error instanceof Error ) {
		message = error.message || 'Unknown error';
	} else if ( typeof error === 'string' ) {
		message = error.slice( 0, 500 ) || 'Unknown error';
	} else if ( error === null || typeof error === 'undefined' ) {
		return 'Unknown error';
	} else {
		try {
			message = String( error ).slice( 0, 500 );
		} catch {
			return 'Unknown error';
		}
	}
	try {
		const redacted = redactLogSecrets( message );
		return ( redacted || 'Unknown error' ).slice( 0, 500 );
	} catch {
		return 'Unknown error';
	}
};
