/**
 * Image-job polling boundary hook (P3-020).
 *
 * Isolates the Dashboard `image_job_status` polling lifecycle behind the
 * tested P3-019 `useAsyncWorkflow` primitive (abort/stale-seq/mounted)
 * instead of hand-rolled poll refs + a `setTimeout` chain in the shell.
 * No router, no store, no polling framework — one bounded card workflow.
 *
 * REST action/auth/response contracts and loading/error/status strings are
 * unchanged from Dashboard's inline implementation.
 *
 * @since NEXT
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { apiCall, getErrorLogMessage } from './apiRequest';
import { useAsyncWorkflow } from './useAbortableFetch';

/**
 * Polling interval for image_job_status ticks.
 */
export const POLL_INTERVAL_MS = 5000;

/**
 * Maximum number of image_job_status poll attempts before giving up
 * (~5 minutes at 5s), matching PageSpeedPanel's cap.
 */
export const MAX_POLL_ATTEMPTS = 60;

/**
 * Maximum delay between image_job_status poll ticks.
 *
 * Polling backs off (5s for the first 10 attempts, then +5s per 10
 * attempts) so deep queues do not hammer admin-ajax at the same rate as
 * near-complete ones.
 *
 * @since NEXT
 */
export const MAX_POLL_DELAY_MS = 15000;

/**
 * Consecutive poll failures before the boundary stops retrying.
 *
 * @since NEXT
 */
export const MAX_POLL_RETRIES = 5;

/**
 * Hidden-tab floor for poll ticks (mirrors PageSpeed backoff).
 *
 * @since NEXT
 */
export const HIDDEN_TAB_POLL_FLOOR_MS = 30000;

/**
 * Delay before the next poll tick, backing off with the attempt count.
 *
 * @since NEXT
 * @param {number} attempts 1-based poll attempt count.
 * @return {number} Milliseconds to wait before the next tick.
 */
export const getPollDelay = ( attempts ) =>
	Math.min(
		POLL_INTERVAL_MS * Math.max( 1, Math.ceil( attempts / 10 ) ),
		MAX_POLL_DELAY_MS
	);

/**
 * Normalize image info payloads into the {webp, avif} count shape.
 *
 * The endpoint (like wppoSettings.image_info) may store arrays of file
 * paths; counts are expected downstream so an Array must never leak into
 * totals (which would coerce them to strings).
 *
 * @since NEXT
 * @param {Object} raw Raw image info object.
 * @return {Object} Normalized {completed, pending, failed} counts.
 */
export const normalizeImageInfo = ( raw ) => {
	const normalize = ( bucket ) => ( {
		webp: Array.isArray( bucket?.webp )
			? bucket.webp.length
			: bucket?.webp || 0,
		avif: Array.isArray( bucket?.avif )
			? bucket.avif.length
			: bucket?.avif || 0,
	} );
	return {
		completed: normalize( raw?.completed ),
		pending: normalize( raw?.pending ),
		failed: normalize( raw?.failed ),
	};
};

/**
 * Image-job poll workflow: owns `optimise_image` start, the
 * `image_job_status` tick chain, and `delete_optimised_image` removal.
 *
 * Every network tick runs through `useAsyncWorkflow().run()` so a re-run
 * aborts the previous tick, a slow earlier response is stale-guarded
 * before any setState/notify, and unmount aborts in-flight work.
 *
 * @since NEXT
 * @param {Object}   options                  Options.
 * @param {Object}   options.initialImageInfo Initial image info for card UI.
 * @param {Function} options.onStatus         Called with normalized image info on each committed update.
 * @param {Function} options.notify           Notice sink ({type, message, durationMs}).
 * @return {Object} Card state + actions.
 */
