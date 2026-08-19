import { render, screen, act, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import ObjectCache from '../ObjectCache';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( { apiCall: jest.fn() } ) );

describe( 'ObjectCache Component', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders Hit Ratio progress bar with correct aria attributes', async () => {
		const mockCacheStatus = {
			enabled: true,
			telemetry: {
				keyspace_hits: 875,
				keyspace_misses: 125, // 875 / 1000 = 87.5%
			},
		};

		apiCall.mockResolvedValueOnce( {
			success: true,
			data: mockCacheStatus,
		} );

		await act( async () => {
			render( <ObjectCache options={ {} } /> );
		} );

		const hitRatioProgress = await screen.findByRole( 'progressbar', {
			name: /Hit Ratio/i,
		} );

		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuemin', '0' );
		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuemax', '100' );
		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuenow', '87.5' );
		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuetext', '87.5%' );
	} );

	it( 'renders Hit Ratio of 0 when telemetry has zero keyspace activity', async () => {
		const mockCacheStatus = {
			enabled: true,
			telemetry: {
				keyspace_hits: 0,
				keyspace_misses: 0,
			},
		};

		apiCall.mockResolvedValueOnce( {
			success: true,
			data: mockCacheStatus,
		} );

		await act( async () => {
			render( <ObjectCache options={ {} } /> );
		} );

		const hitRatioProgress = await screen.findByRole( 'progressbar', {
			name: /Hit Ratio/i,
		} );

		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuenow', '0' );
		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuetext', '0%' );
	} );

	it( 'does not render Hit Ratio progress bar when cache is disabled', async () => {
		const mockCacheStatus = {
			enabled: false,
		};

		apiCall.mockResolvedValueOnce( {
			success: true,
			data: mockCacheStatus,
		} );

		await act( async () => {
			render( <ObjectCache options={ {} } /> );
		} );

		const hitRatioProgress = screen.queryByRole( 'progressbar', {
			name: /Hit Ratio/i,
		} );

		expect( hitRatioProgress ).not.toBeInTheDocument();
	} );

	it( 'surfaces an error notice when an action request fails', async () => {
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: false,
				redis_missing: false,
				foreign_dropin: false,
				redis_reachable: true,
				supported_compressors: { none: true },
			},
		} );
		apiCall.mockRejectedValueOnce( new Error( 'boom' ) );

		await act( async () => {
			render( <ObjectCache options={ {} } /> );
		} );

		const enableBtn = screen.getByRole( 'button', {
			name: /Enable Object Cache/i,
		} );
		await act( async () => {
			fireEvent.click( enableBtn );
		} );

		expect( screen.getByText( 'Action failed.' ) ).toBeInTheDocument();

		errorSpy.mockRestore();
	} );
} );
