/**
 * WebVitalsTrends component.
 *
 * Renders historical PageSpeed performance scores as an inline SVG line chart
 * with no external chart library. Fetches trend data from
 * GET /web_vitals_trends on mount, scoped to the current audit URL.
 *
 * @since 2.14.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faChartLine,
	faSpinner,
	faExclamationCircle,
} from '@fortawesome/free-solid-svg-icons';
import { fetchWebVitalsTrends } from '../lib/apiRequest';
import FeatureCard from './common/FeatureCard';

const SPARK_WIDTH = 640;
const SPARK_HEIGHT = 160;
const PAD_X = 10;
const PAD_Y = 26;

/**
 * Builds an SVG polyline points string from a numeric series.
 *
 * @param {Array<number>} values Series of numeric values (0–100).
 * @return {string} Points string for an SVG polyline.
 */
const buildPoints = ( values ) => {
	if ( ! values || values.length < 1 ) {
		return '';
	}
	const max = Math.max( ...values, 100 );
	const min = Math.min( ...values, 0 );
	const range = max - min || 1;
	const stepX =
		( SPARK_WIDTH - PAD_X * 2 ) / Math.max( values.length - 1, 1 );
	return values
		.map( ( value, index ) => {
			const x = PAD_X + index * stepX;
			const y =
				SPARK_HEIGHT -
				PAD_Y -
				( ( value - min ) / range ) * ( SPARK_HEIGHT - PAD_Y * 2 );
			return `${ x },${ y.toFixed( 1 ) }`;
		} )
		.join( ' ' );
};

/**
 * Renders one strategy series (mobile or desktop).
 *
 * @param {Object} props
 * @param {string} props.strategy 'mobile' or 'desktop'.
 * @param {Object} props.trends   Trends object from the API.
 */
const TrendSeries = ( { strategy, trends } ) => {
	const seriesKey = Object.keys( trends ).find( ( key ) =>
		key.endsWith( `_${ strategy }` )
	);

	if ( ! seriesKey ) {
		return (
			<p className="wppo-text-muted wppo-text-small wppo-mt-8">
				{ __(
					'Not enough trend data yet. Run PageSpeed scans over time or enable auto-rescan.',
					'performance-optimisation'
				) }
			</p>
		);
	}

	const snapshots = trends[ seriesKey ] ?? [];
	const values = snapshots.map( ( snap ) => snap.performance );
	const last =
		snapshots.length > 0
			? snapshots[ snapshots.length - 1 ].performance
			: null;
	const points = buildPoints( values );
	const limited = snapshots.length > 1;

	return (
		<div className="wppo-trend-series">
			<div className="wppo-trend-series__header">
				<span className="wppo-trend-series__label">
					{ strategy === 'mobile'
						? __( 'Mobile', 'performance-optimisation' )
						: __( 'Desktop', 'performance-optimisation' ) }
				</span>
				{ last !== null && (
					<span className="wppo-trend-series__latest">
						{ __( 'Latest score', 'performance-optimisation' ) }
						{ ': ' }
						<strong>{ last }</strong>
					</span>
				) }
			</div>
			{ limited ? (
				<svg
					className="wppo-trend-chart"
					viewBox={ `0 0 ${ SPARK_WIDTH } ${ SPARK_HEIGHT }` }
					role="img"
					aria-label={ sprintf(
						/* translators: %s: strategy (mobile or desktop) */
						__(
							'%s performance score trend chart',
							'performance-optimisation'
						),
						strategy
					) }
				>
					<polyline
						className="wppo-trend-chart__line"
						points={ points }
						strokeWidth="2"
						fill="none"
					/>
				</svg>
			) : (
				<p className="wppo-text-muted wppo-text-small wppo-mt-8">
					{ __(
						'Not enough trend data yet. Run a few PageSpeed scans over time.',
						'performance-optimisation'
					) }
				</p>
			) }
		</div>
	);
};

const WebVitalsTrends = ( { url = '' } ) => {
	const [ trends, setTrends ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const loadTrends = useCallback( async () => {
		if ( ! url ) {
			// Without a known URL the request would return every history and
			// TrendSeries would mislabel them; show an explicit empty state.
			setTrends( null );
			setError( null );
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const response = await fetchWebVitalsTrends( url, '' );
			if ( response.success ) {
				setTrends( response.data?.trends ?? {} );
			} else {
				setError(
					response.message ||
						__(
							'Failed to load trend data.',
							'performance-optimisation'
						)
				);
			}
		} catch ( err ) {
			setError(
				__( 'Failed to load trend data.', 'performance-optimisation' )
			);
			console.error( 'Web Vitals trends load error:', err );
		} finally {
			setLoading( false );
		}
	}, [ url ] );

	useEffect( () => {
		loadTrends();
	}, [ loadTrends ] );

	return (
		<FeatureCard
			title={ __( 'Web Vitals Trends', 'performance-optimisation' ) }
		>
			{ loading && (
				<p className="wppo-text-muted" role="status" aria-live="polite">
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						className="wppo-mr-8"
					/>
					{ __( 'Loading trends…', 'performance-optimisation' ) }
				</p>
			) }

			{ ! loading && error && (
				<div className="wppo-notice wppo-notice--error">
					<FontAwesomeIcon
						icon={ faExclamationCircle }
						className="wppo-mr-8"
					/>
					{ error }
				</div>
			) }

			{ ! loading && ! error && ! url && (
				<p className="wppo-text-muted">
					{ __(
						'Enter a URL to view Web Vitals trend history.',
						'performance-optimisation'
					) }
				</p>
			) }

			{ ! loading && ! error && url && (
				<div className="wppo-trend-layout">
					<div className="wppo-trend-layout__title">
						<FontAwesomeIcon
							icon={ faChartLine }
							className="wppo-mr-8"
						/>
						{ url }
					</div>
					<TrendSeries strategy="mobile" trends={ trends ?? {} } />
					<TrendSeries strategy="desktop" trends={ trends ?? {} } />
				</div>
			) }
		</FeatureCard>
	);
};

export default WebVitalsTrends;
