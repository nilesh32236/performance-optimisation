/**
 * PerformanceHistory component — Phase 3 High-Value Page Tracker.
 *
 * Fetches telemetry history rows, renders a URL/metric selector,
 * an SVG sparkline trend, a data table, and URL management UI.
 *
 * @since 1.7.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faChartLine,
	faTrash,
	faPlus,
	faLink,
} from '@fortawesome/free-solid-svg-icons';
import TrendLine from './common/TrendLine';
import { apiCall } from '../lib/apiRequest';

const t = wppoSettings.translations;
const performanceAudit = wppoSettings.performance_audit || {};

/** Metrics available in the selector. */
const METRIC_OPTIONS = [
	{ key: 'lcp', label: t.lcp || 'LCP (s)' },
	{ key: 'fcp', label: t.fcp || 'FCP (s)' },
	{ key: 'ttfb', label: t.ttfb || 'TTFB (ms)' },
	{ key: 'tbt', label: t.tbt || 'TBT (ms)' },
	{ key: 'cls', label: t.cls || 'CLS' },
	{ key: 'speed_index', label: t.speedIndex || 'Speed Index (s)' },
	{ key: 'total_size', label: t.totalSize || 'Total Size' },
];

/** Format a nullable numeric cell value for display. */
const fmt = ( value, decimals = 2 ) => {
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	const num = parseFloat( value );
	return isNaN( num ) ? '—' : num.toFixed( decimals );
};

