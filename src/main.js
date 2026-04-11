document.addEventListener( 'DOMContentLoaded', function () {
	/**
	 * Shared helper for POST JSON requests.
	 *
	 * @param {string} endpointPath The endpoint path.
	 * @param {Object} payload      The request payload.
	 * @return {Promise}
	 */
	const postJsonRequest = ( endpointPath, payload ) => {
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
					throw new Error( 'Network response was not ok' );
				}
				return response.json();
			} )
			.catch( ( error ) => {
				console.error( `Error calling ${ endpointPath }: `, error );
			} );
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
