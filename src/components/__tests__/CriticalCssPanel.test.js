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

import CriticalCssPanel, {
	normalizeCcssEntry,
	statusConfigFor,
	rolloutHitLabel,
} from '../CriticalCssPanel';

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

	it( 'logs single-regen failures without rethrowing (parent owns feedback)', async () => {
		const onRegenerateSingle = jest.fn( async () => {
			throw new Error( 'nope' );
		} );
		// Harness mimics the parent (FileOptimization): it notifies
		// internally and owns the banner; the child only logs, proving no
		// second banner and no unhandled rejection for the same click.
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
			rollout: null,
			preview: null,
		} );
	} );

	it( 'carries the rollout block and preview through', () => {
		const normalized = normalizeCcssEntry( 'abcdef1234567890', {
			status: 'staged',
			label: 'Home',
			size: 0,
			rollout: { state: 'staged', hit: 'bypass (staged preview)' },
			preview: { has_staged: true, staged_size: 120, delta_bytes: 20 },
		} );
		expect( normalized.statusKey ).toBe( 'staged' );
		expect( normalized.rollout.hit ).toBe( 'bypass (staged preview)' );
		expect( normalized.preview.has_staged ).toBe( true );
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

describe( 'statusConfigFor', () => {
	it( 'resolves known keys and falls back for inherited keys', () => {
		expect( statusConfigFor( 'skipped' ).label ).toBe( 'Skipped' );
		expect( statusConfigFor( '__proto__' ).label ).toBe( 'Not Generated' );
		expect( statusConfigFor( 'mystery' ).label ).toBe( 'Not Generated' );
	} );

	it( 'renders the empty state for non-object status', () => {
		render( <CriticalCssPanel status="oops" onRegenerate={ jest.fn() } /> );

		expect(
			screen.getByText(
				'No templates found. Save settings and regenerate.'
			)
		).toBeInTheDocument();
	} );

	it( 'labels per-template regenerate buttons for screen readers', () => {
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: { status: 'failed', label: 'Home' },
				} }
				onRegenerate={ jest.fn() }
				onRegenerateSingle={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'button', { name: 'Regenerate Home' } )
		).toBeInTheDocument();
	} );
} );

describe( 'safe rollout badges and actions', () => {
	it( 'renders staged badge with hit reason, preview and promote action', async () => {
		const onPromote = jest.fn().mockResolvedValue( undefined );
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: {
						status: 'staged',
						label: 'Home',
						size: 0,
						rollout: {
							state: 'staged',
							hit: 'bypass (staged preview)',
						},
						preview: {
							has_staged: true,
							staged_size: 120,
							delta_bytes: 20,
						},
					},
				} }
				onRegenerate={ jest.fn() }
				onPromote={ onPromote }
				onRollback={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Staged' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Hit reason: Bypass (staged preview)' )
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Promote staged CSS for Home',
			} )
		);
		await waitFor( () =>
			expect( onPromote ).toHaveBeenCalledWith( 'abcdef1234567890' )
		);
	} );

	it( 'renders rolled back badge with rollback action', async () => {
		const onRollback = jest.fn().mockResolvedValue( undefined );
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: {
						status: 'rolled_back',
						label: 'Home',
						size: 512,
						rollout: {
							state: 'rolled_back',
							hit: 'hit (last-good restored)',
						},
					},
				} }
				onRegenerate={ jest.fn() }
				onRollback={ onRollback }
			/>
		);

		expect( screen.getByText( 'Rolled Back' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Hit reason: Hit (last-good restored)' )
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Roll back Home to last-good CSS',
			} )
		);
		await waitFor( () =>
			expect( onRollback ).toHaveBeenCalledWith( 'abcdef1234567890' )
		);
	} );

	it( 'logs rollout errors without a banner (single-owner feedback)', async () => {
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		const onPromote = jest.fn().mockRejectedValue( new Error( 'nope' ) );
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: { status: 'staged', label: 'Home' },
				} }
				onRegenerate={ jest.fn() }
				onPromote={ onPromote }
			/>
		);

		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Promote staged CSS for Home',
			} )
		);
		await waitFor( () => expect( onPromote ).toHaveBeenCalledTimes( 1 ) );
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		errorSpy.mockRestore();
	} );

	it( 'formats the staged preview delta with human-readable sizes', () => {
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: {
						status: 'staged',
						label: 'Home',
						size: 0,
						preview: {
							has_staged: true,
							staged_size: 2048,
							delta_bytes: 1024,
						},
					},
				} }
				onRegenerate={ jest.fn() }
				onPromote={ jest.fn() }
			/>
		);

		expect(
			screen.getByText( 'Preview: 2.0 KB (+1.0 KB)' )
		).toBeInTheDocument();
	} );

	it( 'announces promote/rollback busy state via aria-busy', () => {
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: {
						status: 'staged',
						label: 'Home',
						size: 0,
					},
				} }
				onRegenerate={ jest.fn() }
				onPromote={ jest.fn() }
				onRollback={ jest.fn() }
			/>
		);

		const promote = screen.getByRole( 'button', {
			name: 'Promote staged CSS for Home',
		} );
		expect( promote ).toHaveAttribute( 'aria-busy', 'false' );
	} );

	it( 'maps known rollout hit tokens and falls back to raw strings', () => {
		expect( rolloutHitLabel( 'hit (promoted)' ) ).toBe( 'Hit (promoted)' );
		expect( rolloutHitLabel( 'bypass (unoptimized)' ) ).toBe(
			'Bypass (unoptimized)'
		);
		expect( rolloutHitLabel( 'custom future reason' ) ).toBe(
			'custom future reason'
		);
		expect( rolloutHitLabel( '' ) ).toBe( '' );
		// Dynamic probe codes map to the translated generic template.
		expect( rolloutHitLabel( 'miss (500)' ) ).toBe( 'Miss (500)' );
		expect( rolloutHitLabel( 'miss (503)' ) ).toBe( 'Miss (503)' );
		// Inherited Object keys must not leak functions via `in`.
		expect( rolloutHitLabel( 'toString' ) ).toBe( 'toString' );
		expect( rolloutHitLabel( 'constructor' ) ).toBe( 'constructor' );
	} );

	it( 'announces per-template regenerate busy state via aria-busy', () => {
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: {
						status: 'done',
						label: 'Home',
						size: 0,
					},
				} }
				onRegenerate={ jest.fn() }
				onRegenerateSingle={ jest.fn() }
			/>
		);

		const regen = screen.getByRole( 'button', {
			name: 'Regenerate Home',
		} );
		expect( regen ).toHaveAttribute( 'aria-busy', 'false' );
		expect( regen ).toHaveTextContent( 'Regenerate' );
	} );
} );
