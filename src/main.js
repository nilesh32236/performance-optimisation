document.addEventListener( 'DOMContentLoaded', function () {
	/**
	 * Shared helper for POST JSON requests.
	 *
	 * @param {string} endpointPath The endpoint path.
	 * @param {Object} payload      The request payload.
	 * @param {boolean} isRetry     Whether this is a retry attempt.
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
								return postJsonRequest( endpointPath, payload, true );
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
			} );
	};

	/**
	 * Refreshes the REST API nonce.
	 *
	 * @return {Promise<boolean>} Whether the refresh was successful.
	 */
	const refreshNonce = () => {
		return fetch( wppoObject.apiUrl + '/get_nonce' )
			.then( ( response ) => response.json() )
			.then( ( result ) => {
				if ( result.success && result.data && result.data.nonce ) {
					wppoObject.nonce = result.data.nonce;
					return true;
				}
				return false;
			} )
			.catch( () => false );
	};

	const clearAllCacheBtn = document.querySelector(
		'#wp-admin-bar-wppo_clear_all .ab-item'
	);

	if ( clearAllCacheBtn ) {
		clearAllCacheBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			postJsonRequest( '/clear_cache', { action: 'clear_cache' } );
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
			} );
		} );
	}
} );
