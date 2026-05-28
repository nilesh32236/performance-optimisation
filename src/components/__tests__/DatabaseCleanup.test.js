import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import DatabaseCleanup from '../DatabaseCleanup';

// Mock the API request
jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

import { apiCall } from '../../lib/apiRequest';

describe( 'DatabaseCleanup Component', () => {
	beforeEach( () => {
		global.wppoSettings = {};
		jest.clearAllMocks();
	} );

	it( 'renders table data correctly', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				revisions: 10,
				spam_comments: 5,
				expired_transients: 15,
			},
		} );
		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
			expect( screen.getByText( 'Post Revisions' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Spam Comments' ) ).toBeInTheDocument();
		} );
	} );

	it( 'shows error notification on fetch failure', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		apiCall.mockRejectedValueOnce( new Error( 'Fetch Error' ) );
		render( <DatabaseCleanup /> );

		// Notification doesn't show for counts error but it logs to console, so we can check if data defaults to 0
		await waitFor( () => {
			expect( screen.getAllByText( '0' )[ 0 ] ).toBeInTheDocument();
		} );

		expect( consoleSpy ).toHaveBeenCalledWith(
			'Error fetching database cleanup counts:',
			expect.any( Error )
		);
		consoleSpy.mockRestore();
	} );

	it( 'opens confirm dialog and calls cleanup api successfully', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		// Setup the next API call for cleanup
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { deleted: 10 },
		} );

		// Setup the next API call for refetching counts
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 0 },
		} );

		// Click clean button for revisions
		const cleanButtons = screen.getAllByRole( 'button', {
			name: /Clean/i,
		} );
		fireEvent.click( cleanButtons[ 0 ] );

		// Dialog should be open
		expect(
			screen.getByText(
				/This action will permanently delete post revisions/i
			)
		).toBeInTheDocument();

		// Confirm cleanup
		const confirmButton = screen.getByRole( 'button', { name: 'Delete' } );
		fireEvent.click( confirmButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'database_cleanup', {
				type: 'revisions',
			} );
			expect(
				screen.getByText( 'Cleanup successful: 10 items removed.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows error when cleanup api fails', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		// Setup the next API call for cleanup failing
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Failed to delete items',
		} );

		// Click clean button for revisions
		const cleanButtons = screen.getAllByRole( 'button', {
			name: /Clean/i,
		} );
		fireEvent.click( cleanButtons[ 0 ] );

		// Confirm cleanup
		const confirmButton = screen.getByRole( 'button', { name: 'Delete' } );
		fireEvent.click( confirmButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Failed to delete items' )
			).toBeInTheDocument();
		} );
	} );
} );
