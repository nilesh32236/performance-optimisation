/**
 * @jest-environment jsdom
 */

/**
 * External dependencies
 */
import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
/**
 * Internal dependencies
 */
import WizardApp from '../../src/components/Wizard/WizardApp';

// Mock fetch
global.fetch = jest.fn();

const mockWizardData = {
	apiUrl: 'http://test.com/wp-json/performance-optimisation/v1/',
	nonce: 'test-nonce',
	translations: {
		welcomeTitle: 'Welcome to Performance Optimisation!',
		letsGetStarted: "Let's Get Started",
		nextStep: 'Next',
		previousStep: 'Back',
		finishSetup: 'Finish Setup & Start Optimizing',
		standardPreset: 'Standard (Safe)',
		recommendedPreset: 'Recommended (Balanced)',
		aggressivePreset: 'Aggressive (Maximum Speed)',
		recommended: 'Recommended',
	},
};

describe( 'Wizard Integration Flow', () => {
	beforeEach( () => {
		fetch.mockClear();
		delete window.location;
		window.location = { href: '' };
	} );

	test( 'complete wizard flow with standard preset', async () => {
		// Mock successful API response
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: true,
				data: {
					redirect_url:
						'http://test.com/wp-admin/admin.php?page=performance-optimisation',
				},
			} ),
		} );

		render( <WizardApp wizardData={ mockWizardData } /> );

		// Step 1: Welcome
		expect( screen.getByText( 'Welcome to Performance Optimisation!' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Step 1 of 4' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		// Step 2: Presets
		await waitFor( () => {
			expect( screen.getByText( 'Step 2 of 4' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Choose Your Optimization Level' ) ).toBeInTheDocument();
		} );

		// Select standard preset
		const standardPreset = screen.getByLabelText( /Select.*Standard.*preset/i );
		fireEvent.click( standardPreset );

		fireEvent.click( screen.getByText( 'Next' ) );

		// Step 3: Features
		await waitFor( () => {
			expect( screen.getByText( 'Step 3 of 4' ) ).toBeInTheDocument();
		} );

		// Don't enable any additional features, just proceed
		fireEvent.click( screen.getByText( 'Next' ) );

		// Step 4: Summary
		await waitFor( () => {
			expect( screen.getByText( 'Step 4 of 4' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Setup Summary' ) ).toBeInTheDocument();
		} );

		// Verify summary shows standard preset
		expect( screen.getByText( 'Standard (Safe)' ) ).toBeInTheDocument();

		// Finish setup
		fireEvent.click( screen.getByText( 'Finish Setup & Start Optimizing' ) );

		// Verify API call
		await waitFor( () => {
			expect( fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/performance-optimisation/v1/wizard-setup',
				expect.objectContaining( {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': 'test-nonce',
					},
					body: JSON.stringify( {
						preset: 'standard',
						preloadCache: false,
						imageConversion: false,
					} ),
				} )
			);
		} );
	} );

	test( 'complete wizard flow with recommended preset and features', async () => {
		// Mock successful API response
		fetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: true,
				data: {
					redirect_url:
						'http://test.com/wp-admin/admin.php?page=performance-optimisation',
				},
			} ),
		} );

		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate to presets
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			expect( screen.getByText( 'Choose Your Optimization Level' ) ).toBeInTheDocument();
		} );

		// Select recommended preset
		const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );
		fireEvent.click( recommendedPreset );

		fireEvent.click( screen.getByText( 'Next' ) );

		// Enable features
		await waitFor( () => {
			expect( screen.getByText( 'Step 3 of 4' ) ).toBeInTheDocument();
		} );

		// Enable cache preloading
		const cacheToggle = screen.getByLabelText( /Enable.*Cache.*Preloading/i );
		fireEvent.click( cacheToggle );

		// Enable image conversion
		const imageToggle = screen.getByLabelText( /Enable.*Image.*Conversion/i );
		fireEvent.click( imageToggle );

		fireEvent.click( screen.getByText( 'Next' ) );

		// Summary and finish
		await waitFor( () => {
			expect( screen.getByText( 'Setup Summary' ) ).toBeInTheDocument();
		} );

		fireEvent.click( screen.getByText( 'Finish Setup & Start Optimizing' ) );

		// Verify API call with features enabled
		await waitFor( () => {
			expect( fetch ).toHaveBeenCalledWith(
				'http://test.com/wp-json/performance-optimisation/v1/wizard-setup',
				expect.objectContaining( {
					body: JSON.stringify( {
						preset: 'recommended',
						preloadCache: true,
						imageConversion: true,
					} ),
				} )
			);
		} );
	} );

	test( 'back navigation works correctly', async () => {
		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate forward
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			expect( screen.getByText( 'Step 2 of 4' ) ).toBeInTheDocument();
		} );

		// Select preset and go forward
		const standardPreset = screen.getByLabelText( /Select.*Standard.*preset/i );
		fireEvent.click( standardPreset );
		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			expect( screen.getByText( 'Step 3 of 4' ) ).toBeInTheDocument();
		} );

		// Go back
		fireEvent.click( screen.getByText( 'Back' ) );

		await waitFor( () => {
			expect( screen.getByText( 'Step 2 of 4' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Choose Your Optimization Level' ) ).toBeInTheDocument();
		} );

		// Verify preset is still selected
		const selectedPreset = screen.getByLabelText( /Select.*Standard.*preset/i );
		expect( selectedPreset ).toHaveAttribute( 'aria-checked', 'true' );
	} );

	test( 'handles API validation errors', async () => {
		// Mock validation error response
		fetch.mockResolvedValueOnce( {
			ok: false,
			status: 400,
			json: async () => ( {
				success: false,
				data: { message: 'Invalid preset selection' },
			} ),
		} );

		render( <WizardApp wizardData={ mockWizardData } /> );

		// Complete wizard flow quickly
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );
			fireEvent.click( recommendedPreset );
		} );

		fireEvent.click( screen.getByText( 'Next' ) );
		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			fireEvent.click( screen.getByText( 'Finish Setup & Start Optimizing' ) );
		} );

		// Should show error message
		await waitFor( () => {
			expect( screen.getByText( /Request failed with status 400/ ) ).toBeInTheDocument();
		} );
	} );

	test( 'handles permission errors', async () => {
		// Mock permission error response
		fetch.mockResolvedValueOnce( {
			ok: false,
			status: 403,
			json: async () => ( {
				success: false,
				data: { message: 'Permission denied' },
			} ),
		} );

		render( <WizardApp wizardData={ mockWizardData } /> );

		// Complete wizard flow
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );
			fireEvent.click( recommendedPreset );
		} );

		fireEvent.click( screen.getByText( 'Next' ) );
		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			fireEvent.click( screen.getByText( 'Finish Setup & Start Optimizing' ) );
		} );

		// Should show permission error
		await waitFor( () => {
			expect( screen.getByText( /Permission denied/ ) ).toBeInTheDocument();
		} );
	} );

	test( 'progress indicator updates correctly', async () => {
		render( <WizardApp wizardData={ mockWizardData } /> );

		// Check initial progress
		const progressBar = screen.getByRole( 'progressbar' );
		expect( progressBar ).toHaveAttribute( 'aria-valuenow', '1' );
		expect( progressBar ).toHaveAttribute( 'aria-valuemax', '4' );

		// Navigate to step 2
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			expect( progressBar ).toHaveAttribute( 'aria-valuenow', '2' );
		} );

		// Navigate to step 3
		const standardPreset = screen.getByLabelText( /Select.*Standard.*preset/i );
		fireEvent.click( standardPreset );
		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			expect( progressBar ).toHaveAttribute( 'aria-valuenow', '3' );
		} );

		// Navigate to step 4
		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			expect( progressBar ).toHaveAttribute( 'aria-valuenow', '4' );
		} );
	} );
} );
