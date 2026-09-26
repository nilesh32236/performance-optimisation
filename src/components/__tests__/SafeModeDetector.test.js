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
import FileOptimization from '../FileOptimization';

jest.mock( '../../lib/apiRequest', () => {
	const actual = jest.requireActual( '../../lib/apiRequest' );
	return {
		...actual,
		apiCall: jest.fn(),
		runPerformanceScan: jest.fn(),
	};
} );

import { apiCall } from '../../lib/apiRequest';

describe( 'SafeModeDetector (issue #1465)', () => {
	beforeEach( () => {
		global.wppoSettings = {
			apiUrl: 'https://example.com/wp-json/performance-optimisation/v1/',
			nonce: 'test-nonce',
			settings: {},
			translations: {},
		};
		jest.clearAllMocks();
	} );

	const openScriptsTab = () => {
		render( <FileOptimization options={ {} } serverRules={ {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );
	};

	it( 'renders detector controls with safe-mode buttons', async () => {
		apiCall.mockResolvedValueOnce( {
			success: true,
			data: { staged: {}, has_staged: false, preview_url: '' },
		} );
		openScriptsTab();
		expect(
			screen.getByText( /Auto-exclude detector/i )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Detect fragile handles/i } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: /Enable safe mode \(one-click restore\)/i,
			} )
		).toBeInTheDocument();
	} );

	it( 'detects fragile handles and names the exact handle', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, has_staged: false, preview_url: '' },
			} )
			.mockResolvedValueOnce( {
				success: true,
				data: {
					stack: { safe_mode: false, stack_enabled: true },
					suggestions: [
						{
							handle: 'wc-cart-fragments',
							fields: [ 'excludeDeferJS', 'excludeDelayJS' ],
							reason: 'WooCommerce cart fragments.',
						},
					],
					handles: [ 'wc-cart-fragments' ],
				},
			} );
		openScriptsTab();
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: /Detect fragile handles/i,
				} )
			);
		} );
		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith(
				'safe_mode_detect',
				{},
				'POST'
			);
		} );
		await waitFor( () => {
			expect(
				screen.getByText( 'wc-cart-fragments' )
			).toBeInTheDocument();
		} );
	} );

	it( 'enables safe mode in one click', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, has_staged: false, preview_url: '' },
			} )
			.mockResolvedValueOnce( {
				success: true,
				data: { file_optimisation: { safeMode: true } },
			} );
		openScriptsTab();
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: /Enable safe mode \(one-click restore\)/i,
				} )
			);
		} );
		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'safe_mode', {
				action: 'enable',
			} );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( /Safe mode enabled/i )
			).toBeInTheDocument();
		} );
	} );

	it( 'disables safe mode in one click when already enabled', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, has_staged: false, preview_url: '' },
			} )
			.mockResolvedValueOnce( {
				success: true,
				data: { file_optimisation: { safeMode: false } },
			} );
		render(
			<FileOptimization
				options={ { safeMode: true } }
				serverRules={ {} }
			/>
		);
		fireEvent.click( screen.getByRole( 'tab', { name: /Scripts/i } ) );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: /Disable safe mode/i,
				} )
			);
		} );
		await waitFor( () => {
			expect( apiCall ).toHaveBeenCalledWith( 'safe_mode', {
				action: 'disable',
			} );
		} );
		await waitFor( () => {
			expect(
				screen.getByText( /Safe mode disabled/i )
			).toBeInTheDocument();
		} );
	} );

	it( 'shows an error banner when the detector fails', async () => {
		apiCall
			.mockResolvedValueOnce( {
				success: true,
				data: { staged: {}, has_staged: false, preview_url: '' },
			} )
			.mockRejectedValueOnce( new Error( 'boom' ) );
		openScriptsTab();
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: /Detect fragile handles/i,
				} )
			);
		} );
		await waitFor( () => {
			expect(
				screen.getByText( /Could not run the exclude detector/i )
			).toBeInTheDocument();
		} );
	} );
} );
