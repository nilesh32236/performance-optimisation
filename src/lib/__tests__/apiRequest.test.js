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

		it( 'should not mutate wppoSettings if data.success is false', async () => {
			const mockData = { success: false, data: { newSetting: 'updated' } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			await apiCall( 'update_settings', {} );

			expect( global.wppoSettings.settings ).toEqual( {} );
		} );

		it( 'should not update wppoSettings for other actions', async () => {
			const mockData = { success: true, data: { someSetting: 'value' } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			await apiCall( 'other_action', {} );

			expect( global.wppoSettings.settings ).toEqual( {} );
		} );

		it( 'should default method to POST', async () => {
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( {} ),
			} );

			await apiCall( 'some_action', {} );

			expect( global.fetch.mock.calls[0][1].method ).toBe( 'POST' );
		} );

		it( 'should throw an error on sad path network failure', async () => {
			const mockError = new Error( 'Network error' );
			global.fetch.mockRejectedValueOnce( mockError );

			await expect( apiCall( 'some_action', {} ) ).rejects.toThrow(
				'Network error'
			);
		} );
	} );

	describe( 'fetchSystemInfo', () => {
		it( 'should call apiCall with correct parameters for fetchSystemInfo', async () => {
			const mockData = { success: true, data: { php: '8.1' } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { fetchSystemInfo } = await import( '../apiRequest' );
			const result = await fetchSystemInfo();

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/system_info',
				{
					method: 'GET',
					headers: {
						'X-WP-Nonce': 'testnonce',
					},
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should throw an error on sad path network failure', async () => {
			const mockError = new Error( 'Network error' );
			global.fetch.mockRejectedValueOnce( mockError );

			const { fetchSystemInfo } = await import( '../apiRequest' );
			await expect( fetchSystemInfo() ).rejects.toThrow(
				'Network error'
			);
		} );
	} );

	describe( 'runPerformanceScan', () => {
		it( 'should call apiCall with correct parameters for runPerformanceScan', async () => {
			const mockData = { success: true, data: { score: 90 } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { runPerformanceScan } = await import( '../apiRequest' );
			const result = await runPerformanceScan(
				'https://example.com',
				true
			);

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/performance_scan',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': 'testnonce',
					},
					body: JSON.stringify( {
						url: 'https://example.com',
						force: true,
					} ),
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should default force to false', async () => {
			const mockData = { success: true, data: { score: 90 } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { runPerformanceScan } = await import( '../apiRequest' );
			const result = await runPerformanceScan(
				'https://example.com'
			);

			expect( global.fetch.mock.calls[0][1].body ).toContain( '"force":false' );
			expect( result ).toEqual( mockData );
		} );

		it( 'should throw an error on sad path network failure', async () => {
			const mockError = new Error( 'Network error' );
			global.fetch.mockRejectedValueOnce( mockError );

			const { runPerformanceScan } = await import( '../apiRequest' );
			await expect(
				runPerformanceScan( 'https://example.com', true )
			).rejects.toThrow( 'Network error' );
		} );
	} );

	describe( 'queuePagespeedScan', () => {
		it( 'should call apiCall with correct parameters for queuePagespeedScan', async () => {
			const mockData = { success: true, data: { job_id: 123 } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { queuePagespeedScan } = await import( '../apiRequest' );
			const result = await queuePagespeedScan(
				'https://example.com',
				'desktop'
			);

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/pagespeed_scan',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': 'testnonce',
					},
					body: JSON.stringify( {
						url: 'https://example.com',
						strategy: 'desktop',
					} ),
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should default strategy to mobile in queuePagespeedScan', async () => {
			const mockData = { success: true, data: { job_id: 123 } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { queuePagespeedScan } = await import( '../apiRequest' );
			const result = await queuePagespeedScan(
				'https://example.com'
			);

			expect( global.fetch.mock.calls[0][1].body ).toContain( '"strategy":"mobile"' );
			expect( result ).toEqual( mockData );
		} );

		it( 'should throw an error on sad path network failure', async () => {
			const mockError = new Error( 'Network error' );
			global.fetch.mockRejectedValueOnce( mockError );

			const { queuePagespeedScan } = await import( '../apiRequest' );
			await expect(
				queuePagespeedScan( 'https://example.com', 'desktop' )
			).rejects.toThrow( 'Network error' );
		} );
	} );

	describe( 'getPagespeedResults', () => {
		it( 'should call apiCall with correct parameters for getPagespeedResults', async () => {
			const mockData = { success: true, data: { status: 'ready' } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { getPagespeedResults } = await import( '../apiRequest' );
			const result = await getPagespeedResults(
				'https://example.com',
				'desktop'
			);

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/pagespeed_results?url=https%3A%2F%2Fexample.com&strategy=desktop',
				{
					method: 'GET',
					headers: {
						'X-WP-Nonce': 'testnonce',
					},
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should default strategy to mobile in getPagespeedResults', async () => {
			const mockData = { success: true, data: { status: 'ready' } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { getPagespeedResults } = await import( '../apiRequest' );
			const result = await getPagespeedResults(
				'https://example.com'
			);

			expect( global.fetch.mock.calls[0][0] ).toContain( 'strategy=mobile' );
			expect( result ).toEqual( mockData );
		} );

		it( 'should throw an error on sad path network failure', async () => {
			const mockError = new Error( 'Network error' );
			global.fetch.mockRejectedValueOnce( mockError );

			const { getPagespeedResults } = await import( '../apiRequest' );
			await expect(
				getPagespeedResults( 'https://example.com', 'desktop' )
			).rejects.toThrow( 'Network error' );
		} );
	} );

	describe( 'fetchSuggestions', () => {
		it( 'should call apiCall with correct parameters for fetchSuggestions', async () => {
			const mockData = { success: true, data: { suggestions: [] } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { fetchSuggestions } = await import( '../apiRequest' );
			const result = await fetchSuggestions( 'https://example.com' );

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/suggestions?url=https%3A%2F%2Fexample.com',
				{
					method: 'GET',
					headers: {
						'X-WP-Nonce': 'testnonce',
					},
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should throw an error on sad path network failure', async () => {
			const mockError = new Error( 'Network error' );
			global.fetch.mockRejectedValueOnce( mockError );

			const { fetchSuggestions } = await import( '../apiRequest' );
			await expect(
				fetchSuggestions( 'https://example.com' )
			).rejects.toThrow( 'Network error' );
		} );
	} );

	describe( 'fetchServerRules', () => {
		it( 'should call apiCall with correct parameters for fetchServerRules', async () => {
			const mockData = { success: true, data: { rules: 'none' } };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const { fetchServerRules } = await import( '../apiRequest' );
			const result = await fetchServerRules();

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/server_rules',
				{
					method: 'GET',
					headers: {
						'X-WP-Nonce': 'testnonce',
					},
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should throw an error on sad path network failure', async () => {
			const mockError = new Error( 'Network error' );
			global.fetch.mockRejectedValueOnce( mockError );

			const { fetchServerRules } = await import( '../apiRequest' );
			await expect( fetchServerRules() ).rejects.toThrow(
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
				'http://test.com/wp-json/wppo/v1/recent_activities?page=1',
				{
					method: 'GET',
					headers: {
						'X-WP-Nonce': 'testnonce',
					},
				}
			);
			expect( result ).toEqual( mockData );
		} );

		it( 'should call fetch with correct parameters and return json response for a non-default page', async () => {
			const mockData = { activities: [ { id: 1 } ] };
			global.fetch.mockResolvedValueOnce( {
				json: jest.fn().mockResolvedValueOnce( mockData ),
			} );

			const result = await fetchRecentActivities( 2 );

			expect( global.fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/wppo/v1/recent_activities?page=2',
				{
					method: 'GET',
					headers: {
						'X-WP-Nonce': 'testnonce',
					},
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
				'Error fetching recent activities: ',
				mockError
			);

			consoleSpy.mockRestore();
		} );
	} );
} );
