/**
 * WPPO ESI placeholder hydrator — OLS AJAX fallback.
 *
 * Enterprise: <esi:include> is handled by LSWS; OLS uses <div data-wppo-esi>.
 * Hydrates via fetch(admin-ajax.php?action=wppo_esi_fragment) with
 * credentials: 'same-origin', no jQuery, requestAnimationFrame batched.
 * Intentionally bypasses SPA apiRequest — uses different auth (nonce via POST
 * body + same-origin credentials, no X-WP-Nonce REST header) and must remain
 * decoupled from the admin SPA bundle. Only the shared `@wordpress/i18n`
 * runtime package is imported for translatable error strings.
 *
 * ## Security contract
 *
 * 1. **Nonce** — the `wppo_esi` nonce is sent exactly once, in the POST body
 *    (`_wpnonce`), never in the URL query string. URLs are logged by servers,
 *    proxies and browsers; query strings also leak via `Referer` headers. The
 *    PHP handler (`LiteSpeed_ESI::handle_ajax_fragment()`) validates
 *    `$_POST['_wpnonce']` with `wp_verify_nonce()`.
 *
 * 2. **HTML sanitization** — the server MUST sanitize fragment HTML through
 *    the `wp_kses` allowlist in `LiteSpeed_ESI::get_allowed_fragment_html()`
 *    (extensible via the `wppo_esi_allowed_html` filter) before responding.
 *    As defense-in-depth, this client additionally parses the response into an
 *    inert `<template>`, removes executable elements (`script`, `iframe`,
 *    `object`, `embed`, …), event-handler (`on*`) attributes and
 *    `javascript:`/`vbscript:`/`data:`/`blob:` URLs, and inserts the result
 *    via `el.replaceChildren()` — raw `innerHTML` assignment is never used.
 *    Inline `<style>` elements and `style=""` attributes are also removed —
 *    inline styles cannot run script in modern browsers but permit CSS-based
 *    UI redress or selector-based exfiltration if a fragment source is ever
 *    compromised.
 *
 * @since 2.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Attributes whose values are URLs and must be scheme-checked before the
 * fragment is inserted into the live DOM.
 *
 * @type {Set<string>}
 */
const ESI_URL_ATTRS = new Set( [
	'href',
	'src',
	'srcset',
	'action',
	'formaction',
	'poster',
	'background',
	'xlink:href',
	'cite',
	'ping',
	'usemap',
	'longdesc',
	'dynsrc',
	'lowsrc',
] );

/**
 * Maximum fragment nodes sanitized in detail before bailing out.
 *
 * Fragments are small by contract (cart/admin-bar widgets); an oversized
 * fragment indicates tampering or a server bug, so the fragment is dropped
 * (fail-closed) to bound CPU on the hot hydration path and to avoid
 * inserting an unscrubbed fragment.
 *
 * @since 2.0.0
 * @type {number}
 */
export const MAX_ESI_NODES = 500;

/**
 * Request headers shared by every ESI fragment fetch.
 *
 * @since 2.0.0
 * @type {Object<string, string>}
 */
const ESI_REQUEST_HEADERS = {
	'Content-Type': 'application/x-www-form-urlencoded',
	'X-Requested-With': 'XMLHttpRequest',
};

/**
 * JSON payload error codes indicating an expired/invalid nonce even when the
 * HTTP status is 200. Mirrors the retry list in src/lib/apiRequest.js and
 * src/main.js (rest_forbidden / rest_cookie_invalid_nonce /
 * rest_cookie_nonce_invalid).
 *
 * @since 2.0.0
 * @type {Set<string>}
 */
const ESI_AUTH_ERROR_CODES = new Set( [
	'rest_forbidden',
	'rest_cookie_invalid_nonce',
	'rest_cookie_nonce_invalid',
] );

/**
 * In-flight nonce refresh promise (thundering-herd guard).
 *
 * Concurrent 401/403 responses share a single `nonce` block round-trip, and
 * the promise is cleared once settled so a later failure can refresh again.
 *
 * @since 2.0.0
 * @type {Promise<string>|null}
 */
