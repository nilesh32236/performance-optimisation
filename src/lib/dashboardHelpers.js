/**
 * Pure Dashboard helpers extracted for unit testing and reuse.
 *
 * Previously inlined in src/components/Dashboard.js (god-component concern);
 * moving them here keeps Dashboard as composition while preserving the exact
 * validation semantics.
 *
 * @since NEXT
 */

/**
 * Allowed CDN purge services (client-side allowlist; server allowlists too).
 *
 * @since NEXT
 * @type {string[]}
 */
export const CDN_PURGE_SERVICES = [ 'none', 'cloudflare', 'varnish' ];

/**
 * Cloudflare Zone IDs are 32-char hex.
 *
 * @since NEXT
 * @type {RegExp}
 */
export const CLOUDFLARE_ZONE_RE = /^[a-f0-9]{32}$/i;

/**
 * Maximum Varnish purge endpoints accepted client-side (server caps too).
 *
 * @since NEXT
 * @type {number}
 */
export const MAX_VARNISH_PURGE_URLS = 10;

/**
 * Maximum length of a single Varnish purge URL.
 *
 * @since NEXT
 * @type {number}
 */
export const MAX_VARNISH_PURGE_URL_LENGTH = 2048;

/**
 * Coerce a TTL override select value to a non-negative integer, or undefined
 * when the override should be omitted. Guards against tampered non-numeric,
 * boolean or fractional values: Number('abc') is NaN and JSON.stringify(NaN)
 * becomes null, which the server could misread as an explicit clear.
 *
 * @since NEXT
 * @param {*} value Raw select value ('' | number | string | null | undefined).
 * @return {number|undefined} Non-negative integer, or undefined to omit.
 */
export const toTtlOverride = ( value ) => {
	if ( '' === value || null === value || undefined === value ) {
		return undefined;
	}
	if ( typeof value === 'boolean' ) {
		return undefined;
	}
	if ( typeof value === 'string' && '' === value.trim() ) {
		return undefined;
	}
	const n = Number( value );
	if ( ! Number.isInteger( n ) || n < 0 ) {
		return undefined;
	}
	return n;
};

/**
 * Normalize a Cloudflare Zone ID (client-side only; server treats it as an
 * opaque scalar). Returns '' when the value is not 32-char hex.
 *
 * @since NEXT
 * @param {*} raw Raw input value.
 * @return {string} Normalized zone ID or ''.
 */
export const normalizeCloudflareZoneId = ( raw ) => {
	const zone = String( raw ?? '' )
		.trim()
		.toLowerCase();
	return CLOUDFLARE_ZONE_RE.test( zone ) ? zone : '';
};

/**
 * Parse the Varnish purge-endpoint textarea into validated http(s) URLs.
 * Each URL later receives a server-side PURGE request, so bound the count,
 * dedupe, reject userinfo and cap length as defense-in-depth (server
 * allowlisting remains authoritative).
 *
 * Note: private/loopback hosts are intentionally allowed — correct for
 * Varnish topology — behind the manage_options capability gate (admin-only
 * SSRF by design).
 *
 * @since NEXT
 * @param {string} raw Raw textarea value (URLs separated by newline, comma, semicolon or whitespace).
 * @return {string[]} Validated http(s) URLs (max 10, deduped).
 */
export const parseVarnishPurgeUrls = ( raw ) => {
	if ( typeof raw !== 'string' || '' === raw.trim() ) {
		return [];
	}
	const seen = new Set();
	const out = [];
	for ( const token of raw.split( /[\n,;\s]+/ ) ) {
		const url = token.trim();
		if ( ! url || url.length > MAX_VARNISH_PURGE_URL_LENGTH ) {
			continue;
		}
		let parsed;
		try {
			parsed = new URL( url );
		} catch {
			continue;
		}
		if ( 'http:' !== parsed.protocol && 'https:' !== parsed.protocol ) {
			continue;
		}
		if ( parsed.username || parsed.password ) {
			continue;
		}
		if ( seen.has( url ) ) {
			continue;
		}
		seen.add( url );
		out.push( url );
		if ( out.length >= MAX_VARNISH_PURGE_URLS ) {
			break;
		}
	}
	return out;
};

/**
 * Normalize wppoSettings.image_info which stores arrays of file paths
 * into the {webp: count, avif: count} shape the component expects.
 * Numeric strings are coerced so later (completed+pending) arithmetic never
 * concatenates ('5'+'0'='50').
 *
 * @since NEXT
 * @param {Object} raw Raw image info object.
 * @return {Object} Normalized {completed, pending, failed} counts.
 */
export const normalizeImageInfo = ( raw ) => {
	const normalize = ( bucket ) => ( {
		webp: Array.isArray( bucket?.webp )
			? bucket.webp.length
			: Number( bucket?.webp ) || 0,
		avif: Array.isArray( bucket?.avif )
			? bucket.avif.length
			: Number( bucket?.avif ) || 0,
	} );
	return {
		completed: normalize( raw?.completed ),
		pending: normalize( raw?.pending ),
		failed: normalize( raw?.failed ),
	};
};

/**
 * Compare two normalized image-info objects for equality (poll guard).
 *
 * @since NEXT
 * @param {Object} a First normalized image info.
 * @param {Object} b Second normalized image info.
 * @return {boolean} True when all buckets match.
 */
export const isEqualImageInfo = ( a, b ) => {
	for ( const key of [ 'completed', 'pending', 'failed' ] ) {
		if (
			a?.[ key ]?.webp !== b?.[ key ]?.webp ||
			a?.[ key ]?.avif !== b?.[ key ]?.avif
		) {
			return false;
		}
	}
	return true;
};
