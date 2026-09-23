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
		expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
			tab: 'llms_txt',
			settings: { enabled: true },
		} );
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
} );
