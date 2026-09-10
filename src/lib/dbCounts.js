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
let generation = 0;

/**
 * Fetch database cleanup counts with memoization.
 *
 * @since NEXT
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Counts keyed by cleanup type.
 */
export const getDbCounts = async ( signal ) => {
	const hasSignal = signal !== undefined && signal !== null;
	const now = Date.now();
	if ( cachedData && now - cachedAt < TTL_MS && ! hasSignal ) {
		return { ...cachedData };
	}
	if ( inflight && ! hasSignal ) {
		return inflight.then( ( data ) => ( { ...data } ) );
	}
	const requestGeneration = generation;
	const request = (
		hasSignal
			? apiCall( 'database_cleanup_counts', {}, 'GET', signal )
			: apiCall( 'database_cleanup_counts', {}, 'GET' )
	).then( ( response ) => {
		if ( response && response.success && response.data ) {
			if ( requestGeneration === generation ) {
				if ( ! hasSignal && ! signal?.aborted ) {
					cachedData = response.data;
					cachedAt = Date.now();
				}
			}
			return { ...response.data };
		}
		throw new Error( response?.message || 'Failed to load counts.' );
	} );
	if ( ! hasSignal ) {
		inflight = request.finally( () => {
			// Only release our own slot: an older request settling after
			// a clear + refetch must not drop the newer in-flight promise.
			if ( generation === requestGeneration ) {
				inflight = null;
			}
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
	generation++;
	inflight = null;
};
