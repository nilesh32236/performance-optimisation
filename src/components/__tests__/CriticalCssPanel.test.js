import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '@fortawesome/react-fontawesome', () => ( {
	FontAwesomeIcon: ( { icon } ) => (
		<span data-icon={ icon?.iconName || 'icon' } />
	),
} ) );

jest.mock( '@fortawesome/free-solid-svg-icons', () => ( {
	faCheckCircle: { iconName: 'check-circle' },
	faExclamationTriangle: { iconName: 'exclamation-triangle' },
	faClock: { iconName: 'clock' },
	faTimesCircle: { iconName: 'times-circle' },
} ) );

import CriticalCssPanel, { normalizeCcssEntry } from '../CriticalCssPanel';

describe( 'CriticalCssPanel', () => {
	it( 'renders the empty state when no templates exist', () => {
		render( <CriticalCssPanel status={ {} } onRegenerate={ jest.fn() } /> );

		expect(
			screen.getByText(
				'No templates found. Save settings and regenerate.'
			)
		).toBeInTheDocument();
		expect( screen.getByRole( 'button' ) ).toBeInTheDocument();
	} );

	it( 'renders a ready template with its label', () => {
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: { status: 'ready', label: 'Single' },
				} }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Single' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Generated' ) ).toBeInTheDocument();
	} );

	it( 'falls back to a truncated hash label when no label is present', () => {
		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: 'pending' } }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'abcdef12…' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Pending' ) ).toBeInTheDocument();
	} );

	it( 'renders unknown statuses with the warning badge', () => {
		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: 'mystery' } }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Not Generated' ) ).toBeInTheDocument();
	} );

	it( 'calls onRegenerate when the button is clicked', async () => {
		const onRegenerate = jest.fn().mockResolvedValue( undefined );
		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: 'ready' } }
				onRegenerate={ onRegenerate }
			/>
		);

		fireEvent.click( screen.getByRole( 'button' ) );

		await waitFor( () =>
			expect( onRegenerate ).toHaveBeenCalledTimes( 1 )
		);
	} );

	it( 'rethrows bulk failures so the parent owns feedback', async () => {
		const onRegenerate = jest.fn().mockRejectedValue( new Error( 'boom' ) );
		// Harness mimics the parent (FileOptimization handleRegenerateCss
		// via withNotification): it awaits the child and owns the banner,
		// proving the child rethrew instead of notifying a second time.
		let caught = null;
		const catchingParent = async () => {
			try {
				await onRegenerate();
			} catch ( err ) {
				caught = err;
			}
		};
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: 'ready' } }
				onRegenerate={ catchingParent }
			/>
		);

		fireEvent.click( screen.getByRole( 'button' ) );

		await waitFor( () =>
			expect( onRegenerate ).toHaveBeenCalledTimes( 1 )
		);
		await waitFor( () =>
			expect( caught && caught.message ).toBe( 'boom' )
		);
		// Single-owner feedback: no error banner is rendered by the panel itself.
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();

		errorSpy.mockRestore();
	} );

	it( 'renders prototype-polluting status keys with the warning badge', () => {
		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: '__proto__' } }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Not Generated' ) ).toBeInTheDocument();
	} );

	it( 'renders skipped and failed badges', () => {
		render(
			<CriticalCssPanel
				status={ {
					aaaa1111bbbb2222: {
						status: 'skipped',
						label: 'Builder',
					},
					cccc3333dddd4444: { status: 'failed', label: 'Home' },
				} }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Skipped' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Failed' ) ).toBeInTheDocument();
	} );

	it( 'renders processing and done badges', () => {
		render(
			<CriticalCssPanel
				status={ {
					aaaa1111bbbb2222: {
						status: 'processing',
						label: 'Builder',
					},
					cccc3333dddd4444: { status: 'done', label: 'Home' },
				} }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Processing' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Generated' ).length ).toBeGreaterThan(
			0
		);
	} );

	it( 'rethrows single-regen failures so the parent owns feedback', async () => {
		const onRegenerateSingle = jest.fn( async () => {
			throw new Error( 'nope' );
		} );
		// Harness mimics the parent (FileOptimization): it awaits the child
		// and owns the banner, proving the child rethrew instead of
		// notifying a second time for the same click.
		let caught = null;
		const catchingParent = async ( hash ) => {
			try {
				await onRegenerateSingle( hash );
			} catch ( err ) {
				caught = err;
			}
		};
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: { status: 'failed', label: 'Home' },
				} }
				onRegenerate={ jest.fn() }
				onRegenerateSingle={ catchingParent }
			/>
		);

		fireEvent.click( screen.getByText( 'Regenerate' ) );

		await waitFor( () =>
			expect( onRegenerateSingle ).toHaveBeenCalledWith(
				'abcdef1234567890'
			)
		);
		expect( caught && caught.message ).toBe( 'nope' );
		// Single-owner feedback: no error banner is rendered by the panel itself.
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();

		errorSpy.mockRestore();
	} );

	it( 'resolves onRegenerateSingle success without notifying', async () => {
		const onRegenerateSingle = jest.fn().mockResolvedValue( undefined );

		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: { status: 'failed', label: 'Home' },
				} }
				onRegenerate={ jest.fn() }
				onRegenerateSingle={ onRegenerateSingle }
			/>
		);

		fireEvent.click( screen.getByText( 'Regenerate' ) );

		await waitFor( () =>
			expect( onRegenerateSingle ).toHaveBeenCalledTimes( 1 )
		);
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'normalizeCcssEntry', () => {
	it( 'falls back to none with a hash label for null', () => {
		const normalized = normalizeCcssEntry( 'abcdef1234567890', null );
		expect( normalized.statusKey ).toBe( 'none' );
		expect( normalized.label ).toBe( 'abcdef12…' );
		expect( normalized.size ).toBeNull();
	} );

	it( 'falls back to none for non-object entries', () => {
		expect( normalizeCcssEntry( 'abcdef1234567890', 42 ).statusKey ).toBe(
			'none'
		);
		expect(
			normalizeCcssEntry( 'abcdef1234567890', undefined ).statusKey
		).toBe( 'none' );
	} );

	it( 'falls back to none when status is missing', () => {
		const normalized = normalizeCcssEntry( 'abcdef1234567890', {
			label: 'Custom',
		} );
		expect( normalized.statusKey ).toBe( 'none' );
		expect( normalized.label ).toBe( 'Custom' );
	} );

	it( 'preserves valid entries', () => {
		const normalized = normalizeCcssEntry( 'abcdef1234567890', {
			status: 'ready',
			label: 'Single',
			size: 1234,
			truncated: true,
		} );
		expect( normalized ).toEqual( {
			statusKey: 'ready',
			label: 'Single',
			size: 1234,
			truncated: true,
		} );
	} );

	it( 'nulls non-finite sizes and coerces truncated', () => {
		expect(
			normalizeCcssEntry( 'abcdef1234567890', {
				status: 'done',
				label: 'Single',
				size: Number.NaN,
			} ).size
		).toBeNull();
		expect(
			normalizeCcssEntry( 'abcdef1234567890', {
				status: 'done',
				label: 'Single',
				size: 2048,
				truncated: 1,
			} ).truncated
		).toBe( true );
	} );
} );
