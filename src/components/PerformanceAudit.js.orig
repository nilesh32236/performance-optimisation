/**
 * PerformanceAudit component.
 *
 * Provides a modern URL scan bar and detailed results categorized into
 * user-friendly metrics and advanced developer details.
 *
 * @since 1.5.0
 */

import { useState, useRef } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faSearch,
	faGlobe,
	faCogs,
	faTerminal,
	faChartBar,
	faLightbulb,
} from '@fortawesome/free-solid-svg-icons';
import { runPerformanceScan, fetchSuggestions } from '../lib/apiRequest';
import FeatureCard from './common/FeatureCard';
import StatusBadge from './common/StatusBadge';
import Tooltip from './common/Tooltip';
import SwitchField from './common/SwitchField';

import { __ } from '@wordpress/i18n';

/**
 * Metric definitions with descriptions for tooltips.
 */
const METRIC_INFO = {
	load_time:
		'The total time taken for the page to fully load in the browser.',
	ttfb: 'Time to First Byte. The time it takes for the server to send the first byte of data.',
	dns: 'The time taken to resolve the domain name to an IP address.',
	connect: 'The time taken to establish a TCP connection with the server.',
	ssl: 'The time taken to complete the SSL/TLS handshake for secure connections.',
	page_size: 'The total weight of the page including CSS, JS, and Images.',
	assets: 'The total number of external resources loaded by the page.',
	compression:
		'Whether the server uses Gzip or Brotli to compress text assets.',
	cache_control:
		'Whether the server instructs the browser to cache assets for a long duration.',
	modern_images:
		'The percentage of images on the page that use modern formats like WebP or AVIF.',
	alt_text:
		'Whether all images have descriptive alt attributes for accessibility.',
	dom_size:
		'The total number of HTML elements on the page. High numbers (> 1,500) can slow down rendering.',
	unminified:
		'The number of CSS and JS files that are not minified (lack .min in filename).',
	third_party:
		'The number of scripts loaded from external domains (e.g., Google, Facebook).',
	server_wait:
		'Server processing time. The time taken by the server to process the request before sending data.',
};

/**
 * Derive a status string from a numeric value and thresholds.
 *
 * @param {number} value The metric value.
 * @param {number} good  Upper bound for 'good'.
 * @param {number} poor  Lower bound for 'poor'.
 * @return {string} Status string.
 */
const numericStatus = ( value, good, poor ) => {
	if ( value <= good ) {
		return 'good';
	}
	if ( value <= poor ) {
		return 'needs_improvement';
	}
	return 'poor';
};

/**
 * Derive a status string from a boolean pass/fail value.
 *
 * @param {boolean} passing Whether the check passed.
 * @return {string} 'good' or 'poor'.
 */
const boolStatus = ( passing ) => ( passing ? 'good' : 'poor' );

/**
 * Format bytes into a human-readable string.
 *
 * @param {number} bytes Raw byte count.
 * @return {string} Formatted size string.
 */
const formatBytes = ( bytes ) => {
	if ( ! bytes || bytes === 0 ) {
		return '0 B';
	}
	if ( bytes < 1024 ) {
		return `${ bytes } B`;
	}
	if ( bytes < 1024 * 1024 ) {
		return `${ ( bytes / 1024 ).toFixed( 1 ) } KB`;
	}
	return `${ ( bytes / ( 1024 * 1024 ) ).toFixed( 2 ) } MB`;
};

/**
 * A single row in the results table with optional tooltip.
 *
 * @param {Object} props
 * @param {string} props.label        Row label.
 * @param {string} props.value        Row value.
 * @param {string} [props.status]     Optional status badge.
 * @param {string} [props.tooltipKey] Key into METRIC_INFO for tooltip text.
 */
const ResultRow = ( { label, value, status, tooltipKey } ) => (
	<tr className="wppo-audit-table__row">
		<td className="wppo-audit-table__label">
			{ label }
			{ tooltipKey && <Tooltip content={ METRIC_INFO[ tooltipKey ] } /> }
		</td>
		<td className="wppo-audit-table__value">{ value }</td>
		<td className="wppo-audit-table__status">
			{ status && <StatusBadge status={ status } /> }
		</td>
	</tr>
);

/**
 * A section header row in the table.
 *
 * @param {Object} props
 * @param {string} props.title  Section title.
 * @param {Object} [props.icon] FontAwesome icon definition.
 */
const AuditSection = ( { title, icon } ) => (
	<tr className="wppo-audit-section-header">
		<td colSpan="3">
			<div
				style={ { display: 'flex', alignItems: 'center', gap: '8px' } }
			>
				{ icon && <FontAwesomeIcon icon={ icon } /> }
				{ title }
			</div>
		</td>
	</tr>
);

