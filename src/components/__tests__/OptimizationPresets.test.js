import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '../../lib/apiRequest', () => {
	const actual = jest.requireActual( '../../lib/apiRequest' );
	return {
		...actual,
		apiCall: jest.fn(),
		fetchOptimizationPresets: jest.fn(),
		applyOptimizationPreset: jest.fn(),
	};
} );

import OptimizationPresets from '../OptimizationPresets';
import {
	apiCall,
	fetchOptimizationPresets,
	applyOptimizationPreset,
} from '../../lib/apiRequest';

describe( 'OptimizationPresets', () => {
	beforeEach( () => {
		global.wppoSettings = { settings: { cache_settings: {} } };
		jest.clearAllMocks();
		apiCall.mockResolvedValue( {
			success: true,
			data: { has_snapshot: false },
		} );
	} );

	it( 'renders the three preset buttons and the export action', async () => {
		render( <OptimizationPresets /> );

		expect(
			screen.getByRole( 'button', { name: 'Safe' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Balanced' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Aggressive' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Export JSON/i } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith(
				'settings_snapshot',
				{},
				'GET',
				expect.anything()
			)
		);
	} );

	it( 'shows a diff preview when a preset is picked', async () => {
		fetchOptimizationPresets.mockResolvedValueOnce( {
			success: true,
			data: {
				preset: 'balanced',
				diff: [
					{
						tab: 'file_optimisation',
						key: 'minifyHTML',
						from: false,
						to: true,
					},
				],
			},
		} );

		render( <OptimizationPresets /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Balanced' } ) );

		await waitFor( () =>
			expect( fetchOptimizationPresets ).toHaveBeenCalledWith(
				'balanced'
			)
		);
		expect(
			screen.getByText( '1 setting(s) would change:' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'file_optimisation.minifyHTML' )
		).toBeInTheDocument();
	} );

	it( 'applies the preset and announces success', async () => {
		fetchOptimizationPresets.mockResolvedValueOnce( {
			success: true,
			data: { preset: 'safe', diff: [] },
		} );
		applyOptimizationPreset.mockResolvedValueOnce( {
			success: true,
			message: 'Preset applied successfully.',
			data: { preset: 'safe', diff: [] },
		} );

		render( <OptimizationPresets /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Safe' } ) );
		// Wait for the preview to settle: Apply stays disabled while loading.
		await waitFor( () =>
			expect( fetchOptimizationPresets ).toHaveBeenCalledWith( 'safe' )
		);
		await waitFor( () =>
			expect(
				screen.getByText(
					'This preset already matches your settings — nothing would change.'
				)
			).toBeInTheDocument()
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /Apply Safe/i } )
		);

		await waitFor( () =>
			expect( applyOptimizationPreset ).toHaveBeenCalledWith( 'safe' )
		);
		expect(
			screen.getByText( 'Preset applied successfully.' )
		).toBeInTheDocument();
	} );

	it( 'announces failure when the apply call fails', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		try {
			fetchOptimizationPresets.mockResolvedValueOnce( {
				success: true,
				data: { preset: 'balanced', diff: [] },
			} );
			applyOptimizationPreset.mockRejectedValueOnce(
				new Error( 'boom' )
			);

			render( <OptimizationPresets /> );

			fireEvent.click(
				screen.getByRole( 'button', { name: 'Balanced' } )
			);
			await waitFor( () =>
				expect( fetchOptimizationPresets ).toHaveBeenCalledWith(
					'balanced'
				)
			);
			await waitFor( () =>
				expect(
					screen.getByText(
						'This preset already matches your settings — nothing would change.'
					)
				).toBeInTheDocument()
			);
			fireEvent.click(
				screen.getByRole( 'button', { name: /Apply Balanced/i } )
			);

			await waitFor( () =>
				expect(
					screen.getByText(
						'Failed to apply the preset. Your settings were left unchanged.'
					)
				).toBeInTheDocument()
			);
		} finally {
			consoleSpy.mockRestore();
		}
	} );

	it( 'shows the undo button when a snapshot exists and restores on click', async () => {
		apiCall.mockImplementation( ( action ) => {
			if ( 'settings_snapshot' === action ) {
				return Promise.resolve( {
					success: true,
					data: { has_snapshot: true, taken_at: 1234567890 },
				} );
			}
			if ( 'restore_settings' === action ) {
				return Promise.resolve( {
					success: true,
					message: 'Settings restored successfully.',
				} );
			}
			return Promise.resolve( { success: true, data: {} } );
		} );

		render( <OptimizationPresets /> );

		const undoButton = await screen.findByRole( 'button', {
			name: /Undo/i,
		} );
		fireEvent.click( undoButton );

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith( 'restore_settings', {} )
		);
		expect(
			screen.getByText( 'Settings restored successfully.' )
		).toBeInTheDocument();
	} );
} );