let pendingNonceRefresh = null;

/**
 * AbortController owning every in-flight hydration fetch.
 *
 * Recreated after an abort so a later hydration batch still has a live signal.
 *
 * @since 2.0.0
 * @type {AbortController|null}
 */
let hydrationController = null;

/**
 * Whether a value is an AbortSignal-like object.
 *
 * Guards against being called with a DOM Event (e.g. when this module is
 * registered directly as an event listener).
 *
 * @since 2.0.0
 * @param {*} value Candidate signal.
 * @return {boolean} True when the value quacks like an AbortSignal.
 */
const isAbortSignal = ( value ) =>
	!! value &&
	'object' === typeof value &&
	'boolean' === typeof value.aborted &&
	'function' === typeof value.addEventListener;

/**
 * Return the shared hydration AbortSignal, recreating it after an abort.
 *
 * @since 2.0.0
 * @return {AbortSignal|undefined} Signal, or undefined when unsupported.
 */
const getHydrationSignal = () => {
	if ( 'undefined' === typeof AbortController ) {
		return undefined;
	}
	if ( ! hydrationController || hydrationController.signal.aborted ) {
		hydrationController = new AbortController();
	}
	return hydrationController.signal;
};

/**
 * Abort every in-flight ESI fetch (pagehide teardown).
 *
 * @since 2.0.0
 * @return {void}
 */
const abortHydration = () => {
	if ( hydrationController ) {
		hydrationController.abort();
	}
};

/**
 * Whether an error came from an aborted fetch.
 *
 * Navigation-time aborts are expected and must neither mark placeholders as
 * failed nor log a warning.
 *
 * @since 2.0.0
 * @param {*} err Thrown error.
 * @return {boolean} True when the error is an abort.
 */
const isAbortError = ( err ) => !! err && 'AbortError' === err.name;

/**
 * Client-side defense-in-depth sanitizer for ESI fragment HTML.
 *
 * The authoritative sanitization happens server-side (wp_kses allowlist — see
 * the security contract in the file header). This pass parses the markup in an
 * inert template (scripts and event handlers inside `<template>` content never
 * execute), removes script-capable elements, event-handler attributes and
 * dangerous URL schemes, and returns a DocumentFragment ready for
 * `el.replaceChildren()`.
 *
 * @since 2.0.0
 * @param {string} html Server-provided fragment HTML.
 * @return {DocumentFragment} Sanitized fragment.
 */
export const sanitizeEsiFragment = ( html ) => {
	const template = document.createElement( 'template' );
	template.innerHTML = String( html ?? '' );
	const frag = template.content;

	// Remove script-capable elements outright. Forms/inputs are kept —
	// cart and admin-bar fragments legitimately contain them. Styles are
	// removed too (server-side wp_kses is authoritative; fragments do not
	// need inline styles).
	frag.querySelectorAll(
		'script, iframe, frame, frameset, object, embed, link, meta, base, style'
	).forEach( ( node ) => node.remove() );

	const all = frag.querySelectorAll( '*' );
	if ( all.length > MAX_ESI_NODES ) {
		console.warn(
			'WPPO ESI fragment exceeds sane node count; dropping fragment',
			all.length
		);
		return document.createDocumentFragment();
	}

	all.forEach( ( node ) => {
		// Strip inline styles outright — simplest and safest since wp_kses
		// is authoritative and fragments do not need them.
		if ( node.hasAttribute( 'style' ) ) {
			node.removeAttribute( 'style' );
		}
		Array.from( node.attributes ).forEach( ( attr ) => {
			const name = ( attr.name || '' ).toLowerCase();

			// Strip inline event handlers (onclick, onerror, onbegin, …).
			if ( /^on/i.test( name ) ) {
				node.removeAttribute( attr.name );
				return;
			}

			// Strip dangerous URL schemes from URL-bearing attributes.
			if ( ESI_URL_ATTRS.has( name ) ) {
				// Browsers ignore ASCII whitespace/control characters when parsing
				// schemes ("java\tscript:", "  javascript:"), so normalise before
				// matching (0x00-0x20 covers C0 controls and space).
				const value = String( attr.value || '' )
					.toLowerCase()
					.replace( /[\u0000-\u0020]/g, '' );
				if ( /^(javascript|vbscript|data|blob):/.test( value ) ) {
					node.removeAttribute( attr.name );
				}
			}
		} );
	} );

	return frag;
};

