/**
 * PageSpeedPanel component.
 *
 * Provides a "Run PageSpeed Scan" button that queues a background
 * Google PageSpeed Insights scan via POST /pagespeed_scan, then polls
 * GET /pagespeed_results until the result is ready.
 *
 * Renders Lighthouse category scores, Core Web Vitals, and passes
 * the PageSpeed suggestions up to the parent via onSuggestionsReady.
 *
 * Disabled when pagespeedApiKeyConfigured is false.
 *
 * @since 1.6.0
 */

import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faTachometerAlt,
	faSpinner,
	faCheckCircle,
	faExclamationCircle,
	faMobileAlt,
	faDesktop,
} from '@fortawesome/free-solid-svg-icons';
import {
	queuePagespeedScan,
	getPagespeedResults,
	getErrorLogMessage,
} from '../lib/apiRequest';
import { scoreToStatus } from '../lib/status';
import useNotice from '../lib/useNotice';
import FeatureCard from './common/FeatureCard';
import StatusBadge from './common/StatusBadge';
import NoticeBanner from './common/NoticeBanner';

import { __, sprintf } from '@wordpress/i18n';

// apiKeyConfigured is now derived inside the component for reactivity.

/**
 * Polling interval in milliseconds.
 * PageSpeed API typically takes 15–60 seconds.
 */
const POLL_INTERVAL_MS = 5000;

/**
 * Maximum number of poll attempts before giving up (~5 minutes).
 */
const MAX_POLL_ATTEMPTS = 60;

/**
 * Maximum delay between PageSpeed poll ticks.
 *
 * Polling backs off (5s for the first 10 attempts, then +5s per 10
 * attempts, capped at 15s) so slow PageSpeed jobs do not hit the REST
 * endpoint at the same rate as near-complete ones.
 *
 * @since NEXT
 */
const MAX_POLL_DELAY_MS = 15000;

/**
 * Delay before the next poll tick, backing off with the attempt count.
 *
 * @since NEXT
 * @param {number} attempts 1-based poll attempt count.
 * @return {number} Milliseconds to wait before the next tick.
 */
const getPollDelay = ( attempts ) =>
	Math.min(
		POLL_INTERVAL_MS * Math.max( 1, Math.ceil( attempts / 10 ) ),
		MAX_POLL_DELAY_MS
	);

/**
 * Score colour based on Lighthouse thresholds.
 *
 * Single-sourced via scoreToStatus() in lib/status.js ('warning' maps to the
 * legacy 'needs_improvement' CSS class suffix used by the gauge styles).
 *
 * @param {number} score 0–100
 * @return {string} CSS class suffix.
 */
const scoreStatus = ( score ) => {
	const status = scoreToStatus( score );
	return 'warning' === status ? 'needs_improvement' : status;
};

/**
 * A single Lighthouse category score gauge.
 *
 * @param {Object} props
 * @param {string} props.label Category label.
 * @param {number} props.score 0–100 integer.
 */
/**
 * Format a fetched_at timestamp for the current locale (audit #1420).
 *
 * Falls back to the raw value when parsing fails.
 *
 * @since NEXT
 * @param {string} raw Raw timestamp.
 * @return {string} Localized date/time or the raw value.
 */
const formatFetchedAt = ( raw ) => {
	if ( ! raw ) {
		return '';
	}
	try {
		const date = new Date( raw );
		if ( Number.isNaN( date.getTime() ) ) {
			return raw;
		}
		return date.toLocaleString();
	} catch {
		return raw;
	}
};

const ScoreGauge = ( { label, score } ) => {
	const status = scoreStatus( score );
	// Audit #1354: expose metric context to screen readers.
	return (
		<div
			className={ `wppo-score-gauge wppo-score-gauge--${ status }` }
			role="img"
			aria-label={ sprintf(
				/* translators: 1: gauge label, 2: score value. */
				__( '%1$s: %2$s', 'performance-optimisation' ),
				label,
				score
			) }
		>
			<div className="wppo-score-gauge__circle" aria-hidden="true">
				<span className="wppo-score-gauge__value">{ score }</span>
			</div>
			<span className="wppo-score-gauge__label" aria-hidden="true">
				{ label }
			</span>
		</div>
	);
};

