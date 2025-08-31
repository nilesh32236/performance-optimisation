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
	},
};

describe( 'WizardApp', () => {
	beforeEach( () => {
		fetch.mockClear();
	} );

	test( 'renders welcome step initially', () => {
		render( <WizardApp wizardData={ mockWizardData } /> );

		expect( screen.getByText( 'Welcome to Performance Optimisation!' ) ).toBeInTheDocument();
		expect( screen.getByText( "Let's Get Started" ) ).toBeInTheDocument();
		expect( screen.getByText( 'Step 1 of 4' ) ).toBeInTheDocument();
	} );

	test( 'navigates through steps correctly', async () => {
		render( <WizardApp wizardData={ mockWizardData } /> );

		// Start from welcome step
		expect( screen.getByText( 'Step 1 of 4' ) ).toBeInTheDocument();

		// Click next to go to presets step
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			expect( screen.getByText( 'Step 2 of 4' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Choose Your Optimization Level' ) ).toBeInTheDocument();
		} );
	} );

	test( 'validates preset selection before proceeding', async () => {
		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate to presets step
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			expect( screen.getByText( 'Choose Your Optimization Level' ) ).toBeInTheDocument();
		} );

		// Try to proceed without selecting a preset
		const nextButton = screen.getByText( 'Next' );
		fireEvent.click( nextButton );

		// Should show validation error
		await waitFor( () => {
			expect(
				screen.getByText( 'Please select an optimization preset to continue.' )
			).toBeInTheDocument();
		} );
	} );

	test( 'allows preset selection and navigation', async () => {
		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate to presets step
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			expect( screen.getByText( 'Choose Your Optimization Level' ) ).toBeInTheDocument();
		} );

		// Select recommended preset
		const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );
		fireEvent.click( recommendedPreset );

		// Now next button should work
		const nextButton = screen.getByText( 'Next' );
		fireEvent.click( nextButton );

		await waitFor( () => {
			expect( screen.getByText( 'Step 3 of 4' ) ).toBeInTheDocument();
		} );
	} );

	test( 'handles API success response', async () => {
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

		// Mock window.location.href
		delete window.location;
		window.location = { href: '' };

		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate through all steps
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );
			fireEvent.click( recommendedPreset );
		} );

		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			fireEvent.click( screen.getByText( 'Next' ) );
		} );

		await waitFor( () => {
			const finishButton = screen.getByText( 'Finish Setup & Start Optimizing' );
			fireEvent.click( finishButton );
		} );

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
						preset: 'recommended',
						preloadCache: false,
						imageConversion: false,
					} ),
				} )
			);
		} );
	} );

	test( 'handles API error response', async () => {
		// Mock error API response
		fetch.mockResolvedValueOnce( {
			ok: false,
			status: 500,
			json: async () => ( {
				success: false,
				data: { message: 'Server error occurred' },
			} ),
		} );

		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate through all steps quickly
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );
			fireEvent.click( recommendedPreset );
		} );

		fireEvent.click( screen.getByText( 'Next' ) );
		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			const finishButton = screen.getByText( 'Finish Setup & Start Optimizing' );
			fireEvent.click( finishButton );
		} );

		await waitFor( () => {
			expect( screen.getByText( /Server error/ ) ).toBeInTheDocument();
		} );
	} );

	test( 'handles network error', async () => {
		// Mock network error
		fetch.mockRejectedValueOnce( new Error( 'Network error' ) );

		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate through all steps quickly
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );
			fireEvent.click( recommendedPreset );
		} );

		fireEvent.click( screen.getByText( 'Next' ) );
		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			const finishButton = screen.getByText( 'Finish Setup & Start Optimizing' );
			fireEvent.click( finishButton );
		} );

		await waitFor( () => {
			expect( screen.getByText( /Network error/ ) ).toBeInTheDocument();
		} );
	} );

	test( 'shows loading state during API call', async () => {
		// Mock delayed API response
		fetch.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) =>
					setTimeout(
						() =>
							resolve( {
								ok: true,
								json: async () => ( {
									success: true,
									data: { redirect_url: 'test' },
								} ),
							} ),
						100
					)
				)
		);

		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate through all steps
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );
			fireEvent.click( recommendedPreset );
		} );

		fireEvent.click( screen.getByText( 'Next' ) );
		fireEvent.click( screen.getByText( 'Next' ) );

		await waitFor( () => {
			const finishButton = screen.getByText( 'Finish Setup & Start Optimizing' );
			fireEvent.click( finishButton );
		} );

		// Should show loading state
		expect( screen.getByText( 'Setting up...' ) ).toBeInTheDocument();
	} );

	test( 'handles missing wizard data gracefully', () => {
		render( <WizardApp wizardData={ null } /> );

		// Should still render without crashing
		expect( screen.getByRole( 'main' ) ).toBeInTheDocument();
	} );

	test( 'supports keyboard navigation', async () => {
		render( <WizardApp wizardData={ mockWizardData } /> );

		// Navigate to presets step
		fireEvent.click( screen.getByText( "Let's Get Started" ) );

		await waitFor( () => {
			expect( screen.getByText( 'Choose Your Optimization Level' ) ).toBeInTheDocument();
		} );

		// Test keyboard navigation on preset cards
		const recommendedPreset = screen.getByLabelText( /Select.*Recommended.*preset/i );

		// Focus and press Enter
		recommendedPreset.focus();
		fireEvent.keyPress( recommendedPreset, { key: 'Enter', code: 'Enter' } );

		// Should select the preset
		expect( recommendedPreset ).toHaveAttribute( 'aria-checked', 'true' );
	} );
} );
