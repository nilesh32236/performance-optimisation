// eslint-disable-next-line import/no-extraneous-dependencies -- The project correctly relies on @wordpress/element instead of a direct React dependency, so this bypasses the false-positive error for test files as per memory constraints.
import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import DatabaseCleanup from '../DatabaseCleanup';
import { apiCall } from '../../lib/apiRequest';

// Mock the apiRequest utility
jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'DatabaseCleanup', () => {
	beforeEach( () => {
		// Mock WP globals
		global.wppoSettings = {
			apiUrl: 'http://example.com/wp-json/wppo/v1/',
			nonce: 'test-nonce',
			settings: {},
		};

		// Mock matchMedia for @wordpress/components
		Object.defineProperty( window, 'matchMedia', {
			writable: true,
			value: jest.fn().mockImplementation( ( query ) => ( {
				matches: false,
				media: query,
				onchange: null,
				addListener: jest.fn(),
				removeListener: jest.fn(),
				addEventListener: jest.fn(),
				removeEventListener: jest.fn(),
				dispatchEvent: jest.fn(),
			} ) ),
		} );

		jest.clearAllMocks();
	} );

	it( 'should render the component and fetch counts on mount', async () => {
		// Mock the initial counts fetch response
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10, auto_drafts: 5 },
		} );

		render( <DatabaseCleanup /> );

		// Verify API was called
		expect( apiCall ).toHaveBeenCalledWith(
			'database_cleanup_counts',
			{},
			'GET'
		);

		// Wait for counts to load and be displayed
		await waitFor( () => {
			expect(
				screen.getByText( 'Total Optimization Opportunities' )
			).toBeInTheDocument();
		} );

		// 10 + 5 = 15 total items
		await waitFor( () => {
			expect( screen.getByText( '15' ) ).toBeInTheDocument();
		} );
	} );

	it( 'should update state and send correct payload when dbSchedule is changed and saved', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {},
		} );

		render( <DatabaseCleanup /> );

		const selectElement = screen.getByLabelText( 'Schedule Frequency' );
		expect( selectElement.value ).toBe( 'none' );

		// Change select to 'daily'
		fireEvent.change( selectElement, { target: { value: 'daily' } } );
		expect( selectElement.value ).toBe( 'daily' );

		// Setup mock for save action
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {},
		} );

		// Save settings
		const saveButton = screen.getByText( 'Save Settings' );
		fireEvent.click( saveButton );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'database_cleanup',
				settings: expect.objectContaining( {
					dbSchedule: 'daily',
					dbRevMaxAge: 30, // Default values should be preserved
					dbRevKeepLatest: 5,
				} ),
			} );
		} );
	} );

	it( 'should show error notification on sad path API failure during cleanup', async () => {
		// Init counts fetch
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { revisions: 10 },
		} );

		render( <DatabaseCleanup /> );

		// Wait for initial render - using getAllByText since 10 is shown for both revisions and total
		await waitFor( () => {
			expect( screen.getAllByText( '10' ).length ).toBeGreaterThan( 0 );
		} );

		// Sad path network failure during cleanup
		apiCall.mockRejectedValueOnce( new Error( 'Network disconnected' ) );

		// Trigger cleanup for revisions by clicking Clean button
		const cleanButtons = screen.getAllByText( 'Clean' );
		// Assuming the first one is for 'Post Revisions' based on CLEANUP_TYPES array
		fireEvent.click( cleanButtons[ 0 ] );

		// Confirm dialog should appear
		await waitFor( () => {
			expect(
				screen.getByText( 'Confirm Post Revisions' )
			).toBeInTheDocument();
		} );

		// Click confirm Delete
		const deleteButton = screen.getByText( 'Delete' );
		fireEvent.click( deleteButton );

		// Verify error notification
		await waitFor( () => {
			expect(
				screen.getByText( 'Network disconnected' )
			).toBeInTheDocument();
		} );
	} );
} );
