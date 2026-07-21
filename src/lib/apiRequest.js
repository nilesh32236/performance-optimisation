/**
 * Refresh the REST nonce from the admin-ajax endpoint.
 *
 * WordPress nonces have a 24-hour lifetime by default. If the admin page
 * is left open across multiple days, the SPA needs a fresh nonce to keep
 * making write requests.
 *
 * @since 1.6.0
 * @return {Promise<string>} The refreshed nonce string.
 */
const refreshNonce = async () => {
	try {
		const res = await fetch( wppoSettings.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams( {
				action: 'wppo_get_nonce',
				nonce: wppoSettings.nonce_refresh,
			} ),
		} );
		if ( ! res.ok ) {
			throw new Error( 'Nonce refresh failed with status ' + res.status );
		}
		const data = await res.json();
		if ( data.success && data.data?.nonce ) {
			wppoSettings.nonce = data.data.nonce;
			return data.data.nonce;
		}
		throw new Error( 'Nonce refresh returned invalid response' );
	} catch ( e ) {
		console.error( 'Nonce refresh failed:', e );
		throw e;
	}
};

/**
 * Make a REST API call to the Performance Optimisation plugin.
 *
 * Mutates wppoSettings.settings globally on successful `update_settings` calls.
 *
 * @since 1.0.0
 * @param {string}      action   The REST endpoint action (e.g. 'update_settings').
 * @param {Object|null} body     Request body payload.
 * @param {string}      method   HTTP method ('POST' or 'GET'). Defaults to 'POST'.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved JSON response data.
 */
export const apiCall = async ( action, body, method = 'POST', signal ) => {
	const isGet = 'GET' === method;

	const doFetch = ( nonce ) =>
		fetch( wppoSettings.apiUrl + action, {
			method,
			headers: {
				...( ! isGet && { 'Content-Type': 'application/json' } ),
				'X-WP-Nonce': nonce || wppoSettings.nonce || '',
			},
			...( ! isGet && { body: JSON.stringify( body ) } ),
			signal,
		} );

	const handleResponse = async ( response ) => {
		let data;
		try {
			data = await response.json();
		} catch ( parseError ) {
			throw new Error(
				`Invalid JSON response from ${ action }: ${ parseError.message }`
			);
		}

		// Detect expired nonce (rest_forbidden, rest_cookie_invalid_nonce, etc.).
		if (
			data.code &&
			( data.code === 'rest_forbidden' ||
				data.code === 'rest_cookie_invalid_nonce' ||
				data.code === 'rest_cookie_nonce_invalid' )
		) {
			const freshNonce = await refreshNonce();
			const retryResponse = await doFetch( freshNonce );
			return handleResponse( retryResponse );
		}

		if ( 'update_settings' === action && data.success && data.data ) {
			wppoSettings.settings = Object.freeze( data.data );
		}
		return data;
	};

	try {
		const response = await doFetch( null );
		return await handleResponse( response );
	} catch ( error ) {
		console.error( 'API call failed:', action, error );
		throw error;
	}
};

/**
 * Fetch paginated recent activity log entries.
 *
 * @since 1.0.0
 * @param {number}      page     Page number (defaults to 1).
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved activities data.
 */
export const fetchRecentActivities = ( page = 1, signal ) => {
	return apiCall( `recent_activities?page=${ page }`, {}, 'GET', signal );
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
 * @param {string}      url      The scanned URL.
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved suggestions array.
 */
export const fetchSuggestions = ( url, signal ) => {
	return apiCall(
		`suggestions?url=${ encodeURIComponent( url ) }`,
		{},
		'GET',
		signal
	);
};

/**
 * Retrieve server-level performance rules (Apache/Nginx).
 *
 * @since 1.6.0
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Resolved server rules data.
 */
export const fetchServerRules = ( signal ) => {
	return apiCall( 'server_rules', {}, 'GET', signal );
};
