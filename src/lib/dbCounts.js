import { apiCall } from './apiRequest';

/**
 * Shared database-cleanup counts cache.
 *
 * Dashboard and DatabaseCleanup previously issued the identical
 * `database_cleanup_counts` GET with no shared cache; this module memoizes
 * one in-flight promise with a short TTL so tab navigation reuses data.
 *
 * @since NEXT
 */

const TTL_MS = 60000;

let cachedAt = 0;
let cachedData = null;
let inflight = null;

/**
 * Fetch database cleanup counts with memoization.
 *
 * @since NEXT
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Counts keyed by cleanup type.
 */
export const getDbCounts = async ( signal ) => {
	const now = Date.now();
	if ( cachedData && now - cachedAt < TTL_MS && ! signal ) {
		return cachedData;
	}
	if ( inflight && ! signal ) {
		return inflight;
	}
	const request = (
		signal === undefined
			? apiCall( 'database_cleanup_counts', {}, 'GET' )
			: apiCall( 'database_cleanup_counts', {}, 'GET', signal )
	).then( ( response ) => {
		if ( response && response.success && response.data ) {
			if ( ! signal || ! signal.aborted ) {
				cachedData = response.data;
				cachedAt = Date.now();
			}
			return response.data;
		}
		throw new Error( response?.message || 'Failed to load counts.' );
	} );
	if ( ! signal ) {
		inflight = request.finally( () => {
			inflight = null;
		} );
		return inflight;
	}
	return request;
};

/**
 * Clear the memoized counts (e.g. after a cleanup run invalidates them).
 *
 * @since NEXT
 * @return {void}
 */
export const clearDbCountsCache = () => {
	cachedData = null;
	cachedAt = 0;
	inflight = null;
};
