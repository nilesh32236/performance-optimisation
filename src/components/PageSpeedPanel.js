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
import { queuePagespeedScan, getPagespeedResults } from '../lib/apiRequest';
import FeatureCard from './common/FeatureCard';
import StatusBadge from './common/StatusBadge';

import { __ } from '@wordpress/i18n';

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
 * Score colour based on Lighthouse thresholds.
 *
 * @param {number} score 0–100
 * @return {string} CSS class suffix.
 */
const scoreStatus = ( score ) => {
	if ( score >= 90 ) {
		return 'good';
	}
	if ( score >= 50 ) {
		return 'needs_improvement';
	}
	return 'poor';
};

/**
 * A single Lighthouse category score gauge.
 *
 * @param {Object} props
 * @param {string} props.label Category label.
 * @param {number} props.score 0–100 integer.
 */
const ScoreGauge = ( { label, score } ) => {
	const status = scoreStatus( score );
	return (
		<div className={ `wppo-score-gauge wppo-score-gauge--${ status }` }>
			<div className="wppo-score-gauge__circle">
				<span className="wppo-score-gauge__value">{ score }</span>
			</div>
			<span className="wppo-score-gauge__label">{ label }</span>
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
	if ( score !== null ) {
		if ( score >= 0.9 ) {
			status = 'good';
		} else if ( score >= 0.5 ) {
			status = 'needs_improvement';
		} else {
			status = 'poor';
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
	const [ error, setError ] = useState( null );
	const [ strategy, setStrategy ] = useState( 'mobile' );
	const pollRef = useRef( null );
	const pollCountRef = useRef( 0 );
	const submittingRef = useRef( false );

	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
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
			pollRef.current = setInterval( async () => {
				pollCountRef.current += 1;

				if ( pollCountRef.current > MAX_POLL_ATTEMPTS ) {
					stopPolling();
					setPending( false );
					setScanning( false );
					setError(
						__(
							'PageSpeed scan timed out. Please try again.',
							'performance-optimisation'
						)
					);
					return;
				}

				try {
					const response = await getPagespeedResults(
						scanUrl,
						scanStrategy
					);

					// Any non-success response (including failure sentinel) stops polling.
					if ( ! response.success ) {
						stopPolling();
						setPending( false );
						setScanning( false );
						setError(
							response.message ||
								__(
									'PageSpeed scan failed. Please try again.',
									'performance-optimisation'
								)
						);
						return;
					}

					// HTTP 202 with status: 'not_ready' means still processing.
					if ( response.data?.status === 'not_ready' ) {
						return;
					}

					// Results are ready.
					if ( isMounted.current ) {
						stopPolling();
						setPending( false );
						setScanning( false );
						setResult( response.data );

						// Pass PageSpeed suggestions up to Dashboard → SuggestionsPanel.
						if (
							onSuggestionsReady &&
							response.data?.suggestions
						) {
							onSuggestionsReady( response.data.suggestions );
						}
					}
				} catch ( err ) {
					stopPolling();
					setPending( false );
					setScanning( false );
					setError(
						__(
							'PageSpeed scan failed.',
							'performance-optimisation'
						)
					);
					console.error( 'PageSpeed poll error:', err );
				}
			}, POLL_INTERVAL_MS );
		},
		[ stopPolling, onSuggestionsReady ]
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
		setError( null );

		try {
			const response = await queuePagespeedScan( url, strategy );

			if ( ! response.success ) {
				setScanning( false );
				submittingRef.current = false;
				setError(
					response.message ||
						__(
							'PageSpeed scan failed. Please try again.',
							'performance-optimisation'
						)
				);
				return;
			}

			// Job queued — start polling.
			setPending( true );
			submittingRef.current = false;
			pollForResults( url, strategy );
		} catch ( err ) {
			setScanning( false );
			submittingRef.current = false;
			setError(
				__( 'PageSpeed scan failed.', 'performance-optimisation' )
			);
			console.error( 'PageSpeed scan error:', err );
		}
	}, [ url, strategy, stopPolling, pollForResults, scanning, pending ] );

	const vitalsLabels = {
		fcp: __( 'First Contentful Paint', 'performance-optimisation' ),
		lcp: __( 'Largest Contentful Paint', 'performance-optimisation' ),
		tbt: __( 'Total Blocking Time', 'performance-optimisation' ),
		cls: __( 'Cumulative Layout Shift', 'performance-optimisation' ),
		speed_index: __( 'Speed Index', 'performance-optimisation' ),
		tti: __( 'Time to Interactive', 'performance-optimisation' ),
	};

	const categoryLabels = {
		performance: 'Performance',
		accessibility: 'Accessibility',
		best_practices: 'Best Practices',
		seo: 'SEO',
	};

	return (
		<FeatureCard
			title={ __( 'PageSpeed Insights', 'performance-optimisation' ) }
		>
			{ ! apiKeyConfigured && (
				<div className="wppo-notice wppo-notice--warning">
					<FontAwesomeIcon
						icon={ faExclamationCircle }
						style={ { marginRight: '8px' } }
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
								style={ { marginRight: '8px' } }
							/>
							{ __( 'Scanning…', 'performance-optimisation' ) }
						</>
					) : (
						<>
							<FontAwesomeIcon
								icon={ faTachometerAlt }
								style={ { marginRight: '8px' } }
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
					role="alert"
					aria-live="polite"
				>
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						style={ { marginRight: '8px' } }
					/>
					{ __(
						'PageSpeed scan is running in the background. Results will appear shortly.',
						'performance-optimisation'
					) }
				</div>
			) }

			{ /* Error notice */ }
			{ error && (
				<div
					className="wppo-notice wppo-notice--error"
					role="alert"
					aria-live="assertive"
				>
					{ error }
				</div>
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
								<th>
									{ __(
										'Metric',
										'performance-optimisation'
									) }
								</th>
								<th>
									{ __(
										'Value',
										'performance-optimisation'
									) }
								</th>
								<th>
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
							style={ {
								marginRight: '6px',
								color: 'var(--wppo-color-success)',
							} }
						/>
						{ 'strategy:desktop' === `strategy:${ strategy }`
							? __( 'Desktop', 'performance-optimisation' )
							: __( 'Mobile', 'performance-optimisation' ) }
						{ ' · ' }
						{ result.fetched_at }
					</p>
				</div>
			) }
		</FeatureCard>
	);
};

export default PageSpeedPanel;
