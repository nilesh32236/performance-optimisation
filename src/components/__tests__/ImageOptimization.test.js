import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import ImageOptimization from '../ImageOptimization';

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

	it( 'renders the client-side processing notice when WP 7.1+ media processing is active and conversion is on', () => {
		global.wppoSettings.client_side_media_processing_enabled = true;

		render( <ImageOptimization options={ { convertImg: true } } /> );

		expect(
			screen.getByText(
				/WordPress 7.1\+ may handle image conversion in the browser/i
			)
		).toBeInTheDocument();
	} );

	it( 'does not render the client-side processing notice when the flag is absent', () => {
		render( <ImageOptimization options={ { convertImg: true } } /> );

		expect(
			screen.queryByText(
				/WordPress 7.1\+ may handle image conversion in the browser/i
			)
		).not.toBeInTheDocument();
	} );

	it( 'does not render the client-side processing notice when Auto Convert is off', () => {
		global.wppoSettings.client_side_media_processing_enabled = true;

		render( <ImageOptimization /> );

		expect(
			screen.queryByText(
				/WordPress 7.1\+ may handle image conversion in the browser/i
			)
		).not.toBeInTheDocument();
	} );

	it( 'does not render the client-side processing notice when server-side conversion is forced', () => {
		global.wppoSettings.client_side_media_processing_enabled = true;

		render(
			<ImageOptimization
				options={ {
					convertImg: true,
					forceServerSideConversion: true,
				} }
			/>
		);

		expect(
			screen.queryByText(
				/WordPress 7.1\+ may handle image conversion in the browser/i
			)
		).not.toBeInTheDocument();
	} );

	it( 'hides the client-side processing notice immediately when the Force toggle is enabled', () => {
		global.wppoSettings.client_side_media_processing_enabled = true;

		render( <ImageOptimization options={ { convertImg: true } } /> );

		expect(
			screen.getByText(
				/WordPress 7.1\+ may handle image conversion in the browser/i
			)
		).toBeInTheDocument();

		fireEvent.click(
			screen.getByLabelText( /Force Server-Side Conversion/i )
		);

		expect(
			screen.queryByText(
				/WordPress 7.1\+ may handle image conversion in the browser/i
			)
		).not.toBeInTheDocument();
	} );

	it( 'renders and toggles the Force Server-Side Conversion switch when Auto Convert is on', () => {
		render( <ImageOptimization options={ { convertImg: true } } /> );

		const toggle = screen.getByLabelText( /Force Server-Side Conversion/i );
		expect( toggle ).not.toBeChecked();
		fireEvent.click( toggle );
		expect( toggle ).toBeChecked();
	} );

	it( 'does not render the Force Server-Side Conversion switch when Auto Convert is off', () => {
		render( <ImageOptimization /> );

		expect(
			screen.queryByLabelText( /Force Server-Side Conversion/i )
		).not.toBeInTheDocument();
	} );
} );
