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
	return (
		raw
			.slice( 0, REDACT_SCAN_LIMIT )
			.replace( /AIza[0-9A-Za-z\-_]{10,}/g, '[redacted-key]' )
			.replace(
				/(api[_-]?key|auth[_-]?token)\s*[:=]\s*\S+/gi,
				'$1=[redacted]'
			)
			// Audit #1831: scheme rules run before the generic Authorization
			// rule — that pattern stops at whitespace, so redacting the scheme
			// first keeps "Authorization: Bearer/Basic <cred>" from leaving
			// <cred> behind.
			.replace(
				/\b(bearer)\s+([A-Za-z0-9\-._~+/=]{8,})/gi,
				'$1=[redacted]'
			)
			// The value class excludes spaces so the run stops at the
			// credential. Real base64 credentials mix case or carry
			// digits/symbols, so an all-lowercase token is treated as prose
			// ("Basic authentication failed") and left intact; when it sits
			// in an Authorization header the next rule still redacts it.
			.replace(
				/\b(basic)\s+([A-Za-z0-9\-._~+/=]{8,})/gi,
				( match, scheme, value ) =>
					/^[a-z]+$/.test( value ) ? match : scheme + '=[redacted]'
			)
			// Digest headers carry quoted key=value params
			// (username="..", response="..") that the single-token Basic shape
			// cannot cover; the required '=' keeps plain prose such as "Digest
			// authentication failed" untouched. The optional [:=] separator
			// also accepts the "Digest: username=..." header form, which a
			// plain \s+ boundary could not cross.
			.replace(
				/\b(digest)\s*[:=]?\s+[A-Za-z0-9_]+=(?:"[^"]*"|'[^']*'|[^\s,;]+)(?:\s*,\s*[A-Za-z0-9_]+=(?:"[^"]*"|'[^']*'|[^\s,;]+))*/gi,
				'$1=[redacted]'
			)
			// Fail-closed: consume the whole header value, not just the first
			// whitespace token, so a short credential ("Authorization: Basic
			// YTpi") or one with an unusual character cannot survive. The
			// (^|[^?&]) guard keeps query params named authorization
			// (?authorization=/&authorization=) for the query rule below —
			// without it "[^\r\n]*" would swallow the rest of the line.
			.replace(
				/(^|[^?&])(authorization)\s*[:=][^\r\n]*/gi,
				'$1$2=[redacted]'
			)
			// Audit #1831: Cookie/Set-Cookie headers carry session identifiers.
			// The value is a run of adjacent atoms (a bare run, or a quoted
			// string) so the legal RFC 6265 quoted form
			// "session=\"s3cr3t\"; Path=/" is consumed whole instead of
			// truncating at the opening quote and leaving the secret behind.
			// The trailing (; atom)* consumes the "a=b; c=d" attribute pairs
			// rather than letting \S+ swallow the ';'. The (^|[^?&]) guard
			// keeps query strings (?cookie=/&cookie=) for the query rule below
			// — without it the inserted space breaks the query value match and
			// "[redacted]" is duplicated. Expressed as a capture group (not a
			// lookbehind) so engines without lookbehind support still parse
			// this bundle.
			.replace(
				/(^|[^?&])((?:\bcookie\b|\bset-cookie\b)\s*[:=])\s*(?:[^;\s'"]+|"[^"]*"|'[^']*')+(?:\s*;\s*(?:[^;\s'"]+|"[^"]*"|'[^']*')+)*/gi,
				'$1$2 [redacted]'
			)
			.replace(
				/([?&](?:key|api[_-]?key|token|auth[_-]?token|access_token|refresh_token|id_token|csrf_token|xsrf_token|secret|password|passwd|pwd|authorization|cookie|session|client_secret|private_key|_wpnonce|nonce|wppo_esi|nonce_refresh)\s*=)[^&\s]*/gi,
				'$1[redacted]'
			)
			// Audit #1831: (^|[^\w]|_) leading boundary — \b does not split on
			// '_', so client_secret/access_token/private_key never matched.
			// The explicit '_' alternative also lets this rule reach compound
			// *_token keys the query alternatives do not enumerate
			// (refresh_token/id_token/...). Trailing \b is kept (all
			// alternatives end in a word char). The (?!\s*\[redacted\]) guard
			// keeps this rule from mangling values an earlier rule already
			// masked ("Cookie: [redacted]").
			.replace(
				// Audit #1831 review: bound the input here rather than relying on every
				// caller pre-truncating — this is a shared export imported by four
				// independent bundles, so the bound belongs to the function, and a
				// future caller passing an untruncated server response body would
				// otherwise pay the full ~9-pass scan and re-expose the
				// nested-quantifier Cookie rule to pathological input.
				//
				// The scan window is REDACT_SCAN_LIMIT (8x the published cap), NOT
				// MAX_LOG_MESSAGE_LENGTH: scanning only the first 500 chars would let a
				// credential straddling that boundary be matched partially and logged
				// with its prefix intact. Scanning a prefix that is far wider than the
				// output then slicing the result means any credential inside the
				// published window is matched whole and redacted.
				/(^|[^\w]|_)(password|passwd|pwd|secret|token|api[_-]?key|auth[_-]?token|authorization|cookie|session|sessionid|client_secret|access_token|private_key|_wpnonce|nonce|wppo_esi|nonce_refresh)\b\s*[:=\s]\s*(?!\s*\[redacted\])(['"]?)\S+\3/gi,
				'$1$2=[redacted]'
			)
			.slice( 0, MAX_LOG_MESSAGE_LENGTH )
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
 * Maximum input length scanned by redactLogSecrets().
 *
 * Deliberately wider than MAX_LOG_MESSAGE_LENGTH so the redaction chain sees
 * a whole credential that straddles the published 500-char boundary — the
 * result is sliced back to MAX_LOG_MESSAGE_LENGTH after redaction. Bounding
 * the scan still keeps the ~9-pass chain (and the nested-quantifier Cookie
 * rule in particular) away from pathological input.
 *
 * @since NEXT
 * @type {number}
 */
export const REDACT_SCAN_LIMIT = MAX_LOG_MESSAGE_LENGTH * 8;

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
		message = ( error.message || 'Unknown error' ).slice(
			0,
			MAX_LOG_MESSAGE_LENGTH
		);
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
