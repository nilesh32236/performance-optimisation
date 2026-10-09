/**
 * Shared secret-redaction for log messages (audit #1401).
 *
 * Single home for the credential patterns previously duplicated between
 * the SPA (apiRequest.js) and the dependency-free frontend mirrors
 * (lazyload.js, esi.js, main.js). Imported by all four so a new secret
 * shape can never be redacted in one bundle and leak from another.
 *
 * @since 2.3.0
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
			/([?&](?:key|api[_-]?key|token|secret|password|pwd|_wpnonce|nonce|wppo_esi|nonce_refresh)\s*=)[^&\s]*/gi,
			'$1[redacted]'
		)
		.replace(
			/\b(password|passwd|pwd|secret|token|_wpnonce|nonce|wppo_esi|nonce_refresh)\b\s*[:=\s]\s*(['"]?)\S+\2/gi,
			'$1=[redacted]'
		);
};

/**
 * Maximum length of a log message produced by getErrorLogMessage().
 *
 * Shared by the SPA implementation below and the dependency-free frontend
 * mirrors (lazyload.js, esi.js, main.js); pinned behaviourally and by
 * src/__tests__/logMirrorSync.test.js.
 *
 * @since NEXT
 * @type {number}
 */
export const MAX_LOG_MESSAGE_LENGTH = 500;

/**
 * Extract a safe log message from an error without leaking response bodies.
 *
 * Server error objects can embed response payloads (system info, settings);
 * console output persists in devtools/extensions, so only the message is
 * logged, never the full error/response object.
 *
 * Lives here (next to redactLogSecrets) rather than in apiRequest.js so
 * crash-path consumers such as ErrorBoundary can import one pure,
 * dependency-light module instead of the full fetch/dedupe barrel.
 * apiRequest.js re-exports it for back-compat.
 *
 * @since 2.3.0
 * @since NEXT Moved from lib/apiRequest.js to lib/logSecrets.js (re-exported).
 * @param {*} error Caught error value.
 * @return {string} Safe message string.
 */
export const getErrorLogMessage = ( error ) => {
	let message;
	if ( error instanceof Error ) {
		message = error.message || 'Unknown error';
	} else if ( typeof error === 'string' ) {
		message = error.slice( 0, MAX_LOG_MESSAGE_LENGTH ) || 'Unknown error';
	} else if ( error === null || typeof error === 'undefined' ) {
		return 'Unknown error';
	} else {
		try {
			message = String( error ).slice( 0, MAX_LOG_MESSAGE_LENGTH );
		} catch {
			return 'Unknown error';
		}
	}
	try {
		const redacted = redactLogSecrets( message );
		return ( redacted || 'Unknown error' ).slice(
			0,
			MAX_LOG_MESSAGE_LENGTH
		);
	} catch {
		return 'Unknown error';
	}
};
