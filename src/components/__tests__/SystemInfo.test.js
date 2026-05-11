// eslint-disable-next-line import/no-extraneous-dependencies -- devDependencies are allowed in tests
import React from 'react';
// eslint-disable-next-line import/no-extraneous-dependencies -- devDependencies are allowed in tests
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
// eslint-disable-next-line import/no-extraneous-dependencies -- devDependencies are allowed in tests
import '@testing-library/jest-dom';
import SystemInfo from '../SystemInfo';
import { fetchSystemInfo } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	fetchSystemInfo: jest.fn(),
} ) );

describe( 'SystemInfo Component', () => {
	beforeEach( () => {
		global.wppoSettings = {
			translations: {
				systemInfo: 'System Info',
				loadSystemInfo: 'Load System Info',
				refresh: 'Refresh System Info',
				scanning: 'Loading...',
				scanError: 'Failed to fetch system info. Please try again.',
			},
		};
		jest.clearAllMocks();
	} );

	it( 'renders the initial state correctly', () => {
		render( <SystemInfo /> );
		expect( screen.getByText( 'System Info' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Load System Info' } )
		).toBeInTheDocument();
	} );

	it( 'displays system info on successful fetch', async () => {
		fetchSystemInfo.mockResolvedValueOnce( {
			success: true,
			data: {
				php: { version: '8.2.0', memory_limit: '256M' },
				database: { server_version: '10.5.15-MariaDB' },
				wordpress: { version: '6.4.2' },
				server: { server_software: 'Apache/2.4' },
				cache: { object_cache_status: 'Enabled' },
				infrastructure: { action_scheduler: { available: true } },
				wp_constants: { WP_DEBUG: 'false' },
			},
		} );

		render( <SystemInfo /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Load System Info' } )
		);

		expect(
			screen.getByRole( 'button', { name: 'Loading...' } )
		).toBeInTheDocument();

		await waitFor( () => {
			expect( screen.getByText( '8.2.0' ) ).toBeInTheDocument();
			expect( screen.getByText( '10.5.15-MariaDB' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Apache/2.4' ) ).toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Refresh System Info' } )
			).toBeInTheDocument();
		} );
	} );

	it( 'displays an error message when API returns success: false', async () => {
		fetchSystemInfo.mockResolvedValueOnce( {
			success: false,
			message: 'Custom server error',
		} );

		render( <SystemInfo /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Load System Info' } )
		);

		await waitFor( () => {
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Custom server error'
			);
		} );
	} );

	it( 'displays a fallback error message on network failure', async () => {
		fetchSystemInfo.mockRejectedValueOnce( new Error( 'Network error' ) );

		render( <SystemInfo /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Load System Info' } )
		);

		await waitFor( () => {
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				'Failed to fetch system info. Please try again.'
			);
		} );
	} );
} );
