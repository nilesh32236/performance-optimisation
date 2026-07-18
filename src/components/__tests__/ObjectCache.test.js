import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import ObjectCache from '../ObjectCache';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

describe( 'ObjectCache Component', () => {
	let consoleErrorSpy;

	beforeEach( () => {
		jest.clearAllMocks();
		consoleErrorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
	} );

	afterEach( () => {
		consoleErrorSpy.mockRestore();
	} );

	it( 'renders loading status then displays content', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { enabled: true, redis_reachable: true },
		} );
		render( <ObjectCache options={ {} } /> );

		await waitFor( () => {
			expect(
				screen.getByText(
					'Enterprise-grade Redis object caching with Sentinel and Cluster support.'
				)
			).toBeInTheDocument();
		} );
	} );

	it( 'toggles enable/disable caching', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { enabled: false, redis_reachable: true },
		} );
		render( <ObjectCache options={ {} } /> );

		let enableBtn;
		await waitFor( () => {
			enableBtn = screen.getByRole( 'button', {
				name: /Enable Object Cache/i,
			} );
			expect( enableBtn ).toBeInTheDocument();
		} );

		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Cache enabled',
			data: { enabled: true, redis_reachable: true },
		} );

		fireEvent.click( enableBtn );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'object_cache',
				expect.objectContaining( { action: 'enable' } )
			);
			expect( screen.getByText( 'Cache enabled' ) ).toBeInTheDocument();
		} );
	} );

	it( 'handles cache fetch status error', async () => {
		const err = new Error( 'Network Issue' );
		apiCall.mockRejectedValueOnce( err );
		render( <ObjectCache options={ {} } /> );
		await waitFor( () => {
			expect( consoleErrorSpy ).toHaveBeenCalledWith(
				'Error fetching cache status',
				err
			);
		} );
	} );

	it( 'handles action errors gracefully', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { enabled: false, redis_reachable: true },
		} );
		render( <ObjectCache options={ {} } /> );

		let enableBtn;
		await waitFor( () => {
			enableBtn = screen.getByRole( 'button', {
				name: /Enable Object Cache/i,
			} );
		} );

		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Action failed.',
		} );
		fireEvent.click( enableBtn );

		await waitFor( () => {
			expect( screen.getByText( 'Action failed.' ) ).toBeInTheDocument();
		} );
	} );

	it( 'submits settings successfully', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { enabled: false, redis_reachable: true },
		} );
		render( <ObjectCache options={ {} } /> );

		let hostInput;
		await waitFor( () => {
			hostInput = screen.getByLabelText( /Host/i );
		} );

		fireEvent.change( hostInput, { target: { value: '192.168.1.1' } } );

		const saveBtn = screen.getByRole( 'button', { name: /Save Changes/i } );

		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings saved',
		} );

		fireEvent.click( saveBtn );

		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'update_settings',
				expect.objectContaining( {
					tab: 'object_cache',
					settings: expect.objectContaining( {
						host: '192.168.1.1',
					} ),
				} )
			);
			expect(
				screen.getByText( 'Settings saved successfully.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'renders error notice when redis is missing', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: false,
				redis_missing: true,
				redis_reachable: false,
			},
		} );
		render( <ObjectCache options={ {} } /> );

		await waitFor( () => {
			expect(
				screen.getByText( 'Extension Missing' )
			).toBeInTheDocument();
			expect(
				screen.getByText(
					'The PhpRedis extension is not installed. Native performance will be limited.'
				)
			).toBeInTheDocument();
		} );

		const enableBtn = screen.getByRole( 'button', {
			name: /Enable Object Cache/i,
		} );
		expect( enableBtn ).toBeDisabled();
	} );

	it( 'renders warning notice when foreign dropin is detected', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: false,
				foreign_dropin: true,
				redis_reachable: true,
			},
		} );
		render( <ObjectCache options={ {} } /> );

		await waitFor( () => {
			expect(
				screen.getByText( 'Conflict Detected' )
			).toBeInTheDocument();
			expect(
				screen.getByText(
					'Another object cache plugin is currently active. Please disable it to avoid site crashes.'
				)
			).toBeInTheDocument();
		} );

		const enableBtn = screen.getByRole( 'button', {
			name: /Enable Object Cache/i,
		} );
		expect( enableBtn ).toBeDisabled();
	} );
} );
