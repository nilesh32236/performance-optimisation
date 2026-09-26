/**
 * Session-scoped memo for the object-cache status read.
 *
 * `object_cache` is throttled to five calls a minute, and the Overview
 * remounts on every sub-item change. A page without this made a healthy Redis
 * go grey purely from clicking around: 7 mounts in 20 s produced 8 requests,
 * 3 of them HTTP 429.
 *
 * It lives here rather than inside `Overview.js` so that the Object Cache
 * screen can **invalidate** it. Enabling or disabling Redis from that screen
 * changes exactly the state the Overview reports, and without invalidation the
 * user enables Redis, navigates back, and is told the object cache is switched
 * off until they reload the page.
 *
 * @package
 */

/** The first successful read of this page session, or `undefined`. */
let memo;

/** The request in progress, shared by concurrent callers. */
let inFlight = null;

/**
 * Read the object-cache status once per page session.
 *
 * The memo exists to stop *navigation* re-fetching, not to block a refresh the
 * user explicitly asked for — so `force` bypasses it, because otherwise "Try
 * again" would appear to do nothing for exactly the throttled row that produced
 * it, which is the worst possible behaviour for a retry control.
 *
 * @param {Function} fetcher Performs the request. Called with no arguments.
 * @param {boolean}  [force] Bypass the memo and re-read.
 * @return {Promise<Object|null>} The object-cache payload, or null.
 */
export const readObjectCacheStatus = async ( fetcher, force = false ) => {
	if ( memo !== undefined && ! force ) {
		return memo;
	}
	// Concurrent callers share one request rather than racing to the throttle.
	// A forced read always starts a fresh one.
	if ( ! inFlight || force ) {
		// Captured so the catch below can tell "my" rejection from another's.
		const thisRequest = fetcher()
			.then( ( value ) => {
				// A failed read must never destroy a good earlier result. The
				// endpoint is throttled, so a forced retry is exactly when a
				// null is *most* likely — and nulling the memo there left a
				// healthy Redis reported "Unavailable" for the rest of the
				// session, with no further request to correct it.
				if ( value ) {
					memo = value;
				} else {
					// A read that produced nothing must not be pinned in the
					// shared slot either. Leaving it there meant the *next*
					// ordinary read replayed the null without ever asking again,
					// so one throttled request stuck the row at "Unavailable"
					// for the whole session.
					inFlight = null;
				}
				return value;
			} )
			.catch( ( error ) => {
				// Release the shared slot on failure. `object_cache` is a POST,
				// so `apiCall` binds the caller's signal directly: unmounting
				// mid-flight rejects it, and a rejected promise that was never
				// cleared was replayed by every later mount — the row stayed
				// "Unavailable" until a full page reload.
				//
				// Identity-checked, so an abort belonging to a *superseded*
				// request cannot clear the slot a newer one is holding.
				if ( inFlight === thisRequest ) {
					inFlight = null;
				}
				throw error;
			} );
		inFlight = thisRequest;
	}
	return inFlight;
};

/**
 * Forget the memo, so the next read goes to the server.
 *
 * Called after any action that changes the object-cache state.
 */
export const invalidateObjectCacheStatus = () => {
	memo = undefined;
	inFlight = null;
};

/** Test-only: read the current memo without requesting. */
export const peekObjectCacheStatus = () => memo;

/**
 * Whether an object-cache action result should invalidate the memo.
 *
 * Extracted because the call site lives behind a button that is disabled until
 * the settings form is valid, which makes the branch untestable through the
 * component in jsdom — and an untested branch is exactly how the call ended up
 * on the *failure* path for a whole review round, under a comment claiming the
 * opposite. The rule is small enough to state directly and pin directly.
 *
 * @param {Object} response The `apiCall` result for a mutating action.
 * @return {boolean} True only when the action actually succeeded.
 */
export const shouldInvalidateObjectCache = ( response ) =>
	response?.success === true;
