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
	DIFF_PREVIEW_LIMIT,
} from '../OptimizationPresets';
import { apiCall, fetchOptimizationPresets } from '../../lib/apiRequest';

/**
 * Phase C UX regressions: the safe/beginner path must stay visibly
 * prioritized, the diff preview must stay capped, and the undo contract
 * must stay plain-language. Copy/hierarchy only — no behavior change.
 */
describe( 'OptimizationPresets UX (Phase C)', () => {
	beforeEach( () => {
		global.wppoSettings = { settings: { cache_settings: {} } };
		jest.clearAllMocks();
		apiCall.mockResolvedValue( {
			success: true,
			data: { has_snapshot: false },
		} );
	} );

	it( 'selects Safe by default and marks it Recommended', () => {
		render( <OptimizationPresets /> );

		const safe = screen.getByRole( 'button', { name: 'Safe' } );
		expect( safe ).toHaveAttribute( 'aria-pressed', 'true' );
		expect(
			screen.getByRole( 'button', { name: 'Balanced' } )
		).toHaveAttribute( 'aria-pressed', 'false' );
		// Badge is decorative (aria-hidden) so the accessible name stays 'Safe'.
		expect( screen.getByText( 'Recommended' ) ).toHaveAttribute(
			'aria-hidden',
			'true'
		);
	} );

	it( 'caps the diff preview and reports the remainder', async () => {
		const diff = Array.from(
			{ length: DIFF_PREVIEW_LIMIT + 2 },
			( _, i ) => ( {
				tab: 'file_optimisation',
				key: `key${ i }`,
				from: false,
				to: true,
			} )
		);
		fetchOptimizationPresets.mockResolvedValueOnce( {
			success: true,
			data: { preset: 'safe', diff },
		} );

		const { container } = render( <OptimizationPresets /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Safe' } ) );

		await waitFor( () =>
			expect( fetchOptimizationPresets ).toHaveBeenCalledWith(
				'safe',
				expect.anything()
			)
		);
		expect(
			screen.getByText( `${ diff.length } setting(s) would change:` )
		).toBeInTheDocument();
		expect(
			container.querySelectorAll( '.wppo-presets__diff-list li' )
		).toHaveLength( DIFF_PREVIEW_LIMIT );
		expect(
			screen.getByText( /and 2 more change\(s\)/ )
		).toBeInTheDocument();
	} );

	it( 'explains the undo contract in plain language', async () => {
		apiCall.mockImplementation( ( action ) => {
			if ( 'settings_snapshot' === action ) {
				return Promise.resolve( {
					success: true,
					data: { has_snapshot: true, takenAt: null, taken_at: null },
				} );
			}
			return Promise.resolve( { success: true, data: {} } );
		} );

		render( <OptimizationPresets /> );

		expect(
			await screen.findByText(
				'Undo returns to the snapshot taken when you applied the preset. Export JSON is a manual backup file you keep yourself.'
			)
		).toBeInTheDocument();
	} );
} );
