import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import ImageOptimization from '../ImageOptimization';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'ImageOptimization Component', () => {
	beforeEach( () => {
		global.wppoSettings = {};
		jest.clearAllMocks();
	} );

	it( 'renders all standard fields', () => {
		render( <ImageOptimization /> );
		expect( screen.getByText( /Enable Lazy Load/i ) ).toBeInTheDocument();
		expect(
			screen.getByText( /Wrap in Picture Tag/i )
		).toBeInTheDocument();
		expect( screen.getByText( /Video Lazy Loading/i ) ).toBeInTheDocument();
		expect(
			screen.getByText( /Auto Convert Formats/i )
		).toBeInTheDocument();
	} );

	it( 'shows conditional fields when toggles are enabled', async () => {
		render( <ImageOptimization /> );

		// Image Lazy Loading conditionals
		const lazyLoadToggle = screen.getByRole( 'checkbox', {
			name: /Enable Lazy Load/i,
		} );
		fireEvent.click( lazyLoadToggle );

		await waitFor( () => {
			expect(
				screen.getByLabelText( /Exclude First X Images/i )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'checkbox', {
					name: /SVG Placeholders/i,
				} )
			).toBeInTheDocument();
		} );

		// Auto Convert Formats conditionals
		const convertToggle = screen.getByRole( 'checkbox', {
			name: /Auto Convert Formats/i,
		} );
		fireEvent.click( convertToggle );

		await waitFor( () => {
			expect(
				screen.getByLabelText( /Target Format/i )
			).toBeInTheDocument();
			expect(
				screen.getByLabelText( /Exclude from Conversion/i )
			).toBeInTheDocument();
		} );

		// Preload Front Page Images
		const preloadFrontToggle = screen.getByRole( 'checkbox', {
			name: /Preload Front Page Images/i,
		} );
		fireEvent.click( preloadFrontToggle );

		await waitFor( () => {
			expect(
				screen.getByLabelText( /Frontpage Image URLs to Preload/i )
			).toBeInTheDocument();
		} );

		// Preload Featured Images
		const preloadPostTypeToggle = screen.getByRole( 'checkbox', {
			name: /Preload Featured Images/i,
		} );
		fireEvent.click( preloadPostTypeToggle );

		await waitFor( () => {
			expect(
				screen.getByLabelText( /Exclude URLs from Preload/i )
			).toBeInTheDocument();
		} );
	} );

	it( 'toggles post types when Preload Featured Images is active', async () => {
		render(
			<ImageOptimization
				options={ { availablePostTypes: [ 'post', 'page' ] } }
			/>
		);

		const preloadPostTypeToggle = screen.getByRole( 'checkbox', {
			name: /Preload Featured Images/i,
		} );
		fireEvent.click( preloadPostTypeToggle );

		await waitFor( () => {
			expect(
				document.getElementById( 'type-post' )
			).toBeInTheDocument();
			expect(
				document.getElementById( 'type-page' )
			).toBeInTheDocument();
		} );

		const postCheckbox = document.getElementById( 'type-post' );
		expect( postCheckbox ).not.toBeChecked();

		fireEvent.click( postCheckbox );

		await waitFor( () => {
			expect( postCheckbox ).toBeChecked();
		} );

		fireEvent.click( postCheckbox );

		await waitFor( () => {
			expect( postCheckbox ).not.toBeChecked();
		} );
	} );

	it( 'submits settings correctly with updated payload and shows success notification', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings saved successfully.',
		} );

		render( <ImageOptimization /> );

		// Toggle lazy loading so we test that payload changes
		const lazyLoadToggle = screen.getByRole( 'checkbox', {
			name: /Enable Lazy Load/i,
		} );
		fireEvent.click( lazyLoadToggle );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'image_optimisation',
				settings: expect.objectContaining( {
					lazyLoadImages: true,
				} ),
			} );
			expect(
				screen.getByText( 'Settings saved successfully.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'handles API errors and shows error notification', async () => {
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Failed to update settings.',
		} );

		render( <ImageOptimization /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Failed to update settings.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'handles network errors and shows error notification', async () => {
		apiCall.mockRejectedValueOnce( new Error( 'Network error.' ) );

		render( <ImageOptimization /> );

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect( screen.getByText( 'Network error.' ) ).toBeInTheDocument();
		} );
	} );
} );
