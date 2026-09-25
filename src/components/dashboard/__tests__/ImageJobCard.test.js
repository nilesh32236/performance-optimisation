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

jest.mock( '../../../lib/apiRequest', () => {
	const actual = jest.requireActual( '../../../lib/apiRequest' );
	return {
		...actual,
		apiCall: jest.fn(),
	};
} );

jest.mock( '../../ImageOptimizationCard', () => ( props ) => (
	<div data-testid="image-card">
		<button onClick={ props.onOptimize }>Optimize Images</button>
		<button onClick={ props.onRemove }>Remove Images</button>
		<span data-testid="bg-processing">
			{ props.bgProcessing ? 'processing' : 'idle' }
		</span>
		<span data-testid="bg-queued">{ props.bgJobsQueued }</span>
	</div>
) );
jest.mock(
	'../../common/ConfirmDialog',
	() =>
		( { isOpen, onConfirm, onCancel } ) =>
			isOpen ? (
				<div data-testid="confirm-dialog">
					<button onClick={ onConfirm }>Confirm</button>
					<button onClick={ onCancel }>Cancel</button>
				</div>
			) : null
);

import { useState } from '@wordpress/element';
import ImageJobCard from '../ImageJobCard';
import { apiCall } from '../../../lib/apiRequest';

const flushMount = async () => {
	await waitFor( () => {
		expect( screen.getByTestId( 'image-card' ) ).toBeInTheDocument();
	} );
	await act( async () => {} );
};

