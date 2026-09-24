import { renderHook, act } from '@testing-library/react';
import useSaveSettings from '../useSaveSettings';
import { apiCall, patchSettingsCache } from '../apiRequest';

jest.mock( '../apiRequest', () => {
	const actual = jest.requireActual( '../apiRequest' );
	return {
		...actual,
		apiCall: jest.fn(),
		patchSettingsCache: jest.fn(),
	};
} );

describe( 'useSaveSettings', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		global.wppoSettings = { settings: {} };
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		console.error.mockRestore();
	} );

	it( 'saves a tab and patches the cache with a success notice', async () => {
		apiCall.mockResolvedValue( { success: true } );
		const notify = jest.fn();
		const dismiss = jest.fn();
		const { result } = renderHook( () =>
			useSaveSettings( 'llms_txt', { notify, dismiss } )
		);

		let response;
		await act( async () => {
			response = await result.current.save( { enabled: true } );
		} );

		expect( response.success ).toBe( true );
		// P3-019: the AbortSignal threads into apiCall as the 4th arg.
		expect( apiCall ).toHaveBeenCalledWith(
			'update_settings',
			{
				tab: 'llms_txt',
				settings: { enabled: true },
			},
			'POST',
			expect.any( AbortSignal )
		);
		expect( patchSettingsCache ).toHaveBeenCalledWith( 'llms_txt', {
			enabled: true,
		} );
		expect( dismiss ).toHaveBeenCalled();
		expect( notify ).toHaveBeenCalledWith(
			expect.objectContaining( { type: 'success' } )
		);
		expect( result.current.saving ).toBe( false );
	} );

	it( 'notifies an error when the response fails', async () => {
		apiCall.mockResolvedValue( { success: false, message: 'Nope.' } );
		const notify = jest.fn();
		const { result } = renderHook( () =>
			useSaveSettings( 'llms_txt', { notify, dismiss: jest.fn() } )
		);

		await act( async () => {
			await result.current.save( { enabled: true } );
		} );

		expect( notify ).toHaveBeenCalledWith(
			expect.objectContaining( { type: 'error', message: 'Nope.' } )
		);
		expect( patchSettingsCache ).not.toHaveBeenCalled();
	} );

	it( 'commits the server full map instead of patching the request echo', async () => {
		const serverMap = {
			llms_txt: { enabled: true, server_normalized: true },
			cache_settings: { enabled: false },
		};
		apiCall.mockResolvedValue( { success: true, data: serverMap } );
		const notify = jest.fn();
		const { result } = renderHook( () =>
			useSaveSettings( 'llms_txt', { notify, dismiss: jest.fn() } )
		);

		await act( async () => {
			await result.current.save( { enabled: true } );
		} );

		// The shared contract wins; the request echo must not clobber it.
		expect( patchSettingsCache ).not.toHaveBeenCalled();
		expect( global.wppoSettings.settings ).toBe( serverMap );
		expect( notify ).toHaveBeenCalledWith(
			expect.objectContaining( { type: 'success' } )
		);
	} );

	it( 'patches the request slice when the response has no committable map', async () => {
		apiCall.mockResolvedValue( { success: true, data: null } );
		global.wppoSettings = { settings: { keep: true } };
		const { result } = renderHook( () =>
			useSaveSettings( 'llms_txt', {
				notify: jest.fn(),
				dismiss: jest.fn(),
			} )
		);

		await act( async () => {
			await result.current.save( { enabled: true } );
		} );

		// No committable map (null data): fall back to the request echo so
		// the tab slice still lands in the shared cache.
		expect( patchSettingsCache ).toHaveBeenCalledWith( 'llms_txt', {
			enabled: true,
		} );
		expect( global.wppoSettings.settings ).toEqual( { keep: true } );
	} );

	it( 'seq-guards rapid saves: a stale first response notifies nothing', async () => {
		let resolveFirst;
		const first = new Promise( ( resolve ) => {
			resolveFirst = resolve;
		} );
		apiCall
			.mockReturnValueOnce( first )
			.mockResolvedValueOnce( { success: true } );
		const notify = jest.fn();
		const { result } = renderHook( () =>
			useSaveSettings( 'llms_txt', { notify, dismiss: jest.fn() } )
		);

		let secondPromise;
		await act( async () => {
			const firstPromise = result.current.save( { enabled: true } );
			secondPromise = result.current.save( { enabled: false } );
			// The first run is aborted by the second; settle it late.
			resolveFirst( { success: true } );
			await firstPromise;
		} );
		await act( async () => {
			await secondPromise;
		} );

		// Only the winning (second) save notifies success.
		const successes = notify.mock.calls.filter(
			( [ arg ] ) => arg?.type === 'success'
		);
		expect( successes ).toHaveLength( 1 );
		expect( result.current.saving ).toBe( false );
	} );

	it( 'swallows AbortError without notifying and resets the flag', async () => {
		const abortError = new Error( 'Aborted' );
		abortError.name = 'AbortError';
		apiCall.mockRejectedValueOnce( abortError );
		const notify = jest.fn();
		const { result } = renderHook( () =>
			useSaveSettings( 'llms_txt', { notify, dismiss: jest.fn() } )
		);

		let response;
		await act( async () => {
			response = await result.current.save( { enabled: true } );
		} );

		expect( response ).toBe( undefined );
		expect( notify ).not.toHaveBeenCalled();
		expect( result.current.saving ).toBe( false );
	} );

	it( 'does not notify after unmount (fail-safe flags)', async () => {
		let resolveSave;
		apiCall.mockReturnValueOnce(
			new Promise( ( resolve ) => {
				resolveSave = resolve;
			} )
		);
		global.wppoSettings = { settings: { keep: true } };
		const notify = jest.fn();
		const { result, unmount } = renderHook( () =>
			useSaveSettings( 'llms_txt', { notify, dismiss: jest.fn() } )
		);

		let savePromise;
		await act( async () => {
			savePromise = result.current.save( { enabled: true } );
		} );
		unmount();
		await act( async () => {
			resolveSave( {
				success: true,
				data: { llms_txt: { enabled: true } },
			} );
			await savePromise;
		} );

		// Stale (unmounted): no notify, no cache commit, no tab patch.
		expect( notify ).not.toHaveBeenCalled();
		expect( patchSettingsCache ).not.toHaveBeenCalled();
		expect( global.wppoSettings.settings ).toEqual( { keep: true } );
	} );
} );
