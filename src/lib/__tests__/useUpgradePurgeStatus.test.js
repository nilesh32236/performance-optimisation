import { renderHook, act } from '@testing-library/react';
import useUpgradePurgeStatus, {
	normalizeUpgradePurge,
} from '../useUpgradePurgeStatus';
import { apiCall } from '../apiRequest';

jest.mock( '../apiRequest', () => {
	const actual = jest.requireActual( '../apiRequest' );
	return {
		...actual,
		apiCall: jest.fn(),
	};
} );

describe( 'useUpgradePurgeStatus', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		console.error.mockRestore();
		delete global.wppoSettings;
	} );

	it( 'normalises snake_case and camelCase aliases', () => {
		expect(
			normalizeUpgradePurge( {
				lastPurge: { reason: 'x' },
				safePreviewUrl: 'https://example.com/?wppo_nocache=1',
			} )
		).toEqual( {
			last_purge: { reason: 'x' },
			safe_preview_url: 'https://example.com/?wppo_nocache=1',
		} );
		expect( normalizeUpgradePurge( null ) ).toEqual( {
			last_purge: null,
			safe_preview_url: '',
		} );
	} );

	it( 'seeds from wppoSettings and refreshes from the endpoint', async () => {
		global.wppoSettings = {
			upgradePurge: { last_purge: { reason: 'seed' } },
		};
		apiCall.mockResolvedValue( {
			success: true,
			data: { last_purge: { reason: 'fresh' } },
		} );
		const { result } = renderHook( () => useUpgradePurgeStatus() );
		expect( result.current.upgradePurge.last_purge ).toEqual( {
			reason: 'seed',
		} );

		await act( async () => {
			await result.current.refreshUpgradePurgeStatus();
		} );

		expect( apiCall ).toHaveBeenCalledWith(
			'upgrade_purge_status',
			{},
			'GET'
		);
		expect( result.current.upgradePurge.last_purge ).toEqual( {
			reason: 'fresh',
		} );
	} );

	it( 'keeps the seed when refresh fails', async () => {
		global.wppoSettings = {
			upgradePurge: { last_purge: { reason: 'seed' } },
		};
		apiCall.mockRejectedValue( new Error( 'nope' ) );
		const { result } = renderHook( () => useUpgradePurgeStatus() );

		await act( async () => {
			await result.current.refreshUpgradePurgeStatus();
		} );

		expect( result.current.upgradePurge.last_purge ).toEqual( {
			reason: 'seed',
		} );
	} );
} );
