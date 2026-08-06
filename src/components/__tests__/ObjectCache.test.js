import { render, screen, act } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import ObjectCache from '../ObjectCache';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest' );

describe( 'ObjectCache Component', () => {
	it( 'renders Hit Ratio progress bar with correct aria attributes', async () => {
		// Mock options to enable cache and telemetry with a known hit ratio
		const mockOptions = {
			cache_status: {
				enabled: true,
				telemetry: {
					keyspace_hits: 875,
					keyspace_misses: 125, // 875 / 1000 = 87.5%
				},
			},
		};

		apiCall.mockResolvedValue( {
			success: true,
			data: mockOptions.cache_status,
		} );

		await act( async () => {
			render( <ObjectCache options={ mockOptions } /> );
		} );

		const hitRatioProgress = await screen.findByRole( 'progressbar', {
			name: /Hit Ratio/i,
		} );

		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuemin', '0' );
		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuemax', '100' );
		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuenow', '87.5' );
		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuetext', '87.5%' );
	} );
} );
