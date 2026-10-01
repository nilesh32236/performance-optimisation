/**
 * A shared GET must be shared until the **fetch** settles, not until a waiter
 * gives up.
 *
 * The registry entry used to be released in a `finally` around the waiter's own
 * `await`. A caller that aborted — while the underlying fetch, which carries no
 * signal and cannot be cancelled, was still running — freed the entry at once,
 * so the next identical GET started a second request for work already in
 * flight. An independent review reproduced it in the real App: mount → navigate
 * away → back produced **two** `recent_activities` trips for one logical fetch.
 *
 * @package
 */

import { apiCall } from '../apiRequest';

/** A response body the shared path will accept. */
const okBody = () => ( { success: true, data: { value: 1 } } );

describe( 'in-flight GET sharing', () => {
	let calls;
	let resolvers;

	beforeEach( () => {
		calls = 0;
		resolvers = [];
		global.fetch = jest.fn( () => {
			calls += 1;
			return new Promise( ( resolve ) => {
				resolvers.push( () =>
					resolve( {
						ok: true,
						status: 200,
						headers: { get: () => 'application/json' },
						json: async () => okBody(),
					} )
				);
			} );
		} );
		global.wppoSettings = {
			apiUrl: '/wp-json/performance-optimisation/v1',
			nonce: 'test',
			settings: {},
		};
	} );

	afterEach( () => {
		delete global.fetch;
	} );

	it( 'does not start a second request when the first caller aborts', async () => {
		const first = new AbortController();
		const p1 = apiCall( 'thing', {}, 'GET', first.signal ).catch(
			( e ) => e
		);

		// Give the first call a tick to register.
		await Promise.resolve();
		first.abort();
		const err = await p1;
		expect( err?.name ).toBe( 'AbortError' );

		// The underlying fetch is un-signalled and still running…
		expect( calls ).toBe( 1 );
		expect( resolvers ).toHaveLength( 1 );

		// …so a second identical GET must join it, not start its own.
		const p2 = apiCall( 'thing', {}, 'GET', new AbortController().signal );
		await Promise.resolve();
		expect( calls ).toBe( 1 );

		resolvers[ 0 ]();
		await p2;
	} );

	it( 'still shares between two concurrent callers with no abort', async () => {
		const p1 = apiCall( 'other', {}, 'GET', new AbortController().signal );
		const p2 = apiCall( 'other', {}, 'GET', new AbortController().signal );
		await Promise.resolve();
		expect( calls ).toBe( 1 );
		resolvers[ 0 ]();
		await Promise.all( [ p1, p2 ] );
	} );

	it( 'releases the entry once the fetch settles, so a later call hits the network', async () => {
		const p1 = apiCall( 'later', {}, 'GET', new AbortController().signal );
		await Promise.resolve();
		resolvers[ 0 ]();
		await p1;
		await Promise.resolve();

		const p2 = apiCall( 'later', {}, 'GET', new AbortController().signal );
		await Promise.resolve();
		// A sequential call must not replay a settled result from memory.
		expect( calls ).toBe( 2 );
		resolvers[ 1 ]();
		await p2;
	} );
} );
