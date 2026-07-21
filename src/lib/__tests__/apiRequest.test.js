import { apiCall, fetchRecentActivities } from '../apiRequest';

describe( 'API Request library', () => {
	beforeEach( () => {
		global.wppoSettings = {
			apiUrl: 'http://test.com/wp-json/wppo/v1/',
			nonce: 'testnonce',
			settings: {},
		};
		global.fetch = jest.fn();
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
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

		it( 'should throw custom error on initial JSON parse failure', async () => {
			global.fetch.mockResolvedValueOnce( {
				json: jest
					.fn()
					.mockRejectedValueOnce( new Error( 'Unexpected token <' ) ),
			} );

			await expect( apiCall( 'some_action', {} ) ).rejects.toThrow(
				'Invalid JSON response from some_action: Unexpected token <'
			);
		} );

		it( 'should refresh nonce and retry on rest_forbidden', async () => {
			// 1. Initial request fails with rest_forbidden
			const initialMockData = {
				code: 'rest_forbidden',
				message: 'Forbidden',
			};
			// 2. Refresh nonce request succeeds
			const refreshMockData = { success: true, nonce: 'newnonce123' };
			// 3. Retry request succeeds
			const retryMockData = {
				success: true,
				data: { status: 'retried' },
			};

			global.fetch
				.mockResolvedValueOnce( {
					json: jest.fn().mockResolvedValueOnce( initialMockData ),
				} )
				.mockResolvedValueOnce( {
					ok: true,
					json: jest.fn().mockResolvedValueOnce( refreshMockData ),
				} )
				.mockResolvedValueOnce( {
					json: jest.fn().mockResolvedValueOnce( retryMockData ),
				} );

			const result = await apiCall( 'some_action', {} );

			// Check if nonce was updated in wppoSettings
			expect( global.wppoSettings.nonce ).toBe( 'newnonce123' );
			expect( result ).toEqual( retryMockData );
			expect( global.fetch ).toHaveBeenCalledTimes( 3 );
			// Check if refresh nonce was called
			expect( global.fetch ).toHaveBeenNthCalledWith(
				2,
				'http://test.com/wp-json/wppo/v1/refresh_nonce'
			);
			// Check if retry was called with new nonce
			expect( global.fetch ).toHaveBeenNthCalledWith(
				3,
				'http://test.com/wp-json/wppo/v1/some_action',
				expect.objectContaining( {
					headers: expect.objectContaining( {
						'X-WP-Nonce': 'newnonce123',
					} ),
				} )
			);
		} );

		it( 'should throw custom error on retry JSON parse failure', async () => {
			// 1. Initial request fails with rest_forbidden
			const initialMockData = {
				code: 'rest_forbidden',
				message: 'Forbidden',
			};
			// 2. Refresh nonce request succeeds
			const refreshMockData = { success: true, nonce: 'newnonce123' };

			global.fetch
				.mockResolvedValueOnce( {
					json: jest.fn().mockResolvedValueOnce( initialMockData ),
				} )
				.mockResolvedValueOnce( {
					ok: true,
					json: jest.fn().mockResolvedValueOnce( refreshMockData ),
				} )
				.mockResolvedValueOnce( {
					json: jest
						.fn()
						.mockRejectedValueOnce(
							new Error( 'Unexpected end of input' )
						),
				} );

			await expect( apiCall( 'some_action', {} ) ).rejects.toThrow(
				'Invalid JSON response from some_action (retry): Unexpected end of input'
			);
		} );

		it( 'should fall back to old nonce if refreshNonce fetch fails', async () => {
			// 1. Initial request fails with rest_forbidden
			const initialMockData = {
				code: 'rest_forbidden',
				message: 'Forbidden',
			};
			// 3. Retry request succeeds (using old nonce because refresh failed)
			const retryMockData = {
				success: true,
				data: { status: 'retried_with_old_nonce' },
			};

			global.fetch
				.mockResolvedValueOnce( {
					json: jest.fn().mockResolvedValueOnce( initialMockData ),
				} )
				.mockResolvedValueOnce( {
					ok: false, // Simulate !res.ok
				} )
				.mockResolvedValueOnce( {
					json: jest.fn().mockResolvedValueOnce( retryMockData ),
				} );

			const result = await apiCall( 'some_action', {} );

			expect( global.wppoSettings.nonce ).toBe( 'testnonce' );
			expect( result ).toEqual( retryMockData );
			// Check if retry was called with OLD nonce
			expect( global.fetch ).toHaveBeenNthCalledWith(
				3,
				'http://test.com/wp-json/wppo/v1/some_action',
				expect.objectContaining( {
					headers: expect.objectContaining( {
						'X-WP-Nonce': 'testnonce',
					} ),
				} )
			);
		} );

		it( 'should log error if refreshNonce throws error', async () => {
			// 1. Initial request fails with rest_forbidden
			const initialMockData = {
				code: 'rest_forbidden',
				message: 'Forbidden',
			};
			// 3. Retry request succeeds (using old nonce because refresh failed)
			const retryMockData = {
				success: true,
				data: { status: 'retried_with_old_nonce' },
			};
			const refreshError = new Error( 'Refresh error' );

			global.fetch
				.mockResolvedValueOnce( {
					json: jest.fn().mockResolvedValueOnce( initialMockData ),
				} )
				.mockRejectedValueOnce( refreshError ) // Simulate fetch rejection
				.mockResolvedValueOnce( {
					json: jest.fn().mockResolvedValueOnce( retryMockData ),
				} );

			const result = await apiCall( 'some_action', {} );

			expect( console.error ).toHaveBeenCalledWith(
				'Nonce refresh failed:',
				refreshError
			);
			expect( global.wppoSettings.nonce ).toBe( 'testnonce' );
			expect( result ).toEqual( retryMockData );
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

			await expect( fetchRecentActivities() ).rejects.toThrow(
				'Failed to fetch'
			);
			expect( console.error ).toHaveBeenCalledWith(
				'Error fetching recent activities: ',
				mockError
			);
		} );
	} );
} );
