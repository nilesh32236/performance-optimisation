import { getDbCounts, clearDbCountsCache } from '../dbCounts';
import { apiCall } from '../apiRequest';

jest.mock( '../apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'dbCounts memoization (lib/dbCounts.js)', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		clearDbCountsCache();
	} );

	it( 'calls apiCall with 3 args when no signal is given', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { posts: 3 },
		} );

		await getDbCounts();

		expect( apiCall ).toHaveBeenCalledWith(
			'database_cleanup_counts',
			{},
			'GET'
		);
		expect( apiCall ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'forwards an AbortSignal as the 4th arg and bypasses the cache', async () => {
		apiCall.mockResolvedValue( {
			success: true,
			data: { posts: 1 },
		} );
		const controller = new AbortController();

		await getDbCounts();
		await getDbCounts( controller.signal );

		expect( apiCall ).toHaveBeenNthCalledWith(
			1,
			'database_cleanup_counts',
			{},
			'GET'
		);
		expect( apiCall ).toHaveBeenNthCalledWith(
			2,
			'database_cleanup_counts',
			{},
			'GET',
			controller.signal
		);
	} );

	it( 'treats explicit null like undefined for the 3-arg call shape', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { posts: 2 },
		} );

		await getDbCounts( null );

		expect( apiCall ).toHaveBeenCalledWith(
			'database_cleanup_counts',
			{},
			'GET'
		);
	} );

	it( 'reuses the cached value within the TTL without a second request', async () => {
		apiCall.mockResolvedValue( {
			success: true,
			data: { posts: 5 },
		} );

		const first = await getDbCounts();
		const second = await getDbCounts();

		expect( apiCall ).toHaveBeenCalledTimes( 1 );
		expect( second ).toEqual( first );
		// Cache hits are copies: caller mutation must not pollute the cache.
		second.posts = 999;
		const third = await getDbCounts();
		expect( third.posts ).toBe( 5 );
	} );

	it( 'drops stale in-flight results after clearDbCountsCache', async () => {
		let resolveFirst;
		apiCall.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					resolveFirst = resolve;
				} )
		);

		const pending = getDbCounts();
		clearDbCountsCache();
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { posts: 7 },
		} );

		// Stale request resolves after the clear: must not repopulate.
		resolveFirst( { success: true, data: { posts: 1 } } );
		await pending;

		const fresh = await getDbCounts();
		expect( fresh.posts ).toBe( 7 );
		expect( apiCall ).toHaveBeenCalledTimes( 2 );
	} );
} );
