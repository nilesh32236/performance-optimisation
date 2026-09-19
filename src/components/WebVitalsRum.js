/**
 * WebVitalsRum component.
 *
 * Renders aggregated real-user Core Web Vitals (LCP/INP/CLS/FCP/TTFB) collected
 * from real visitors, grouped by day. Fetches GET /rum_data on mount.
 *
 * @since 2.18.0
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faUsers, faSpinner } from '@fortawesome/free-solid-svg-icons';
import { apiCall, getErrorLogMessage } from '../lib/apiRequest';
import { formatMs } from '../lib/format';
import useNotice from '../lib/useNotice';
import FeatureCard from './common/FeatureCard';
import NoticeBanner from './common/NoticeBanner';

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
	const { notice, notify, dismiss } = useNotice();
	const retryControllerRef = useRef( null );

	const load = useCallback(
		async ( signal ) => {
			setLoading( true );
			dismiss();
			try {
				const response = await apiCall( 'rum_data', {}, 'GET', signal );
				if ( signal?.aborted ) {
					return;
				}
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
					notify( {
						type: 'error',
						message:
							response.message ||
							__(
								'Failed to load real-user data.',
								'performance-optimisation'
							),
					} );
				}
			} catch ( loadError ) {
				if ( signal?.aborted || loadError?.name === 'AbortError' ) {
					return;
				}
				notify( {
					type: 'error',
					message: __(
						'Failed to load real-user data.',
						'performance-optimisation'
					),
				} );
				console.error(
					'Error fetching RUM data:',
					getErrorLogMessage( loadError )
				);
			} finally {
				if ( ! signal?.aborted ) {
					setLoading( false );
				}
			}
		},
		[ dismiss, notify ]
	);

	useEffect( () => {
		const controller = new AbortController();
		load( controller.signal );
		return () => {
			controller.abort();
			retryControllerRef.current?.abort();
		};
	}, [ load ] );

	const fmtMs = ( value ) =>
		value === null || value === undefined ? '—' : formatMs( value );
	// Audit #1354: coerce — a string CLS value from the API would
	// otherwise throw TypeError and break the panel.
	const fmtCls = ( value ) => {
		if ( value === null || value === undefined ) {
			return '—';
		}
		const num = Number( value );
		return Number.isFinite( num ) ? num.toFixed( 3 ) : '—';
	};

	const rumTable =
		data.length > 0 ? (
			<table className="wppo-rum-table wppo-table">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Day', 'performance-optimisation' ) }
						</th>
						<th scope="col">
							{ __( 'LCP', 'performance-optimisation' ) }
						</th>
						<th scope="col">
							{ __( 'INP', 'performance-optimisation' ) }
						</th>
						<th scope="col">
							{ __( 'CLS', 'performance-optimisation' ) }
						</th>
						<th scope="col">
							{ __( 'FCP', 'performance-optimisation' ) }
						</th>
						<th scope="col">
							{ __( 'TTFB', 'performance-optimisation' ) }
						</th>
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
		) : null;

	let body;
	if ( notice ) {
		body = (
			<>
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
				<button
					type="button"
					className="wppo-button wppo-button--secondary wppo-button--sm wppo-mt-10"
					onClick={ () => {
						retryControllerRef.current?.abort();
						const controller = new AbortController();
						retryControllerRef.current = controller;
						load( controller.signal ).finally( () => {
							if ( retryControllerRef.current === controller ) {
								retryControllerRef.current = null;
							}
						} );
					} }
					disabled={ loading }
				>
					{ __( 'Retry', 'performance-optimisation' ) }
				</button>
				{ rumTable }
			</>
		);
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
		body = rumTable;
	}

	return (
		<FeatureCard
			title={ __( 'Real-user Web Vitals', 'performance-optimisation' ) }
			icon={ <FontAwesomeIcon icon={ faUsers } /> }
			actions={
				loading && (
					<span role="status" aria-live="polite">
						<FontAwesomeIcon
							icon={ faSpinner }
							spin
							aria-hidden="true"
						/>
						{ __( 'Loading…', 'performance-optimisation' ) }
					</span>
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
						/* translators: %d: number of sample days retained. */
						_n(
							'Showing up to %d day.',
							'Showing up to %d days.',
							14,
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
