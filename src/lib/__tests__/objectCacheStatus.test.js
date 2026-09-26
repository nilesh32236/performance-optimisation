/**
 * Tests for the session-scoped object-cache status memo.
 *
 * `object_cache` is throttled to five calls a minute, so this memo is what
 * stops a healthy Redis going grey purely from clicking around. Each of these
 * pins a way that went wrong in the first version of it.
 */

import {
	invalidateObjectCacheStatus,
	peekObjectCacheStatus,
	readObjectCacheStatus,
} from '../objectCacheStatus';

describe( 'readObjectCacheStatus', () => {
	beforeEach( () => {
		invalidateObjectCacheStatus();
	} );

	it( 'reads once and serves the memo to later callers', async () => {
		const fetcher = jest
			.fn()
			.mockResolvedValue( { enabled: true, redis_reachable: true } );
		const first = await readObjectCacheStatus( fetcher );
		const second = await readObjectCacheStatus( fetcher );
		const third = await readObjectCacheStatus( fetcher );
		expect( first ).toEqual( second );
		expect( second ).toEqual( third );
		expect( fetcher ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shares one request between concurrent callers', async () => {
		// Racing to the throttle is the thing this exists to prevent.
		const fetcher = jest
			.fn()
			.mockResolvedValue( { enabled: true, redis_reachable: true } );
		await Promise.all( [
			readObjectCacheStatus( fetcher ),
			readObjectCacheStatus( fetcher ),
			readObjectCacheStatus( fetcher ),
		] );
		expect( fetcher ).toHaveBeenCalledTimes( 1 );
	} );

	it( 're-reads when forced, so an explicit retry actually retries', async () => {
		const fetcher = jest
			.fn()
			.mockResolvedValue( { enabled: true, redis_reachable: true } );
		await readObjectCacheStatus( fetcher );
		await readObjectCacheStatus( fetcher, true );
		expect( fetcher ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'D2: a failed forced retry does NOT overwrite a good result', async () => {
		// The endpoint is throttled, so a forced retry is exactly when a null is
		// most likely. Writing null into the memo left a healthy Redis reported
		// "Unavailable" for the rest of the session, with no further request
		// able to correct it.
		const good = { enabled: true, redis_reachable: true };
		const fetcher = jest
			.fn()
			.mockResolvedValueOnce( good )
			.mockResolvedValueOnce( null );

		await readObjectCacheStatus( fetcher );
		const retried = await readObjectCacheStatus( fetcher, true );

		expect( retried ).toBeNull();
		// The *next* ordinary read must still see the good result, and must not
		// issue a fourth request.
		await readObjectCacheStatus( fetcher );
		expect( fetcher ).toHaveBeenCalledTimes( 2 );
		expect( peekObjectCacheStatus() ).toEqual( good );
	} );

	it( 'D1: a rejected request releases the shared slot', async () => {
		// `object_cache` is a POST, so `apiCall` binds the caller's signal:
		// unmounting mid-flight rejects it. A rejected promise that was never
		// cleared was replayed by every later mount, so the row stayed
		// "Unavailable" until a full page reload.
		const fetcher = jest
			.fn()
			.mockRejectedValueOnce( new Error( 'aborted' ) )
			.mockResolvedValue( { enabled: true, redis_reachable: true } );

		await expect( readObjectCacheStatus( fetcher ) ).rejects.toThrow(
			'aborted'
		);
		// The next mount must retry rather than replay the dead promise.
		const next = await readObjectCacheStatus( fetcher );
		expect( next ).toEqual( { enabled: true, redis_reachable: true } );
		expect( fetcher ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'D1: concurrent callers all see the rejection, then a later one retries', async () => {
		const fetcher = jest
			.fn()
			.mockRejectedValueOnce( new Error( 'aborted' ) )
			.mockResolvedValue( { enabled: true, redis_reachable: true } );
		const results = await Promise.allSettled( [
			readObjectCacheStatus( fetcher ),
			readObjectCacheStatus( fetcher ),
		] );
		expect( results.every( ( r ) => r.status === 'rejected' ) ).toBe(
			true
		);
		await readObjectCacheStatus( fetcher );
		expect( fetcher ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'D3: invalidating makes the next read go to the server', async () => {
		// The production path: the user enables Redis on the Object Cache
		// screen, returns to the Overview, and must not be told it is off.
		const fetcher = jest.fn().mockResolvedValue( { enabled: false } );
		await readObjectCacheStatus( fetcher );
		expect( peekObjectCacheStatus() ).toEqual( { enabled: false } );

		fetcher.mockResolvedValue( { enabled: true, redis_reachable: true } );
		invalidateObjectCacheStatus();

		const after = await readObjectCacheStatus( fetcher );
		expect( after ).toEqual( { enabled: true, redis_reachable: true } );
		expect( fetcher ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'does not memoise a first-read failure as if it were a result', async () => {
		const fetcher = jest
			.fn()
			.mockResolvedValueOnce( null )
			.mockResolvedValue( { enabled: true, redis_reachable: true } );
		await readObjectCacheStatus( fetcher );
		// A null first read left the memo undefined, so the next read retries
		// rather than being stuck reporting a failure.
		const second = await readObjectCacheStatus( fetcher );
		expect( second ).toEqual( { enabled: true, redis_reachable: true } );
		expect( fetcher ).toHaveBeenCalledTimes( 2 );
	} );
} );