/**
 * Top-level overview cards showing the four key metrics at a glance.
 *
 * @param {Object} props
 * @param {Object} props.result Scan result from the REST API.
 */
const MetricOverview = ( { result } ) => (
	<div className="wppo-audit-overview">
		<div className="wppo-audit-overview-card">
			<div className="wppo-audit-overview-card__label">
				{ __( 'Load Time', 'performance-optimisation' ) }
				<Tooltip content={ METRIC_INFO.load_time } />
			</div>
			<span className="wppo-audit-overview-card__value">
				{ result.load_time } s
			</span>
			<div className="wppo-audit-overview-card__status">
				<StatusBadge
					status={ numericStatus( result.load_time, 2.5, 4 ) }
				/>
			</div>
		</div>
		<div className="wppo-audit-overview-card">
			<div className="wppo-audit-overview-card__label">
				{ __( 'TTFB', 'performance-optimisation' ) }
				<Tooltip content={ METRIC_INFO.ttfb } />
			</div>
			<span className="wppo-audit-overview-card__value">
				{ result.ttfb } ms
			</span>
			<div className="wppo-audit-overview-card__status">
				<StatusBadge
					status={ numericStatus( result.ttfb, 200, 500 ) }
				/>
			</div>
		</div>
		<div className="wppo-audit-overview-card">
			<div className="wppo-audit-overview-card__label">
				{ __( 'Page Size', 'performance-optimisation' ) }
				<Tooltip content={ METRIC_INFO.page_size } />
			</div>
			<span className="wppo-audit-overview-card__value">
				{ formatBytes( result.total_size ) }
			</span>
			<div className="wppo-audit-overview-card__status">
				<StatusBadge
					status={ numericStatus(
						result.total_size / 1024,
						500,
						1000
					) }
				/>
			</div>
		</div>
		<div className="wppo-audit-overview-card">
			<div className="wppo-audit-overview-card__label">
				{ __( 'Total Assets', 'performance-optimisation' ) }
				<Tooltip content={ METRIC_INFO.assets } />
			</div>
			<span className="wppo-audit-overview-card__value">
				{ result.css_count + result.js_count + result.media_count }
			</span>
		</div>
	</div>
);

