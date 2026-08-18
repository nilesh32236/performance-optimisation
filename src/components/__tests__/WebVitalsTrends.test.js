import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => ( {
	fetchWebVitalsTrends: jest.fn(),
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
	faChartLine: { iconName: 'chart-line' },
	faSpinner: { iconName: 'spinner' },
	faExclamationCircle: { iconName: 'exclamation-circle' },
} ) );

import WebVitalsTrends from '../WebVitalsTrends';
import { fetchWebVitalsTrends } from '../../lib/apiRequest';

describe( 'WebVitalsTrends Component', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders loading state then trend series', async () => {
		fetchWebVitalsTrends.mockResolvedValueOnce( {
			success: true,
			data: {
				trends: {
					abc123_mobile: [
						{ fetched_at: '2026-08-01', performance: 70 },
						{ fetched_at: '2026-08-02', performance: 82 },
					],
					abc123_desktop: [
						{ fetched_at: '2026-08-01', performance: 85 },
					],
				},
			},
		} );

		render( <WebVitalsTrends url="https://example.com/" /> );

		expect( screen.getByText( 'Web Vitals Trends' ) ).toBeInTheDocument();

		await waitFor( () => {
			expect( screen.getByText( 'Mobile' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Desktop' ) ).toBeInTheDocument();
		} );

		// Mobile has 2 snapshots → latest score shown.
		expect( screen.getByText( '82' ) ).toBeInTheDocument();
		// The chart svg is rendered for the 2-point mobile series.
		expect(
			screen.getByRole( 'img', {
				name: /mobile performance score trend chart/i,
			} )
		).toBeInTheDocument();

		expect( fetchWebVitalsTrends ).toHaveBeenCalledWith(
			'https://example.com/',
			''
		);
	} );

	it( 'shows a friendly message when there is no trend data yet', async () => {
		fetchWebVitalsTrends.mockResolvedValueOnce( {
			success: true,
			data: { trends: {} },
		} );

		render( <WebVitalsTrends url="https://example.com/" /> );

		await waitFor( () => {
			expect(
				screen.getAllByText( /Not enough trend data yet/i )
			).toHaveLength( 2 );
		} );
	} );

	it( 'shows an error message when the request fails', async () => {
		fetchWebVitalsTrends.mockRejectedValueOnce(
			new Error( 'Network error' )
		);

		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		try {
			render( <WebVitalsTrends url="https://example.com/" /> );

			await waitFor( () => {
				expect(
					screen.getByText( /Failed to load trend data/i )
				).toBeInTheDocument();
			} );
		} finally {
			consoleSpy.mockRestore();
		}
	} );

	it( 'renders an explicit empty state without a URL', async () => {
		render( <WebVitalsTrends url="" /> );

		expect(
			await screen.findByText(
				'Enter a URL to view Web Vitals trend history.'
			)
		).toBeInTheDocument();
		expect( fetchWebVitalsTrends ).not.toHaveBeenCalled();
	} );
} );
