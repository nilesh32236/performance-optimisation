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

import OptimizationPresets, {
	exportSettingsJson,
	stripSensitiveSettings,
} from '../OptimizationPresets';
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
				'balanced',
				expect.anything()
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
			expect( fetchOptimizationPresets ).toHaveBeenCalledWith(
				'safe',
				expect.anything()
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
			screen.getByRole( 'button', { name: /Apply Safe/i } )
		);

		await waitFor( () =>
			expect( applyOptimizationPreset ).toHaveBeenCalledWith( 'safe' )
		);
		expect(
			screen.getByText( 'Preset applied successfully.' )
		).toBeInTheDocument();
	} );

	it( 'commits the apply_preset server settings to the shared cache', async () => {
		const serverMap = {
			cache_settings: {},
			file_optimisation: { minifyHTML: true },
		};
		fetchOptimizationPresets.mockResolvedValueOnce( {
			success: true,
			data: { preset: 'balanced', diff: [] },
		} );
		applyOptimizationPreset.mockResolvedValueOnce( {
			success: true,
			message: 'Preset applied successfully.',
			data: { preset: 'balanced', settings: serverMap, diff: [] },
		} );

		render( <OptimizationPresets /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Balanced' } ) );
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
				screen.getByText( 'Preset applied successfully.' )
			).toBeInTheDocument()
		);
		// P3-019: the nested server settings reach the shared global.
		expect( global.wppoSettings.settings ).toEqual( serverMap );
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
					'balanced',
					expect.anything()
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

	it( 'ignores a stale preview response after rapid preset switching', async () => {
		let resolveBalanced;
		let resolveSafe;
		fetchOptimizationPresets.mockImplementation( ( name ) => {
			if ( 'balanced' === name ) {
				return new Promise( ( resolve ) => {
					resolveBalanced = resolve;
				} );
			}
			return new Promise( ( resolve ) => {
				resolveSafe = resolve;
			} );
		} );

		render( <OptimizationPresets /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Balanced' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Safe' } ) );

		// Resolve out of order: the stale Balanced response settles last
		// and must not overwrite the Safe diff.
		resolveSafe( {
			success: true,
			data: { preset: 'safe', diff: [] },
		} );
		resolveBalanced( {
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

		await waitFor( () =>
			expect(
				screen.getByText(
					'This preset already matches your settings — nothing would change.'
				)
			).toBeInTheDocument()
		);
		expect(
			screen.queryByText( 'file_optimisation.minifyHTML' )
		).not.toBeInTheDocument();
	} );

	it( 'strips secrets from the exported JSON backup', () => {
		global.wppoSettings = {
			settings: {
				cache_settings: { enableCache: true },
				performance_audit: {
					pagespeed_api_key: 'AIza-secret',
					other: 'keep',
				},
				object_cache: { password: 's3cret', host: '127.0.0.1' },
			},
		};
		// Capture the serialized payload synchronously by intercepting Blob
		// construction (jsdom Blob.text() is async and timing-sensitive).
		const RealBlob = global.Blob;
		let captured = '';
		global.Blob = jest.fn( ( parts, options ) => {
			captured = Array.isArray( parts ) ? parts.join( '' ) : '';
			return new RealBlob( parts, options );
		} );
		global.URL.createObjectURL = jest.fn( () => 'blob:mock-url' );
		global.URL.revokeObjectURL = jest.fn();
		const anchor = { click: jest.fn(), remove: jest.fn() };
		const createSpy = jest
			.spyOn( document, 'createElement' )
			.mockReturnValue( anchor );
		const appendSpy = jest
			.spyOn( document.body, 'appendChild' )
			.mockImplementation( () => anchor );
		try {
			const notify = jest.fn();
			exportSettingsJson( notify );

			expect( captured ).not.toBe( '' );
			const parsed = JSON.parse( captured );
			expect(
				parsed.performance_audit.pagespeed_api_key
			).toBeUndefined();
			expect( parsed.object_cache.password ).toBeUndefined();
			expect( parsed.performance_audit.other ).toBe( 'keep' );
			expect( parsed.object_cache.host ).toBe( '127.0.0.1' );
			expect( parsed.cache_settings.enableCache ).toBe( true );
			// The live global keeps its secrets.
			expect(
				global.wppoSettings.settings.performance_audit.pagespeed_api_key
			).toBe( 'AIza-secret' );
			expect( global.wppoSettings.settings.object_cache.password ).toBe(
				's3cret'
			);
			expect( notify ).toHaveBeenCalledWith(
				expect.objectContaining( { type: 'success' } )
			);
		} finally {
			createSpy.mockRestore();
			appendSpy.mockRestore();
			global.Blob = RealBlob;
			delete global.URL.createObjectURL;
			delete global.URL.revokeObjectURL;
		}
	} );

	it( 'stripSensitiveSettings tolerates missing tabs and non-objects', () => {
		expect( stripSensitiveSettings( null ) ).toEqual( {} );
		expect( stripSensitiveSettings( { cache_settings: {} } ) ).toEqual( {
			cache_settings: {},
		} );
	} );
} );
