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

	it( 'displays successful scan results', async () => {
		const mockData = {
			load_time: 1.2,
			ttfb: 0.2,
			total_size: 500000,
			css_count: 2,
			js_count: 3,
			media_count: 5,
			compression_value: 'gzip',
			gzip_brotli_compression: true,
			cache_control_value: 'max-age=31536000',
			cache_control_headers: true,
			uses_modern_image_formats: 80,
			image_alt_attributes: true,
		};
		runPerformanceScan.mockResolvedValueOnce( { success: true, data: mockData } );

		render( <PerformanceAudit /> );
		const submitButton = screen.getByRole( 'button', { name: /Run Scan/i } );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect( screen.getByText( 'Scan Results' ) ).toBeInTheDocument();
		} );
		expect( screen.getByText( '1.2 s' ) ).toBeInTheDocument();
		expect( screen.getByText( '0.2 ms' ) ).toBeInTheDocument();
		expect( screen.getByText( '488.3 KB' ) ).toBeInTheDocument(); // 500000 / 1024
		expect( screen.getByText( '10' ) ).toBeInTheDocument(); // 2+3+5
		expect( screen.getByText( 'gzip' ) ).toBeInTheDocument();
		expect( screen.getByText( 'max-age=31536000' ) ).toBeInTheDocument();
		expect( screen.getByText( '80.0%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'All images have alt text' ) ).toBeInTheDocument();
	} );

	it( 'toggles developer mode and shows advanced timings', async () => {
		const mockData = {
			load_time: 1.2,
			ttfb: 0.2,
			total_size: 500000,
			css_count: 2,
			js_count: 3,
			media_count: 5,
			dns_lookup_time: 15,
			connect_time: 20,
			ssl_time: 25,
			server_wait_time: 100,
			css_total_size: 10240,
			js_total_size: 20480,
			media_total_size: 30720,
			lazy_image_count: 3,
			eager_image_count: 2,
			dom_size: 500,
			unminified_assets_count: 1,
			third_party_scripts_count: 2,
			page_url: 'https://example.com',
			scan_type: 'desktop',
			uses_https: true,
			robots_txt_exists: true,
		};
		runPerformanceScan.mockResolvedValueOnce( { success: true, data: mockData } );

		render( <PerformanceAudit /> );
		const submitButton = screen.getByRole( 'button', { name: /Run Scan/i } );
		fireEvent.click( submitButton );

		await waitFor( () => {
			expect( screen.getByText( 'Scan Results' ) ).toBeInTheDocument();
		} );

		expect( screen.queryByText( 'Advanced Timings' ) ).not.toBeInTheDocument();

		const devModeToggle = screen.getByRole( 'checkbox', { name: /Developer Details/i } );
		fireEvent.click( devModeToggle );

		expect( screen.getByText( 'Advanced Timings' ) ).toBeInTheDocument();
		expect( screen.getByText( '15 ms' ) ).toBeInTheDocument();
		expect( screen.getByText( '20 ms' ) ).toBeInTheDocument();
		expect( screen.getByText( '25 ms' ) ).toBeInTheDocument();
		expect( screen.getByText( '100 ms' ) ).toBeInTheDocument();
		expect( screen.getByText( '2 (10.0 KB)' ) ).toBeInTheDocument();
		expect( screen.getByText( '3 (20.0 KB)' ) ).toBeInTheDocument();
		expect( screen.getByText( '5 (30.0 KB)' ) ).toBeInTheDocument();
		expect( screen.getByText( '500' ) ).toBeInTheDocument();
		expect( screen.getByText( 'desktop' ) ).toBeInTheDocument();
	} );

	it( 'displays cached notice and handles Scan Fresh Data', async () => {
		const mockData = {
			is_cached: true,
			load_time: 1.2,
			ttfb: 0.2,
			total_size: 500000,
			css_count: 2,
			js_count: 3,
			media_count: 5,
		};
		runPerformanceScan.mockResolvedValueOnce( { success: true, data: mockData } );

		render( <PerformanceAudit /> );
		fireEvent.click( screen.getByRole( 'button', { name: /Run Scan/i } ) );

		await waitFor( () => {
			expect( screen.getByText( /Displaying cached results from the last hour/i ) ).toBeInTheDocument();
		} );

		runPerformanceScan.mockResolvedValueOnce( { success: true, data: { ...mockData, is_cached: false } } );
		fireEvent.click( screen.getByRole( 'button', { name: /Scan Fresh Data/i } ) );

		await waitFor( () => {
			expect( screen.queryByText( /Displaying cached results from the last hour/i ) ).not.toBeInTheDocument();
		} );
		expect( runPerformanceScan ).toHaveBeenCalledWith( 'https://example.com', true );
	} );

	it( 'uses home URL when "Use Home URL" button is clicked', () => {
		render( <PerformanceAudit /> );
		const input = screen.getByLabelText( /URL to Audit/i );
		fireEvent.change( input, { target: { value: 'https://test.com' } } );
		expect( input ).toHaveValue( 'https://test.com' );

		const homeButton = screen.getByRole( 'button', { name: /Use Home URL/i } );
		fireEvent.click( homeButton );
		expect( input ).toHaveValue( 'https://example.com' );
	} );

	it( 'displays error message on scan failure with message', async () => {
		runPerformanceScan.mockResolvedValueOnce( { success: false, message: 'Custom Error Message' } );

		render( <PerformanceAudit /> );
		fireEvent.click( screen.getByRole( 'button', { name: /Run Scan/i } ) );

		await waitFor( () => {
			expect( screen.getByText( 'Custom Error Message' ) ).toBeInTheDocument();
		} );
	} );

	it( 'displays default error message on scan failure without message', async () => {
		runPerformanceScan.mockResolvedValueOnce( { success: false } );

		render( <PerformanceAudit /> );
		fireEvent.click( screen.getByRole( 'button', { name: /Run Scan/i } ) );

		await waitFor( () => {
			expect( screen.getByText( 'Scan failed. Please try again.' ) ).toBeInTheDocument();
		} );
	} );

	it( 'displays default error message on scan exception', async () => {
		runPerformanceScan.mockRejectedValueOnce( new Error( 'Network error' ) );
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		render( <PerformanceAudit /> );
		fireEvent.click( screen.getByRole( 'button', { name: /Run Scan/i } ) );

		await waitFor( () => {
			expect( screen.getByText( 'Scan failed. Please try again.' ) ).toBeInTheDocument();
		} );
		expect( consoleSpy ).toHaveBeenCalled();
		consoleSpy.mockRestore();
	} );

	it( 'handles fetchSuggestions error gracefully without breaking UI', async () => {
		runPerformanceScan.mockResolvedValueOnce( {
			success: true,
			data: {
				load_time: 1.2,
				ttfb: 0.2,
				total_size: 500000,
				css_count: 2,
				js_count: 3,
				media_count: 5,
			},
		} );
		fetchSuggestions.mockRejectedValueOnce( new Error( 'Suggestions error' ) );
		const consoleSpy = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const onSuggestionsReady = jest.fn();

		render( <PerformanceAudit onSuggestionsReady={ onSuggestionsReady } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /Run Scan/i } ) );

		await waitFor( () => {
			expect( screen.getByText( 'Scan Results' ) ).toBeInTheDocument();
		} );
		expect( onSuggestionsReady ).not.toHaveBeenCalled();
		expect( consoleSpy ).toHaveBeenCalledWith( 'Could not fetch suggestions:', expect.any( Error ) );
		consoleSpy.mockRestore();
	} );
} );
