import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import PerformanceAudit from '../PerformanceAudit';

// Mock API calls
jest.mock( '../../lib/apiRequest', () => ( {
	runPerformanceScan: jest.fn(),
	fetchSuggestions: jest.fn(),
} ) );

import { runPerformanceScan, fetchSuggestions } from '../../lib/apiRequest';

describe( 'PerformanceAudit Component', () => {
	beforeEach( () => {
		global.wppoSettings = {
			performance_audit: {
				homeUrl: 'https://example.com',
			},
		};
		jest.clearAllMocks();
	} );

	it( 'renders the performance audit form with default home URL', () => {
		render( <PerformanceAudit /> );
		const input = screen.getByLabelText( /URL to Audit/i );
		expect( input ).toHaveValue( 'https://example.com' );
	} );

	it( 'aborts previous fetchSuggestions request when a second scan is started', async () => {
		const onSuggestionsReady = jest.fn();

		let firstFetchSignal;
		let resolveFirstSuggestions;

		// Mock first scan
		runPerformanceScan.mockResolvedValueOnce( {
			success: true,
			data: {
				load_time: 1.2,
				ttfb: 0.2,
				dns: 0.05,
				connect: 0.05,
				ssl: 0.05,
				page_size: 500000,
				css_count: 2,
				js_count: 3,
				media_count: 5,
				total_assets: 10,
				score: 95,
				audits: {},
			},
		} );

		fetchSuggestions.mockImplementationOnce( ( url, signal ) => {
			firstFetchSignal = signal;
			return new Promise( ( resolve ) => {
				resolveFirstSuggestions = resolve;
			} );
		} );

		render(
			<PerformanceAudit onSuggestionsReady={ onSuggestionsReady } />
		);

		// Start 1st scan
		const submitButton = screen.getByRole( 'button', {
			name: /Use Home URL/i,
		} ).nextElementSibling;
		fireEvent.click( submitButton );

		// Wait for first scan's runPerformanceScan to complete and fetchSuggestions to be called
		await waitFor( () => {
			expect( fetchSuggestions ).toHaveBeenCalledTimes( 1 );
		} );

		expect( firstFetchSignal.aborted ).toBe( false );

		// Mock second scan
		runPerformanceScan.mockResolvedValueOnce( {
			success: true,
			data: {
				load_time: 1.0,
				ttfb: 0.1,
				dns: 0.02,
				connect: 0.02,
				ssl: 0.02,
				page_size: 400000,
				css_count: 2,
				js_count: 2,
				media_count: 4,
				total_assets: 8,
				score: 98,
				audits: {},
			},
		} );

		fetchSuggestions.mockImplementationOnce( () =>
			Promise.resolve( {
				success: true,
				data: { suggestions: [ 'suggestion_scan_2' ] },
			} )
		);

		// Start 2nd scan while 1st suggestions request is still pending
		fireEvent.click( submitButton );

		// Wait for second scan to trigger and abort the first signal
		await waitFor( () => {
			expect( firstFetchSignal.aborted ).toBe( true );
		} );

		// Resolve first scan's pending suggestions request
		resolveFirstSuggestions( {
			success: true,
			data: { suggestions: [ 'suggestion_scan_1' ] },
		} );

		// Verify onSuggestionsReady was called with scan 2's suggestions, not scan 1's
		await waitFor( () => {
			expect( onSuggestionsReady ).toHaveBeenCalledWith( [
				'suggestion_scan_2',
			] );
		} );

		expect( onSuggestionsReady ).not.toHaveBeenCalledWith( [
			'suggestion_scan_1',
		] );
	} );
} );
