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
import { clearDbCountsCache } from '../../lib/dbCounts';

describe( 'DatabaseCleanup Component', () => {
	beforeEach( () => {
		global.wppoSettings = {};
		jest.clearAllMocks();
		clearDbCountsCache();
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
			expect(
				screen.getByRole( 'button', {
					name: /Optimize Everything Now/i,
				} )
			).toBeInTheDocument();
		} );

		const optimizeButton = screen.getByRole( 'button', {
			name: 'Optimize Everything Now',
		} );
		expect( optimizeButton ).toBeInTheDocument();
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
			expect( screen.getByText( 'Save Error' ) ).toBeInTheDocument();
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

		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		try {
			// API throws an error
			apiCall.mockRejectedValueOnce( new Error( 'API Exception' ) );

			const cleanButtons = screen.getAllByRole( 'button', {
				name: /Clean/i,
			} );
			fireEvent.click( cleanButtons[ 0 ] );

			const confirmButton = screen.getByRole( 'button', {
				name: 'Delete',
			} );
			fireEvent.click( confirmButton );

			await waitFor( () => {
				expect(
					screen.getByText( 'Error executing cleanup.' )
				).toBeInTheDocument();
			} );

			expect( consoleSpy ).toHaveBeenCalledWith(
				'Database cleanup error:',
				expect.any( Error )
			);
		} finally {
			consoleSpy.mockRestore();
		}
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

		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		try {
			// API throws an error
			apiCall.mockRejectedValueOnce( {} );

			const cleanButtons = screen.getAllByRole( 'button', {
				name: /Clean/i,
			} );
			fireEvent.click( cleanButtons[ 0 ] );

			const confirmButton = screen.getByRole( 'button', {
				name: 'Delete',
			} );
			fireEvent.click( confirmButton );

			await waitFor( () => {
				expect(
					screen.getByText( 'Error executing cleanup.' )
				).toBeInTheDocument();
			} );

			expect( consoleSpy ).toHaveBeenCalledWith(
				'Database cleanup error:',
				{}
			);
		} finally {
			consoleSpy.mockRestore();
		}
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

		try {
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
		} finally {
			consoleSpy.mockRestore();
		}
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

	it( 'renders optimize tables toggle with default on', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		expect(
			screen.getByText( 'Optimize tables after cleanup' )
		).toBeInTheDocument();

		const toggle = screen.getByRole( 'checkbox', {
			name: /Optimize tables after cleanup/i,
		} );
		expect( toggle ).toBeChecked();
	} );

	it( 'toggles optimize tables setting', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		const toggle = screen.getByRole( 'checkbox', {
			name: /Optimize tables after cleanup/i,
		} );
		expect( toggle ).toBeChecked();

		fireEvent.click( toggle );

		expect( toggle ).not.toBeChecked();
	} );

	it( 'saves optimize tables setting with settings', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect( screen.getAllByText( '10' )[ 0 ] ).toBeInTheDocument();
		} );

		const toggle = screen.getByRole( 'checkbox', {
			name: /Optimize tables after cleanup/i,
		} );

		fireEvent.click( toggle );

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
				expect.objectContaining( {
					tab: 'database_cleanup',
					settings: expect.objectContaining( {
						dbOptimize: false,
					} ),
				} )
			);
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

	it( 'exports expired transients as a JSON download before purge', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 0, expired_transients: 2 },
		} );
		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect(
				screen.getByRole( 'button', { name: 'Export' } )
			).toBeInTheDocument();
		} );

		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				count: 2,
				transients: [
					{
						option_name: '_transient_expired_one',
						timeout_option: '_transient_timeout_expired_one',
						expired_at: 1700000000,
						size: 42,
					},
				],
			},
		} );

		global.URL.createObjectURL = jest.fn( () => 'blob:mock' );
		global.URL.revokeObjectURL = jest.fn();

		fireEvent.click( screen.getByRole( 'button', { name: 'Export' } ) );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'expired_transients_export?limit=500',
				{},
				'GET'
			);
			expect(
				screen.getByText( 'Exported 2 expired transients.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'warns when the expired-transient export is truncated at the limit', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 0, expired_transients: 600 },
		} );
		render( <DatabaseCleanup /> );

		await waitFor( () => {
			expect(
				screen.getByRole( 'button', { name: 'Export' } )
			).toBeInTheDocument();
		} );

		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				count: 500,
				limit: 500,
				truncated: true,
				transients: [],
			},
		} );

		global.URL.createObjectURL = jest.fn( () => 'blob:mock' );
		global.URL.revokeObjectURL = jest.fn();

		fireEvent.click( screen.getByRole( 'button', { name: 'Export' } ) );

		await waitFor( () => {
			expect( screen.getByText( /truncated/ ) ).toBeInTheDocument();
		} );
	} );

	it( 'renders the Action Scheduler card with queue health and excludes the health object from totals', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				revisions: 10,
				action_scheduler: 42,
				action_scheduler_health: {
					available: true,
					pending: 3,
					failed: 1,
					oldest_pending_age_seconds: 3600,
					reclaimable: 42,
					reclaimable_bytes: 1024,
					total_bytes: 2048,
				},
			},
		} );
		render( <DatabaseCleanup /> );

		// Wait for counts to load (health line only renders from payload).
		expect(
			await screen.findByText(
				'Queue health: 3 pending, 1 failed, oldest pending 3600 seconds ago.'
			)
		).toBeInTheDocument();

		expect(
			screen.getByText( 'Action Scheduler Queue' )
		).toBeInTheDocument();

		// Reclaimable count on the card.
		expect( screen.getAllByText( '42' ).length ).toBeGreaterThan( 0 );

		// Total is 10 + 42 = 52 — the health object must not inflate it.
		expect( screen.getByText( '52 items' ) ).toBeInTheDocument();
	} );

	it( 'hides the queue-health line when Action Scheduler is unavailable', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				revisions: 10,
				action_scheduler: 0,
				action_scheduler_health: {
					available: false,
					pending: 0,
					failed: 0,
					oldest_pending_age_seconds: null,
					reclaimable: 0,
					reclaimable_bytes: 0,
					total_bytes: 0,
				},
			},
		} );
		render( <DatabaseCleanup /> );

		// Wait for counts to load before asserting absence.
		expect( await screen.findByText( '10 items' ) ).toBeInTheDocument();

		expect(
			screen.getByText( 'Action Scheduler Queue' )
		).toBeInTheDocument();

		expect( screen.queryByText( /Queue health:/ ) ).not.toBeInTheDocument();
	} );

	it( 'surfaces the REST note when the Action Scheduler cleaner is unavailable', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				revisions: 0,
				// Stale nonzero count keeps the Clean button enabled while
				// the health payload reports AS as unavailable.
				action_scheduler: 5,
				action_scheduler_health: {
					available: false,
					pending: 0,
					failed: 0,
					oldest_pending_age_seconds: null,
					reclaimable: 0,
					reclaimable_bytes: 0,
					total_bytes: 0,
				},
			},
		} );
		render( <DatabaseCleanup /> );

		// Wait for counts to load so the Clean button is enabled.
		expect( await screen.findByText( '5 items' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Action Scheduler Queue' )
		).toBeInTheDocument();

		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				type: 'action_scheduler',
				deleted: 0,
				action_scheduler_available: false,
				note: 'Action Scheduler is not available or cleanup is disabled; nothing was purged.',
			},
		} );
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 0, action_scheduler: 0 },
		} );

		const cleanButtons = screen.getAllByRole( 'button', {
			name: /Clean/i,
		} );
		fireEvent.click( cleanButtons[ cleanButtons.length - 1 ] );

		const confirmButton = screen.getByRole( 'button', { name: 'Delete' } );
		fireEvent.click( confirmButton );

		await waitFor( () => {
			expect(
				screen.getByText(
					'Action Scheduler is not available or cleanup is disabled; nothing was purged.'
				)
			).toBeInTheDocument();
		} );
	} );

	it( 'aborts the in-flight counts request on unmount without notifying', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		try {
			apiCall.mockImplementationOnce(
				( action, payload, method, signal ) => {
					if ( 'database_cleanup_counts' !== action ) {
						return Promise.resolve( { success: true, data: {} } );
					}
					return new Promise( ( resolve, reject ) => {
						if ( signal ) {
							signal.addEventListener( 'abort', () => {
								const abortError = new Error( 'Aborted' );
								abortError.name = 'AbortError';
								reject( abortError );
							} );
						}
					} );
				}
			);

			const { unmount } = render( <DatabaseCleanup /> );

			await waitFor( () =>
				expect( apiCall ).toHaveBeenCalledWith(
					'database_cleanup_counts',
					{},
					'GET',
					expect.any( AbortSignal )
				)
			);

			const countsCall = apiCall.mock.calls.find(
				( [ action ] ) => 'database_cleanup_counts' === action
			);
			const signal = countsCall[ 3 ];
			expect( signal ).toBeInstanceOf( AbortSignal );

			unmount();
			expect( signal.aborted ).toBe( true );

			await act( async () => {} );

			// No error notification path taken: nothing logged and the
			// aborted fetch never resolves into state. (No DOM assertion
			// here: after unmount() the tree is gone, so queryByText
			// would pass vacuously even if notify() had fired.)
			expect( consoleSpy ).not.toHaveBeenCalled();
		} finally {
			consoleSpy.mockRestore();
			apiCall.mockReset();
		}
	} );
} );