/**
 * Build the AJAX endpoint URL for ESI fragment hydration.
 *
 * Deliberately takes no arguments and appends no query parameters — the
 * action, block and nonce all travel in the POST body (see buildEsiBody) so
 * the nonce never appears in URLs, access logs or Referer headers.
 *
 * @since 2.0.0
 * @return {string} admin-ajax.php URL.
 */
export const buildEsiUrl = () => {
	return (
		( typeof window !== 'undefined' &&
			window.wppoSettings &&
			window.wppoSettings.ajaxUrl ) ||
		( typeof window !== 'undefined' && window.ajaxurl ) ||
		'/wp-admin/admin-ajax.php'
	);
};

/**
 * Build the POST body for an ESI fragment request.
 *
 * The nonce is sent once, as `_wpnonce` (the parameter
 * `LiteSpeed_ESI::handle_ajax_fragment()` validates with wp_verify_nonce()).
 *
 * @since 2.0.0
 * @param {string} block Block name.
 * @param {string} nonce Nonce value.
 * @return {URLSearchParams} Request body.
 */
export const buildEsiBody = ( block, nonce ) => {
	const body = new URLSearchParams( {
		action: 'wppo_esi_fragment',
		block: String( block || 'cart' ),
	} );
	if ( nonce ) {
		body.set( '_wpnonce', nonce );
	}
	return body;
};

/**
 * Apply an already-sanitized fragment to a placeholder element and clear
 * placeholder attributes.
 *
 * @since 2.0.0
 * @param {HTMLElement}      el   Placeholder element.
 * @param {DocumentFragment} frag Sanitized fragment (consumed by the last target; clone for fan-out).
 * @return {void}
 */
const applyFragmentToElement = ( el, frag ) => {
	el.replaceChildren( frag );
	el.removeAttribute( 'data-wppo-esi' );
	// Do not leave the spent nonce in the markup: it is no longer
	// needed and prevents re-hydration with a stale value.
	el.removeAttribute( 'data-nonce' );
	el.removeAttribute( 'data-wppo-nonce' );
	// The placeholder announced itself as a busy live region while the
	// fragment loaded (audit #888 finding 7); the hydrated fragment
	// carries its own semantics, so drop the loading-state ARIA attrs.
	el.removeAttribute( 'role' );
	el.removeAttribute( 'aria-live' );
	el.removeAttribute( 'aria-busy' );
	el.removeAttribute( 'aria-label' );
};

/**
 * Clear the loading live-region state after a failed hydration.
 *
 * Audit #1077 finding 1: without this the placeholder keeps
 * role="status"/aria-live/aria-busy and assistive technology announces the
 * loading label forever. Mirrors the applyFragmentToElement() ARIA cleanup
 * and leaves a translated failure label in place of the loading label.
 *
 * @since 2.0.0
 * @param {HTMLElement} el Placeholder element.
 * @return {void}
 */
const markElementFailed = ( el ) => {
	el.removeAttribute( 'aria-busy' );
	el.removeAttribute( 'aria-live' );
	el.removeAttribute( 'role' );
	el.setAttribute(
		'aria-label',
		__( 'Embedded content failed to load.', 'performance-optimisation' )
	);
};

/**
 * Read the block name and nonce for a placeholder element.
 *
 * @since 2.0.0
 * @param {HTMLElement} el Placeholder element.
 * @return {{block: string, nonce: string}|null} Block info, or null when no block.
 */
