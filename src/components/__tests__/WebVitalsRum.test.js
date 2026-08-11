import { render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

jest.mock( '../common/FeatureCard', () => ( { children, title } ) => (
	<div data-testid="feature-card">
		<h3>{ title }</h3>
		{ children }
	</div>
) );

import WebVitalsRum from '../WebVitalsRum';
import { apiCall } from '../../lib/apiRequest';

describe( 'WebVitalsRum', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders an empty state when there is no data', async () => {
		apiCall.mockResolvedValue( { success: true, data: {} } );

		render( <WebVitalsRum /> );

		await waitFor( () =>
			expect(
				screen.getByText(
					'No real-user data yet. Enable "Collect Real-user Web Vitals" in Tools and wait for visitors.'
				)
			).toBeInTheDocument()
		);
	} );

	it( 'aggregates per-day site-wide averages into rows', async () => {
		apiCall.mockResolvedValue( {
			success: true,
			data: {
				'2026-08-10': {
					'/about/': {
						lcp: { n: 2, sum: 5000 },
						cls: { n: 2, sum: 0.1 },
					},
					'/': {
						lcp: { n: 1, sum: 1000 },
					},
				},
			},
		} );

		render( <WebVitalsRum /> );

		await waitFor( () =>
			expect( screen.getByText( '2026-08-10' ) ).toBeInTheDocument()
		);

		// Site-wide LCP average = (5000 + 1000) / 3 = 2000 ms.
		expect( screen.getByText( '2000 ms' ) ).toBeInTheDocument();
		// Site-wide CLS average = 0.1 / 2 = 0.05.
		expect( screen.getByText( '0.050' ) ).toBeInTheDocument();
	} );

	it( 'announces an error without crashing', async () => {
		apiCall.mockRejectedValue( new Error( 'boom' ) );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render( <WebVitalsRum /> );

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'rum_data', {}, 'GET' )
		);

		errorSpy.mockRestore();
	} );
} );
