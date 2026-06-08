/**
 * SystemInfo component.
 *
 * Renders server/PHP/WP environment details in a tabular layout.
 * Data is fetched on-demand when the user clicks "Load System Info"
 * rather than automatically on mount, to keep the Dashboard fast.
 *
 * @since 1.5.0
 */

import { useState, memo } from '@wordpress/element';
import { fetchSystemInfo } from '../lib/apiRequest';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';

import { __ } from '@wordpress/i18n';

/**
 * A single key-value row in a system info table.
 *
 * @param {Object} props
 * @param {string} props.label Row label.
 * @param {*}      props.value Row value (null renders as '—').
 */
const InfoRow = ( { label, value } ) => (
	<tr className="wppo-sysinfo-table__row">
		<td className="wppo-sysinfo-table__label">{ label }</td>
		<td className="wppo-sysinfo-table__value">
			{ value !== null && value !== undefined && value !== ''
				? String( value )
				: '—' }
		</td>
	</tr>
);

/**
 * A labelled table of InfoRow items.
 *
 * @param {Object} props
 * @param {string} props.title  Section heading.
 * @param {Object} props.data   Key-value pairs to render.
 * @param {Object} props.labels Optional map of data keys to display labels.
 */
const InfoTable = ( { title, data, labels = {} } ) => {
	if ( ! data ) {
		return null;
	}

	return (
		<div className="wppo-sysinfo-section">
			<h4 className="wppo-sysinfo-section__title">{ title }</h4>
			<table className="wppo-sysinfo-table">
				<tbody>
					{ Object.entries( data ).map( ( [ key, value ] ) => (
						<InfoRow
							key={ key }
							label={ labels[ key ] || key }
							value={ value }
						/>
					) ) }
				</tbody>
			</table>
		</div>
	);
};

const SystemInfo = () => {
	const [ info, setInfo ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ loaded, setLoaded ] = useState( false );
	const [ error, setError ] = useState( null );

	const handleLoad = async () => {
		setLoading( true );
		setError( null );

		try {
			const response = await fetchSystemInfo();
			if ( response.success && response.data ) {
				setInfo( response.data );
				setLoaded( true );
			} else {
				setError(
					response.message ||
						__(
							'Failed to fetch system info. Please try again.',
							'performance-optimisation'
						)
				);
			}
		} catch ( err ) {
			setError(
				__(
					'Failed to fetch system info. Please try again.',
					'performance-optimisation'
				)
			);
			console.error( 'System info fetch error:', err );
		} finally {
			setLoading( false );
		}
	};

	return (
		<FeatureCard title={ __( 'System Info', 'performance-optimisation' ) }>
			{ /* Load trigger — shown to initiate fetch or refresh data */ }
			<div
				className={ `wppo-sysinfo-trigger ${
					loaded ? 'wppo-sysinfo-trigger--compact' : ''
				}` }
			>
				{ ! loaded && ! error && (
					<p className="wppo-sysinfo-trigger__desc" id="sysinfo-trigger-desc">
						{ __(
							'View PHP, database, WordPress, and server environment details.',
							'performance-optimisation'
						) }
					</p>
				) }
				<LoadingSubmitButton
					type="button"
					className="wppo-button wppo-button--secondary"
					onClick={ handleLoad }
					isLoading={ loading }
					aria-describedby={ ! loaded && ! error ? 'sysinfo-trigger-desc' : undefined }
					label={
						loaded
							? __( 'Refresh', 'performance-optimisation' )
							: __(
									'Load System Info',
									'performance-optimisation'
							  )
					}
					loadingLabel={ __(
						'Loading…',
						'performance-optimisation'
					) }
				/>
			</div>

			{ /* Error state */ }
			{ error && (
				<div
					className="wppo-notice wppo-notice--error"
					role="alert"
					aria-live="assertive"
				>
					{ error }
				</div>
			) }

			{ /* Results — two-column grid of tables */ }
			{ info && (
				<div className="wppo-sysinfo-grid">
					<InfoTable
						title="PHP"
						data={ info.php }
						labels={ {
							version: __(
								'PHP Version',
								'performance-optimisation'
							),
							sapi: 'SAPI',
							memory_limit: __(
								'Memory Limit',
								'performance-optimisation'
							),
							max_execution_time: 'Max Execution Time',
							upload_max_filesize: 'Upload Max Filesize',
							post_max_size: 'Post Max Size',
							display_errors: 'Display Errors',
							extensions_count: 'Extensions Loaded',
						} }
					/>
					<InfoTable
						title="Database"
						data={ info.database }
						labels={ {
							server_version: __(
								'DB Version',
								'performance-optimisation'
							),
							extension: 'Extension',
							client_version: 'Client Version',
							max_connections: 'Max Connections',
						} }
					/>
					<InfoTable
						title="WordPress"
						data={ info.wordpress }
						labels={ {
							version: __(
								'WP Version',
								'performance-optimisation'
							),
							environment_type: 'Environment',
							permalink_structure: 'Permalink Structure',
							using_https: __(
								'HTTPS',
								'performance-optimisation'
							),
							multisite: 'Multisite',
						} }
					/>
					<InfoTable
						title="Server"
						data={ info.server }
						labels={ {
							server_software: __(
								'Server Software',
								'performance-optimisation'
							),
							os: 'Operating System',
							architecture: 'Architecture',
						} }
					/>
					<InfoTable
						title="Cache"
						data={ {
							object_cache_status:
								info.cache?.object_cache_status,
							active_cache_plugin:
								info.cache?.active_cache_plugin,
							peak_memory_usage: info.cache?.peak_memory_usage,
							current_memory_usage:
								info.cache?.current_memory_usage,
						} }
						labels={ {
							object_cache_status: __(
								'Object Cache',
								'performance-optimisation'
							),
							active_cache_plugin: __(
								'Active Cache Plugin',
								'performance-optimisation'
							),
							peak_memory_usage: 'Peak Memory',
							current_memory_usage: 'Current Memory',
						} }
					/>
					<InfoTable
						title={ __(
							'Infrastructure',
							'performance-optimisation'
						) }
						data={ {
							action_scheduler: info.infrastructure
								?.action_scheduler?.available
								? __( 'Available', 'performance-optimisation' )
								: __(
										'Unavailable',
										'performance-optimisation'
								  ),
							pagespeed_api: info.infrastructure?.pagespeed_api
								?.configured
								? __( 'Configured', 'performance-optimisation' )
								: __(
										'Not Configured',
										'performance-optimisation'
								  ),
						} }
						labels={ {
							action_scheduler: __(
								'Action Scheduler',
								'performance-optimisation'
							),
							pagespeed_api: __(
								'PageSpeed Insights API',
								'performance-optimisation'
							),
						} }
					/>
					<InfoTable
						title="WP Constants"
						data={ info.wp_constants }
					/>
				</div>
			) }
		</FeatureCard>
	);
};

export default memo( SystemInfo );
