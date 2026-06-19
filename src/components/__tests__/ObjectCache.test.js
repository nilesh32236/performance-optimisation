import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React, { act } from 'react';
import ObjectCache from '../ObjectCache';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
} ) );

import { apiCall } from '../../lib/apiRequest';

describe( 'ObjectCache Component', () => {
	beforeEach( () => {
		global.wppoSettings = {};
		jest.clearAllMocks();
	} );

	it( 'renders the component', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: false,
				redis_missing: false,
				foreign_dropin: false,
				redis_reachable: false,
				supported_compressors: { none: true, zstd: true },
			},
		} );

		await act( async () => {
			render( <ObjectCache /> );
		} );

		expect( screen.getByText( 'Object Cache' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'Enterprise-grade Redis object caching with Sentinel and Cluster support.'
			)
		).toBeInTheDocument();
		expect( apiCall ).toHaveBeenCalledWith( 'object_cache', {
			action: 'status',
		} );
	} );

	it( 'handles API failures silently on status fetch', async () => {
		const consoleSpy = jest.spyOn( console, 'error' ).mockImplementation();
		apiCall.mockRejectedValueOnce( new Error( 'Network Error' ) );

		await act( async () => {
			render( <ObjectCache /> );
		} );

		expect( consoleSpy ).toHaveBeenCalledWith(
			'Error fetching cache status',
			expect.any( Error )
		);
		consoleSpy.mockRestore();
	} );

	it( 'shows warning if foreign dropin detected', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: false,
				foreign_dropin: true,
				redis_missing: false,
			},
		} );

		await act( async () => {
			render( <ObjectCache /> );
		} );

		expect( screen.getByText( 'Conflict Detected' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				/Another object cache plugin is currently active/i
			)
		).toBeInTheDocument();
	} );

	it( 'shows error if redis extension missing', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: false,
				foreign_dropin: false,
				redis_missing: true,
			},
		} );

		await act( async () => {
			render( <ObjectCache /> );
		} );

		expect( screen.getByText( 'Extension Missing' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /The PhpRedis extension is not installed/i )
		).toBeInTheDocument();
	} );

	it( 'handles enabling object cache', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				enabled: false,
				redis_reachable: true, // Needs to be reachable to enable
				redis_missing: false,
				foreign_dropin: false,
			},
		} ); // initial fetch
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Object cache enabled',
		} ); // action response
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { enabled: true },
		} ); // refetch

		await act( async () => {
			render( <ObjectCache /> );
		} );

		const enableBtn = screen.getByRole( 'button', {
			name: /Enable Object Cache/i,
		} );
		await act( async () => {
			fireEvent.click( enableBtn );
		} );

		expect( apiCall ).toHaveBeenCalledWith(
			'object_cache',
			expect.objectContaining( { action: 'enable' } )
		);
		expect(
			screen.getByText( 'Object cache enabled' )
		).toBeInTheDocument();
	} );

	it( 'handles test connection failure', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { enabled: false },
		} ); // initial fetch
		apiCall.mockResolvedValueOnce( {
			success: false,
			message: 'Connection failed',
		} ); // action response

		await act( async () => {
			render( <ObjectCache /> );
		} );

		const testBtn = screen.getByRole( 'button', {
			name: /Test Connection/i,
		} );
		await act( async () => {
			fireEvent.click( testBtn );
		} );

		expect( apiCall ).toHaveBeenCalledWith(
			'object_cache',
			expect.objectContaining( { action: 'ping' } )
		);
		expect( screen.getByText( 'Connection failed' ) ).toBeInTheDocument();
	} );

	it( 'handles save settings', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { enabled: false },
		} ); // initial fetch
		apiCall.mockResolvedValueOnce( {
			success: true,
			message: 'Settings saved successfully.',
		} ); // submit response

		await act( async () => {
			render( <ObjectCache /> );
		} );

		const saveBtn = screen.getByRole( 'button', { name: /Save Changes/i } );
		await act( async () => {
			fireEvent.click( saveBtn );
		} );

		expect( apiCall ).toHaveBeenCalledWith(
			'update_settings',
			expect.objectContaining( { tab: 'object_cache' } )
		);
		expect(
			screen.getByText( 'Settings saved successfully.' )
		).toBeInTheDocument();
	} );
} );
