import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import PreloadSettings from '../PreloadSettings';

// Mock the API request
jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

import { apiCall } from '../../lib/apiRequest';

describe( 'PreloadSettings Component', () => {
	beforeEach( () => {
		global.wppoSettings = { translations: {} };
		jest.clearAllMocks();
	} );

	it( 'renders default fields correctly', () => {
		render( <PreloadSettings /> );

		expect( screen.getByText( 'Preload Settings' ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Enable Preload Cache/i )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Preconnect/i, {
				selector: 'input[type="checkbox"]',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /DNS Prefetch/i, {
				selector: 'input[type="checkbox"]',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Preload Fonts/i, {
				selector: 'input[type="checkbox"]',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByLabelText( /Preload Critical CSS/i, {
				selector: 'input[type="checkbox"]',
			} )
		).toBeInTheDocument();

		// Conditional fields should not be visible initially
		expect(
			screen.queryByLabelText( /Exclude URLs from Cache Warm-up/i )
		).not.toBeInTheDocument();
		expect(
			screen.queryByLabelText( /Preconnect Origins/i )
		).not.toBeInTheDocument();
		expect(
			screen.queryByLabelText( /DNS Prefetch Origins/i )
		).not.toBeInTheDocument();
		expect(
			screen.queryByLabelText( /Font URLs to Preload/i )
		).not.toBeInTheDocument();
		expect(
			screen.queryByLabelText( /CSS URLs to Preload/i )
		).not.toBeInTheDocument();
	} );

	it( 'toggles conditional fields when switches are clicked', () => {
		render( <PreloadSettings /> );

		// Enable Preload Cache
		fireEvent.click( screen.getByLabelText( /Enable Preload Cache/i ) );
		expect(
			screen.getByLabelText( /Exclude URLs from Cache Warm-up/i )
		).toBeInTheDocument();

		// Preconnect
		fireEvent.click(
			screen.getByLabelText( /Preconnect/i, {
				selector: 'input[type="checkbox"]',
			} )
		);
		expect(
			screen.getByLabelText( /Preconnect Origins/i )
		).toBeInTheDocument();

		// DNS Prefetch
		fireEvent.click(
			screen.getByLabelText( /DNS Prefetch/i, {
				selector: 'input[type="checkbox"]',
			} )
		);
		expect(
			screen.getByLabelText( /DNS Prefetch Origins/i )
		).toBeInTheDocument();

		// Preload Fonts
		fireEvent.click(
			screen.getByLabelText( /Preload Fonts/i, {
				selector: 'input[type="checkbox"]',
			} )
		);
		expect(
			screen.getByLabelText( /Font URLs to Preload/i )
		).toBeInTheDocument();

		// Preload Critical CSS
		fireEvent.click(
			screen.getByLabelText( /Preload Critical CSS/i, {
				selector: 'input[type="checkbox"]',
			} )
		);
		expect(
			screen.getByLabelText( /CSS URLs to Preload/i )
		).toBeInTheDocument();
	} );

	it( 'submits the form successfully with correct payload', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render( <PreloadSettings /> );

		// Change some settings
		fireEvent.click( screen.getByLabelText( /Enable Preload Cache/i ) );
		const excludeTextarea = screen.getByLabelText(
			/Exclude URLs from Cache Warm-up/i
		);
		fireEvent.change( excludeTextarea, {
			target: { value: 'custom/exclude' },
		} );

		fireEvent.click(
			screen.getByLabelText( /Preconnect/i, {
				selector: 'input[type="checkbox"]',
			} )
		);
		const preconnectTextarea =
			screen.getByLabelText( /Preconnect Origins/i );
		fireEvent.change( preconnectTextarea, {
			target: { value: 'https://test.com' },
		} );

		// Submit form
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Settings/i } )
		);

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'preload_settings',
				settings: expect.objectContaining( {
					enablePreloadCache: true,
					excludePreloadCache: 'custom/exclude',
					preconnect: true,
					preconnectOrigins: 'https://test.com',
				} ),
			} );
			expect(
				screen.getByText( 'Settings updated successfully.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'handles API failure (success: false)', async () => {
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Custom error from API.',
		} );

		render( <PreloadSettings /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Settings/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'Custom error from API.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'handles network failure gracefully', async () => {
		apiCall.mockRejectedValueOnce( new Error( 'Network error' ) );

		render( <PreloadSettings /> );

		// Ignore console.error for this specific test
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Settings/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'An unexpected error occurred.' )
			).toBeInTheDocument();
		} );

		consoleSpy.mockRestore();
	} );
} );
