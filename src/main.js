document.addEventListener( 'DOMContentLoaded', function () {
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
	 * @return {Promise<boolean>} Whether the refresh was successful.
	 */
	const refreshNonce = () => {
		return fetch( wppoObject.apiUrl + '/get_nonce', {
			headers: {
				'X-WP-Nonce': wppoObject.nonce,
			},
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
				const noticeDispatch = window.wp.data.dispatch( 'core/notices' );
				if ( noticeDispatch && noticeDispatch.createNotice ) {
					noticeDispatch.createNotice( type, message, {
						isDismissible: true,
						type: 'snackbar',
					} );
					dispatched = true;
				}
			} catch ( _err ) {
				dispatched = false;
			}
		}

		if ( ! dispatched ) {
			// eslint-disable-next-line no-alert
			alert( message );
		}
	};

	const clearAllCacheBtn = document.querySelector(
		'#wp-admin-bar-wppo_clear_all .ab-item'
	);

	if ( clearAllCacheBtn ) {
		clearAllCacheBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			postJsonRequest( '/clear_cache', { action: 'clear_cache' } )
				.then( ( res ) => {
					if ( res.success ) {
						showNotice( 'Cache cleared successfully.' );
					} else {
						showNotice(
							res.message || 'Failed to clear cache.',
							'error'
						);
					}
				} )
				.catch( ( error ) => {
					console.error( 'Cache clear failed: ', error );
					showNotice(
						'Failed to clear cache. Please try again.',
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
			const path = window.location.pathname;
			postJsonRequest( '/clear_cache', {
				action: 'clear_single_page_cache',
				path,
			} )
				.then( ( res ) => {
					if ( res.success ) {
						showNotice( 'Page cache cleared successfully.' );
					} else {
						showNotice(
							res.message || 'Failed to clear page cache.',
							'error'
						);
					}
				} )
				.catch( ( error ) => {
					console.error( 'Page cache clear failed: ', error );
					showNotice(
						'Failed to clear page cache. Please try again.',
						'error'
					);
				} );
		} );
	}
} );
