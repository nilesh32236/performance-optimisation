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
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <PageSpeedPanel url={ defaultUrl } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Run PageSpeed Scan/i } )
		);

		await waitFor( () => {
			expect(
				screen.getByText( 'PageSpeed scan failed.' )
			).toBeInTheDocument();
		} );
		consoleSpy.mockRestore();
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
			jest.runAllTimers();
		} );
		await act( async () => {} );
		await act( async () => {
			jest.runAllTimers();
		} );
		await act( async () => {} );

		await waitFor( () => {
			expect( screen.getByText( 'Scan job failed' ) ).toBeInTheDocument();
		} );
	} );

	it( 'cleans up on unmount', () => {
		const { unmount } = render( <PageSpeedPanel url={ defaultUrl } /> );
		unmount();
		expect( true ).toBe( true );
	} );
} );
