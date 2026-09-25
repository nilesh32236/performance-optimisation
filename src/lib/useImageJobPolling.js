/**
 * Bounded image-job status polling for the Dashboard image card.
 *
 * This is deliberately a small workflow hook, not a polling framework. The
 * request loop uses the P3-019 useAsyncWorkflow contract so each REST poll
 * has an AbortSignal, stale responses cannot commit, and unmount cleanup
 * cancels the current request and its next timer.
 *
 * @since NEXT
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { apiCall, getErrorLogMessage } from './apiRequest';
import { isAbortError, useAsyncWorkflow } from './useAbortableFetch';

const POLL_INTERVAL_MS = 5000;
const MAX_POLL_ATTEMPTS = 60;
const MAX_POLL_DELAY_MS = 15000;
const MAX_POLL_FAILURES = 5;

const getPollDelay = ( attempts ) =>
	Math.min(
		POLL_INTERVAL_MS * Math.max( 1, Math.ceil( attempts / 10 ) ),
		MAX_POLL_DELAY_MS
	);

/**
 * Own the Dashboard image-job polling lifecycle.
 *
 * @since NEXT
 * @param {Object}   options             Workflow callbacks.
 * @param {Function} options.notify      Notice dispatcher.
 * @param {Function} options.onImageInfo Called with a normalized status body.
 * @return {Object} Polling state and start/stop controls.
 */
const useImageJobPolling = ( { notify, onImageInfo } ) => {
	const [ bgProcessing, setBgProcessing ] = useState( false );
	const [ bgJobsQueued, setBgJobsQueued ] = useState( 0 );
	const [ savings, setSavings ] = useState( null );
	const timerRef = useRef( null );
	const retryRef = useRef( 0 );
	const attemptsRef = useRef( 0 );
	const generationRef = useRef( 0 );
	const { run, cancel, mountedRef } = useAsyncWorkflow();

	const clearPolling = useCallback( () => {
		generationRef.current += 1;
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
			timerRef.current = null;
		}
		cancel();
	}, [ cancel ] );

	useEffect( () => {
		return () => {
			generationRef.current += 1;
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
				timerRef.current = null;
			}
			cancel();
		};
	}, [ cancel ] );

	const pollJobStatus = useCallback( async () => {
		const generation = generationRef.current;
		timerRef.current = null;
		attemptsRef.current += 1;

		if ( attemptsRef.current >= MAX_POLL_ATTEMPTS ) {
			if ( mountedRef.current && generation === generationRef.current ) {
				setBgProcessing( false );
				notify( {
					type: 'error',
					message: __(
						'Image optimisation timed out. Please try again.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			}
			return;
		}

		let outcome = 'stale';
		try {
			outcome = await run( async ( { signal, isStale } ) => {
				const response = await apiCall(
					'image_job_status',
					{},
					'GET',
					signal
				);

				if (
					isStale() ||
					generation !== generationRef.current ||
					! mountedRef.current
				) {
					return 'stale';
				}

				retryRef.current = 0;
				if ( ! response.success || ! response.data ) {
					throw new Error(
						response.message || 'Status check failed'
					);
				}

				const queuedJobs = Number( response.data.queued_jobs ?? NaN );
				setBgJobsQueued(
					Number.isFinite( queuedJobs ) ? queuedJobs : 0
				);
				setSavings( response.data.savings ?? null );
				onImageInfo?.( response.data );

				if ( Number.isFinite( queuedJobs ) && queuedJobs === 0 ) {
					attemptsRef.current = 0;
					setBgProcessing( false );
					notify( {
						type: 'success',
						message: __(
							'Image optimisation completed.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
					return 'complete';
				}

				return 'retry';
			} );
		} catch ( error ) {
			if (
				isAbortError( error ) ||
				generation !== generationRef.current ||
				! mountedRef.current
			) {
				return;
			}

			console.error(
				'Error polling job status:',
				getErrorLogMessage( error )
			);
			retryRef.current += 1;
			if ( retryRef.current >= MAX_POLL_FAILURES ) {
				setBgProcessing( false );
				notify( {
					type: 'error',
					message: __(
						'Status check stopped after repeated failures.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
				return;
			}
			notify( {
				type: 'error',
				message: __(
					'Status check failed. Retrying…',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
			outcome = 'retry';
		}

		if (
			outcome !== 'retry' ||
			generation !== generationRef.current ||
			! mountedRef.current
		) {
			return;
		}

		const delay = getPollDelay( attemptsRef.current );
		timerRef.current = setTimeout(
			pollJobStatus,
			typeof document !== 'undefined' && document.hidden
				? Math.max( delay, 30000 )
				: delay
		);
	}, [ mountedRef, notify, onImageInfo, run ] );

	const startPolling = useCallback(
		( jobsQueued = 0 ) => {
			clearPolling();
			if ( ! mountedRef.current ) {
				return;
			}
			retryRef.current = 0;
			attemptsRef.current = 0;
			const count = Number( jobsQueued );
			setBgJobsQueued( Number.isFinite( count ) ? count : 0 );
			setBgProcessing( true );
			timerRef.current = setTimeout( pollJobStatus, POLL_INTERVAL_MS );
		},
		[ clearPolling, mountedRef, pollJobStatus ]
	);

	const stopPolling = useCallback( () => {
		clearPolling();
		if ( mountedRef.current ) {
			setBgProcessing( false );
			setBgJobsQueued( 0 );
		}
	}, [ clearPolling, mountedRef ] );

	return {
		bgProcessing,
		bgJobsQueued,
		savings,
		startPolling,
		stopPolling,
	};
};

export default useImageJobPolling;
