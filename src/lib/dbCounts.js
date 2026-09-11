import { apiCall } from './apiRequest';

/**
 * Shared database-cleanup counts cache.
 *
 * Dashboard and DatabaseCleanup previously issued the identical
 * `database_cleanup_counts` GET with no shared cache; this module memoizes
 * one in-flight promise with a short TTL so tab navigation reuses data.
 *
 * @since 2.0.0
 */

const TTL_MS = 60000;

let cachedAt = 0;
let cachedData = null;
let inflight = null;
let inflightSignal = null;
let generation = 0;

/**
 * Fetch database cleanup counts with memoization.
 *
 * @since 2.0.0
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Counts keyed by cleanup type.
 */
export const getDbCounts = async ( signal ) => {
	const hasSignal = signal !== undefined && signal !== null;
	const ownerSignal = signal ?? null;
	const now = Date.now();
	if ( cachedData && now - cachedAt < TTL_MS ) {
		// A cache hit must still honour an already-aborted caller signal —
		// callers expect AbortError rather than a value after cancellation.
		if ( hasSignal && signal.aborted ) {
			throw new DOMException(
				'The operation was aborted.',
				'AbortError'
			);
		}
		return { ...cachedData };
	}
	// Only coalesce onto an in-flight request created by the same caller
	// signal (or by another signal-less caller). A different AbortSignal must
	// not adopt — and then be rejected by — another component's request.
	if ( inflight && inflightSignal === ownerSignal ) {
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
				cachedData = { ...response.data };
				cachedAt = Date.now();
			}
			return { ...response.data };
		}
		throw new Error( response?.message || 'Failed to load counts.' );
	} );
	inflight = request.finally( () => {
		if (
			generation === requestGeneration &&
			inflightSignal === ownerSignal
		) {
			inflight = null;
			inflightSignal = null;
		}
	} );
	inflightSignal = ownerSignal;
	return inflight;
};

/**
 * Clear the memoized counts (e.g. after a cleanup run invalidates them).
 *
 * @since 2.0.0
 * @return {void}
 */
export const clearDbCountsCache = () => {
	cachedData = null;
	cachedAt = 0;
	generation++;
	inflight = null;
	inflightSignal = null;
};
