import {
	render,
	screen,
	fireEvent,
	waitFor,
	act,
} from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import ImageOptimization from '../ImageOptimization';
import { apiCall } from '../../lib/apiRequest';

// Mock the API request
jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'ImageOptimization Component', () => {
	beforeEach( () => {
		global.wppoSettings = {};
		jest.clearAllMocks();
	} );

	it( 'renders the Prioritize LCP Images toggle in Advanced Preloading', () => {
		render( <ImageOptimization /> );
		expect(
			screen.getByLabelText( /Prioritize LCP Images in Final HTML/i )
		).toBeInTheDocument();
	} );

	it( 'toggles the Prioritize LCP Images switch', () => {
		render( <ImageOptimization /> );
		const toggle = screen.getByLabelText(
			/Prioritize LCP Images in Final HTML/i
		);
		expect( toggle ).not.toBeChecked();
		fireEvent.click( toggle );
		expect( toggle ).toBeChecked();
	} );

	it( 'reveals client-side MIME type chips when the override toggle is enabled', () => {
		render( <ImageOptimization /> );
		const toggle = screen.getByLabelText(
			/Override Client-Side MIME Types/i
		);
		expect( toggle ).not.toBeChecked();
		fireEvent.click( toggle );
		expect( toggle ).toBeChecked();
		expect( screen.getByText( 'AVIF' ) ).toBeInTheDocument();
		expect( screen.getByText( 'HEIC' ) ).toBeInTheDocument();
	} );

	it( 'reveals the background-image lazy toggle when lazy load is enabled', () => {
		render( <ImageOptimization /> );
		expect(
			screen.queryByLabelText( /Lazy-load CSS Background Images/i )
		).not.toBeInTheDocument();

		fireEvent.click( screen.getByLabelText( /Enable Lazy Load/i ) );

		expect(
			screen.getByLabelText( /Lazy-load CSS Background Images/i )
		).toBeInTheDocument();
	} );

	it( 'defaults the native lazy loading toggle to on', () => {
		render( <ImageOptimization /> );
		fireEvent.click( screen.getByLabelText( /Enable Lazy Load/i ) );

		const toggle = screen.getByLabelText( /Use Native Lazy Loading/i );
		expect( toggle ).toBeChecked();
	} );

	it( 'honours a stored lazyLoadNative=false override (legacy JS path)', () => {
		render(
			<ImageOptimization
				options={ { lazyLoadNative: false, lazyLoadImages: true } }
			/>
		);

		const toggle = screen.getByLabelText( /Use Native Lazy Loading/i );
		expect( toggle ).not.toBeChecked();
	} );

	it( 'renders the client-side processing notice when WP 7.1+ media processing is active', () => {
		global.wppoSettings.client_side_media_processing_enabled = true;

		render( <ImageOptimization /> );

		expect(
			screen.getByText(
				/WordPress 7.1\+ is handling image conversion in the browser/i
			)
		).toBeInTheDocument();
	} );

	it( 'does not render the client-side processing notice when the flag is absent', () => {
		render( <ImageOptimization /> );

		expect(
			screen.queryByText(
				/WordPress 7.1\+ is handling image conversion in the browser/i
			)
		).not.toBeInTheDocument();
	} );

	it( 'does not render the client-side processing notice when server-side conversion is forced', () => {
		global.wppoSettings.client_side_media_processing_enabled = true;

		render(
			<ImageOptimization
				options={ { forceServerSideConversion: true } }
			/>
		);

		expect(
			screen.queryByText(
				/WordPress 7.1\+ is handling image conversion in the browser/i
			)
		).not.toBeInTheDocument();
	} );

	it( 'renders and toggles the Force Server-Side Conversion switch', () => {
		render( <ImageOptimization /> );

		const toggle = screen.getByLabelText( /Force Server-Side Conversion/i );
		expect( toggle ).not.toBeChecked();
		fireEvent.click( toggle );
		expect( toggle ).toBeChecked();
	} );

	it( 'renders the lazy-render toggle off by default and nests the builder exclusion', () => {
		render( <ImageOptimization /> );

		const toggle = screen.getByLabelText(
			/Lazy-render Below-fold Sections/i
		);
		expect( toggle ).not.toBeChecked();
		expect(
			screen.queryByLabelText( /Exclude Page-builder Sections/i )
		).not.toBeInTheDocument();

		fireEvent.click( toggle );
		expect( toggle ).toBeChecked();

		const nested = screen.getByLabelText(
			/Exclude Page-builder Sections/i
		);
		expect( nested ).toBeInTheDocument();
		expect( nested ).toBeChecked();
	} );

	it( 'toggles the nested builder exclusion off', () => {
		render( <ImageOptimization /> );

		fireEvent.click(
			screen.getByLabelText( /Lazy-render Below-fold Sections/i )
		);

		const nested = screen.getByLabelText(
			/Exclude Page-builder Sections/i
		);
		expect( nested ).toBeChecked();
		fireEvent.click( nested );
		expect( nested ).not.toBeChecked();
	} );

	it( 'persists both lazy-render toggles via update_settings', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings updated successfully.',
		} );

		render( <ImageOptimization /> );

		fireEvent.click(
			screen.getByLabelText( /Lazy-render Below-fold Sections/i )
		);

		const submitButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );

		await act( async () => {
			fireEvent.click( submitButton );
		} );

		expect( apiCall ).toHaveBeenCalledWith(
			'update_settings',
			expect.objectContaining( {
				tab: 'image_optimisation',
				settings: expect.objectContaining( {
					lazyRenderBelowFold: true,
					lazyRenderExcludeBuilders: true,
				} ),
			} )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'Settings updated successfully.' )
			).toBeInTheDocument();
		} );
	} );
} );
