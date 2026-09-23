import { renderHook, act, waitFor } from '@testing-library/react';
import useServerRules from '../useServerRules';
import { fetchServerRules } from '../apiRequest';

jest.mock( '../apiRequest', () => ( {
	fetchServerRules: jest.fn(),
	getErrorLogMessage: jest.fn( ( error ) =>
		error instanceof Error ? error.message : 'Unknown error'
	),
} ) );

describe( 'useServerRules (REF-009)', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		console.error.mockRestore();
	} );

	it( 'fetches once on mount and stores successful data', async () => {
		fetchServerRules.mockResolvedValueOnce( {
			success: true,
			data: { apache: 'rules' },
		} );

		const { result } = renderHook( () => useServerRules() );

		expect( result.current.serverRules ).toBeNull();
		expect( result.current.serverRulesError ).toBe( false );

		await waitFor( () =>
			expect( result.current.serverRules ).toEqual( {
				apache: 'rules',
			} )
		);
		expect( fetchServerRules ).toHaveBeenCalledTimes( 1 );
		expect( result.current.serverRulesError ).toBe( false );
	} );

	it( 'sets error state when the response is not successful', async () => {
		fetchServerRules.mockResolvedValueOnce( { success: false } );

		const { result } = renderHook( () => useServerRules() );

		await waitFor( () =>
			expect( result.current.serverRulesError ).toBe( true )
		);
		expect( result.current.serverRules ).toBeNull();
	} );

	it( 'sets error state when the fetch throws', async () => {
		fetchServerRules.mockRejectedValueOnce( new Error( 'boom' ) );

		const { result } = renderHook( () => useServerRules() );

		await waitFor( () =>
			expect( result.current.serverRulesError ).toBe( true )
		);
		expect( result.current.serverRules ).toBeNull();
	} );

	it( 'swallows abort without writing state', async () => {
		fetchServerRules.mockImplementation(
			( signal ) =>
				new Promise( ( _, reject ) => {
					signal.addEventListener( 'abort', () => {
						const aborted = new Error( 'Aborted' );
						aborted.name = 'AbortError';
						reject( aborted );
					} );
				} )
		);

		const { result, unmount } = renderHook( () => useServerRules() );

		await waitFor( () =>
			expect( fetchServerRules ).toHaveBeenCalledTimes( 1 )
		);

		act( () => {
			unmount();
		} );

		expect( result.current.serverRules ).toBeNull();
		expect( result.current.serverRulesError ).toBe( false );
	} );

	it( 'aborts the in-flight request on unmount', async () => {
		let seenSignal = null;
		fetchServerRules.mockImplementation(
			( signal ) =>
				new Promise( () => {
					seenSignal = signal;
				} )
		);

		const { unmount } = renderHook( () => useServerRules() );

		await waitFor( () =>
			expect( fetchServerRules ).toHaveBeenCalledTimes( 1 )
		);
		expect( seenSignal.aborted ).toBe( false );

		act( () => {
			unmount();
		} );

		expect( seenSignal.aborted ).toBe( true );
	} );

	it( 'retry() refetches after a failure', async () => {
		fetchServerRules.mockResolvedValueOnce( { success: false } );

		const { result } = renderHook( () => useServerRules() );

		await waitFor( () =>
			expect( result.current.serverRulesError ).toBe( true )
		);
		expect( fetchServerRules ).toHaveBeenCalledTimes( 1 );

		fetchServerRules.mockResolvedValueOnce( {
			success: true,
			data: { nginx: 'rules' },
		} );

		act( () => {
			result.current.retry();
		} );

		// Retry resets the error flag synchronously before refetching.
		expect( result.current.serverRulesError ).toBe( false );

		await waitFor( () =>
			expect( result.current.serverRules ).toEqual( {
				nginx: 'rules',
			} )
		);
		expect( fetchServerRules ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'does not refetch without retry (hasFetched guard)', async () => {
		fetchServerRules.mockResolvedValue( {
			success: true,
			data: { apache: 'rules' },
		} );

		const { result, rerender } = renderHook( () => useServerRules() );

		await waitFor( () =>
			expect( result.current.serverRules ).toEqual( {
				apache: 'rules',
			} )
		);

		rerender();
		rerender();

		expect( fetchServerRules ).toHaveBeenCalledTimes( 1 );
	} );
} );