describe( 'ImageJobCard (P3-020 polling boundary)', () => {
	beforeEach( () => {
		global.wppoSettings = {
			image_info: {},
			settings: { cache_settings: {} },
		};
		// mockReset (not just clearAllMocks): drops once-queues left over by
		// a previous test so queued responses can never bleed across tests.
		apiCall.mockReset();
		apiCall.mockResolvedValue( { success: true, data: {} } );
		jest.useRealTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		jest.restoreAllMocks();
	} );

	it( 'starts a background run and completes the poll', async () => {
		jest.useFakeTimers();
		try {
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: { background: true, jobs_queued: 2 },
			} );
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: {
					queued_jobs: 0,
					completed: { webp: 1, avif: 1 },
					pending: { webp: 0, avif: 0 },
					failed: { webp: 0, avif: 0 },
				},
			} );
			const onStatus = jest.fn();

			render(
				<ImageJobCard initialImageInfo={ {} } onStatus={ onStatus } />
			);
			await flushMount();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);

			await waitFor( () =>
				expect(
					screen.getByText(
						'Image optimisation started in background.'
					)
				).toBeInTheDocument()
			);
			expect( screen.getByTestId( 'bg-processing' ) ).toHaveTextContent(
				'processing'
			);

			await act( async () => {
				jest.advanceTimersByTime( 5000 );
			} );
			await act( async () => {} );

			await waitFor( () => {
				expect( apiCall ).toHaveBeenCalledWith(
					'image_job_status',
					{},
					'GET',
					expect.any( AbortSignal )
				);
				expect(
					screen.getByText( 'Image optimisation completed.' )
				).toBeInTheDocument();
			} );
			expect( screen.getByTestId( 'bg-processing' ) ).toHaveTextContent(
				'idle'
			);
			// Stats-strip sync: committed normalized image info.
			expect( onStatus ).toHaveBeenCalledWith(
				expect.objectContaining( {
					completed: { webp: 1, avif: 1 },
				} )
			);
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'cancels duplicate runs while one is in flight (no duplicate fetch)', async () => {
		jest.useFakeTimers();
		try {
			// First optimise_image POST hangs until released.
			let releaseFirst;
			const firstGate = new Promise( ( resolve ) => {
				releaseFirst = resolve;
			} );
			let firstSignal = null;
			apiCall.mockImplementationOnce(
				( action, payload, method, signal ) => {
					firstSignal = signal;
					return firstGate.then( () => ( {
						success: true,
						data: {
							completed: { webp: 1, avif: 0 },
							pending: { webp: 0, avif: 0 },
							failed: { webp: 0, avif: 0 },
						},
					} ) );
				}
			);
			render( <ImageJobCard initialImageInfo={ {} } /> );
			await flushMount();

			const optimizeButton = screen.getByRole( 'button', {
				name: /Optimize Images/i,
			} );
			fireEvent.click( optimizeButton );
			await waitFor( () =>
				expect( apiCall ).toHaveBeenCalledWith(
					'optimise_image',
					{},
					'POST',
					expect.any( AbortSignal )
				)
			);

			// A second click while the first run is in flight is a no-op:
			// no duplicate optimise_image fetch.
			fireEvent.click( optimizeButton );
			await act( async () => {} );
			expect( apiCall ).toHaveBeenCalledTimes( 1 );
			expect( firstSignal ).toBeInstanceOf( AbortSignal );
			expect( firstSignal.aborted ).toBe( false );

			// Releasing the first run commits and frees the boundary for a
			// later run.
			releaseFirst();
			await waitFor( () =>
				expect(
					screen.getByText( 'Images optimized successfully.' )
				).toBeInTheDocument()
			);
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'ignores a stale poll response that resolves after a newer run', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		jest.useFakeTimers();
		try {
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: { background: true, jobs_queued: 2 },
			} );
			// Slow first tick: ignores the abort signal and resolves late,
			// so only the workflow seq-guard can suppress it.
			let releaseSlowTick;
			const slowGate = new Promise( ( resolve ) => {
				releaseSlowTick = resolve;
			} );
			apiCall.mockImplementationOnce( async () => {
				await slowGate;
				return {
					success: true,
					data: {
						queued_jobs: 5,
						completed: { webp: 0, avif: 0 },
						pending: { webp: 5, avif: 0 },
						failed: { webp: 0, avif: 0 },
					},
				};
			} );
			apiCall.mockResolvedValueOnce( { success: true } );
			const onStatus = jest.fn();
			render(
				<ImageJobCard initialImageInfo={ {} } onStatus={ onStatus } />
			);
			await flushMount();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);
			await waitFor( () =>
				expect(
					screen.getByText(
						'Image optimisation started in background.'
					)
				).toBeInTheDocument()
			);

			// First tick starts and hangs on the gate.
			await act( async () => {
				jest.advanceTimersByTime( 5000 );
			} );
			await waitFor( () =>
				expect( apiCall ).toHaveBeenCalledWith(
					'image_job_status',
					{},
					'GET',
					expect.any( AbortSignal )
				)
			);

			// A newer run (remove) starts while the tick is in flight: its
			// workflow run aborts the tick and bumps the sequence.
			fireEvent.click(
				screen.getByRole( 'button', { name: /Remove Images/i } )
			);
			fireEvent.click(
				screen.getByRole( 'button', { name: /Confirm/i } )
			);
			await waitFor( () =>
				expect( apiCall ).toHaveBeenCalledWith(
					'delete_optimised_image',
					{},
					'POST',
					expect.any( AbortSignal )
				)
			);
			await waitFor( () =>
				expect(
					screen.getByText( 'Optimized images removed.' )
				).toBeInTheDocument()
			);

			// The slow tick resolves after the newer run committed: it must
			// be ignored (stale) instead of resurrecting queued=5.
			releaseSlowTick();
			await act( async () => {} );
			await act( async () => {} );

			expect( onStatus ).toHaveBeenCalledWith( {
				completed: { webp: 0, avif: 0 },
				pending: { webp: 0, avif: 0 },
				failed: { webp: 0, avif: 0 },
			} );
			expect( onStatus ).not.toHaveBeenCalledWith(
				expect.objectContaining( {
					pending: { webp: 5, avif: 0 },
				} )
			);
			expect(
				screen.queryByText( 'Image optimisation completed.' )
			).not.toBeInTheDocument();
		} finally {
			jest.useRealTimers();
			consoleSpy.mockRestore();
		}
	} );

	it( 'cleans up timers and aborts in-flight work on unmount without notifying', async () => {
		const consoleSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		jest.useFakeTimers();
		try {
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: { background: true, jobs_queued: 2 },
			} );
			apiCall.mockImplementationOnce(
				( action, payload, method, signal ) => {
					return new Promise( ( resolve, reject ) => {
						if ( signal ) {
							signal.addEventListener( 'abort', () => {
								const abortError = new Error( 'Aborted' );
								abortError.name = 'AbortError';
								reject( abortError );
							} );
						}
					} );
				}
			);
			const onStatus = jest.fn();
			const { unmount } = render(
				<ImageJobCard initialImageInfo={ {} } onStatus={ onStatus } />
			);
			await flushMount();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);
			await waitFor( () =>
				expect(
					screen.getByText(
						'Image optimisation started in background.'
					)
				).toBeInTheDocument()
			);

			await act( async () => {
				jest.advanceTimersByTime( 5000 );
			} );
			await waitFor( () =>
				expect( apiCall ).toHaveBeenCalledWith(
					'image_job_status',
					{},
					'GET',
					expect.any( AbortSignal )
				)
			);
			const pollSignal = apiCall.mock.calls.find(
				( [ action ] ) => 'image_job_status' === action
			)[ 3 ];
			expect( pollSignal ).toBeInstanceOf( AbortSignal );

			unmount();
			expect( pollSignal.aborted ).toBe( true );

			await act( async () => {
				jest.advanceTimersByTime( 30000 );
			} );
			await act( async () => {} );

			// Aborted ticks stay silent and never sync stats.
			expect( consoleSpy ).not.toHaveBeenCalled();
			expect( onStatus ).not.toHaveBeenCalled();
		} finally {
			jest.useRealTimers();
			consoleSpy.mockRestore();
		}
	} );

	it( 'keeps poll state isolated from sibling save state', async () => {
		jest.useFakeTimers();
		try {
			apiCall.mockResolvedValueOnce( {
				success: true,
				data: { background: true, jobs_queued: 3 },
			} );
			const siblingNotify = jest.fn();
			const Sibling = () => {
				const [ saved, setSaved ] = useState( false );
				return (
					<div>
						<span data-testid="sibling">
							{ saved ? 'saved' : 'unsaved' }
						</span>
						<button
							onClick={ () => {
								setSaved( true );
								siblingNotify( 'sibling-saved' );
							} }
						>
							Save Sibling
						</button>
					</div>
				);
			};
			render(
				<>
					<ImageJobCard initialImageInfo={ {} } />
					<Sibling />
				</>
			);
			await flushMount();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Optimize Images/i } )
			);
			await waitFor( () =>
				expect(
					screen.getByText(
						'Image optimisation started in background.'
					)
				).toBeInTheDocument()
			);

			// Poll activity must not touch sibling state.
			await act( async () => {
				jest.advanceTimersByTime( 15000 );
			} );
			await act( async () => {} );
			expect( screen.getByTestId( 'sibling' ) ).toHaveTextContent(
				'unsaved'
			);
			expect( siblingNotify ).not.toHaveBeenCalled();

			fireEvent.click(
				screen.getByRole( 'button', { name: /Save Sibling/i } )
			);
			expect( screen.getByTestId( 'sibling' ) ).toHaveTextContent(
				'saved'
			);
			expect( siblingNotify ).toHaveBeenCalledWith( 'sibling-saved' );
			// Sibling save must not disturb poll state.
			expect( screen.getByTestId( 'bg-processing' ) ).toHaveTextContent(
				'processing'
			);
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'removes optimized images after confirming and syncs the stats strip', async () => {
		apiCall.mockResolvedValueOnce( { success: true } );
		const onStatus = jest.fn();
		render(
			<ImageJobCard initialImageInfo={ {} } onStatus={ onStatus } />
		);
		await flushMount();

		fireEvent.click(
			screen.getByRole( 'button', { name: /Remove Images/i } )
		);
		expect( screen.getByTestId( 'confirm-dialog' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: /Confirm/i } ) );

		await waitFor( () =>
			expect( apiCall ).toHaveBeenCalledWith(
				'delete_optimised_image',
				{},
				'POST',
				expect.any( AbortSignal )
			)
		);
		await waitFor( () =>
			expect(
				screen.getByText( 'Optimized images removed.' )
			).toBeInTheDocument()
		);
		expect( onStatus ).toHaveBeenCalledWith( {
			completed: { webp: 0, avif: 0 },
			pending: { webp: 0, avif: 0 },
			failed: { webp: 0, avif: 0 },
		} );
	} );
} );
