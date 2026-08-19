/**
 * WebVitalsRum component.
 *
 * Renders aggregated real-user Core Web Vitals (LCP/INP/CLS/FCP/TTFB) collected
 * from real visitors, grouped by day. Fetches GET /rum_data on mount.
 *
 * @since 2.18.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faUsers, faSpinner } from '@fortawesome/free-solid-svg-icons';
import { apiCall } from '../lib/apiRequest';
import FeatureCard from './common/FeatureCard';

/**
 * Aggregate all paths for a day into site-wide metric averages.
 *
 * @param {Object} day Aggregates keyed by path.
 * @return {Object} Per-metric { n, avg }.
 */
const dayAverages = ( day ) => {
	const totals = {};
	for ( const path of Object.values( day ) ) {
		for ( const [ metric, bucket ] of Object.entries( path ) ) {
			if ( ! totals[ metric ] ) {
				totals[ metric ] = { n: 0, sum: 0 };
			}
			totals[ metric ].n += bucket.n;
			totals[ metric ].sum += bucket.sum;
		}
	}
	const averages = {};
	for ( const [ metric, total ] of Object.entries( totals ) ) {
		averages[ metric ] = total.n ? total.sum / total.n : null;
	}
	return averages;
};

/**
 * @return {Element} The RUM panel.
 */
const WebVitalsRum = () => {
	const [ data, setData ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const response = await apiCall( 'rum_data', {}, 'GET' );
			if ( response.success && response.data ) {
				const rows = Object.entries( response.data )
					.sort( ( [ a ], [ b ] ) => a.localeCompare( b ) )
					.map( ( [ day, paths ] ) => ( {
						day,
						...dayAverages( paths ),
					} ) )
					.slice( -14 );
				setData( rows );
			} else {
				setError(
					response.message ||
						__(
							'Failed to load real-user data.',
							'performance-optimisation'
						)
				);
			}
		} catch ( loadError ) {
			setError(
				__(
					'Failed to load real-user data.',
					'performance-optimisation'
				)
			);
			console.error( 'Error fetching RUM data:', loadError );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const fmtMs = ( value ) =>
		value === null || value === undefined
			? '—'
			: `${ Math.round( value ) } ms`;
	const fmtCls = ( value ) =>
		value === null || value === undefined ? '—' : value.toFixed( 3 );

	let body;
	if ( error ) {
		body = <p className="wppo-text-muted">{ error }</p>;
	} else if ( data.length === 0 && ! loading ) {
		body = (
			<p className="wppo-text-muted">
				{ __(
					'No real-user data yet. Enable "Collect Real-user Web Vitals" in Tools and wait for visitors.',
					'performance-optimisation'
				) }
			</p>
		);
	} else {
		body = (
			<table className="wppo-rum-table wppo-table">
				<thead>
					<tr>
						<th>{ __( 'Day', 'performance-optimisation' ) }</th>
						<th>{ __( 'LCP', 'performance-optimisation' ) }</th>
						<th>{ __( 'INP', 'performance-optimisation' ) }</th>
						<th>{ __( 'CLS', 'performance-optimisation' ) }</th>
						<th>{ __( 'FCP', 'performance-optimisation' ) }</th>
						<th>{ __( 'TTFB', 'performance-optimisation' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data.map( ( row ) => (
						<tr key={ row.day }>
							<td>{ row.day }</td>
							<td>{ fmtMs( row.lcp ) }</td>
							<td>{ fmtMs( row.inp ) }</td>
							<td>{ fmtCls( row.cls ) }</td>
							<td>{ fmtMs( row.fcp ) }</td>
							<td>{ fmtMs( row.ttfb ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		);
	}

	return (
		<FeatureCard
			title={ __( 'Real-user Web Vitals', 'performance-optimisation' ) }
			icon={ <FontAwesomeIcon icon={ faUsers } /> }
			actions={
				loading && (
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						aria-label={ __(
							'Loading…',
							'performance-optimisation'
						) }
					/>
				)
			}
		>
			<p className="wppo-text-muted">
				{ __(
					'Aggregated Core Web Vitals from real visitors, per day (site-wide).',
					'performance-optimisation'
				) }
			</p>
			{ body }
			{ data.length > 0 && (
				<p className="wppo-text-muted wppo-text-small">
					{ sprintf(
						/* translators: %d: number of sample days retained */
						__(
							'Showing up to %d days.',
							'performance-optimisation'
						),
						14
					) }
				</p>
			) }
		</FeatureCard>
	);
};

export default WebVitalsRum;
