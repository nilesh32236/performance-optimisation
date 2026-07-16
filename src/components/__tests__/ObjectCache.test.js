import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import ObjectCache from '../ObjectCache';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'ObjectCache Component', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'should trigger Test Connection API call with correct payload', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Connection successful.',
		} );

		render( <ObjectCache options={ {} } /> );

		const testConnectionButton = screen.getByRole( 'button', {
			name: /Test Connection/i,
		} );
		fireEvent.click( testConnectionButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'object_cache',
				expect.objectContaining( {
					action: 'ping',
					mode: 'standalone',
					host: '127.0.0.1',
					port: 6379,
					database: 0,
					compression: 'none',
					persistent: false,
					use_tls: false,
				} )
			);
		} );
	} );

	it( 'should handle failed API call for Test Connection', async () => {
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Action failed.',
		} );

		render( <ObjectCache options={ {} } /> );

		const testConnectionButton = screen.getByRole( 'button', {
			name: /Test Connection/i,
		} );
		fireEvent.click( testConnectionButton );

		await waitFor( () => {
			expect(
				screen.getByText( /Action failed\./i )
			).toBeInTheDocument();
		} );
	} );
} );