const readBlockInfo = ( el ) => {
	const block = el.getAttribute( 'data-wppo-esi' );
	if ( ! block ) {
		return null;
	}
	const nonce =
		el.getAttribute( 'data-nonce' ) ||
		el.getAttribute( 'data-wppo-nonce' ) ||
		'';
	return { block, nonce };
};

/**
 * Parse a fetch response as JSON without throwing on empty/non-JSON bodies.
 *
 * @since 2.0.0
 * @param {Response} response Fetch response.
 * @return {Promise<Object|null>} Parsed payload, or null.
 */
const readJsonSafely = async ( response ) => {
	try {
		return await response.json();
	} catch {
		return null;
	}
};

/**
 * Extract fragment HTML from a wppo_esi_fragment JSON payload.
 *
 * @since 2.0.0
 * @param {Object|null} data JSON payload.
 * @return {string} Fragment HTML (empty when absent).
 */
const extractFragmentHtml = ( data ) => {
	if ( ! data ) {
		return '';
	}
	const html = data.data && data.data.html ? data.data.html : data.html || '';
	return html ? String( html ) : '';
};

/**
 * Whether a response/payload indicates a nonce/auth failure worth retrying.
 *
 * @since 2.0.0
 * @param {Response}    response Fetch response.
 * @param {Object|null} data     Parsed payload.
 * @return {boolean} True when a nonce refresh + single retry is warranted.
 */
const isAuthFailure = ( response, data ) => {
	if ( response && ( 401 === response.status || 403 === response.status ) ) {
		return true;
	}
	return !! ( data && data.code && ESI_AUTH_ERROR_CODES.has( data.code ) );
};

/**
 * Fetch a fresh `wppo_esi` nonce via the public `nonce` ESI block.
 *
 * The `nonce` block is exempt from nonce verification server-side (see
 * LiteSpeed_ESI::handle_ajax_fragment()) and returns a freshly minted nonce as
 * its fragment. Concurrent callers share one in-flight request.
 *
 * @since 2.0.0
 * @param {AbortSignal|undefined} signal Optional abort signal.
 * @return {Promise<string>} Fresh nonce, or empty string on failure.
 */
const refreshEsiNonce = ( signal ) => {
	if ( pendingNonceRefresh ) {
		return pendingNonceRefresh;
	}
	const refresh = fetch( buildEsiUrl(), {
		method: 'POST',
		credentials: 'same-origin',
		headers: ESI_REQUEST_HEADERS,
		body: buildEsiBody( 'nonce', '' ),
		signal,
	} )
		.then( ( response ) => ( response.ok ? response.json() : null ) )
		.then( ( data ) => extractFragmentHtml( data ) )
		.catch( () => '' );

	pendingNonceRefresh = refresh.finally( () => {
		pendingNonceRefresh = null;
	} );
	return pendingNonceRefresh;
};

/**
 * Request a single ESI fragment, refreshing the nonce once on 401/403.
 *
 * Mirrors the src/main.js 403 nonce-refresh pattern: one refresh + one retry,
 * then surface the failure. Aborts (navigation) propagate to the caller.
 *
 * @since 2.0.0
 * @param {string}                block  Block name.
 * @param {string}                nonce  Placeholder nonce.
 * @param {AbortSignal|undefined} signal Optional abort signal.
 * @return {Promise<string>} Sanitized-by-server fragment HTML.
 * @throws {Error} When the request fails after the retry.
 */
const requestEsiFragment = async ( block, nonce, signal ) => {
	const send = ( token ) =>
		fetch( buildEsiUrl(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: ESI_REQUEST_HEADERS,
			body: buildEsiBody( block, token ),
			signal,
		} );

	let response = await send( nonce );
	let data = await readJsonSafely( response );

	if ( isAuthFailure( response, data ) ) {
		const freshNonce = await refreshEsiNonce( signal );
		if ( freshNonce ) {
			response = await send( freshNonce );
			data = await readJsonSafely( response );
		}
	}

	if ( ! response.ok ) {
		throw new Error(
			`ESI fragment request failed (HTTP ${ response.status })`
		);
	}

	return extractFragmentHtml( data );
};

