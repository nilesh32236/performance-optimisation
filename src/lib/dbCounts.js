import { apiCall } from './apiClient';

/**
 * Shared database-cleanup counts cache.
 *
 * Dashboard and DatabaseCleanup previously issued the identical
 * `database_cleanup_counts` GET with no shared cache; this module memoizes
 * one in-flight promise with a short TTL so tab navigation reuses data.
 *
 * The import comes from `./apiClient` (not the `./apiRequest` barrel) to
 * match `scanApi.js` and keep the refactor's barrel decoupling: a future
 * barrel → dbCounts import would otherwise create a cycle.
 *
 * In-flight dedup intentionally keys on signal identity: two concurrent
 * getDbCounts() calls with different AbortSignals issue two GETs instead of
 * sharing one promise, so aborting one caller's signal can never reject an
 * unrelated component's in-flight request. The duplicate-request cost on
 * concurrent distinct-signal mounts is the accepted trade-off for that
 * abort isolation (pinned by dbCounts.test.js).
 *
 * @since 2.0.0
 */

const DEFAULT_TTL_MS = 60000;

const hasSignalArg = ( signal ) => signal !== undefined && signal !== null;

/**
 * Override the TTL (primarily for tests).
 *
 * Thin wrapper over the module singleton's setTtl() (audit maintainability:
 * the singleton is a createDbCountsCache() instance, so the TTL/inflight/
 * abort/generation logic lives in exactly one place).
 *
 * @since NEXT
 * @param {number} ttlMs TTL in milliseconds.
 * @return {void}
 */
export const setDbCountsTtl = ( ttlMs ) => {
	singleton.setTtl( ttlMs );
};

/**
 * Create an isolated counts cache (for tests; production uses the module singleton).
 *
 * Single implementation of the TTL/inflight/signal/abort/generation logic
 * (audit maintainability): getDbCounts()/clearDbCountsCache() below are a
 * thin singleton over this factory instead of a second copy, so an
 * abort-semantics or stale-while-revalidate fix cannot land in one copy and
 * miss the other.
 *
 * @since NEXT
 * @param {Object}   options       Options.
 * @param {number}   options.ttlMs TTL in milliseconds.
 * @param {Function} options.fetch Fetch implementation receiving a signal.
 * @return {{get: Function, clear: Function, setTtl: Function}} Isolated cache.
 */
export const createDbCountsCache = ( {
	ttlMs = DEFAULT_TTL_MS,
	fetch: fetchImpl = ( signal ) =>
		hasSignalArg( signal )
			? apiCall( 'database_cleanup_counts', {}, 'GET', signal )
			: apiCall( 'database_cleanup_counts', {}, 'GET' ),
} = {} ) => {
	let safeTtl =
		Number.isFinite( ttlMs ) && ttlMs >= 0 ? ttlMs : DEFAULT_TTL_MS;
	let localAt = 0;
	let localData = null;
	let localInflight = null;
	let localInflightSignal = null;
	let localGeneration = 0;
	return {
		get: async ( signal ) => {
			const ownerSignal = signal ?? null;
			const now = Date.now();
			if ( localData && now - localAt < safeTtl ) {
				if ( signal && signal.aborted ) {
					throw new DOMException(
						'The operation was aborted.',
						'AbortError'
					);
				}
				return { ...localData };
			}
			if ( localInflight && localInflightSignal === ownerSignal ) {
				return localInflight.then( ( data ) => ( { ...data } ) );
			}
			const requestGeneration = localGeneration;
			const request = fetchImpl( signal ).then( ( response ) => {
				if ( response && response.success && response.data ) {
					if ( requestGeneration === localGeneration ) {
						localData = { ...response.data };
						localAt = Date.now();
					}
					return { ...response.data };
				}
				throw new Error(
					response?.message || 'Failed to load counts.'
				);
			} );
			localInflight = request.finally( () => {
				if (
					localGeneration === requestGeneration &&
					localInflightSignal === ownerSignal
				) {
					localInflight = null;
					localInflightSignal = null;
				}
			} );
			localInflightSignal = ownerSignal;
			return localInflight;
		},
		clear: () => {
			localData = null;
			localAt = 0;
			localGeneration++;
			localInflight = null;
			localInflightSignal = null;
		},
		setTtl: ( nextTtlMs ) => {
			if ( Number.isFinite( nextTtlMs ) && nextTtlMs >= 0 ) {
				safeTtl = nextTtlMs;
			}
		},
	};
};

/**
 * Module singleton backing getDbCounts()/clearDbCountsCache().
 *
 * Thin singleton over createDbCountsCache() (audit maintainability) instead
 * of a second ~50-line copy of the TTL/inflight/signal/abort/generation
 * logic.
 */
const singleton = createDbCountsCache();

/**
 * Fetch database cleanup counts with memoization.
 *
 * @since 2.0.0
 * @param {AbortSignal} [signal] Optional AbortSignal for request cancellation.
 * @return {Promise<Object>} Counts keyed by cleanup type.
 */
export const getDbCounts = ( signal ) => singleton.get( signal );

/**
 * Clear the memoized counts (e.g. after a cleanup run invalidates them).
 *
 * @since 2.0.0
 * @return {void}
 */
export const clearDbCountsCache = () => singleton.clear();