const PerformanceHistory = () => {
	const [ rows, setRows ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ selectedUrl, setSelectedUrl ] = useState( '' );
	const [ selectedMetric, setSelectedMetric ] = useState( 'lcp' );
	const [ newUrl, setNewUrl ] = useState( '' );
	const [ urlSaving, setUrlSaving ] = useState( false );
	const [ urlMessage, setUrlMessage ] = useState( '' );
	const [ clearing, setClearing ] = useState( false );

	/** Fetch all telemetry rows from the REST API. */
	const fetchRows = useCallback( async () => {
		setLoading( true );
		setError( '' );
		try {
			const res = await apiCall( 'telemetry', {}, 'GET' );
			if ( res.success ) {
				setRows( res.data || [] );
			} else {
				setError( res.message || 'Failed to load history.' );
			}
		} catch ( err ) {
			setError( 'Failed to load history.' );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		fetchRows();
	}, [ fetchRows ] );

	/** Unique URLs present in the data. */
	const uniqueUrls = [ ...new Set( rows.map( ( r ) => r.url ) ) ].filter(
		Boolean
	);

	/** Rows filtered by the selected URL (all rows when no URL selected). */
	const filteredRows = selectedUrl
		? rows.filter( ( r ) => r.url === selectedUrl )
		: rows;

	/**
	 * Trend data: selected metric values from filtered rows, reversed to
	 * chronological order (oldest first = left-to-right on the chart).
	 */
	const trendData = [ ...filteredRows ]
		.reverse()
		.map( ( r ) => parseFloat( r[ selectedMetric ] ) )
		.filter( ( v ) => ! isNaN( v ) );

	/** Add a new high-value URL. */
	const handleAddUrl = async () => {
		const trimmed = newUrl.trim();
		if ( ! trimmed ) {
			return;
		}

		setUrlSaving( true );
		setUrlMessage( '' );

		try {
			const res = await apiCall( 'telemetry/urls', {
				urls: [ trimmed ],
			} );
			if ( res.success ) {
				setNewUrl( '' );
				setUrlMessage( t.urlAdded || 'URL added successfully.' );
			} else {
				setUrlMessage( res.message || 'Failed to add URL.' );
			}
		} catch ( err ) {
			setUrlMessage( 'Failed to add URL.' );
		} finally {
			setUrlSaving( false );
		}
	};

	/** Clear all telemetry history. */
	const handleClearHistory = async () => {
		const confirmed = window.confirm(
			t.clearHistoryConfirm ||
				'This will permanently delete all telemetry history and cached scan data. Continue?'
		);
		if ( ! confirmed ) {
			return;
		}

		setClearing( true );
		try {
			const res = await apiCall( 'telemetry', {}, 'DELETE' );
			if ( res.success ) {
				setRows( [] );
			}
		} catch ( err ) {
			// Silently fail — rows will remain visible.
		} finally {
			setClearing( false );
		}
	};

	/** WooCommerce preset URLs from PHP. */
	const wooPresets = performanceAudit.woocommercePresets || [];

	return (
		<div className="wppo-performance-history">

			{ /* Page header */ }
			<div className="wppo-history-page-header">
				<div className="wppo-history-page-header__title">
					<FontAwesomeIcon icon={ faChartLine } />
					<h2>{ t.performanceHistory || 'Performance History' }</h2>
				</div>
				<button
					className="wppo-button wppo-button--danger wppo-button--sm"
					onClick={ handleClearHistory }
					disabled={ clearing || rows.length === 0 }
				>
					<FontAwesomeIcon icon={ faTrash } />
					{ clearing
						? t.clearing || 'Clearing…'
						: t.clearHistory || 'Clear History' }
				</button>
			</div>

			{ /* URL manager card */ }
			<div className="wppo-feature-card">
				<div className="wppo-feature-card__header">
					<h3>
						<FontAwesomeIcon icon={ faLink } />
						{ t.urlSelector || 'Tracked URLs' }
					</h3>
				</div>
				<div className="wppo-feature-card__body">
					<div className="wppo-history-url-input-row">
						<input
							type="url"
							className="wppo-input"
							value={ newUrl }
							onChange={ ( e ) => setNewUrl( e.target.value ) }
							placeholder={
								t.urlPlaceholder || 'https://example.com/page'
							}
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' ) {
									handleAddUrl();
								}
							} }
						/>
						<button
							className="wppo-button wppo-button--primary"
							onClick={ handleAddUrl }
							disabled={ urlSaving || ! newUrl.trim() }
						>
							<FontAwesomeIcon icon={ faPlus } />
							{ urlSaving
								? t.saving || 'Saving…'
								: t.addUrl || 'Add URL' }
						</button>
					</div>

					{ urlMessage && (
						<p className="wppo-history-url-message">
							{ urlMessage }
						</p>
					) }

					{ wooPresets.length > 0 && (
						<div className="wppo-history-presets">
							<span className="wppo-history-presets__label">
								{ t.woocommercePresets ||
									'WooCommerce Presets' }
								:
							</span>
							{ wooPresets.map( ( url ) => (
								<button
									key={ url }
									className="wppo-button wppo-button--ghost wppo-button--sm"
									onClick={ () => setNewUrl( url ) }
									title={ url }
								>
									{ url }
								</button>
							) ) }
						</div>
					) }
				</div>
			</div>

			{ /* Trend card */ }
			<div className="wppo-feature-card">
				<div className="wppo-feature-card__header">
					<h3>
						<FontAwesomeIcon icon={ faChartLine } />
						{ t.trendData || 'Trend' }
					</h3>
					{ /* Selectors inline in header */ }
					<div className="wppo-history-selectors">
						<select
							className="wppo-select wppo-history-select"
							value={ selectedUrl }
							onChange={ ( e ) =>
								setSelectedUrl( e.target.value )
							}
							aria-label={ t.urlSelector || 'Filter by URL' }
						>
							<option value="">
								{ t.selectUrl || '— All URLs —' }
							</option>
							{ uniqueUrls.map( ( url ) => (
								<option key={ url } value={ url }>
									{ url }
								</option>
							) ) }
						</select>

						<select
							className="wppo-select wppo-history-select"
							value={ selectedMetric }
							onChange={ ( e ) =>
								setSelectedMetric( e.target.value )
							}
							aria-label={ t.trendData || 'Metric' }
						>
							{ METRIC_OPTIONS.map( ( m ) => (
								<option key={ m.key } value={ m.key }>
									{ m.label }
								</option>
							) ) }
						</select>
					</div>
				</div>
				<div className="wppo-feature-card__body wppo-history-trend-body">
					<TrendLine
						data={ trendData }
						color="var(--wppo-primary, #2271b1)"
						height={ 100 }
						strokeWidth={ 2.5 }
					/>
				</div>
			</div>

			{ /* Data table card */ }
			<div className="wppo-feature-card">
				<div className="wppo-feature-card__header">
					<h3>{ 'Scan History' }</h3>
					{ filteredRows.length > 0 && (
						<span className="wppo-history-count-badge">
							{ filteredRows.length }
						</span>
					) }
				</div>
				<div className="wppo-feature-card__body wppo-history-table-body">
					{ loading && (
						<p className="wppo-history-state-msg">
							{ t.loadingRecentActivities || 'Loading…' }
						</p>
					) }

					{ ! loading && error && (
						<p className="wppo-history-state-msg wppo-history-state-msg--error">
							{ error }
						</p>
					) }

					{ ! loading && ! error && filteredRows.length === 0 && (
						<p className="wppo-history-state-msg">
							{ t.noHistoryData ||
								'No history data yet. Add URLs and run scans to start tracking.' }
						</p>
					) }

					{ ! loading && ! error && filteredRows.length > 0 && (
						<div className="wppo-history-table-wrapper">
							<table className="wppo-history-table">
								<thead>
									<tr>
										<th>URL</th>
										<th>{ t.lcp || 'LCP' }</th>
										<th>{ t.fcp || 'FCP' }</th>
										<th>{ t.ttfb || 'TTFB' }</th>
										<th>{ t.tbt || 'TBT' }</th>
										<th>{ t.cls || 'CLS' }</th>
										<th>
											{ t.speedIndex || 'Speed Index' }
										</th>
										<th>{ t.totalSize || 'Total Size' }</th>
										<th>{ t.scanType || 'Type' }</th>
										<th>Date</th>
									</tr>
								</thead>
								<tbody>
									{ filteredRows.map( ( row ) => (
										<tr key={ row.id }>
											<td
												className="wppo-history-url-cell"
												title={ row.url }
											>
												{ row.url }
											</td>
											<td>{ fmt( row.lcp ) }s</td>
											<td>{ fmt( row.fcp ) }s</td>
											<td>{ fmt( row.ttfb ) }ms</td>
											<td>{ fmt( row.tbt ) }ms</td>
											<td>{ fmt( row.cls, 3 ) }</td>
											<td>
												{ fmt( row.speed_index ) }s
											</td>
											<td>
												{ row.total_size
													? (
															parseInt(
																row.total_size,
																10
															) / 1024
													  ).toFixed( 1 ) + ' KB'
													: '—' }
											</td>
											<td>
												<span className="wppo-history-type-badge">
													{ row.scan_type || '—' }
												</span>
											</td>
											<td className="wppo-history-date-cell">
												{ row.scanned_at
													? new Date(
															row.scanned_at +
																' UTC'
													  ).toLocaleString()
													: '—' }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</div>
			</div>
		</div>
	);
};

export default PerformanceHistory;
