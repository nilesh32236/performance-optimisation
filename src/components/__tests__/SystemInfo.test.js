// eslint-disable-next-line import/no-extraneous-dependencies -- Allowed in tests because `@wordpress/element` handles React, but tests need `@testing-library/react` explicitly.
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SystemInfo from '../SystemInfo';
import * as apiRequest from '../../lib/apiRequest';

// Mock the API request function
jest.mock('../../lib/apiRequest', () => ({
	fetchSystemInfo: jest.fn(),
}));

describe('SystemInfo Component', () => {
	beforeEach(() => {
		// Mock WP environment globals
		global.wppoSettings = {
			translations: {
				systemInfo: 'System Info',
				loadSystemInfo: 'Load System Info',
				refresh: 'Refresh System Info',
				scanning: 'Loading...',
				scanError: 'Failed to fetch system info. Please try again.',
				phpVersion: 'PHP Version',
				dbVersion: 'DB Version',
			},
		};

		jest.clearAllMocks();
	});

	afterEach(() => {
		delete global.wppoSettings;
	});

	it('should render the load button initially', () => {
		render(<SystemInfo />);
		expect(screen.getByRole('button', { name: 'Load System Info' })).toBeInTheDocument();
		expect(screen.getByText('View PHP, database, WordPress, and server environment details.')).toBeInTheDocument();
	});

	it('should fetch and display system info on successful load', async () => {
		// Mock happy path response
		const mockInfoData = {
			success: true,
			data: {
				php: { version: '8.1.0' },
				database: { server_version: '10.5.12-MariaDB' },
				wordpress: { version: '6.2' },
				server: { server_software: 'Apache/2.4' },
				cache: { object_cache_status: 'Enabled' },
				infrastructure: {},
				wp_constants: {},
			},
		};

		apiRequest.fetchSystemInfo.mockResolvedValueOnce(mockInfoData);

		render(<SystemInfo />);

		const loadButton = screen.getByRole('button', { name: 'Load System Info' });
		fireEvent.click(loadButton);

		// Assert loading state
		expect(screen.getByRole('button', { name: 'Loading...' })).toBeInTheDocument();

		// Wait for data to render
		await waitFor(() => {
			expect(screen.getByText('8.1.0')).toBeInTheDocument();
			expect(screen.getByText('10.5.12-MariaDB')).toBeInTheDocument();
			expect(screen.getByText('6.2')).toBeInTheDocument();
			expect(screen.getByText('Apache/2.4')).toBeInTheDocument();
		});

		// Ensure the button changed to "Refresh"
		expect(screen.getByRole('button', { name: 'Refresh System Info' })).toBeInTheDocument();
	});

	it('should display an error message on sad path API failure', async () => {
		// Mock sad path response (success: false)
		const mockErrorResponse = {
			success: false,
			message: 'Custom backend error message',
		};

		apiRequest.fetchSystemInfo.mockResolvedValueOnce(mockErrorResponse);

		render(<SystemInfo />);

		const loadButton = screen.getByRole('button', { name: 'Load System Info' });
		fireEvent.click(loadButton);

		// Wait for the error message to appear
		await waitFor(() => {
			expect(screen.getByRole('alert')).toHaveTextContent('Custom backend error message');
		});

		// Verify data tables did not render
		expect(screen.queryByText('PHP Version')).not.toBeInTheDocument();
	});

	it('should handle network/exception errors gracefully', async () => {
		// Mock sad path exception (e.g., 500 error)
		apiRequest.fetchSystemInfo.mockRejectedValueOnce(new Error('Network error'));

        // Suppress console.error for this specific test so we don't pollute test output
        const consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {});

		render(<SystemInfo />);

		const loadButton = screen.getByRole('button', { name: 'Load System Info' });
		fireEvent.click(loadButton);

		// Wait for the default error message to appear
		await waitFor(() => {
			expect(screen.getByRole('alert')).toHaveTextContent('Failed to fetch system info. Please try again.');
		});

        consoleSpy.mockRestore();
	});
});