/**
 * Hydrate a single placeholder element.
 *
 * @since 2.0.0
 * @param {HTMLElement}           el     Placeholder element.
 * @param {AbortSignal|undefined} signal Optional abort signal.
 * @return {Promise<void>}
 */
export const hydrateElement = async ( el, signal ) => {
	const info = readBlockInfo( el );
	if ( ! info ) {
		return;
	}
	const { block, nonce } = info;
	const activeSignal = isAbortSignal( signal )
		? signal
		: getHydrationSignal();
	try {
		const html = await requestEsiFragment( block, nonce, activeSignal );
		if ( ! html ) {
			markElementFailed( el );
			return;
		}
		// Server-sanitized per the wp_kses contract (file header); this
		// client-side pass is defense-in-depth before DOM insertion.
		applyFragmentToElement( el, sanitizeEsiFragment( html ) );
	} catch ( err ) {
		if ( isAbortError( err ) ) {
			return;
		}
		console.warn(
			__(
				'WPPO ESI hydration failed; embedded content could not be loaded.',
				'performance-optimisation'
			),
			err
		);
		markElementFailed( el );
	}
};

/**
 * Hydrate all placeholders on the page, batched via requestAnimationFrame.
 *
 * Placeholders sharing the same block + nonce share a single in-flight
 * fetch; the sanitized fragment is fanned out (cloned per extra element)
 * so N identical blocks produce one network request.
 *
 * @since 2.0.0
 * @param {AbortSignal|undefined} signal Optional abort signal.
 * @return {void}
 */
export const hydrateESIPlaceholders = ( signal ) => {
	const els = document.querySelectorAll( '[data-wppo-esi]' );
	if ( ! els.length ) {
		return;
	}
	const activeSignal = isAbortSignal( signal )
		? signal
		: getHydrationSignal();
	const run = () => {
		const groups = new Map();
		els.forEach( ( el ) => {
			const info = readBlockInfo( el );
			if ( ! info ) {
				return;
			}
			const key = `${ info.block }\0${ info.nonce }`;
			if ( ! groups.has( key ) ) {
				groups.set( key, { ...info, targets: [] } );
			}
			groups.get( key ).targets.push( el );
		} );
		Promise.all(
			[ ...groups.values() ].map( async ( { block, nonce, targets } ) => {
				try {
					const html = await requestEsiFragment(
						block,
						nonce,
						activeSignal
					);
					if ( ! html ) {
						targets.forEach( ( el ) => markElementFailed( el ) );
						return;
					}
					const frag = sanitizeEsiFragment( html );
					targets.forEach( ( el, index ) => {
						// Clone before consuming: replaceChildren() moves
						// children out of frag, so every target but the last
						// gets a clone and the last consumes the original.
						const piece =
							index === targets.length - 1
								? frag
								: frag.cloneNode( true );
						applyFragmentToElement( el, piece );
					} );
				} catch ( err ) {
					if ( isAbortError( err ) ) {
						return;
					}
					console.warn(
						__(
							'WPPO ESI hydration failed; embedded content could not be loaded.',
							'performance-optimisation'
						),
						err
					);
					targets.forEach( ( el ) => markElementFailed( el ) );
				}
			} )
		).catch( ( err ) => {
			console.warn(
				__(
					'WPPO ESI hydration failed; embedded content could not be loaded.',
					'performance-optimisation'
				),
				err
			);
		} );
	};
	if ( typeof window !== 'undefined' && 'requestAnimationFrame' in window ) {
		window.requestAnimationFrame( run );
	} else {
		run();
	}
};

// Abort pending hydration fetches when the page is being unloaded so a
// navigation never leaves in-flight requests pinning the event loop.
if (
	typeof window !== 'undefined' &&
	'function' === typeof window.addEventListener
) {
	window.addEventListener( 'pagehide', abortHydration );
}

// Auto-hydrate on DOMContentLoaded.
if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () =>
			hydrateESIPlaceholders()
		);
	} else {
		hydrateESIPlaceholders();
	}
}

export default hydrateESIPlaceholders;
