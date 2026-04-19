// Utility function to handle API calls
export const apiCall = ( action, body, method = 'POST' ) => {
	const isGet = 'GET' === method;
	return fetch( wppoSettings.apiUrl + action, {
		method,
		headers: {
			...( ! isGet && { 'Content-Type': 'application/json' } ),
			'X-WP-Nonce': wppoSettings.nonce,
		},
		...( ! isGet && { body: JSON.stringify( body ) } ),
	} ).then( async ( response ) => {
		const data = await response.json();
		if ( 'update_settings' === action && data.success ) {
			wppoSettings.settings = data.data;
		}
		return data;
	} );
};

export const fetchRecentActivities = ( page = 1 ) => {
	return fetch( wppoSettings.apiUrl + 'recent_activities?page=' + page, {
		method: 'GET',
		headers: {
			'X-WP-Nonce': wppoSettings.nonce,
		},
	} )
		.then( ( response ) => response.json() )
		.catch( ( error ) => {
			console.error( 'Error fetching recent activities: ', error );
			throw error; // Re-throw the error for further handling if needed
		} );
};

/**
 * Run a local telemetry scan on the given URL.
 *
 * @since 1.5.0
 * @param {string} url The URL to scan.
 * @return {Promise<Object>} Resolved scan result data.
 */
export const runPerformanceScan = ( url, force = false ) => {
	return apiCall( 'performance_scan', { url, force } );
};

/**
 * Fetch system information (PHP, DB, WordPress, server, cache).
 *
 * @since 1.5.0
 * @return {Promise<Object>} Resolved system info data.
 */
export const fetchSystemInfo = () => {
	return apiCall( 'system_info', {}, 'GET' );
};
