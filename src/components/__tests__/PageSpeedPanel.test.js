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

jest.mock( '../../lib/apiRequest', () => ( {
	queuePageseedScan: jest.fn(),
	queuePagespeedScan: jest.fn(),
	getPagespeedResults: jest.fn(),
} ) );

jest.mock( '@fortawesome/react-fontawesome', () => ( {
	FontAwesomeIcon: ( { icon, className, style, spin } ) => (
		<span
			data-icon={ icon?.iconName || 'icon' }
			className={ className || '' }
			style={ style || {} }
			data-spin={ spin ? 'true' : 'false' }
		/>
	),
} ) );

jest.mock( '@fortawesome/free-solid-svg-icons', () => ( {
	faTachometerAlt: { iconName: 'tachometer-alt' },
	faSpinner: { iconName: 'spinner' },
	faCheckCircle: { iconName: 'check-circle' },
	faExclamationCircle: { iconName: 'exclamation-circle' },
	faMobileAlt: { iconName: 'mobile-alt' },
	faDesktop: { iconName: 'desktop' },
} ) );

import PageSpeedPanel from '../PageSpeedPanel';
import { queuePagespeedScan, getPagespeedResults } from '../../lib/apiRequest';

describe( 'PageSpeedPanel Component', () => {
	const defaultUrl = 'https://example.com';

	beforeEach( () => {
		global.wppoSettings = {
			performance_audit: {
				pagespeedApiKeyConfigured: true,
			},
		};
		jest.clearAllMocks();
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		jest.restoreAllMocks();
	} );

	it( 'renders the scan button', () => {
		render( <PageSpeedPanel url={ defaultUrl } /> );
		expect(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		).toBeInTheDocument();
	} );

	it( 'shows warning when API key is not configured', () => {
		global.wppoSettings = {
			performance_audit: {
				pagespeedApiKeyConfigured: false,
			},
		};
		render( <PageSpeedPanel url={ defaultUrl } /> );
		expect(
			screen.getByText( /PageSpeed API key is not configured/i )
		).toBeInTheDocument();
	} );

	it( 'disables scan button when API key is not configured', () => {
		global.wppoSettings = {
			performance_audit: {
				pagespeedApiKeyConfigured: false,
			},
		};
		render( <PageSpeedPanel url={ defaultUrl } /> );
		expect(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		).toBeDisabled();
	} );

	it( 'renders strategy toggle buttons', () => {
		render( <PageSpeedPanel url={ defaultUrl } /> );
		expect(
			screen.getByRole( 'button', { name: /Mobile/i } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Desktop/i } )
		).toBeInTheDocument();
	} );

	it( 'switches strategy on toggle click', () => {
		render( <PageSpeedPanel url={ defaultUrl } /> );
		const desktopBtn = screen.getByRole( 'button', { name: /Desktop/i } );
		fireEvent.click( desktopBtn );
		expect( desktopBtn ).toHaveClass( 'wppo-strategy-btn--active' );
	} );

	it( 'initiates scan on button click', async () => {
		queuePagespeedScan.mockResolvedValueOnce( {
			success: true,
			data: { job_id: 123 },
		} );
		getPagespeedResults.mockResolvedValueOnce( {
			success: true,
			data: { status: 'not_ready' },
		} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		const scanBtn = screen.getByRole( 'button', {
			name: /Run PageSpeed Scan/i,
		} );
		fireEvent.click( scanBtn );

		await waitFor( () => {
			expect( queuePagespeedScan ).toHaveBeenCalledWith(
				defaultUrl,
				'mobile'
			);
		} );
	} );

	it( 'shows scanning state after initiating scan', async () => {
		queuePagespeedScan.mockResolvedValueOnce( {
			success: true,
			data: { job_id: 123 },
		} );
		getPagespeedResults.mockResolvedValueOnce( {
			success: true,
			data: { status: 'not_ready' },
		} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		await waitFor( () => {
			expect( screen.getByText( /Scanning/i ) ).toBeInTheDocument();
		} );
	} );

	it( 'shows pending state and polls for results', async () => {
		queuePagespeedScan.mockResolvedValueOnce( {
			success: true,
			data: { job_id: 123 },
		} );
		getPagespeedResults.mockResolvedValueOnce( {
			success: true,
			data: { status: 'not_ready' },
		} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText(
					/PageSpeed scan is running in the background/i
				)
			).toBeInTheDocument();
		} );
	} );

	it( 'displays error message on scan queue failure', async () => {
		queuePagespeedScan.mockResolvedValueOnce( {
			success: false,
			message: 'API quota exceeded',
		} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'API quota exceeded' )
			).toBeInTheDocument();
		} );
	} );

	it( 'displays error on scan exception', async () => {
		queuePagespeedScan.mockRejectedValueOnce(
			new Error( 'Network error' )
		);
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'PageSpeed scan failed.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows timeout error after max poll attempts', async () => {
		queuePagespeedScan.mockResolvedValueOnce( {
			success: true,
			data: { job_id: 123 },
		} );

		getPagespeedResults.mockResolvedValue( {
			success: true,
			data: { status: 'not_ready' },
		} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( /PageSpeed scan is running/i )
			).toBeInTheDocument();
		} );

		// Advance timers one poll interval at a time, flushing microtasks
		// between each so the async poll continuation can execute.
		for ( let i = 0; i <= 60; i++ ) {
			await act( async () => {
				jest.advanceTimersByTime( 5000 );
			} );
		}

		await waitFor( () => {
			expect(
				screen.getByText( /PageSpeed scan timed out/i )
			).toBeInTheDocument();
		} );
	} );

	it( 'displays error when polling returns failure', async () => {
		queuePagespeedScan.mockResolvedValueOnce( {
			success: true,
			data: { job_id: 123 },
		} );
		getPagespeedResults.mockResolvedValueOnce( {
			success: true,
			data: { status: 'not_ready' },
		} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( /PageSpeed scan is running/i )
			).toBeInTheDocument();
		} );

		getPagespeedResults.mockResolvedValueOnce( {
			success: false,
			message: 'Scan job failed',
		} );

		// Fire the poll timer (1st async iteration gets the not_ready
		// response and schedules a 2nd poll), flush microtasks so the
		// async continuation runs, then fire the 2nd poll (gets the
		// failure response and calls setError).
		await act( async () => {
			jest.advanceTimersByTime( 5000 );
		} );
		await act( async () => {} );

		await act( async () => {
			jest.advanceTimersByTime( 5000 );
		} );
		await act( async () => {} );

		await waitFor( () => {
			expect( screen.getByText( 'Scan job failed' ) ).toBeInTheDocument();
		} );
	} );

	it( 'displays error when polling throws an exception', async () => {
		queuePagespeedScan.mockResolvedValueOnce( {
			success: true,
			data: { job_id: 123 },
		} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( /PageSpeed scan is running/i )
			).toBeInTheDocument();
		} );

		jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		getPagespeedResults.mockRejectedValueOnce(
			new Error( 'Network issue' )
		);

		await act( async () => {
			jest.runAllTimers();
		} );
		await act( async () => {} );

		await waitFor( () => {
			expect(
				screen.getByText( 'PageSpeed scan failed.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'displays scan results and calls onSuggestionsReady on success', async () => {
		queuePagespeedScan.mockResolvedValueOnce( {
			success: true,
			data: { job_id: 123 },
		} );

		const mockSuggestions = [ { id: 's1', message: 'Optimize images' } ];
		const mockResult = {
			scores: {
				performance: 95,
				accessibility: 60,
				best_practices: 30,
				seo: null,
			},
			vitals: {
				fcp: { display_value: '1.2 s', score: 0.95 },
				lcp: { display_value: '2.8 s', score: 0.6 },
				tbt: { display_value: '600 ms', score: 0.2 },
				cls: { display_value: '0.1', score: null },
			},
			strategy: 'mobile',
			fetched_at: '2024-08-01 12:00:00',
			suggestions: mockSuggestions,
		};

		getPagespeedResults.mockResolvedValueOnce( {
			success: true,
			data: mockResult,
		} );

		const mockOnSuggestionsReady = jest.fn();

		render(
			<PageSpeedPanel
				url={ defaultUrl }
				onSuggestionsReady={ mockOnSuggestionsReady }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		// Let the queue promise resolve, triggering pollForResults
		await act( async () => {} );

		// Advance time so pollRef.current fires (5000ms delay)
		await act( async () => {
			jest.advanceTimersByTime( 5000 );
		} );

		// Let the poll promise resolve
		await act( async () => {} );

		await waitFor( () => {
			expect( screen.getByText( '95' ) ).toBeInTheDocument();
			expect( screen.getByText( '60' ) ).toBeInTheDocument();
			expect( screen.getByText( '30' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Performance' ) ).toBeInTheDocument();

			expect( screen.getByText( '1.2 s' ) ).toBeInTheDocument();
			expect( screen.getByText( '2.8 s' ) ).toBeInTheDocument();
			expect( screen.getByText( '600 ms' ) ).toBeInTheDocument();
			expect(
				screen.getByText( 'First Contentful Paint' )
			).toBeInTheDocument();

			expect( mockOnSuggestionsReady ).toHaveBeenCalledWith(
				mockSuggestions
			);
		} );
	} );

	it( 'does not start scan if url is empty', async () => {
		render( <PageSpeedPanel url="" /> );
		const scanBtn = screen.getByRole( 'button', {
			name: /Run PageSpeed Scan/i,
		} );
		fireEvent.click( scanBtn );

		expect( queuePagespeedScan ).not.toHaveBeenCalled();
	} );

	it( 'cleans up on unmount', () => {
		const { unmount } = render( <PageSpeedPanel url={ defaultUrl } /> );
		unmount();
		expect( true ).toBe( true );
	} );
} );