export const useImageJobPoll = ( {
	initialImageInfo,
	onStatus,
	notify,
} = {} ) => {
	const { run, cancel, mountedRef } = useAsyncWorkflow();
	const [ bgProcessing, setBgProcessing ] = useState( false );
	const [ bgJobsQueued, setBgJobsQueued ] = useState( 0 );
	const [ imgSavings, setImgSavings ] = useState( null );
	const [ imageInfo, setImageInfo ] = useState( () =>
		normalizeImageInfo( initialImageInfo ?? {} )
	);
	const [ optimizing, setOptimizing ] = useState( false );
	const [ removing, setRemoving ] = useState( false );

	const timerRef = useRef( null );
	const attemptsRef = useRef( 0 );
	const retriesRef = useRef( 0 );
	const pollingRef = useRef( false );
	const submittingRef = useRef( false );
	const optimizingRef = useRef( false );
	// Latest callbacks without re-creating the tick chain (avoids timer
	// resets when the parent re-renders the stats strip).
	const callbacksRef = useRef( { onStatus, notify } );
	callbacksRef.current = { onStatus, notify };
	const tickRef = useRef( null );

	const clearTimer = useCallback( () => {
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
			timerRef.current = null;
		}
	}, [] );

	const scheduleNextTick = useCallback( () => {
		const delay = getPollDelay( attemptsRef.current );
		const wait =
			typeof document !== 'undefined' && document.hidden
				? Math.max( delay, HIDDEN_TAB_POLL_FLOOR_MS )
				: delay;
		timerRef.current = setTimeout( () => {
			if ( tickRef.current ) {
				tickRef.current();
			}
		}, wait );
	}, [] );

	/**
	 * Stop the tick chain and abort the in-flight tick. Stale completions
	 * are ignored via the workflow seq-guard; no scheduling follows.
	 *
	 * @since NEXT
	 */
	const stop = useCallback( () => {
		pollingRef.current = false;
		clearTimer();
		cancel();
	}, [ cancel, clearTimer ] );

	const pollTick = useCallback( async () => {
		if ( ! pollingRef.current ) {
			return;
		}
		attemptsRef.current += 1;
		if ( attemptsRef.current >= MAX_POLL_ATTEMPTS ) {
			pollingRef.current = false;
			timerRef.current = null;
			setBgProcessing( false );
			callbacksRef.current.notify?.( {
				type: 'error',
				message: __(
					'Image optimisation timed out. Please try again.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
			return;
		}
		try {
			const outcome = await run( async ( { signal, isStale } ) => {
				const response = await apiCall(
					'image_job_status',
					{},
					'GET',
					signal
				);
				// Unmount/stop cleanup may have aborted this tick while the
				// request was in flight — bail before any setState/notify.
				if ( isStale() || signal?.aborted ) {
					return { aborted: true };
				}
				if ( ! response.success || ! response.data ) {
					// Malformed payload: retryable failure with backoff.
					throw new Error(
						response.message || 'Status check failed'
					);
				}
				// Coerce: the endpoint may omit queued_jobs or encode it as
				// a string ("0"). Strict === 0 would never detect completion
				// and poll until MAX_POLL_ATTEMPTS.
				if ( isStale() ) {
					return { stale: true };
				}
				const queuedJobs = Number( response.data.queued_jobs ?? NaN );
				return { response, queuedJobs };
			} );
			if ( ! outcome || outcome.aborted || outcome.stale ) {
				return;
			}
			if ( ! pollingRef.current ) {
				return;
			}
			retriesRef.current = 0;
			const { response, queuedJobs } = outcome;
			const normalized = normalizeImageInfo( response.data );
			setBgJobsQueued( Number.isFinite( queuedJobs ) ? queuedJobs : 0 );
			setImgSavings( response.data.savings ?? null );
			setImageInfo( normalized );
			callbacksRef.current.onStatus?.( normalized );

			if ( Number.isFinite( queuedJobs ) && queuedJobs === 0 ) {
				pollingRef.current = false;
				timerRef.current = null;
				setBgProcessing( false );
				attemptsRef.current = 0;
				callbacksRef.current.notify?.( {
					type: 'success',
					message: __(
						'Image optimisation completed.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
				return;
			}
			if ( pollingRef.current ) {
				scheduleNextTick();
			}
		} catch ( error ) {
			if ( error?.name === 'AbortError' ) {
				return;
			}
			if ( ! pollingRef.current ) {
				return;
			}
			console.error(
				'Error polling job status:',
				getErrorLogMessage( error )
			);
			retriesRef.current += 1;
			if ( retriesRef.current >= MAX_POLL_RETRIES ) {
				pollingRef.current = false;
				timerRef.current = null;
				setBgProcessing( false );
				callbacksRef.current.notify?.( {
					type: 'error',
					message: __(
						'Status check stopped after repeated failures.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
				return;
			}
			callbacksRef.current.notify?.( {
				type: 'error',
				message: __(
					'Status check failed. Retrying…',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
			if ( pollingRef.current ) {
				scheduleNextTick();
			}
		}
	}, [ run, scheduleNextTick ] );
	tickRef.current = pollTick;

	// Unmount: stop the timer chain and abort the in-flight tick/run so a
	// slow request can never setState/notify after the card is gone.
	useEffect( () => {
		return () => {
			pollingRef.current = false;
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
				timerRef.current = null;
			}
			cancel();
		};
	}, [ cancel ] );

	/**
	 * Start an optimisation run: `optimise_image` POST, then the poll
	 * chain for the background path or a direct commit for the sync path.
	 *
	 * @since NEXT
	 */
	const optimizeImages = useCallback( () => {
		if (
			optimizingRef.current ||
			pollingRef.current ||
			submittingRef.current
		) {
			return undefined;
		}
		submittingRef.current = true;
		optimizingRef.current = true;
		setOptimizing( true );
		return run( async ( { signal, isStale } ) => {
			const response = await apiCall(
				'optimise_image',
				{},
				'POST',
				signal
			);
			if ( isStale() || signal?.aborted ) {
				return { aborted: true };
			}
			return { response };
		} )
			.then( ( outcome ) => {
				if (
					! outcome ||
					outcome.aborted ||
					! mountedRef.current ||
					submittingRef.current === false
				) {
					return;
				}
				const { response } = outcome;
				if ( response.data?.background ) {
					// Background (Action Scheduler) path.
					setBgProcessing( true );
					const jobsQueued = Number( response.data.jobs_queued ?? 0 );
					setBgJobsQueued(
						Number.isFinite( jobsQueued ) ? jobsQueued : 0
					);
					callbacksRef.current.notify?.( {
						type: 'success',
						message: __(
							'Image optimisation started in background.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
					clearTimer();
					attemptsRef.current = 0;
					retriesRef.current = 0;
					pollingRef.current = true;
					timerRef.current = setTimeout( () => {
						if ( tickRef.current ) {
							tickRef.current();
						}
					}, POLL_INTERVAL_MS );
				} else {
					// Synchronous path (Action Scheduler unavailable).
					setBgJobsQueued( 0 );
					setBgProcessing( false );
					if ( response.success && response.data ) {
						const normalized = normalizeImageInfo( response.data );
						setImageInfo( normalized );
						callbacksRef.current.onStatus?.( normalized );
						callbacksRef.current.notify?.( {
							type: 'success',
							message: __(
								'Images optimized successfully.',
								'performance-optimisation'
							),
							durationMs: 5000,
						} );
					}
					clearTimer();
					pollingRef.current = false;
					timerRef.current = null;
				}
			} )
			.catch( ( optimizeError ) => {
				if (
					optimizeError?.name === 'AbortError' ||
					! mountedRef.current
				) {
					return;
				}
				console.error(
					'Image optimisation failed.',
					getErrorLogMessage( optimizeError )
				);
				callbacksRef.current.notify?.( {
					type: 'error',
					message: __(
						'Image optimisation failed.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} )
			.finally( () => {
				submittingRef.current = false;
				optimizingRef.current = false;
				if ( mountedRef.current ) {
					setOptimizing( false );
				}
			} );
	}, [ run, mountedRef, clearTimer ] );

	/**
	 * Delete optimised copies and reset card + stats-strip state.
	 *
	 * @since NEXT
	 */
	const removeImages = useCallback( () => {
		setRemoving( true );
		return run( async ( { signal, isStale } ) => {
			const data = await apiCall(
				'delete_optimised_image',
				{},
				'POST',
				signal
			);
			if ( isStale() || signal?.aborted ) {
				return { aborted: true };
			}
			return { data };
		} )
			.then( ( outcome ) => {
				if ( ! outcome || outcome.aborted ) {
					return;
				}
				if ( ! mountedRef.current ) {
					return;
				}
				const { data } = outcome;
				if ( data.success ) {
					const reset = {
						completed: { webp: 0, avif: 0 },
						pending: { webp: 0, avif: 0 },
						failed: { webp: 0, avif: 0 },
					};
					setImageInfo( reset );
					callbacksRef.current.onStatus?.( reset );
					callbacksRef.current.notify?.( {
						type: 'success',
						message: __(
							'Optimized images removed.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				} else {
					callbacksRef.current.notify?.( {
						type: 'error',
						message:
							data.message ||
							__(
								'Failed to remove optimized images.',
								'performance-optimisation'
							),
						durationMs: 5000,
					} );
				}
			} )
			.catch( ( removeError ) => {
				if (
					removeError?.name === 'AbortError' ||
					! mountedRef.current
				) {
					return;
				}
				console.error(
					'Dashboard request failed:',
					getErrorLogMessage( removeError )
				);
				callbacksRef.current.notify?.( {
					type: 'error',
					message: __(
						'Failed to remove optimized images.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} )
			.finally( () => {
				if ( mountedRef.current ) {
					setRemoving( false );
				}
			} );
	}, [ run, mountedRef ] );

	return {
		bgProcessing,
		bgJobsQueued,
		imgSavings,
		imageInfo,
		optimizing,
		removing,
		optimizeImages,
		removeImages,
		stop,
	};
};
