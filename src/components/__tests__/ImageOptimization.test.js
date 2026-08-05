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
} );
