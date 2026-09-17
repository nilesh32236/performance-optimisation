import { redactLogSecrets } from './lib/logSecrets';

document.addEventListener( 'DOMContentLoaded', function () {
	// Degrade silently when the script is enqueued without localization
	// (mirrors the wppoSettings guard in src/lib/apiRequest.js).
	if (
		typeof wppoObject === 'undefined' ||
		! wppoObject ||
		! wppoObject.apiUrl
	) {
		return;
	}
	// Keep in sync with src/lib/authErrors.js (AUTH_ERROR_CODES) + src/lib/apiRequest.js refreshNonce()/apiCall() retry logic.
	// This entry is intentionally standalone (admin-bar, enqueued on every admin page via
	// wppoObject) and does not import the SPA's apiRequest module to avoid bundle coupling.
	// Sync note: apiCall() retries when the JSON payload carries rest_forbidden /
	// rest_cookie_invalid_nonce / rest_cookie_nonce_invalid (even on HTTP 200);
	// postJsonRequest() below mirrors that payload-code check in addition to the
	// HTTP-403 check. When changing retry behaviour, update both copies.
	// Sync is enforced by src/lib/__tests__/authSync.test.js.

	let pendingRefresh = null;
	const fallbackTimers = new Set();

	/**
	 * Extract a safe log message from an error without leaking response
	 * bodies. Server error objects can embed settings/status payloads and
	 * console output persists for any extension/devtools user, so only the
	 * message is logged, never the full error object. Mirrors
	 * getErrorLogMessage() in src/lib/apiRequest.js; the leaf-only
	 * lib/logSecrets.js import keeps this entry standalone (no SPA bundle
	 * coupling) while sharing redaction (audit #1401).
	 *
	 * @param {*} error Caught error value.
	 * @return {string} Safe message string.
	 */
	const getErrorLogMessage = ( error ) => {
		let message;
		if ( error instanceof Error ) {
			message = error.message || 'Unknown error';
		} else if ( 'string' === typeof error ) {
			message = error.slice( 0, 500 ) || 'Unknown error';
		} else if ( error === null || 'undefined' === typeof error ) {
			return 'Unknown error';
		} else {
			try {
				message = String( error ).slice( 0, 500 );
			} catch {
				return 'Unknown error';
			}
		}
		try {
			return ( redactLogSecrets( message ) || 'Unknown error' ).slice(
				0,
				500
			);
		} catch {
			return 'Unknown error';
		}
	};

	// Audit #1354: shared controller so admin-bar requests die on
	// navigation instead of resolving into a torn-down page.
	const pageController =
		typeof AbortController !== 'undefined' ? new AbortController() : null;
	if (
		pageController &&
		typeof window !== 'undefined' &&
		window.addEventListener
	) {
		window.addEventListener( 'pagehide', () => pageController.abort(), {
			once: true,
		} );
	}
	/**
	 * Shared helper for POST JSON requests.
	 *
	 * @param {string}  endpointPath The endpoint path.
	 * @param {Object}  payload      The request payload.
	 * @param {boolean} isRetry      Whether this is a retry attempt.
	 * @return {Promise}               The fetch promise.
	 */
	const postJsonRequest = ( endpointPath, payload, isRetry = false ) => {
		return fetch( wppoObject.apiUrl + endpointPath, {
			method: 'POST',
			signal: pageController ? pageController.signal : undefined,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': wppoObject.nonce,
			},
			body: JSON.stringify( payload ),
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					// Handle 401/403 (likely invalid nonce) by refreshing the
					// nonce — mirrors apiCall() (audit #1420).
					if (
						( 403 === response.status ||
							401 === response.status ) &&
						! isRetry
					) {
						return refreshNonce().then( ( success ) => {
							if ( success ) {
								return postJsonRequest(
									endpointPath,
									payload,
									true
								);
							}
							throw new Error( 'Failed to refresh nonce' );
						} );
					}
					throw new Error( 'Network response was not ok' );
				}
				return response.json().then( ( data ) => {
					// Mirror apiCall(): retry when the payload carries an
					// auth-error code even though the HTTP status was OK.
					if (
						data &&
						( data.code === 'rest_forbidden' ||
							data.code === 'rest_cookie_invalid_nonce' ||
							data.code === 'rest_cookie_nonce_invalid' ) &&
						! isRetry
					) {
						return refreshNonce().then( ( success ) => {
							if ( success ) {
								return postJsonRequest(
									endpointPath,
									payload,
									true
								);
							}
							// Mirror apiCall(): a failed nonce refresh throws
							// rather than resolving with the auth-error payload.
							throw new Error( 'Failed to refresh nonce' );
						} );
					}
					return data;
				} );
			} )
			.catch( ( error ) => {
				console.error(
					`Error calling ${ endpointPath }: `,
					getErrorLogMessage( error )
				);
				throw error;
			} );
	};

	/**
	 * Refreshes the REST API nonce.
	 *
	 * Deduplicates concurrent refreshes so simultaneous 403s share a single
	 * admin-ajax round-trip (thundering-herd guard). Mirrors the
	 * pendingRefresh pattern in src/lib/apiRequest.js.
	 *
	 * @return {Promise<boolean>} Whether the refresh was successful.
	 */
	const refreshNonce = () => {
		if ( pendingRefresh ) {
			return pendingRefresh;
		}

		const formData = new FormData();
		formData.append( 'action', 'wppo_get_nonce' );
		formData.append( 'nonce', wppoObject.nonce_refresh );

		// Audit #1420: pagehide aborts the refresh too (was unabortable).
		const refreshPromise = fetch( wppoObject.ajaxUrl, {
			method: 'POST',
			body: formData,
			...( pageController ? { signal: pageController.signal } : {} ),
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					return false;
				}
				return response.json();
			} )
			.then( ( result ) => {
				if (
					result &&
					result.success &&
					result.data &&
					result.data.nonce
				) {
					wppoObject.nonce = result.data.nonce;
					return true;
				}
				return false;
			} )
			.catch( ( error ) => {
				// Audit #1420: pagehide aborts are expected, not errors.
				if ( error?.name !== 'AbortError' ) {
					console.error(
						'Failed to refresh nonce:',
						getErrorLogMessage( error )
					);
				}
				return false;
			} );

		pendingRefresh = refreshPromise.finally( () => {
			pendingRefresh = null;
		} );

		return pendingRefresh;
	};

	/**
	 * Resolve an admin-bar notice string with i18n priority:
	 * the wppoObject.translations map (server-translated via MO, always
	 * available) first, then window.wp.i18n.__ (via
	 * wp_set_script_translations JSON, best-effort), then the English
	 * fallback. Keeps this entry standalone (no @wordpress/i18n import)
	 * so build/main.asset.php dependencies stay unchanged.
	 *
	 * Note: wppoObject itself is required by this module (apiUrl, nonce,
	 * ajaxUrl are dereferenced unguarded elsewhere); the typeof guard below
	 * covers only the translations lookup so unit tests can exercise the
	 * fallback paths without a global.
	 *
	 * @param {string} key      Translation key in wppoObject.translations.
	 * @param {string} fallback English fallback string.
	 * @return {string} Localized string.
	 */
	const getNoticeString = ( key, fallback ) => {
		if (
			typeof wppoObject !== 'undefined' &&
			wppoObject.translations &&
			'string' === typeof wppoObject.translations[ key ] &&
			wppoObject.translations[ key ]
		) {
			return wppoObject.translations[ key ];
		}
		if (
			window.wp &&
			window.wp.i18n &&
			typeof window.wp.i18n.__ === 'function'
		) {
			try {
				// Static extraction is covered by the PHP-side __() calls in
				// Main::enqueue_admin_bar_script(); the variable fallback here
				// only exercises the runtime wp_set_script_translations JSON.
				// eslint-disable-next-line @wordpress/i18n-no-variables
				return window.wp.i18n.__(
					fallback,
					'performance-optimisation'
				);
			} catch {
				// Fall through to the English fallback below.
			}
		}
		return fallback;
	};

	/**
	 * Displays a notice using the WordPress core notice store if available,
	 * otherwise falls back to a standard alert.
	 *
	 * @param {string} message The notice message.
	 * @param {string} type    The notice type (success, error, warning, info).
	 */
	const showNotice = ( message, type = 'success' ) => {
		let dispatched = false;
		if ( window.wp && window.wp.data ) {
			try {
				const noticeDispatch =
					window.wp.data.dispatch( 'core/notices' );
				if ( noticeDispatch && noticeDispatch.createNotice ) {
					noticeDispatch.createNotice( type, message, {
						isDismissible: true,
						type: 'snackbar',
					} );
					dispatched = true;
				}
			} catch {
				dispatched = false;
			}
		}

		if ( ! dispatched ) {
			const noticeEl = document.createElement( 'div' );
			noticeEl.className = `notice notice-${ type } is-dismissible wppo-admin-notice`;
			noticeEl.setAttribute( 'role', 'alert' );
			noticeEl.textContent = message;
			let timer = null;
			const clearTimer = () => {
				if ( timer ) {
					clearTimeout( timer );
					fallbackTimers.delete( timer );
					timer = null;
				}
			};
			const dismissNotice = () => {
				clearTimer();
				noticeEl.remove();
			};
			const dismissBtn = document.createElement( 'button' );
			dismissBtn.className = 'notice-dismiss';
			dismissBtn.setAttribute(
				'aria-label',
				getNoticeString( 'dismiss', 'Dismiss' )
			);
			dismissBtn.addEventListener( 'click', dismissNotice );
			noticeEl.appendChild( dismissBtn );
			const target =
				document.getElementById( 'wpbody-content' ) || document.body;
			target.insertBefore( noticeEl, target.firstChild );
			timer = setTimeout( () => {
				fallbackTimers.delete( timer );
				timer = null;
				noticeEl.remove();
			}, 5000 );
			fallbackTimers.add( timer );
		}
	};

	window.addEventListener( 'pagehide', () => {
		fallbackTimers.forEach( ( timer ) => clearTimeout( timer ) );
		fallbackTimers.clear();
	} );

	// Audit #1420: busy guard — disable the clicked item while the
	// request is in flight so double-click cannot double-clear.
	const withBusyItem = ( item, task ) => {
		if ( item.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}
		item.setAttribute( 'aria-disabled', 'true' );
		item.setAttribute( 'aria-busy', 'true' );
		const release = () => {
			item.removeAttribute( 'aria-disabled' );
			item.removeAttribute( 'aria-busy' );
		};
		task().then( release, release );
	};

	const clearAllCacheBtn = document.querySelector(
		'#wp-admin-bar-wppo_clear_all .ab-item'
	);

	if ( clearAllCacheBtn ) {
		clearAllCacheBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			const item = this;
			withBusyItem( item, () =>
				postJsonRequest( '/clear_cache', { action: 'clear_cache' } )
					.then( ( res ) => {
					if ( res.success ) {
						showNotice(
							getNoticeString(
								'cacheCleared',
								'Cache cleared successfully.'
							)
						);
					} else {
						showNotice(
							res.message ||
								getNoticeString(
									'clearFailed',
									'Failed to clear cache.'
								),
							'error'
						);
					}
				} )
				.catch( ( error ) => {
					console.error(
						'Cache clear failed: ',
						getErrorLogMessage( error )
					);
					showNotice(
						getNoticeString(
							'clearRetry',
							'Failed to clear cache. Please try again.'
						),
						'error'
					);
				} )
			);
		} );
	}

	const clearCacheBtn = document.querySelector(
		'#wp-admin-bar-wppo_clear_this_page .ab-item'
	);

	if ( clearCacheBtn ) {
		clearCacheBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			const item = this;
			let path = window.location.pathname;
			let decodedPath;
			try {
				decodedPath = decodeURIComponent( path );
			} catch {
				decodedPath = '';
			}
			if (
				! path ||
				'string' !== typeof path ||
				path.length > 2048 ||
				path[ 0 ] !== '/' ||
				decodedPath.includes( '..' )
			) {
				path = '/';
			}
			withBusyItem( item, () =>
				postJsonRequest( '/clear_cache', {
					action: 'clear_single_page_cache',
					path,
				} )
					.then( ( res ) => {
					if ( res.success ) {
						showNotice(
							getNoticeString(
								'pageCleared',
								'Page cache cleared successfully.'
							)
						);
					} else {
						showNotice(
							res.message ||
								getNoticeString(
									'pageFailed',
									'Failed to clear page cache.'
								),
							'error'
						);
					}
				} )
				.catch( ( error ) => {
					console.error(
						'Page cache clear failed: ',
						getErrorLogMessage( error )
					);
					showNotice(
						getNoticeString(
							'pageRetry',
							'Failed to clear page cache. Please try again.'
						),
						'error'
					);
				} )
			);
		} );
	}
} );
