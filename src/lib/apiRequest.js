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
 * @param {string}  url   The URL to scan.
 * @param {boolean} force Whether to force the scan.
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

/**
 * Queue a Google PageSpeed Insights scan as a background job.
 *
 * @since 1.6.0
 * @param {string} url      The URL to scan.
 * @param {string} strategy 'mobile' or 'desktop'.
 * @return {Promise<Object>} Resolved response with job_id.
 */
export const queuePagespeedScan = ( url, strategy = 'mobile' ) => {
	return apiCall( 'pagespeed_scan', { url, strategy } );
};

/**
 * Retrieve cached PageSpeed Insights results for a URL and strategy.
 *
 * Returns { status: 'not_ready' } with HTTP 202 if the background job
 * has not yet completed.
 *
 * @since 1.6.0
 * @param {string} url      The scanned URL.
 * @param {string} strategy 'mobile' or 'desktop'.
 * @return {Promise<Object>} Resolved result data or not_ready status.
 */
export const getPagespeedResults = ( url, strategy = 'mobile' ) => {
	return apiCall(
		`pagespeed_results?url=${ encodeURIComponent(
			url
		) }&strategy=${ encodeURIComponent( strategy ) }`,
		{},
		'GET'
	);
};

/**
 * Retrieve Suggestion_Engine output for a cached telemetry scan.
 *
 * @since 1.6.0
 * @param {string} url The scanned URL.
 * @return {Promise<Object>} Resolved suggestions array.
 */
export const fetchSuggestions = ( url ) => {
	return apiCall(
		`suggestions?url=${ encodeURIComponent( url ) }`,
		{},
		'GET'
	);
};

/**
 * Delete all telemetry history rows and clear the transient index.
 *
 * @since 1.7.0
 * @return {Promise<Object>} Resolved response with deleted count.
 */
export const deleteTelemetry = () => {
	return apiCall( 'telemetry', {}, 'DELETE' );
};

/**
 * Fetch telemetry history rows, optionally filtered by URL.
 *
 * @since 1.7.0
 * @param {string} url Optional URL to filter by.
 * @return {Promise<Object>} Resolved rows array.
 */
export const fetchTelemetry = ( url = '' ) => {
	const query = url ? `?url=${ encodeURIComponent( url ) }` : '';
	return apiCall( `telemetry${ query }`, {}, 'GET' );
};

/**
 * Save (merge + deduplicate) high-value URLs into plugin settings.
 *
 * @since 1.7.0
 * @param {string[]} urls Array of URLs to add.
 * @return {Promise<Object>} Resolved deduplicated URL list.
 */
export const saveTelemetryUrls = ( urls ) => {
	return apiCall( 'telemetry/urls', { urls } );
};