const PerformanceAudit = ( { onSuggestionsReady, onUrlChange } ) => {
	const homeUrl =
		typeof wppoSettings !== 'undefined'
			? wppoSettings.performance_audit?.homeUrl ?? ''
			: '';

	const [ url, setUrl ] = useState( homeUrl );
	const [ scanning, setScanning ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ devMode, setDevMode ] = useState( false );
	const submittingRef = useRef( false );

	const handleDevModeToggle = ( e ) => {
		setDevMode( e.target.checked );
	};

	const handleScan = async ( e, force = false ) => {
		if ( scanning || submittingRef.current ) {
			return;
		}
		submittingRef.current = true;
		if ( e ) {
			e.preventDefault();
		}
		setScanning( true );
		setError( null );
		setResult( null );

		const abortController = new AbortController();

		try {
			const response = await runPerformanceScan( url, force );
			if ( response.success && response.data ) {
				setResult( response.data );

				// Phase 2 — notify parent of the scanned URL so PageSpeedPanel
				// can use the same URL without the user having to re-enter it.
				if ( onUrlChange ) {
					onUrlChange( url );
				}

				// Phase 2 — fetch telemetry-based suggestions and pass up to Dashboard.
				if ( onSuggestionsReady ) {
					fetchSuggestions( url, abortController.signal )
						.then( ( sugResp ) => {
							if ( abortController.signal.aborted ) {
								return;
							}
							if (
								sugResp.success &&
								sugResp.data?.suggestions
							) {
								onSuggestionsReady( sugResp.data.suggestions );
							}
						} )
						.catch( ( sugErr ) => {
							if ( abortController.signal.aborted ) {
								return;
							}
							// Non-fatal — suggestions are a bonus, not required.
							console.warn(
								'Could not fetch suggestions:',
								sugErr
							);
						} );
				}
			} else {
				setError(
					response.message ||
						__(
							'Scan failed. Please try again.',
							'performance-optimisation'
						)
				);
			}
		} catch ( err ) {
			if ( abortController.signal.aborted ) {
				return;
			}
			setError(
				__(
					'Scan failed. Please try again.',
					'performance-optimisation'
				)
			);
			console.error( 'Performance scan error:', err );
		} finally {
			submittingRef.current = false;
			setScanning( false );
		}
	};

	const setHomeUrl = () => {
		setUrl( homeUrl );
	};

	return (
		<FeatureCard
			title={ __( 'Performance Audit', 'performance-optimisation' ) }
		>
			{ /* Modern Scan Bar */ }
			<form className="wppo-audit-controls" onSubmit={ handleScan }>
				<div className="wppo-audit-controls__icon">
					<FontAwesomeIcon icon={ faSearch } />
				</div>
				<input
					id="wppo-audit-url"
					type="url"
					className="wppo-audit-controls__input"
					value={ url }
					onChange={ ( e ) => setUrl( e.target.value ) }
					placeholder={ __(
						'https://example.com',
						'performance-optimisation'
					) }
					required
					aria-label={ __(
						'URL to Audit',
						'performance-optimisation'
					) }
				/>
				<div className="wppo-audit-controls__actions">
					<button
						type="button"
						className="wppo-button wppo-button--ghost"
						onClick={ setHomeUrl }
						title={ __(
							'Use Home URL',
							'performance-optimisation'
						) }
						aria-label={ __(
							'Use Home URL',
							'performance-optimisation'
						) }
					>
						<FontAwesomeIcon icon={ faGlobe } />
					</button>
					<button
						type="submit"
						className="wppo-button wppo-button--primary"
						disabled={ scanning }
					>
						{ scanning ? (
							__( 'Scanning…', 'performance-optimisation' )
						) : (
							<>
								<FontAwesomeIcon
									icon={ faSearch }
									style={ { marginRight: '8px' } }
								/>
								{ __( 'Run Scan', 'performance-optimisation' ) }
							</>
						) }
					</button>
				</div>
			</form>

			{ error && (
				<div className="wppo-notice wppo-notice--error">{ error }</div>
			) }

			{ result && (
				<div className="wppo-audit-results">
					<div className="wppo-audit-meta">
						<div className="wppo-audit-meta__title">
							<FontAwesomeIcon
								icon={ faChartBar }
								style={ { marginRight: '8px' } }
							/>
							{ __( 'Scan Results', 'performance-optimisation' ) }
						</div>
						<div className="wppo-audit-meta__toggle">
							<SwitchField
								checked={ devMode }
								onChange={ handleDevModeToggle }
								name="devMode"
								label={ __(
									'Developer Details',
									'performance-optimisation'
								) }
								showLabel={ false }
							/>
						</div>
					</div>

					<MetricOverview result={ result } />

					{ result.is_cached && (
						<div
							className="wppo-notice wppo-notice--info"
							style={ {
								marginBottom: '24px',
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'space-between',
							} }
						>
							<span>
								<FontAwesomeIcon
									icon={ faLightbulb }
									style={ { marginRight: '8px' } }
								/>
								{ __(
									'Displaying cached results from the last hour.',
									'performance-optimisation'
								) }
							</span>
							<button
								type="button"
								className="wppo-button wppo-button--ghost wppo-button--sm"
								onClick={ ( e ) => handleScan( e, true ) }
								disabled={ scanning }
							>
								{ __(
									'Scan Fresh Data',
									'performance-optimisation'
								) }
							</button>
						</div>
					) }

					<table className="wppo-audit-table">
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
							{ /* Optimizations Section */ }
							<AuditSection
								title={ __(
									'Optimizations',
									'performance-optimisation'
								) }
								icon={ faCogs }
							/>
							<ResultRow
								label={ __(
									'Gzip/Brotli Compression',
									'performance-optimisation'
								) }
								value={
									result.compression_value &&
									result.compression_value !== 'none'
										? result.compression_value
										: __(
												'Disabled',
												'performance-optimisation'
										  )
								}
								status={ boolStatus(
									result.gzip_brotli_compression
								) }
								tooltipKey="compression"
							/>
							<ResultRow
								label={ __(
									'Cache-Control',
									'performance-optimisation'
								) }
								value={
									result.cache_control_value &&
									result.cache_control_value !== 'none'
										? result.cache_control_value
										: __(
												'None',
												'performance-optimisation'
										  )
								}
								status={ boolStatus(
									result.cache_control_headers
								) }
								tooltipKey="cache_control"
							/>
							<ResultRow
								label={ __(
									'Modern Formats',
									'performance-optimisation'
								) }
								value={ `${ Number(
									result.uses_modern_image_formats || 0
								).toFixed( 1 ) }%` }
								status={ numericStatus(
									100 -
										( parseFloat(
											result.uses_modern_image_formats
										) || 0 ),
									20,
									50
								) }
								tooltipKey="modern_images"
							/>
							<ResultRow
								label={ __(
									'Image Alt Attributes',
									'performance-optimisation'
								) }
								value={
									result.image_alt_attributes
										? __(
												'All images have alt text',
												'performance-optimisation'
										  )
										: __(
												'Some images missing alt text',
												'performance-optimisation'
										  )
								}
								status={ boolStatus(
									result.image_alt_attributes
								) }
								tooltipKey="alt_text"
							/>

							{ /* Advanced Developer Section */ }
							{ devMode && (
								<>
									<AuditSection
										title={ __(
											'Advanced Timings',
											'performance-optimisation'
										) }
										icon={ faTerminal }
									/>
									<ResultRow
										label={ __(
											'DNS Lookup',
											'performance-optimisation'
										) }
										value={ `${ result.dns_lookup_time } ms` }
										tooltipKey="dns"
									/>
									<ResultRow
										label={ __(
											'TCP Connection',
											'performance-optimisation'
										) }
										value={ `${ result.connect_time } ms` }
										tooltipKey="connect"
									/>
									<ResultRow
										label={ __(
											'SSL Handshake',
											'performance-optimisation'
										) }
										value={ `${ result.ssl_time } ms` }
										tooltipKey="ssl"
									/>
									<ResultRow
										label={ __(
											'True TTFB',
											'performance-optimisation'
										) }
										value={ `${ result.ttfb } ms` }
										tooltipKey="ttfb"
									/>
									<ResultRow
										label={ __(
											'Server Processing',
											'performance-optimisation'
										) }
										value={ `${ result.server_wait_time } ms` }
										tooltipKey="server_wait"
									/>

									<AuditSection
										title={ __(
											'Asset Breakdown',
											'performance-optimisation'
										) }
										icon={ faChartBar }
									/>
									<ResultRow
										label={ __(
											'CSS Files',
											'performance-optimisation'
										) }
										value={ `${
											result.css_count
										} (${ formatBytes(
											result.css_total_size
										) })` }
									/>
									<ResultRow
										label={ __(
											'JS Files',
											'performance-optimisation'
										) }
										value={ `${
											result.js_count
										} (${ formatBytes(
											result.js_total_size
										) })` }
									/>
									<ResultRow
										label={ __(
											'Total Images',
											'performance-optimisation'
										) }
										value={ `${
											result.media_count
										} (${ formatBytes(
											result.media_total_size
										) })` }
									/>
									<ResultRow
										label={ __(
											'Lazy-Loaded',
											'performance-optimisation'
										) }
										value={ result.lazy_image_count }
									/>
									<ResultRow
										label={ __(
											'Eager-Loaded',
											'performance-optimisation'
										) }
										value={ result.eager_image_count }
									/>
									<ResultRow
										label={ __(
											'Total DOM Nodes',
											'performance-optimisation'
										) }
										value={ result.dom_size }
										tooltipKey="dom_size"
									/>
									<ResultRow
										label={ __(
											'Unminified Assets',
											'performance-optimisation'
										) }
										value={ result.unminified_assets_count }
										tooltipKey="unminified"
									/>
									<ResultRow
										label={ __(
											'Third-Party Scripts',
											'performance-optimisation'
										) }
										value={
											result.third_party_scripts_count
										}
										tooltipKey="third_party"
									/>

									<AuditSection
										title={ __(
											'Environment',
											'performance-optimisation'
										) }
										icon={ faGlobe }
									/>
									<ResultRow
										label={ __(
											'Page URL',
											'performance-optimisation'
										) }
										value={ result.page_url }
									/>
									<ResultRow
										label={ __(
											'Scan Type',
											'performance-optimisation'
										) }
										value={ result.scan_type }
									/>
									<ResultRow
										label={ __(
											'HTTPS',
											'performance-optimisation'
										) }
										value={
											result.uses_https
												? __(
														'Enabled',
														'performance-optimisation'
												  )
												: __(
														'Disabled',
														'performance-optimisation'
												  )
										}
										status={ boolStatus(
											result.uses_https
										) }
									/>
									<ResultRow
										label={ __(
											'robots.txt',
											'performance-optimisation'
										) }
										value={
											result.robots_txt_exists
												? __(
														'Exists',
														'performance-optimisation'
												  )
												: __(
														'Missing',
														'performance-optimisation'
												  )
										}
										status={ boolStatus(
											result.robots_txt_exists
										) }
									/>
								</>
							) }
						</tbody>
					</table>

					{ ! devMode && (
						<div
							className="wppo-notice wppo-notice--info"
							style={ { marginTop: '24px' } }
						>
							<FontAwesomeIcon
								icon={ faLightbulb }
								style={ { marginRight: '8px' } }
							/>
							<span>
								Enable <strong>Developer Details</strong> for
								granular network timings and environment info.
							</span>
						</div>
					) }
				</div>
			) }
		</FeatureCard>
	);
};

export default PerformanceAudit;