/**
 * A single Core Web Vital row.
 *
 * @param {Object} props
 * @param {string} props.label        Metric label.
 * @param {string} props.displayValue Formatted value from Lighthouse.
 * @param {number} props.score        0.0–1.0 Lighthouse score.
 */
const VitalRow = ( { label, displayValue, score } ) => {
	let status = null;
	if ( score !== null && score !== undefined ) {
		const mapped = scoreToStatus( Number( score ) * 100 );
		if ( 'warning' === mapped ) {
			status = 'needs_improvement';
		} else if ( 'unknown' !== mapped ) {
			status = mapped;
		}
	}

	return (
		<tr className="wppo-vitals-table__row">
			<td className="wppo-vitals-table__label">{ label }</td>
			<td className="wppo-vitals-table__value">
				{ displayValue ?? '—' }
			</td>
			<td className="wppo-vitals-table__status">
				{ status && <StatusBadge status={ status } /> }
			</td>
		</tr>
	);
};

const PageSpeedPanel = ( { url, onSuggestionsReady } ) => {
	const [ scanning, setScanning ] = useState( false );
	const [ pending, setPending ] = useState( false );
	const [ result, setResult ] = useState( null );
	const { notice, notify, dismiss } = useNotice();
	const [ strategy, setStrategy ] = useState( 'mobile' );
	const pollRef = useRef( null );
	const pollCountRef = useRef( 0 );
	const pollSignalRef = useRef( null );
	const queueSignalRef = useRef( null );
	const submittingRef = useRef( false );

	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearTimeout( pollRef.current );
			pollRef.current = null;
		}
		if ( pollSignalRef.current ) {
			pollSignalRef.current.abort();
			pollSignalRef.current = null;
		}
		if ( queueSignalRef.current ) {
			queueSignalRef.current.abort();
			queueSignalRef.current = null;
		}
		pollCountRef.current = 0;
	}, [] );

	const isMounted = useRef( true );

	// Component lifecycle and polling cleanup.
	useEffect( () => {
		isMounted.current = true;
		return () => {
			isMounted.current = false;
			stopPolling();
		};
	}, [ stopPolling ] );

	const apiKeyConfigured =
		typeof wppoSettings !== 'undefined'
			? wppoSettings.performance_audit?.pagespeedApiKeyConfigured ?? false
			: false;

	const pollForResults = useCallback(
		( scanUrl, scanStrategy ) => {
			const poll = async () => {
				pollCountRef.current += 1;

				if ( pollCountRef.current >= MAX_POLL_ATTEMPTS ) {
					stopPolling();
					if ( isMounted.current ) {
						setPending( false );
						setScanning( false );
						notify( {
							type: 'error',
							message: __(
								'PageSpeed scan timed out. Please try again.',
								'performance-optimisation'
							),
						} );
					}
					return;
				}

				let signal = null;
				try {
					// Polls are strictly sequential: the next tick is only
					// scheduled after the previous await settles, so the
					// previous controller (if any) is already settled and
					// needs no abort here. A fresh controller per tick keeps
					// stopPolling()/unmount able to cancel the in-flight poll.
					pollSignalRef.current = new AbortController();
					signal = pollSignalRef.current.signal;
					const response = await getPagespeedResults(
						scanUrl,
						scanStrategy,
						signal
					);
					if ( signal.aborted ) {
						pollSignalRef.current = null;
						return;
					}

					if ( ! response.success ) {
						stopPolling();
						if ( isMounted.current ) {
							setPending( false );
							setScanning( false );
							notify( {
								type: 'error',
								message:
									response.message ||
									__(
										'PageSpeed scan failed. Please try again.',
										'performance-optimisation'
									),
							} );
						}
						return;
					}

					if ( response.data?.status === 'not_ready' ) {
						if ( isMounted.current ) {
							// Audit #1354 review: defer the next tick while
							// the tab is hidden instead of polling PHP/DB.
							const delay = getPollDelay( pollCountRef.current );
							pollRef.current = setTimeout(
								poll,
								typeof document !== 'undefined' &&
									document.hidden
									? Math.max( delay, 30000 )
									: delay
							);
						}
						return;
					}

					if ( isMounted.current ) {
						stopPolling();
						setPending( false );
						setScanning( false );
						setResult( response.data );

						if (
							onSuggestionsReady &&
							response.data?.suggestions
						) {
							onSuggestionsReady( response.data.suggestions );
						}
					}
				} catch ( err ) {
					if ( err?.name === 'AbortError' ) {
						pollSignalRef.current = null;
						return;
					}
					if ( signal && signal.aborted ) {
						return;
					}
					stopPolling();
					if ( isMounted.current ) {
						setPending( false );
						setScanning( false );
						notify( {
							type: 'error',
							message: __(
								'PageSpeed scan failed.',
								'performance-optimisation'
							),
						} );
					}
					console.error(
						'PageSpeed poll error:',
						getErrorLogMessage( err )
					);
				}
			};
			// First tick fires immediately so results that are already ready
			// do not wait a full interval; later ticks back off via getPollDelay().
			pollRef.current = setTimeout( poll, 0 );
		},
		[ stopPolling, onSuggestionsReady, notify ]
	);

	const handleScan = useCallback( async () => {
		if ( ! url || scanning || pending || submittingRef.current ) {
			return;
		}
		submittingRef.current = true;

		stopPolling();
		setScanning( true );
		setPending( false );
		setResult( null );
		dismiss();

		try {
			queueSignalRef.current = new AbortController();
			const response = await queuePagespeedScan(
				url,
				strategy,
				queueSignalRef.current.signal
			);
			queueSignalRef.current = null;

			if ( ! isMounted.current ) {
				submittingRef.current = false;
				return;
			}
			if ( ! response.success ) {
				setScanning( false );
				submittingRef.current = false;
				notify( {
					type: 'error',
					message:
						response.message ||
						__(
							'PageSpeed scan failed. Please try again.',
							'performance-optimisation'
						),
				} );
				return;
			}

			// Job queued — start polling.
			setPending( true );
			submittingRef.current = false;
			pollForResults( url, strategy );
		} catch ( err ) {
			submittingRef.current = false;
			if ( ! isMounted.current ) {
				return;
			}
			if ( err?.name === 'AbortError' ) {
				setScanning( false );
				return;
			}
			setScanning( false );
			notify( {
				type: 'error',
				message: __(
					'PageSpeed scan failed.',
					'performance-optimisation'
				),
			} );
			console.error( 'PageSpeed scan error:', getErrorLogMessage( err ) );
		}
	}, [
		url,
		strategy,
		stopPolling,
		pollForResults,
		scanning,
		pending,
		notify,
		dismiss,
	] );

	const vitalsLabels = {
		fcp: __( 'First Contentful Paint', 'performance-optimisation' ),
		lcp: __( 'Largest Contentful Paint', 'performance-optimisation' ),
		tbt: __( 'Total Blocking Time', 'performance-optimisation' ),
		cls: __( 'Cumulative Layout Shift', 'performance-optimisation' ),
		speed_index: __( 'Speed Index', 'performance-optimisation' ),
		tti: __( 'Time to Interactive', 'performance-optimisation' ),
	};

	const categoryLabels = {
		performance: __( 'Performance', 'performance-optimisation' ),
		accessibility: __( 'Accessibility', 'performance-optimisation' ),
		best_practices: __( 'Best Practices', 'performance-optimisation' ),
		seo: __( 'SEO', 'performance-optimisation' ),
	};

	return (
		<FeatureCard
			title={ __( 'PageSpeed Insights', 'performance-optimisation' ) }
		>
			{ ! apiKeyConfigured && (
				<div className="wppo-notice wppo-notice--warning">
					<FontAwesomeIcon
						icon={ faExclamationCircle }
						className="wppo-mr-8"
					/>
					{ __(
						'PageSpeed API key is not configured. Add it in Settings.',
						'performance-optimisation'
					) }
				</div>
			) }

			{ /* Strategy selector + scan button */ }
			<div className="wppo-pagespeed-controls">
				<div className="wppo-pagespeed-strategy">
					<button
						type="button"
						className={ `wppo-strategy-btn ${
							strategy === 'mobile'
								? 'wppo-strategy-btn--active'
								: ''
						}` }
						onClick={ () => setStrategy( 'mobile' ) }
						disabled={ scanning || pending }
						aria-pressed={ strategy === 'mobile' }
					>
						<FontAwesomeIcon icon={ faMobileAlt } />
						{ __( 'Mobile', 'performance-optimisation' ) }
					</button>
					<button
						type="button"
						className={ `wppo-strategy-btn ${
							strategy === 'desktop'
								? 'wppo-strategy-btn--active'
								: ''
						}` }
						onClick={ () => setStrategy( 'desktop' ) }
						disabled={ scanning || pending }
						aria-pressed={ strategy === 'desktop' }
					>
						<FontAwesomeIcon icon={ faDesktop } />
						{ __( 'Desktop', 'performance-optimisation' ) }
					</button>
				</div>

				<button
					type="button"
					className="wppo-button wppo-button--primary"
					onClick={ handleScan }
					disabled={ ! apiKeyConfigured || scanning || pending }
				>
					{ scanning || pending ? (
						<>
							<FontAwesomeIcon
								icon={ faSpinner }
								spin
								aria-hidden="true"
								className="wppo-mr-8"
							/>
							{ __( 'Scanning…', 'performance-optimisation' ) }
						</>
					) : (
						<>
							<FontAwesomeIcon
								icon={ faTachometerAlt }
								aria-hidden="true"
								className="wppo-mr-8"
							/>
							{ __(
								'Run PageSpeed Scan',
								'performance-optimisation'
							) }
						</>
					) }
				</button>
			</div>

			{ /* Pending notice */ }
			{ pending && (
				<div
					className="wppo-notice wppo-notice--info"
					role="status" // Audit #1420: in-progress notices are status, not alert.
					aria-live="polite"
				>
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						aria-hidden="true"
						className="wppo-mr-8"
					/>
					{ __(
						'PageSpeed scan is running in the background. Results will appear shortly.',
						'performance-optimisation'
					) }
				</div>
			) }

			{ /* Error notice */ }
			{ notice && (
				<NoticeBanner type={ notice.type } message={ notice.message } />
			) }

			{ /* Results */ }
			{ result && (
				<div className="wppo-pagespeed-results">
					{ /* Category score gauges */ }
					<div className="wppo-score-gauges">
						{ Object.entries( result.scores ?? {} ).map(
							( [ key, score ] ) => (
								<ScoreGauge
									key={ key }
									label={ categoryLabels[ key ] ?? key }
									score={ score }
								/>
							)
						) }
					</div>

					{ /* Core Web Vitals table */ }
					<table className="wppo-vitals-table">
						<thead>
							<tr>
								<th scope="col">
									{ __(
										'Metric',
										'performance-optimisation'
									) }
								</th>
								<th scope="col">
									{ __(
										'Value',
										'performance-optimisation'
									) }
								</th>
								<th scope="col">
									{ __(
										'Status',
										'performance-optimisation'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ Object.entries( result.vitals ?? {} ).map(
								( [ key, vital ] ) => (
									<VitalRow
										key={ key }
										label={ vitalsLabels[ key ] ?? key }
										displayValue={ vital.display_value }
										score={ vital.score }
									/>
								)
							) }
						</tbody>
					</table>

					<p className="wppo-pagespeed-meta">
						<FontAwesomeIcon
							icon={ faCheckCircle }
							className="wppo-pagespeed-meta__icon"
						/>
						{ ( result.strategy ?? strategy ).toLowerCase() ===
						'desktop'
							? __( 'Desktop', 'performance-optimisation' )
							: __( 'Mobile', 'performance-optimisation' ) }
						{ ' · ' }
						{ /* Audit #1420: locale-aware date. */ }
						{ formatFetchedAt( result.fetched_at ) }
					</p>
				</div>
			) }
		</FeatureCard>
	);
};

export default PageSpeedPanel;
