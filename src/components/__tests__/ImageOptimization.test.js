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
} );
