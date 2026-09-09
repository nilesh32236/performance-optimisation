document.addEventListener( 'DOMContentLoaded', function () {
	// Keep in sync with src/lib/apiRequest.js: refreshNonce() + apiCall() retry-on-403 logic.
	// This entry is intentionally standalone (admin-bar, enqueued on every admin page via
	// wppoObject) and does not import the SPA's apiRequest module to avoid bundle coupling.

	let pendingRefresh = null;
	let fallbackTimer = null;

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
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': wppoObject.nonce,
			},
			body: JSON.stringify( payload ),
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					// Handle 403 Forbidden (likely invalid nonce) by refreshing the nonce.
					if ( 403 === response.status && ! isRetry ) {
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
				return response.json();
			} )
			.catch( ( error ) => {
				console.error( `Error calling ${ endpointPath }: `, error );
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

		const refreshPromise = fetch( wppoObject.ajaxUrl, {
			method: 'POST',
			body: formData,
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
				console.error( 'Failed to refresh nonce:', error );
				return false;
			} );

		pendingRefresh = refreshPromise.finally( () => {
			pendingRefresh = null;
		} );

		return pendingRefresh;
	};

	/**
	 * Resolve an admin-bar notice string with i18n priority:
	 * window.wp.i18n.__ (via wp_set_script_translations) first, then the
	 * wppoObject.translations map (via wp_localize-style inline data), then
	 * the English fallback. Keeps this entry standalone (no @wordpress/i18n
	 * import) so build/main.asset.php dependencies stay unchanged.
	 *
	 * @param {string} key      Translation key in wppoObject.translations.
	 * @param {string} fallback English fallback string.
	 * @return {string} Localized string.
	 */
	const getNoticeString = ( key, fallback ) => {
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
				// Fall through to the translations map / fallback below.
			}
		}
		if (
			typeof wppoObject !== 'undefined' &&
			wppoObject.translations &&
			'string' === typeof wppoObject.translations[ key ] &&
			wppoObject.translations[ key ]
		) {
			return wppoObject.translations[ key ];
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
			if ( fallbackTimer ) {
				clearTimeout( fallbackTimer );
				fallbackTimer = null;
			}
			const noticeEl = document.createElement( 'div' );
			noticeEl.className = `notice notice-${ type } is-dismissible wppo-admin-notice`;
			noticeEl.setAttribute( 'role', 'alert' );
			noticeEl.textContent = message;
			const dismissBtn = document.createElement( 'button' );
			dismissBtn.className = 'notice-dismiss';
			dismissBtn.setAttribute(
				'aria-label',
				getNoticeString( 'dismiss', 'Dismiss' )
			);
			dismissBtn.addEventListener( 'click', () => {
				if ( fallbackTimer ) {
					clearTimeout( fallbackTimer );
					fallbackTimer = null;
				}
				noticeEl.remove();
			} );
			noticeEl.appendChild( dismissBtn );
			const target =
				document.getElementById( 'wpbody-content' ) || document.body;
			target.insertBefore( noticeEl, target.firstChild );
			fallbackTimer = setTimeout( () => {
				noticeEl.remove();
				fallbackTimer = null;
			}, 5000 );
		}
	};

	window.addEventListener( 'pagehide', () => {
		if ( fallbackTimer ) {
			clearTimeout( fallbackTimer );
			fallbackTimer = null;
		}
	} );

	const clearAllCacheBtn = document.querySelector(
		'#wp-admin-bar-wppo_clear_all .ab-item'
	);

	if ( clearAllCacheBtn ) {
		clearAllCacheBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
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
					console.error( 'Cache clear failed: ', error );
					showNotice(
						getNoticeString(
							'clearRetry',
							'Failed to clear cache. Please try again.'
						),
						'error'
					);
				} );
		} );
	}

	const clearCacheBtn = document.querySelector(
		'#wp-admin-bar-wppo_clear_this_page .ab-item'
	);

	if ( clearCacheBtn ) {
		clearCacheBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
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
					console.error( 'Page cache clear failed: ', error );
					showNotice(
						getNoticeString(
							'pageRetry',
							'Failed to clear page cache. Please try again.'
						),
						'error'
					);
				} );
		} );
	}
} );
