import { apiCall, fetchRecentActivities } from '../apiRequest';

describe( 'API Request library', () => {
	beforeEach( () => {
		global.wppoSettings = {
			apiUrl: 'http://test.com/wp-json/wppo/v1/',
			nonce: 'testnonce',
			settings: {},
		};
		global.fetch = jest.fn();
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	describe( 'apiCall', () => {
		it( 'should call fetch with correct parameters and return data', async () => {
			const mockData = { success: true, data: { test: 'value' } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const body = { setting: 'test' };
			const result = await apiCall( 'some_action', body );

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/some_action',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': 'testnonce',
					},
					body: JSON.stringify( body ),
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should update global wppoSettings on successful update_settings action', async () => {
			const mockData = { success: true, data: { newSetting: 'updated' } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			await apiCall( 'update_settings', {} );

			expect( global.wppoSettings.settings ).toEqual( {
				newSetting: 'updated',
			} );
		} );

		it( 'should throw an error on sad path network failure', async () => {
			const mockError = new Error( 'Network error' );
			global.fetch.mockRejectedValueOnce( mockError );

			await expect( apiCall( 'some_action', {} ) ).rejects.toThrow(
				'Network error'
			);
		} );
	} );

	describe( 'fetchRecentActivities', () => {
		it( 'should call fetch with correct parameters and return json response', async () => {
			const mockData = { activities: [] };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const result = await fetchRecentActivities();

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/recent_activities',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': 'testnonce',
					},
					body: JSON.stringify( { page: '1' } ),
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should throw error and log on sad path failure', async () => {
			const mockError = new Error( 'Failed to fetch' );
			global.fetch.mockRejectedValueOnce( mockError );

			// Mock console.error
			const consoleSpy = jest
				.spyOn( console, 'error' )
				.mockImplementation( () => {} );

			await expect( fetchRecentActivities() ).rejects.toThrow(
				'Failed to fetch'
			);
			expect( consoleSpy ).toHaveBeenCalledWith(
				'Error fetching recent activities:',
				mockError
			);

			consoleSpy.mockRestore();
		} );
	} );
} );
