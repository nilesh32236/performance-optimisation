/**
 * WPPO ESI placeholder hydrator — OLS AJAX fallback.
 *
 * Enterprise: <esi:include> is handled by LSWS; OLS uses <div data-wppo-esi>.
 * Hydrates via fetch(admin-ajax.php?action=wppo_esi_fragment) with
 * credentials: 'same-origin', no jQuery, requestAnimationFrame batched.
 * Intentionally bypasses SPA apiRequest — uses different auth (nonce via query
 * + same-origin credentials, no X-WP-Nonce REST header) and must remain
 * decoupled from the admin SPA bundle.
 *
 * @since NEXT
 */

/**
 * Build AJAX URL for a given block and nonce.
 *
 * @param {string} block Block name.
 * @param {string} nonce Nonce value.
 * @return {string} URL
 */
export const buildEsiUrl = ( block, nonce ) => {
	const base =
		( typeof window !== 'undefined' &&
			window.wppoSettings &&
			window.wppoSettings.ajaxUrl ) ||
		( typeof window !== 'undefined' && window.ajaxurl ) ||
		'/wp-admin/admin-ajax.php';
	const urlBase = base;
	const params = new URLSearchParams( {
		action: 'wppo_esi_fragment',
		block,
	} );
	if ( nonce ) {
		params.set( '_wpnonce', nonce );
		params.set( 'nonce', nonce );
	}
	const sep = urlBase.includes( '?' ) ? '&' : '?';
	return `${ urlBase }${ sep }${ params.toString() }`;
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
	const url = buildEsiUrl( block, nonce );
	try {
		const res = await fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
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
			el.innerHTML = html;
			el.removeAttribute( 'data-wppo-esi' );
			// Keep nonce for debugging but remove to avoid re-hydration.
			// el.removeAttribute( 'data-nonce' );
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
