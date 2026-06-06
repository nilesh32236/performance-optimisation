import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import SystemInfo from '../SystemInfo';

// Mock the API request
jest.mock( '../../lib/apiRequest', () => ( {
	fetchSystemInfo: jest.fn(),
} ) );

import { fetchSystemInfo } from '../../lib/apiRequest';

describe( 'SystemInfo Component', () => {
	beforeEach( () => {
		global.wppoSettings = { translations: {} };
		jest.clearAllMocks();
	} );

	it( 'renders error message on successful API response with success: false', async () => {
		fetchSystemInfo.mockResolvedValueOnce( {
			success: false,
			message: 'API returned an error message',
		} );
		render( <SystemInfo /> );

		const loadButton = screen.getByRole( 'button', {
			name: /load system info/i,
		} );
		fireEvent.click( loadButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'API returned an error message' )
			).toBeInTheDocument();
		} );
	} );

	it( 'renders infrastructure table when infrastructure data is present', async () => {
		fetchSystemInfo.mockResolvedValueOnce( {
			success: true,
			data: {
				infrastructure: {
					action_scheduler: { available: true },
					pagespeed_api: { configured: true },
				},
			},
		} );
		render( <SystemInfo /> );

		const loadButton = screen.getByRole( 'button', {
			name: /load system info/i,
		} );
		fireEvent.click( loadButton );

		await waitFor( () => {
			expect( screen.getByText( 'Infrastructure' ) ).toBeInTheDocument();
			expect(
				screen.getAllByText( 'Available' )[ 0 ]
			).toBeInTheDocument();
			expect(
				screen.getAllByText( 'Configured' )[ 0 ]
			).toBeInTheDocument();
		} );
	} );

	it( 'renders trigger button and then loads system info', async () => {
		fetchSystemInfo.mockResolvedValueOnce( {
			success: true,
			data: {
				php: { version: '8.0.0' },
				database: {},
				wordpress: {},
				server: {},
			},
		} );
		render( <SystemInfo /> );

		const loadButton = screen.getByRole( 'button', {
			name: /load system info/i,
		} );
		expect( loadButton ).toBeInTheDocument();

		fireEvent.click( loadButton );

		await waitFor( () => {
			expect( screen.getByText( '8.0.0' ) ).toBeInTheDocument();
		} );
	} );

	it( 'renders error message on successful response but success false', async () => {
		fetchSystemInfo.mockResolvedValueOnce( {
			success: false,
			message: 'Custom error from API',
		} );
		render( <SystemInfo /> );

		const loadButton = screen.getByRole( 'button', {
			name: /load system info/i,
		} );
		fireEvent.click( loadButton );

		await waitFor( () => {
			expect(
				screen.getByText( 'Custom error from API' )
			).toBeInTheDocument();
		} );
	} );

	it( 'renders error message on failure', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		fetchSystemInfo.mockRejectedValueOnce( new Error( 'Network Error' ) );
		render( <SystemInfo /> );

		const loadButton = screen.getByRole( 'button', {
			name: /load system info/i,
		} );
		fireEvent.click( loadButton );

		await waitFor( () => {
			expect(
				screen.getByText( /Failed to fetch system info/i )
			).toBeInTheDocument();
		} );

		expect( consoleSpy ).toHaveBeenCalledWith(
			'System info fetch error:',
			expect.any( Error )
		);
		consoleSpy.mockRestore();
	} );
} );
