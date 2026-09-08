import {
	render,
	screen,
	act,
	fireEvent,
	waitFor,
} from '@testing-library/react';
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
		expect( hitRatioProgress ).toHaveAttribute( 'aria-valuetext', '0.0%' );
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

	it( 'sends the password only for credential actions and clears it after success', async () => {
		const statusResponse = {
			success: true,
			data: {
				enabled: false,
				redis_missing: false,
				foreign_dropin: false,
				redis_reachable: true,
				supported_compressors: { none: true },
			},
		};
		apiCall.mockImplementation( async ( action, payload = {} ) => {
			if ( 'object_cache' === action && 'status' === payload.action ) {
				return statusResponse;
			}
			return {
				success: true,
				message: 'Object Cache enabled successfully.',
			};
		} );

		await act( async () => {
			render( <ObjectCache options={ { password: 's3cret' } } /> );
		} );

		const passwordInput = screen.getByLabelText( /Auth Password/i );
		expect( passwordInput.value ).toBe( 's3cret' );

		const enableBtn = screen.getByRole( 'button', {
			name: /Enable Object Cache/i,
		} );
		await act( async () => {
			fireEvent.click( enableBtn );
		} );

		// Enable is a credential action — settings (incl. password) were sent…
		const enablePayload = apiCall.mock.calls.find(
			( [ action, payload ] ) =>
				'object_cache' === action && 'enable' === payload?.action
		);
		expect( enablePayload[ 1 ].password ).toBe( 's3cret' );

		// …but the password no longer lingers in state afterwards.
		await waitFor( () =>
			expect( screen.getByLabelText( /Auth Password/i ).value ).toBe( '' )
		);
	} );

	it( 'renders the circuit-breaker banner with reason and failure count when open', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: false,
				redis_missing: false,
				foreign_dropin: false,
				redis_reachable: false,
				circuit_open: true,
				circuit_reason: 'Redis Auth failed.',
				circuit_error_code: 'auth_fail',
				failure_count: 5,
				supported_compressors: { none: true },
			},
		} );

		await act( async () => {
			render( <ObjectCache options={ {} } /> );
		} );

		const banner = await screen.findByRole( 'alert' );
		expect( banner ).toHaveTextContent( /Auto-Disabled/i );
		expect( banner ).toHaveTextContent( 'Redis Auth failed.' );
		expect( banner ).toHaveTextContent( '(5 failures)' );
		expect(
			screen.getByRole( 'button', {
				name: /Re-enable Object Cache/i,
			} )
		).toBeInTheDocument();
	} );

	it( 'hides the circuit-breaker banner when the circuit is closed', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: true,
				redis_missing: false,
				foreign_dropin: false,
				redis_reachable: true,
				circuit_open: false,
				failure_count: 0,
				supported_compressors: { none: true },
			},
		} );

		await act( async () => {
			render( <ObjectCache options={ {} } /> );
		} );

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith(
				'object_cache',
				expect.objectContaining( { action: 'status' } )
			)
		);

		expect(
			screen.queryByText( /Auto-Disabled/i )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: /Re-enable Object Cache/i,
			} )
		).not.toBeInTheDocument();
	} );

	it( 'recovers via the recover action with mode-only payload and refreshes status', async () => {
		const statusResponse = {
			success: true,
			data: {
				enabled: false,
				redis_missing: false,
				foreign_dropin: false,
				redis_reachable: false,
				circuit_open: true,
				circuit_reason: 'Redis Auth failed.',
				failure_count: 5,
				supported_compressors: { none: true },
			},
		};
		const recoveredResponse = {
			success: true,
			data: {
				enabled: true,
				redis_missing: false,
				foreign_dropin: false,
				redis_reachable: true,
				circuit_open: false,
				failure_count: 0,
				supported_compressors: { none: true },
			},
		};
		let statusCalls = 0;
		apiCall.mockImplementation( async ( action, payload = {} ) => {
			if ( 'object_cache' === action && 'status' === payload.action ) {
				statusCalls += 1;
				return 1 === statusCalls ? statusResponse : recoveredResponse;
			}
			if ( 'object_cache' === action && 'recover' === payload.action ) {
				return {
					success: true,
					message: 'Object Cache recovered successfully.',
				};
			}
			return { success: true };
		} );

		await act( async () => {
			render( <ObjectCache options={ { password: 's3cret' } } /> );
		} );

		const recoverBtn = await screen.findByRole( 'button', {
			name: /Re-enable Object Cache/i,
		} );
		await act( async () => {
			fireEvent.click( recoverBtn );
		} );

		// Recover reuses stored settings server-side: only the mode travels,
		// never the password.
		const recoverPayload = apiCall.mock.calls.find(
			( [ action, payload ] ) =>
				'object_cache' === action && 'recover' === payload?.action
		);
		expect( recoverPayload ).toBeDefined();
		expect( recoverPayload[ 1 ].password ).toBeUndefined();
		expect( recoverPayload[ 1 ].mode ).toBe( 'standalone' );

		// Status refreshes after recovery and the banner clears.
		await waitFor( () =>
			expect(
				screen.queryByText( /Auto-Disabled/i )
			).not.toBeInTheDocument()
		);
		expect(
			screen.getByText( 'Object Cache recovered successfully.' )
		).toBeInTheDocument();
	} );

	it( 'never includes the password in payloads for non-credential actions', async () => {
		const statusResponse = {
			success: true,
			data: {
				enabled: true,
				redis_missing: false,
				foreign_dropin: false,
				redis_reachable: true,
				supported_compressors: { none: true },
				telemetry: {
					keyspace_hits: 1,
					keyspace_misses: 1,
				},
			},
		};
		apiCall.mockImplementation( async ( action, payload = {} ) => {
			if ( 'object_cache' === action && 'status' === payload.action ) {
				return statusResponse;
			}
			return { success: true, message: 'Object Cache flushed.' };
		} );

		await act( async () => {
			render( <ObjectCache options={ { password: 's3cret' } } /> );
		} );

		const flushBtn = screen.getByRole( 'button', {
			name: /Flush Cache/i,
		} );
		await act( async () => {
			fireEvent.click( flushBtn );
		} );

		const flushPayload = apiCall.mock.calls.find(
			( [ action, payload ] ) =>
				'object_cache' === action && 'flush' === payload?.action
		);
		expect( flushPayload ).toBeDefined();
		expect( flushPayload[ 1 ].password ).toBeUndefined();
		expect( flushPayload[ 1 ].mode ).toBe( 'standalone' );

		// The password is untouched for actions that never used it.
		expect( screen.getByLabelText( /Auth Password/i ).value ).toBe(
			's3cret'
		);
	} );
} );
