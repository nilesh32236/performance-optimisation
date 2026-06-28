import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import PluginSetting from '../PluginSetting';
import { apiCall, fetchRecentActivities } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	fetchRecentActivities: jest.fn(),
} ) );

describe( 'PluginSetting Component', () => {
	let originalCreateObjectURL;
	let originalRevokeObjectURL;
	let consoleErrorSpy;

	beforeEach( () => {
		global.wppoSettings = {
			settings: {
				performance_audit: {
					pagespeed_api_key: 'old_key',
				},
			},
			performance_audit: {
				pagespeedApiKeyConfigured: true,
			},
		};
		jest.clearAllMocks();

		originalCreateObjectURL = global.URL.createObjectURL;
		originalRevokeObjectURL = global.URL.revokeObjectURL;
		global.URL.createObjectURL = jest.fn( () => 'mock-url' );
		global.URL.revokeObjectURL = jest.fn();

		consoleErrorSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		global.URL.createObjectURL = originalCreateObjectURL;
		global.URL.revokeObjectURL = originalRevokeObjectURL;
		consoleErrorSpy.mockRestore();
	} );

	it( 'renders the component', () => {
		render( <PluginSetting options={ {} } /> );
		expect( screen.getByText( /Manage your plugin configuration/i ) ).toBeInTheDocument();
	} );

	describe( 'PageSpeed API Key', () => {
		it( 'saves the API key successfully', async () => {
			apiCall.mockResolvedValueOnce( { success: true } );
			render( <PluginSetting options={ {} } /> );

			const input = screen.getByLabelText( /Google PageSpeed API Key/i );
			fireEvent.change( input, { target: { value: 'new_api_key' } } );

			const saveBtn = screen.getByRole( 'button', { name: /Save Settings/i } );
			fireEvent.click( saveBtn );

			await waitFor( () => {
				expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
					tab: 'performance_audit',
					settings: {
						pagespeed_api_key: 'new_api_key',
					},
				} );
				expect( screen.getByText( /API key saved/i ) ).toBeInTheDocument();
			} );

			expect( global.wppoSettings.settings.performance_audit.pagespeed_api_key ).toBe( 'new_api_key' );
		} );

		it( 'handles API key save error', async () => {
			apiCall.mockResolvedValueOnce( { success: false, message: 'Invalid key' } );
			render( <PluginSetting options={ {} } /> );

			const input = screen.getByLabelText( /Google PageSpeed API Key/i );
			fireEvent.change( input, { target: { value: 'bad_key' } } );

			const saveBtn = screen.getByRole( 'button', { name: /Save Settings/i } );
			fireEvent.click( saveBtn );

			await waitFor( () => {
				expect( screen.getByText( /Invalid key/i ) ).toBeInTheDocument();
			} );
		} );

		it( 'handles API network failure on save', async () => {
			apiCall.mockRejectedValueOnce( new Error( 'Network error' ) );
			render( <PluginSetting options={ {} } /> );

			const saveBtn = screen.getByRole( 'button', { name: /Save Settings/i } );
			fireEvent.click( saveBtn );

			await waitFor( () => {
				expect( screen.getByText( /Error saving API key/i ) ).toBeInTheDocument();
			} );
			expect( consoleErrorSpy ).toHaveBeenCalled();
		} );
	} );

	describe( 'Export Settings', () => {
		it( 'redacts the API key and downloads JSON', async () => {
			const mockClick = jest.fn();
			const anchorMock = {
				click: mockClick,
				setAttribute: jest.fn(),
				nodeType: 1, // Add mock properties to satisfy jsdom Node expectations if appended, though it shouldn't be appended here.
			};

			// Only mock document.createElement for 'a' to avoid breaking React's own element creation.
			const originalCreateElement = document.createElement.bind(document);
			jest.spyOn( document, 'createElement' ).mockImplementation( (tagName) => {
				if ( tagName === 'a' ) return anchorMock;
				return originalCreateElement(tagName);
			} );

			const options = {
				performance_audit: {
					pagespeed_api_key: 'secret123',
				},
				other_setting: true,
			};

			render( <PluginSetting options={ options } /> );

			const exportBtn = screen.getByRole( 'button', { name: /Export Settings/i } );
			fireEvent.click( exportBtn );

			// Check what was stringified in the Blob
			// URL.createObjectURL is called with the Blob
			expect( global.URL.createObjectURL ).toHaveBeenCalled();
			expect( document.createElement ).toHaveBeenCalledWith( 'a' );
			expect( mockClick ).toHaveBeenCalled();
			expect( global.URL.revokeObjectURL ).toHaveBeenCalledWith( 'mock-url' );

			// Get the blob passed to createObjectURL
			const blobArg = global.URL.createObjectURL.mock.calls[0][0];

			// In jsdom/jest, Blob implementation might not have .text()
			// We can use a FileReader to read it.
			const text = await new Promise( ( resolve ) => {
				const reader = new FileReader();
				reader.onload = () => resolve( reader.result );
				reader.readAsText( blobArg );
			} );

			const parsed = JSON.parse( text );

			expect( parsed.performance_audit.pagespeed_api_key ).toBe( 'REDACTED' );
			expect( parsed.other_setting ).toBe( true );
		} );
	} );

	describe( 'Import Settings', () => {
		it( 'shows error when trying to import without a file', () => {
			render( <PluginSetting options={ {} } /> );

			const importBtn = screen.getByRole( 'button', { name: /Import Settings/i } );
			// Button is initially disabled, so we have to ensure it's not clickable or it doesn't do anything
			expect( importBtn ).toBeDisabled();
		} );

		it( 'reads file and calls API on successful import', async () => {
			apiCall.mockResolvedValueOnce( { success: true, message: 'Imported ok' } );

			render( <PluginSetting options={ {} } /> );

			const fileInput = screen.getByLabelText( /Select configuration file/i );
			const mockFile = new File( [ '{"imported_key": "val"}' ], 'test.json', { type: 'application/json' } );

			// Simulate file selection
			fireEvent.change( fileInput, { target: { files: [ mockFile ] } } );

			const importBtn = screen.getByRole( 'button', { name: /Import Settings/i } );
			expect( importBtn ).not.toBeDisabled();
			fireEvent.click( importBtn );

			// Confirm dialog appears
			const confirmBtn = screen.getByRole( 'button', { name: /Confirm/i, hidden: true } );
			fireEvent.click( confirmBtn );

			await waitFor( () => {
				expect( apiCall ).toHaveBeenCalledWith( 'import_settings', {
					action: 'import_settings',
					settings: { imported_key: 'val' },
				} );
				expect( screen.getByText( /Imported ok/i ) ).toBeInTheDocument();
			} );
		} );

		it( 'handles invalid JSON file format', async () => {
			render( <PluginSetting options={ {} } /> );

			const fileInput = screen.getByLabelText( /Select configuration file/i );
			const mockFile = new File( [ 'not valid json' ], 'test.json', { type: 'application/json' } );

			fireEvent.change( fileInput, { target: { files: [ mockFile ] } } );

			const importBtn = screen.getByRole( 'button', { name: /Import Settings/i } );
			fireEvent.click( importBtn );

			const confirmBtn = screen.getByRole( 'button', { name: /Confirm/i, hidden: true } );
			fireEvent.click( confirmBtn );

			await waitFor( () => {
				expect( screen.getByText( /Invalid file format/i ) ).toBeInTheDocument();
			} );
			expect( apiCall ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'Activity Log', () => {
		it( 'loads activity log successfully', async () => {
			fetchRecentActivities.mockResolvedValueOnce( {
				activities: [ { activity: 'Cleared cache' }, { activity: 'Optimized image' } ],
				current_page: 1,
				total_pages: 1,
			} );
			render( <PluginSetting options={ {} } /> );

			const loadBtn = screen.getByRole( 'button', { name: /Load Activity Log/i } );
			fireEvent.click( loadBtn );

			await waitFor( () => {
				expect( fetchRecentActivities ).toHaveBeenCalledWith( 1 );
				expect( screen.getByText( /Cleared cache/i ) ).toBeInTheDocument();
				expect( screen.getByText( /Optimized image/i ) ).toBeInTheDocument();
			} );
		} );

		it( 'handles failed activity log load gracefully', async () => {
			fetchRecentActivities.mockRejectedValueOnce( new Error( 'Network timeout' ) );
			render( <PluginSetting options={ {} } /> );

			const loadBtn = screen.getByRole( 'button', { name: /Load Activity Log/i } );
			fireEvent.click( loadBtn );

			await waitFor( () => {
				expect( screen.getByText( /Network timeout/i ) ).toBeInTheDocument();
			} );
			expect( consoleErrorSpy ).toHaveBeenCalled();
		} );
	} );
} );
