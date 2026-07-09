import {
	render,
	screen,
	waitFor,
	fireEvent,
	act,
} from '@testing-library/react';
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

	it( 'cancels cleanup when cancel button is clicked in dialog', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
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

		// Cancel cleanup
		const cancelButton = screen.getByRole( 'button', { name: 'Cancel' } );
		fireEvent.click( cancelButton );

		await waitFor( () => {
			expect(
				screen.queryByText(
					/This action will permanently delete post revisions/i
				)
			).not.toBeInTheDocument();
		} );
	} );

	it( 'opens confirm dialog to optimize everything', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10, spam_comments: 5 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getByText( '15' ) ).toBeInTheDocument();
		} );

		// Click optimize everything
		const optimizeButton = screen.getByRole( 'button', {
			name: 'Optimize Everything Now',
		} );
		fireEvent.click( optimizeButton );

		await waitFor( () => {
			expect(
				screen.getByText(
					/This action will permanently delete overhead items from your database. Proceed?/i
				)
			).toBeInTheDocument();
		} );
	} );

	it( 'handles settings change correctly', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		const dbScheduleSelect = screen.getByLabelText( 'Schedule Frequency' );
		fireEvent.change( dbScheduleSelect, {
			target: { value: 'daily', name: 'dbSchedule' },
		} );

		expect( dbScheduleSelect.value ).toBe( 'daily' );
	} );

	it( 'saves settings successfully and shows notification', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {},
		} );
		render( <DatabaseCleanup /> );

		// Setup the next API call for update_settings
		apiCall.mockResolvedValueOnce( {
			success: true,
		} );

		const saveButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( saveButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.any( Object )
			);
			expect(
				screen.getByText( 'Settings saved successfully.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows error notification when saving settings fails', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {},
		} );
		render( <DatabaseCleanup /> );

		// Setup the next API call for update_settings failing
		apiCall.mockRejectedValueOnce( new Error( 'Save Error' ) );

		const saveButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( saveButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Error saving settings.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows error notification with custom message on cleanup failure with success true', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		// Setup the next API call for cleanup failing but success true in db
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Custom error message.',
			data: { failures: { some_item: 'failed' }, deleted: 5 },
		} );

		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 5 },
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
				screen.getByText( 'Custom error message. Failures: some_item' )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows fallback error when cleanup API throws an exception', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		// API throws an error
		apiCall.mockRejectedValueOnce( new Error( 'API Exception' ) );

		const cleanButtons = screen.getAllByRole( 'button', {
			name: /Clean/i,
		} );
		fireEvent.click( cleanButtons[ 0 ] );

		const confirmButton = screen.getByRole( 'button', { name: 'Delete' } );
		fireEvent.click( confirmButton );

		await waitFor( () => {
			expect( screen.getByText( 'API Exception' ) ).toBeInTheDocument();
		} );
	} );

	it( 'shows empty error message fallback when cleanup API throws an exception without message', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		// API throws an error
		apiCall.mockRejectedValueOnce( {} );

		const cleanButtons = screen.getAllByRole( 'button', {
			name: /Clean/i,
		} );
		fireEvent.click( cleanButtons[ 0 ] );

		const confirmButton = screen.getByRole( 'button', { name: 'Delete' } );
		fireEvent.click( confirmButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Error executing cleanup.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'dismisses notification when dismiss button is clicked', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {},
		} );
		render( <DatabaseCleanup /> );

		apiCall.mockResolvedValueOnce( {
			success: true,
		} );

		const saveButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( saveButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Settings saved successfully.' )
			).toBeInTheDocument();
		} );

		const dismissButton = screen.getByRole( 'button', {
			name: /Dismiss/i,
		} );
		fireEvent.click( dismissButton );

		await waitFor( () => {
			expect(
				screen.queryByText( 'Settings saved successfully.' )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'dismisses notification automatically after 5 seconds', async () => {
		jest.useFakeTimers();
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {},
		} );
		render( <DatabaseCleanup /> );

		apiCall.mockResolvedValueOnce( {
			success: true,
		} );

		const saveButton = screen.getByRole( 'button', {
			name: /Save Settings/i,
		} );
		fireEvent.click( saveButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Settings saved successfully.' )
			).toBeInTheDocument();
		} );

		act( () => {
			jest.advanceTimersByTime( 5000 );
		} );

		await waitFor( () => {
			expect(
				screen.queryByText( 'Settings saved successfully.' )
			).not.toBeInTheDocument();
		} );
		jest.useRealTimers();
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

	it( 'shows fallback error message with failures when cleanup API fails without a message', async () => {
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
			data: { failures: { item1: 'error', item2: 'error' } },
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
				screen.getByText( 'Cleanup failed. Failures: item1, item2' )
			).toBeInTheDocument();
		} );
	} );
} );
