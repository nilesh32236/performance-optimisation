import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import PluginSetting from '../PluginSetting';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	fetchRecentActivities: jest.fn(),
} ) );

import { apiCall, fetchRecentActivities } from '../../lib/apiRequest';

describe( 'PluginSetting Component', () => {
	beforeEach( () => {
		global.wppoSettings = {
			settings: {
				performance_audit: {},
			},
			performance_audit: {},
		};
		global.URL.createObjectURL = jest.fn();
		global.URL.revokeObjectURL = jest.fn();
		jest.clearAllMocks();
	} );

	it( 'renders the component and its main sections', () => {
		render( <PluginSetting options={{}} /> );

		expect( screen.getByText( 'Optimization Activity Log' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Google PageSpeed API Key' ).length ).toBeGreaterThan(0);
		expect( screen.getByText( 'Export Configuration' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Import Configuration' ) ).toBeInTheDocument();
	} );

	it( 'loads and displays activity logs', async () => {
		fetchRecentActivities.mockResolvedValueOnce( {
			activities: [ { activity: 'Cache cleared successfully.' } ],
			current_page: 1,
			total_pages: 1,
		} );

		render( <PluginSetting options={{}} /> );

		const loadLogBtn = screen.getByRole( 'button', { name: /Load Activity Log/i } );
		fireEvent.click( loadLogBtn );

		await waitFor( () => {
			expect( fetchRecentActivities ).toHaveBeenCalledWith( 1 );
			expect( screen.getByText( 'Cache cleared successfully.' ) ).toBeInTheDocument();
		} );
	} );

	it( 'handles activity log load error gracefully', async () => {
		fetchRecentActivities.mockRejectedValueOnce( new Error( 'Failed to fetch logs' ) );
        const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		render( <PluginSetting options={{}} /> );

		const loadLogBtn = screen.getByRole( 'button', { name: /Load Activity Log/i } );
		fireEvent.click( loadLogBtn );

		await waitFor( () => {
			expect( fetchRecentActivities ).toHaveBeenCalledWith( 1 );
			expect( screen.getByText( 'Failed to fetch logs' ) ).toBeInTheDocument();
		} );

        consoleSpy.mockRestore();
	} );

	it( 'updates and saves Google PageSpeed API Key successfully', async () => {
		apiCall.mockResolvedValueOnce( { success: true } );

		render( <PluginSetting options={{}} /> );

		const input = screen.getByLabelText( 'Google PageSpeed API Key' );
		fireEvent.change( input, { target: { value: 'NEW_API_KEY_123' } } );

		const saveBtn = screen.getByRole( 'button', { name: /Save Settings/i } );
		fireEvent.click( saveBtn );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'performance_audit',
				settings: { pagespeed_api_key: 'NEW_API_KEY_123' },
			} );
			expect( screen.getByText( 'API key saved.' ) ).toBeInTheDocument();
		} );
	} );

	it( 'handles error when saving Google PageSpeed API Key', async () => {
		apiCall.mockResolvedValueOnce( { success: false, message: 'Invalid API key.' } );

		render( <PluginSetting options={{}} /> );

		const input = screen.getByLabelText( 'Google PageSpeed API Key' );
		fireEvent.change( input, { target: { value: 'INVALID_API_KEY' } } );

		const saveBtn = screen.getByRole( 'button', { name: /Save Settings/i } );
		fireEvent.click( saveBtn );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', expect.any(Object) );
			expect( screen.getByText( 'Invalid API key.' ) ).toBeInTheDocument();
		} );
	} );

	it( 'exports settings correctly', () => {
        const originalCreateElement = document.createElement.bind(document);
		const createElementSpy = jest.spyOn( document, 'createElement' );
		const mockAnchor = {
            nodeType: 1,
            setAttribute: jest.fn(),
            click: jest.fn(),
            href: '',
            download: ''
        };
		createElementSpy.mockImplementation( ( tag ) => {
			if ( tag === 'a' ) return mockAnchor;
			return originalCreateElement( tag );
		} );

		render( <PluginSetting options={{ performance_audit: { pagespeed_api_key: 'SUPER_SECRET' } }} /> );

		const exportBtn = screen.getByRole( 'button', { name: /Export Settings/i } );
		fireEvent.click( exportBtn );

		expect( mockAnchor.click ).toHaveBeenCalled();
		expect( mockAnchor.download ).toMatch( /plugin-settings_.*\.json/ );

		createElementSpy.mockRestore();
	} );

	it( 'disables import button when no file is selected', () => {
		render( <PluginSetting options={{}} /> );

		const importBtn = screen.getByRole( 'button', { name: /Import Settings/i } );
		expect(importBtn).toBeDisabled();
	} );

	it( 'imports settings successfully', async () => {
		apiCall.mockResolvedValueOnce( { success: true, message: 'File imported successfully' } );

		render( <PluginSetting options={{}} /> );

		const file = new File( [ '{"test":"value"}' ], 'settings.json', { type: 'application/json' } );
		const input = screen.getByLabelText( /Select configuration file/i );

		fireEvent.change( input, { target: { files: [ file ] } } );

		const importBtn = screen.getByRole( 'button', { name: /Import Settings/i } );

        // Button should not be disabled after file selection
        expect(importBtn).not.toBeDisabled();

        // Mock FileReader
        const mockFileReader = {
            readAsText: jest.fn(),
            result: '{"test":"value"}',
            onload: null,
            onerror: null,
            onabort: null
        };
        jest.spyOn(window, 'FileReader').mockImplementation(() => mockFileReader);

        fireEvent.click( importBtn );

        // Modal is open, click confirm
        const confirmBtn = screen.getByRole('button', { name: /Confirm/i });
        fireEvent.click(confirmBtn);

        // Simulate onload
        act(() => {
            mockFileReader.onload({ target: { result: mockFileReader.result } });
        });

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'import_settings', {
				action: 'import_settings',
				settings: { test: 'value' },
			} );
			expect( screen.getByText( 'File imported successfully' ) ).toBeInTheDocument();
		} );
	} );

    it( 'handles import error with invalid JSON', async () => {
		render( <PluginSetting options={{}} /> );

		const file = new File( [ 'INVALID_JSON' ], 'settings.json', { type: 'application/json' } );
		const input = screen.getByLabelText( /Select configuration file/i );

		fireEvent.change( input, { target: { files: [ file ] } } );

		const importBtn = screen.getByRole( 'button', { name: /Import Settings/i } );

        // Mock FileReader
        const mockFileReader = {
            readAsText: jest.fn(),
            result: 'INVALID_JSON',
            onload: null,
            onerror: null,
            onabort: null
        };
        jest.spyOn(window, 'FileReader').mockImplementation(() => mockFileReader);

        fireEvent.click( importBtn );

        // Modal is open, click confirm
        const confirmBtn = screen.getByRole('button', { name: /Confirm/i });
        fireEvent.click(confirmBtn);

        // Simulate onload with invalid JSON
        act(() => {
            mockFileReader.onload({ target: { result: mockFileReader.result } });
        });

		await waitFor( () => {
			expect( screen.getByText( 'Invalid file format. Please select a valid JSON file.' ) ).toBeInTheDocument();
		} );
	} );

    it( 'handles FileReader error during import', async () => {
		render( <PluginSetting options={{}} /> );

		const file = new File( [ '{"test":"value"}' ], 'settings.json', { type: 'application/json' } );
		const input = screen.getByLabelText( /Select configuration file/i );

		fireEvent.change( input, { target: { files: [ file ] } } );

		const importBtn = screen.getByRole( 'button', { name: /Import Settings/i } );

        // Mock FileReader
        const mockFileReader = {
            readAsText: jest.fn(),
            onload: null,
            onerror: null,
            onabort: null
        };
        jest.spyOn(window, 'FileReader').mockImplementation(() => mockFileReader);

        fireEvent.click( importBtn );

        // Modal is open, click confirm
        const confirmBtn = screen.getByRole('button', { name: /Confirm/i });
        fireEvent.click(confirmBtn);

        // Simulate onerror
        act(() => {
            mockFileReader.onerror();
        });

		await waitFor( () => {
			expect( screen.getByText( 'Error reading file' ) ).toBeInTheDocument();
		} );
	} );
} );
