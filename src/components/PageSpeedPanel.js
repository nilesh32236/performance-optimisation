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

import { useState, useRef, useCallback } from '@wordpress/element';
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

const t =
	typeof wppoSettings !== 'undefined' && wppoSettings.translations
		? wppoSettings.translations
		: {};

const apiKeyConfigured =
	typeof wppoSettings !== 'undefined'
		? wppoSettings.performance_audit?.pagespeedApiKeyConfigured ?? false
		: false;

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

	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
		pollCountRef.current = 0;
	}, [] );

	const pollForResults = useCallback(
		( scanUrl, scanStrategy ) => {
			pollRef.current = setInterval( async () => {
				pollCountRef.current += 1;

				if ( pollCountRef.current > MAX_POLL_ATTEMPTS ) {
					stopPolling();
					setPending( false );
					setScanning( false );
					setError(
						t.pagespeedError ||
							'PageSpeed scan timed out. Please try again.'
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
						setError( response.message || t.pagespeedError );
						return;
					}

					// HTTP 202 with status: 'not_ready' means still processing.
					if ( response.data?.status === 'not_ready' ) {
						return;
					}

					// Results are ready.
					stopPolling();
					setPending( false );
					setScanning( false );
					setResult( response.data );

					// Pass PageSpeed suggestions up to Dashboard → SuggestionsPanel.
					if ( onSuggestionsReady && response.data?.suggestions ) {
						onSuggestionsReady( response.data.suggestions );
					}
				} catch ( err ) {
					stopPolling();
					setPending( false );
					setScanning( false );
					setError( t.pagespeedError || 'PageSpeed scan failed.' );
					console.error( 'PageSpeed poll error:', err );
				}
			}, POLL_INTERVAL_MS );
		},
		[ stopPolling, onSuggestionsReady ]
	);

	const handleScan = useCallback( async () => {
		if ( ! url ) {
			return;
		}

		stopPolling();
		setScanning( true );
		setPending( false );
		setResult( null );
		setError( null );

		try {
			const response = await queuePagespeedScan( url, strategy );

			if ( ! response.success ) {
				setScanning( false );
				setError( response.message || t.pagespeedError );
				return;
			}

			// Job queued — start polling.
			setPending( true );
			pollForResults( url, strategy );
		} catch ( err ) {
			setScanning( false );
			setError( t.pagespeedError || 'PageSpeed scan failed.' );
			console.error( 'PageSpeed scan error:', err );
		}
	}, [ url, strategy, stopPolling, pollForResults ] );

	const vitalsLabels = {
		fcp: t.fcp || 'First Contentful Paint',
		lcp: t.lcp || 'Largest Contentful Paint',
		tbt: t.tbt || 'Total Blocking Time',
		cls: t.cls || 'Cumulative Layout Shift',
		speed_index: t.speedIndex || 'Speed Index',
		tti: t.tti || 'Time to Interactive',
	};

	const categoryLabels = {
		performance: 'Performance',
		accessibility: 'Accessibility',
		best_practices: 'Best Practices',
		seo: 'SEO',
	};

	return (
		<FeatureCard title={ t.pagespeedResults || 'PageSpeed Insights' }>
			{ ! apiKeyConfigured && (
				<div className="wppo-notice wppo-notice--warning">
					<FontAwesomeIcon
						icon={ faExclamationCircle }
						style={ { marginRight: '8px' } }
					/>
					{ t.pagespeedApiKeyMissing ||
						'PageSpeed API key is not configured. Add it in Settings.' }
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
						{ t.pagespeedMobile || 'Mobile' }
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
						{ t.pagespeedDesktop || 'Desktop' }
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
							{ t.pagespeedScanning || 'Scanning...' }
						</>
					) : (
						<>
							<FontAwesomeIcon
								icon={ faTachometerAlt }
								style={ { marginRight: '8px' } }
							/>
							{ t.pagespeedScan || 'Run PageSpeed Scan' }
						</>
					) }
				</button>
			</div>

			{ /* Pending notice */ }
			{ pending && (
				<div className="wppo-notice wppo-notice--info">
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						style={ { marginRight: '8px' } }
					/>
					{ t.pagespeedPending ||
						'PageSpeed scan is running in the background. Results will appear shortly.' }
				</div>
			) }

			{ /* Error notice */ }
			{ error && (
				<div className="wppo-notice wppo-notice--error">{ error }</div>
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
								<th>{ t.metric || 'Metric' }</th>
								<th>{ t.value || 'Value' }</th>
								<th>{ t.status || 'Status' }</th>
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
						{ t.pagespeedDesktop === strategy
							? t.pagespeedDesktop
							: t.pagespeedMobile }
						{ ' · ' }
						{ result.fetched_at }
					</p>
				</div>
			) }
		</FeatureCard>
	);
};

export default PageSpeedPanel;
