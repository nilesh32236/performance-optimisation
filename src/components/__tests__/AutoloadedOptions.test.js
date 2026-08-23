import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

jest.mock( '../common/FeatureCard', () => ( { children, title } ) => (
	<div data-testid="feature-card">
		<h3>{ title }</h3>
		{ children }
	</div>
) );

import AutoloadedOptions from '../AutoloadedOptions';
import { apiCall } from '../../lib/apiRequest';

describe( 'AutoloadedOptions', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the largest autoloaded options', async () => {
		apiCall.mockResolvedValue( {
			success: true,
			data: {
				options: [
					{ option_name: 'big_option', size: 5000 },
					{ option_name: 'small_option', size: 10 },
				],
			},
		} );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect( screen.getByText( 'big_option' ) ).toBeInTheDocument()
		);
		expect( screen.getByText( '4.9 KB' ) ).toBeInTheDocument();
		expect( screen.getByText( '10 B' ) ).toBeInTheDocument();
		expect( apiCall ).toHaveBeenCalledWith(
			'autoloaded_options?limit=20',
			{},
			'GET'
		);
	} );

	it( 'renders an empty state when no options exist', async () => {
		apiCall.mockResolvedValue( { success: true, data: { options: [] } } );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect(
				screen.getByText( 'No autoloaded options found.' )
			).toBeInTheDocument()
		);
	} );

	it( 'renders a distinct failure message when the request fails', async () => {
		apiCall.mockRejectedValue( new Error( 'boom' ) );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <AutoloadedOptions /> );

		await waitFor( () =>
			expect(
				screen.getByText( 'Failed to load autoloaded options.' )
			).toBeInTheDocument()
		);
		expect(
			screen.queryByText( 'No autoloaded options found.' )
		).not.toBeInTheDocument();

		errorSpy.mockRestore();
	} );
} );
