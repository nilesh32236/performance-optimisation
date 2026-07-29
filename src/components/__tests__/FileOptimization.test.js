import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import FileOptimization from '../FileOptimization';

// Mock the API request
jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

import { apiCall } from '../../lib/apiRequest';

describe( 'FileOptimization Component', () => {
	beforeEach( () => {
		global.wppoSettings = {
			apiUrl: 'https://example.com/wp-json/performance-optimisation/v1/',
			nonce: 'test-nonce',
			settings: {},
			translations: {},
		};
		jest.clearAllMocks();
	} );

	it( 'renders the component and defaults to the assets tab', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		expect(
			screen.getByRole( 'tab', { name: /Assets/i } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: /Scripts/i } )
		).toBeInTheDocument();
		expect( screen.getByText( 'CSS Optimization' ) ).toBeInTheDocument(); // Within assets tab
	} );

	it( 'updates form state when switch is toggled', () => {
		render(
			<FileOptimization
				options={ { minifyCSS: false } }
				serverRules={ {} }
			/>
		);

		const minifyCssSwitch = screen.getByLabelText( /Minify CSS/i );
		expect( minifyCssSwitch ).not.toBeChecked();

		fireEvent.click( minifyCssSwitch );
		expect( minifyCssSwitch ).toBeChecked();
	} );

	it( 'submits settings successfully and displays success notification', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		expect( apiCall ).toHaveBeenCalledWith(
			'update_settings',
			expect.objectContaining( {
				tab: 'file_optimisation',
				settings: expect.any( Object ),
			} )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'Settings updated successfully.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'submits settings but fails and displays error notification', async () => {
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Failed updating settings on server.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Failed updating settings on server.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'handles sad path network error and logs to console', async () => {
		const mockError = new Error( 'Network Failure' );
		apiCall.mockRejectedValueOnce( mockError );

		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'An unexpected error occurred.' )
			).toBeInTheDocument();
		} );

		expect( consoleSpy ).toHaveBeenCalledWith(
			'Failed to update settings.',
			mockError
		);

		consoleSpy.mockRestore();
	} );

	it( 'navigates sub-tabs using keyboard arrows', async () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const assetsTab = screen.getByRole( 'tab', { name: /Assets/i } );
		const scriptsTab = screen.getByRole( 'tab', { name: /Scripts/i } );

		assetsTab.focus();
		expect( assetsTab ).toHaveFocus();

		// Simulate right arrow
		fireEvent.keyDown( assetsTab, { key: 'ArrowRight' } );

		await waitFor( () => {
			expect( scriptsTab ).toHaveFocus();
		} );

		// Simulate left arrow on scripts tab
		fireEvent.keyDown( scriptsTab, { key: 'ArrowLeft' } );

		await waitFor( () => {
			expect( assetsTab ).toHaveFocus();
		} );

		// Simulate ignored key
		fireEvent.keyDown( assetsTab, { key: 'Enter' } );
		expect( assetsTab ).toHaveFocus();
	} );

	it( 'renders apache server rules correctly', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ { server_type: 'apache' } }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect(
			screen.getByText( /Enable Server Rules/i )
		).toBeInTheDocument();
		const enableRulesSwitch =
			screen.getByLabelText( /Enable Server Rules/i );
		expect( enableRulesSwitch ).not.toBeDisabled();
	} );

	it( 'renders nginx server rules correctly', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ {
					server_type: 'nginx',
					nginx: 'nginx_rules_mock',
				} }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect( screen.getByText( /Nginx Detected/i ) ).toBeInTheDocument();
		expect( screen.getByText( 'nginx_rules_mock' ) ).toBeInTheDocument();
	} );

	it( 'renders unrecognised server message for other servers', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ { server_type: 'other' } }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect(
			screen.getByText( /Unrecognised server software/i )
		).toBeInTheDocument();
	} );

	it( 'renders Remove Unused CSS switch in assets tab', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		expect( screen.getByText( 'Remove Unused CSS' ) ).toBeInTheDocument();
	} );

	it( 'toggling Remove Unused CSS shows safelist textarea and regenerate button', () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );
		expect( screen.getByText( 'Safelist Selectors' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Regenerate Used CSS' ) ).toBeInTheDocument();
	} );

	it( 'submit includes removeUnusedCSS and excludeUnusedCSS in settings payload', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render(
			<FileOptimization
				options={ {
					removeUnusedCSS: true,
					excludeUnusedCSS: '.my-class',
				} }
				serverRules={ {} }
			/>
		);

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						removeUnusedCSS: true,
						excludeUnusedCSS: '.my-class',
					} ),
				} )
			);
		} );
	} );

	it( 'end-to-end: toggle Remove Unused CSS, type safelist, submit includes both', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		// Initially the safelist textarea and regenerate button should be hidden.
		expect(
			screen.queryByText( 'Safelist Selectors' )
		).not.toBeInTheDocument();

		// Toggle Remove Unused CSS on.
		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );

		// Now safelist and regenerate button should appear.
		expect( screen.getByText( 'Safelist Selectors' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Regenerate Used CSS' ) ).toBeInTheDocument();

		// Type into the safelist textarea.
		const textarea = screen.getByLabelText( 'Safelist Selectors' );
		fireEvent.change( textarea, {
			target: { value: '.my-safelist\n.another-rule' },
		} );

		// Submit the form.
		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						removeUnusedCSS: true,
						excludeUnusedCSS: '.my-safelist\n.another-rule',
					} ),
				} )
			);
		} );
	} );

	it( 'regenerate button calls used_css_regenerate API after saving settings', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Used CSS regeneration queued.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const switchField = screen.getByLabelText( /Remove Unused CSS/i );
		fireEvent.click( switchField );

		const regenerateButton = screen.getByText( 'Regenerate Used CSS' );
		fireEvent.click( regenerateButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledTimes( 2 );
		} );

		expect( apiCall.mock.calls[ 0 ][ 0 ] ).toBe( 'update_settings' );
		expect( apiCall.mock.calls[ 0 ][ 1 ].tab ).toBe( 'file_optimisation' );
		expect( apiCall.mock.calls[ 0 ][ 1 ].settings.removeUnusedCSS ).toBe(
			true
		);

		expect( apiCall.mock.calls[ 1 ][ 0 ] ).toBe( 'used_css_regenerate' );

		await waitFor( () => {
			expect(
				screen.getByText( 'Used CSS regeneration queued.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'renders server rules correctly without double-encoding', () => {
		render(
			<FileOptimization
				options={ {} }
				serverRules={ {
					server_type: 'nginx',
					nginx: 'server { listen 80; }',
				} }
			/>
		);

		const networkTab = screen.getByRole( 'tab', { name: /Network/i } );
		fireEvent.click( networkTab );

		expect( screen.getByText( /Nginx Detected/i ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'server { listen 80; }' )
		).toBeInTheDocument();
	} );

	it( 'renders Critical CSS switch in the assets tab', () => {
		render(
			<FileOptimization
				options={ { criticalCSS: false } }
				serverRules={ {} }
			/>
		);

		expect( screen.getByLabelText( /Critical CSS/i ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /Critical CSS/i ) ).not.toBeChecked();
	} );

	it( 'shows CriticalCssPanel when Critical CSS is enabled', () => {
		render(
			<FileOptimization
				options={ { criticalCSS: true } }
				serverRules={ {} }
				ccssStatus={ {} }
			/>
		);

		expect(
			screen.getByText( /Critical CSS Status/i )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Regenerate All/i } )
		).toBeInTheDocument();
	} );

	it( 'toggles Critical CSS switch to show/hide panel', () => {
		render(
			<FileOptimization
				options={ { criticalCSS: false } }
				serverRules={ {} }
				ccssStatus={ {} }
			/>
		);

		expect(
			screen.queryByText( /Critical CSS Status/i )
		).not.toBeInTheDocument();

		const criticalCssSwitch = screen.getByLabelText( /Critical CSS/i );
		fireEvent.click( criticalCssSwitch );

		expect(
			screen.getByText( /Critical CSS Status/i )
		).toBeInTheDocument();
	} );

	it( 'calls apiCall when Regenerate All is clicked', async () => {
		apiCall.mockResolvedValueOnce( { success: true } );

		render(
			<FileOptimization
				options={ { criticalCSS: true } }
				serverRules={ {} }
				ccssStatus={ { test_hash: { status: 'none', label: 'Test' } } }
				onCcssRefresh={ jest.fn() }
			/>
		);

		const regenerateButton = screen.getByRole( 'button', {
			name: /Regenerate All/i,
		} );
		fireEvent.click( regenerateButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'regenerate_ccss' );
		} );

		expect( apiCall ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'displays template label from ccssStatus object', () => {
		render(
			<FileOptimization
				options={ { criticalCSS: true } }
				serverRules={ {} }
				ccssStatus={ {
					abc123: { status: 'ready', label: 'Home' },
					def456: { status: 'none', label: 'Single Post' },
				} }
			/>
		);

		expect( screen.getByText( 'Home' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Single Post' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Generated' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not Generated' ) ).toBeInTheDocument();
	} );

	it( 'renders Host Google Fonts Locally switch in the assets tab', () => {
		render(
			<FileOptimization
				options={ { hostGoogleFontsLocally: false } }
				serverRules={ {} }
			/>
		);

		expect(
			screen.getByLabelText( /Host Google Fonts Locally/i )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Host Google Fonts Locally/i )
		).not.toBeChecked();
	} );

	it( 'submits hostGoogleFontsLocally setting correctly', async () => {
		apiCall.mockResolvedValueOnce( { success: true } );

		render(
			<FileOptimization
				options={ { hostGoogleFontsLocally: false } }
				serverRules={ {} }
			/>
		);

		const switchField = screen.getByLabelText(
			/Host Google Fonts Locally/i
		);
		fireEvent.click( switchField );
		expect( switchField ).toBeChecked();

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'file_optimisation',
					settings: expect.objectContaining( {
						hostGoogleFontsLocally: true,
					} ),
				} )
			);
		} );
	} );
} );
