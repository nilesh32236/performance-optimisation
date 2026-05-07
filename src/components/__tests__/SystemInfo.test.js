import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SystemInfo from '../SystemInfo';
import { fetchSystemInfo } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	fetchSystemInfo: jest.fn(),
} ) );

describe( 'SystemInfo', () => {
	beforeEach( () => {
		global.wppoSettings = {
			translations: {},
		};
		jest.clearAllMocks();
	} );

	it( 'renders default state correctly', () => {
		render( <SystemInfo /> );

		expect( screen.getByText( 'System Info' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'View PHP, database, WordPress, and server environment details.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Load System Info/i } )
		).toBeInTheDocument();
		expect( screen.queryByText( 'PHP' ) ).not.toBeInTheDocument();
	} );

	it( 'loads and displays system info on successful API call', async () => {
		const mockData = {
			success: true,
			data: {
				php: { version: '8.1.0' },
				database: { server_version: '10.5.15-MariaDB' },
				wordpress: { version: '6.2' },
				server: { server_software: 'nginx/1.21.6' },
				cache: { object_cache_status: 'Enabled' },
				infrastructure: { action_scheduler: { available: true } },
				wp_constants: { WP_DEBUG: true },
			},
		};

		fetchSystemInfo.mockResolvedValueOnce( mockData );

		render( <SystemInfo /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Load System Info/i } )
		);

		expect( fetchSystemInfo ).toHaveBeenCalledTimes( 1 );

		await waitFor( () => {
			expect( screen.getByText( 'PHP' ) ).toBeInTheDocument();
		} );

		expect( screen.getByText( '8.1.0' ) ).toBeInTheDocument();
		expect( screen.getByText( '10.5.15-MariaDB' ) ).toBeInTheDocument();
		expect( screen.getByText( '6.2' ) ).toBeInTheDocument();
		expect( screen.getByText( 'nginx/1.21.6' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Enabled' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Available' ) ).toBeInTheDocument();
		expect( screen.getByText( 'WP_DEBUG' ) ).toBeInTheDocument();
	} );

	it( 'displays an error message on API failure response', async () => {
		fetchSystemInfo.mockResolvedValueOnce( {
			success: false,
			message: 'Custom error message from server.',
		} );

		render( <SystemInfo /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Load System Info/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'Custom error message from server.' )
			).toBeInTheDocument();
		} );

		expect( screen.queryByText( 'PHP' ) ).not.toBeInTheDocument();
	} );

	it( 'displays a fallback error message on API exception', async () => {
		// Mock console.error to avoid polluting test output
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		fetchSystemInfo.mockRejectedValueOnce( new Error( 'Network failure' ) );

		render( <SystemInfo /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Load System Info/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText(
					'Failed to fetch system info. Please try again.'
				)
			).toBeInTheDocument();
		} );

		expect( screen.queryByText( 'PHP' ) ).not.toBeInTheDocument();

		consoleSpy.mockRestore();
	} );
} );
