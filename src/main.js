document.addEventListener( 'DOMContentLoaded', function () {
	const clearAllCacheBtn = document.querySelector( '#wp-admin-bar-qtpo_clear_all .ab-item' );

	if ( clearAllCacheBtn ) {
		clearAllCacheBtn.addEventListener( 'click', function () {
			fetch( qtpoObject.apiUrl + '/clear_cache', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': qtpoObject.nonce
				},
				body: JSON.stringify( { action: 'clear_cache' } )
			} )
				.then( response => response.json() )
				.then( data => console.log( 'Cache cleared successfully: ', data ) )
				.catch( error => console.error( 'Error clearing cache: ', error ) );
		} );
	}

	const clearCacheBtn = document.querySelector( '#wp-admin-bar-qtpo_clear_this_page .ab-item' );

	if ( clearCacheBtn ) {
		clearCacheBtn.addEventListener( 'click', function () {
			const path = window.location.pathname;
			fetch( qtpoObject.apiUrl + '/clear_cache', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': qtpoObject.nonce
				},
				body: JSON.stringify( { action: 'clear_single_page_cahce', path } )
			} )
				.then( response => response.json() )
				.then( data => console.log( 'Cache cleared successfully: ', data ) )
				.catch( error => console.error( 'Error clearing cache: ', error ) );
		} );
	}
} )