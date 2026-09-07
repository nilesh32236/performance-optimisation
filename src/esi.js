/**
 * WPPO ESI placeholder hydrator — OLS AJAX fallback.
 *
 * Enterprise: <esi:include> is handled by LSWS; OLS uses <div data-wppo-esi>.
 * Hydrates via fetch(admin-ajax.php?action=wppo_esi_fragment) with
 * credentials: 'same-origin', no jQuery, requestAnimationFrame batched.
 * Intentionally bypasses SPA apiRequest — uses different auth (nonce via POST
 * body + same-origin credentials, no X-WP-Nonce REST header) and must remain
 * decoupled from the admin SPA bundle.
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
 *
 * @since NEXT
 */

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
 * Client-side defense-in-depth sanitizer for ESI fragment HTML.
 *
 * The authoritative sanitization happens server-side (wp_kses allowlist — see
 * the security contract in the file header). This pass parses the markup in an
 * inert template (scripts and event handlers inside `<template>` content never
 * execute), removes script-capable elements, event-handler attributes and
 * dangerous URL schemes, and returns a DocumentFragment ready for
 * `el.replaceChildren()`.
 *
 * @since NEXT
 * @param {string} html Server-provided fragment HTML.
 * @return {DocumentFragment} Sanitized fragment.
 */
export const sanitizeEsiFragment = ( html ) => {
	const template = document.createElement( 'template' );
	template.innerHTML = String( html ?? '' );
	const frag = template.content;

	// Remove script-capable elements outright. Forms/inputs are kept —
	// cart and admin-bar fragments legitimately contain them.
	frag.querySelectorAll(
		'script, iframe, frame, frameset, object, embed, link, meta, base'
	).forEach( ( node ) => node.remove() );

	frag.querySelectorAll( '*' ).forEach( ( node ) => {
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
 * @since NEXT
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
 * @since NEXT
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
 * Hydrate a single placeholder element.
 *
 * @param {HTMLElement} el Placeholder element.
 * @return {Promise<void>}
 */
export const hydrateElement = async ( el ) => {
	const block = el.getAttribute( 'data-wppo-esi' );
	if ( ! block ) {
		return;
	}
	const nonce =
		el.getAttribute( 'data-nonce' ) ||
		el.getAttribute( 'data-wppo-nonce' ) ||
		'';
	try {
		const res = await fetch( buildEsiUrl(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest',
			},
			body: buildEsiBody( block, nonce ),
		} );
		if ( ! res.ok ) {
			return;
		}
		const data = await res.json();
		const html =
			data && data.data && data.data.html
				? data.data.html
				: data.html || '';
		if ( html ) {
			// Server-sanitized per the wp_kses contract (file header); this
			// client-side pass is defense-in-depth before DOM insertion.
			el.replaceChildren( sanitizeEsiFragment( html ) );
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
		}
	} catch ( err ) {
		console.warn( 'WPPO ESI hydrate failed', err );
	}
};

/**
 * Hydrate all placeholders on the page, batched via requestAnimationFrame.
 *
 * @return {void}
 */
export const hydrateESIPlaceholders = () => {
	const els = document.querySelectorAll( '[data-wppo-esi]' );
	if ( ! els.length ) {
		return;
	}
	const run = () => {
		els.forEach( ( el ) => {
			hydrateElement( el );
		} );
	};
	if ( typeof window !== 'undefined' && 'requestAnimationFrame' in window ) {
		window.requestAnimationFrame( run );
	} else {
		run();
	}
};

// Auto-hydrate on DOMContentLoaded.
if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', hydrateESIPlaceholders );
	} else {
		hydrateESIPlaceholders();
	}
}

export default hydrateESIPlaceholders;
