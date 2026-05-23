import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import PluginSetting from '../PluginSetting';

// Mock the API request
jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	fetchRecentActivities: jest.fn(),
} ) );

import { apiCall, fetchRecentActivities } from '../../lib/apiRequest';

describe( 'PluginSetting Component', () => {
	beforeEach( () => {
		global.wppoSettings = {
			translations: {},
			settings: {
				performance_audit: {
					pagespeed_api_key: 'test-key-123',
				},
			},
		};
		global.URL.createObjectURL = jest.fn();
		jest.clearAllMocks();
	} );

	it( 'renders PageSpeed API key and saves successfully', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: {
				pagespeed_api_key: 'new-key-456',
			},
		} );
		render( <PluginSetting options={ { performance_audit: { pagespeed_api_key: 'test-key-123' } } } /> );

		// Check if input is rendered with the initial value
		const input = screen.getByLabelText( 'API Key' );
		expect( input ).toBeInTheDocument();
		expect( input ).toHaveValue( 'test-key-123' );

		// Change input
		fireEvent.change( input, { target: { value: 'new-key-456' } } );

		// Click Save
		const saveBtn = screen.getAllByRole( 'button', { name: /Save/i } )[ 0 ];
		fireEvent.click( saveBtn );

		// Ensure api is called
		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'update_settings', {
				tab: 'performance_audit',
				settings: { pagespeed_api_key: 'new-key-456' },
			} );
		} );

		// Check success message
		await waitFor( () => {
			expect( screen.getByText( /API key saved/i ) ).toBeInTheDocument();
		} );
	} );

	it( 'shows error message when PageSpeed API key save fails', async () => {
		apiCall.mockRejectedValueOnce( new Error( 'Network Failure' ) );
		render( <PluginSetting options={ { performance_audit: { pagespeed_api_key: 'test-key-123' } } } /> );

		const input = screen.getByLabelText( 'API Key' );
		fireEvent.change( input, { target: { value: 'failed-key' } } );

		const saveBtn = screen.getAllByRole( 'button', { name: /Save/i } )[ 0 ];
		fireEvent.click( saveBtn );

		// Check error message
		await waitFor( () => {
			expect( screen.getByText( /Error saving API key/i ) ).toBeInTheDocument();
		} );
	} );
} );
