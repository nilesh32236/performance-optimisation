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
		global.wppoSettings = { translations: {} };
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
			'Failed updating file optimisation settings',
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

	it( 'disables enableServerRules automatically if server type is not apache', async () => {
		render(
			<FileOptimization
				options={ { enableServerRules: true } }
				serverRules={ { server_type: 'nginx' } }
			/>
		);
		// Assuming there is some UI reflection of the updated options or we test the hook implicitly via the submit behavior,
		// but `FileOptimization` just passes it internally.
		// Let's trigger a save and check the payload to verify it was set to false
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
						enableServerRules: false,
					} ),
				} )
			);
		} );
	} );

	it( 'displays error notification on failed API response (success: false)', async () => {
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Custom API error message.',
		} );

		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Custom API error message.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'ignores non-arrow keydown events during sub-tab navigation', async () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );

		const assetsTab = screen.getByRole( 'tab', { name: /Assets/i } );

		assetsTab.focus();
		expect( assetsTab ).toHaveFocus();

		// Simulate enter key, which is not an arrow key
		fireEvent.keyDown( assetsTab, { key: 'Enter' } );

		// The focus should remain on assetsTab since no navigation occurred
		await waitFor( () => {
			expect( assetsTab ).toHaveFocus();
		} );
	} );
} );
