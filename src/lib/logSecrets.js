/**
 * Shared secret-redaction for log messages (audit #1401).
 *
 * Single home for the credential patterns previously duplicated between
 * the SPA (apiRequest.js) and the dependency-free frontend mirrors
 * (lazyload.js, esi.js, main.js). Imported by all four so a new secret
 * shape can never be redacted in one bundle and leak from another.
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
