import { useState, useEffect } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faBroom,
	faLink,
	faCheckCircle,
	faExclamationCircle,
	faShieldAlt,
	faTimes,
	faNetworkWired,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';

import { __ } from '@wordpress/i18n';

const ObjectCache = ( { options = {} } ) => {
	const defaultSettings = {
		mode: 'standalone',
		host: '127.0.0.1',
		port: 6379,
		password: '',
		database: 0,
		nodes: '',
		master_name: 'mymaster',
		use_tls: false,
		persistent: false,
		compression: 'none',
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isActionLoading, setIsActionLoading ] = useState( false );
	const [ cacheStatus, setCacheStatus ] = useState( {
		enabled: false,
		redis_missing: false,
		foreign_dropin: false,
		redis_reachable: false,
		statusLoaded: false,
		supported_compressors: { none: true },
	} );
	const [ actionMsg, setActionMsg ] = useState( null );

	useEffect( () => {
		fetchStatus();
	}, [] );

	const fetchStatus = async () => {
		try {
			const res = await apiCall( 'object_cache', { action: 'status' } );
			if ( res.success ) {
				setCacheStatus( { ...res.data, statusLoaded: true } );
			}
		} catch ( error ) {
			console.error( 'Error fetching cache status', error );
			setActionMsg( {
				type: 'error',
				text: __(
					'Failed to check cache status.',
					'performance-optimisation'
				),
			} );
		}
	};

	const handleSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setIsLoading( true );
		setActionMsg( null );

		try {
			const res = await apiCall( 'update_settings', {
				tab: 'object_cache',
				settings,
			} );
			if ( res.success ) {
				setActionMsg( {
					type: 'success',
					text: __(
						'Settings saved successfully.',
						'performance-optimisation'
					),
				} );
			} else {
				setActionMsg( {
					type: 'error',
					text:
						res.message ||
						__(
							'Error saving settings.',
							'performance-optimisation'
						),
				} );
			}
		} catch ( err ) {
			setActionMsg( {
				type: 'error',
				text: __(
					'Error saving settings.',
					'performance-optimisation'
				),
			} );
			console.error( err );
		} finally {
			setIsLoading( false );
		}
	};

	const handleAction = async ( action ) => {
		setIsActionLoading( true );
		setActionMsg( null );
		try {
			const credentialsRequired = [
				'enable',
				'ping',
				'authenticate',
				'test-connection',
			];
			const payload = {
				action,
				...( credentialsRequired.includes( action )
					? settings
					: { mode: settings.mode } ),
			};
			const res = await apiCall( 'object_cache', payload );

			if ( ! res?.success ) {
				setActionMsg( {
					type: 'error',
					text:
						res?.message ||
						__( 'Action failed.', 'performance-optimisation' ),
				} );
				return;
			}

			if ( [ 'enable', 'disable', 'ping' ].includes( action ) ) {
				await fetchStatus();
			}
			setActionMsg( {
				type: 'success',
				text:
					res.message ||
					__( 'Action successful.', 'performance-optimisation' ),
			} );
		} finally {
			setIsActionLoading( false );
		}
	};

	const hitRatio = ( () => {
		if ( ! cacheStatus.telemetry ) {
			return 0;
		}
		const hits =
			parseInt( cacheStatus.telemetry.keyspace_hits || '0', 10 ) || 0;
		const misses =
			parseInt( cacheStatus.telemetry.keyspace_misses || '0', 10 ) || 0;
		const total = hits + misses;
		return total > 0 ? ( ( hits / total ) * 100 ).toFixed( 1 ) : 0;
	} )();

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Object Cache', 'performance-optimisation' ) }
				description={ __(
					'Enterprise-grade Redis object caching with Sentinel and Cluster support.',
					'performance-optimisation'
				) }
				actions={
					<div className="wppo-feature-header__actions">
						{ cacheStatus.enabled ? (
							<>
								<LoadingSubmitButton
									type="button"
									className="wppo-button wppo-button--secondary"
									onClick={ () => handleAction( 'flush' ) }
									disabled={ isActionLoading }
									isLoading={ isActionLoading }
									label={
										<>
											<FontAwesomeIcon icon={ faBroom } />{ ' ' }
											{ __(
												'Flush Cache',
												'performance-optimisation'
											) }
										</>
									}
								/>
								<LoadingSubmitButton
									type="button"
									className="wppo-button wppo-button--danger"
									onClick={ () => handleAction( 'disable' ) }
									disabled={ isActionLoading }
									isLoading={ isActionLoading }
									label={
										<>
											<FontAwesomeIcon icon={ faTimes } />{ ' ' }
											{ __(
												'Disable',
												'performance-optimisation'
											) }
										</>
									}
								/>
							</>
						) : (
							<LoadingSubmitButton
								type="button"
								className="wppo-button wppo-button--primary"
								onClick={ () => handleAction( 'enable' ) }
								disabled={
									isActionLoading ||
									cacheStatus.redis_missing ||
									! cacheStatus.redis_reachable ||
									cacheStatus.foreign_dropin
								}
								isLoading={ isActionLoading }
								label={
									<>
										<FontAwesomeIcon
											icon={ faCheckCircle }
										/>{ ' ' }
										{ __(
											'Enable Object Cache',
											'performance-optimisation'
										) }
									</>
								}
							/>
						) }
					</div>
				}
			/>

			{ actionMsg && (
				<div
					className={ `wppo-notice wppo-notice--${ actionMsg.type }` }
					role="alert"
				>
					<FontAwesomeIcon
						icon={
							actionMsg.type === 'success'
								? faCheckCircle
								: faExclamationCircle
						}
					/>
					<span>{ actionMsg.text }</span>
				</div>
			) }

			<div className="wppo-notices-container">
				{ cacheStatus.redis_missing && (
					<div className="wppo-notice wppo-notice--error">
						<FontAwesomeIcon icon={ faExclamationCircle } />
						<div>
							<strong>
								{ __(
									'Extension Missing',
									'performance-optimisation'
								) }
							</strong>
							<p>
								{ __(
									'The PhpRedis extension is not installed. Native performance will be limited.',
									'performance-optimisation'
								) }
							</p>
						</div>
					</div>
				) }
				{ cacheStatus.foreign_dropin && (
					<div className="wppo-notice wppo-notice--warning">
						<FontAwesomeIcon icon={ faExclamationCircle } />
						<div>
							<strong>
								{ __(
									'Conflict Detected',
									'performance-optimisation'
								) }
							</strong>
							<p>
								{ __(
									'Another object cache plugin is currently active. Please disable it to avoid site crashes.',
									'performance-optimisation'
								) }
							</p>
						</div>
					</div>
				) }
			</div>

			{ cacheStatus.telemetry && cacheStatus.enabled && (
				<div className="wppo-stats-grid">
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">
							{ __( 'Memory Usage', 'performance-optimisation' ) }
						</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry?.used_memory_human || '0B' }
						</span>
						<span className="wppo-text-muted">
							{ __( 'Peak:', 'performance-optimisation' ) }{ ' ' }
							{ cacheStatus.telemetry?.used_memory_peak_human ||
								'0B' }
						</span>
					</div>
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">
							{ __( 'Hit Ratio', 'performance-optimisation' ) }
						</span>
						<span className="wppo-stat-value">{ hitRatio }%</span>
						<div className="wppo-progress-bar">
							<div
								className="wppo-progress-bar__fill"
								style={ { width: `${ hitRatio }%` } }
							></div>
						</div>
					</div>
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">
							{ __(
								'Active Clients',
								'performance-optimisation'
							) }
						</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry?.connected_clients || 0 }
						</span>
						<span className="wppo-text-muted">
							{ __( 'Total:', 'performance-optimisation' ) }{ ' ' }
							{ cacheStatus.telemetry
								?.total_connections_received || 0 }
						</span>
					</div>
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">
							{ __(
								'Redis Version',
								'performance-optimisation'
							) }
						</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry?.redis_version || 'N/A' }
						</span>
						<span className="wppo-text-muted">
							{ __( 'Uptime:', 'performance-optimisation' ) }{ ' ' }
							{ cacheStatus.telemetry?.uptime_in_seconds
								? (
										cacheStatus.telemetry
											.uptime_in_seconds / 3600
								  ).toFixed( 1 )
								: '0' }
							h
						</span>
					</div>
				</div>
			) }

			<form className="wppo-stacked-cards" onSubmit={ handleSubmit }>
				<FeatureCard
					title={ __(
						'Connection Settings',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faLink } /> }
				>
					<div className="wppo-field-group">
						<div className="wppo-field">
							<label className="wppo-field-label" htmlFor="mode">
								{ __(
									'Deployment Mode',
									'performance-optimisation'
								) }
							</label>
							<select
								className="wppo-select"
								id="mode"
								name="mode"
								value={ settings.mode }
								onChange={ handleChange( setSettings ) }
							>
								<option value="standalone">
									{ __(
										'Standalone (Single Node)',
										'performance-optimisation'
									) }
								</option>
								<option value="sentinel">
									{ __(
										'Redis Sentinel (HA)',
										'performance-optimisation'
									) }
								</option>
								<option value="cluster">
									{ __(
										'Redis Cluster',
										'performance-optimisation'
									) }
								</option>
							</select>
						</div>

						{ settings.mode === 'standalone' ? (
							<div className="wppo-grid-2-col wppo-mt-24">
								<div>
									<label
										className="wppo-field-label"
										htmlFor="host"
									>
										{ __(
											'Host',
											'performance-optimisation'
										) }
									</label>
									<input
										className="wppo-input"
										id="host"
										type="text"
										name="host"
										value={ settings.host }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
								<div>
									<label
										className="wppo-field-label"
										htmlFor="port"
									>
										{ __(
											'Port',
											'performance-optimisation'
										) }
									</label>
									<input
										className="wppo-input"
										id="port"
										type="number"
										name="port"
										value={ settings.port }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
							</div>
						) : (
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="nodes"
								>
									{ __(
										'Server Nodes',
										'performance-optimisation'
									) }
								</label>
								<textarea
									className="wppo-textarea"
									id="nodes"
									name="nodes"
									rows="3"
									placeholder={ __(
										'host:port (one per line)',
										'performance-optimisation'
									) }
									value={ settings.nodes }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						) }

						{ settings.mode === 'sentinel' && (
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="master_name"
								>
									{ __(
										'Sentinel Master Name',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input"
									id="master_name"
									type="text"
									name="master_name"
									value={ settings.master_name }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						) }

						<div className="wppo-grid-2-col wppo-mt-24">
							<div>
								<label
									className="wppo-field-label"
									htmlFor="password"
								>
									{ __(
										'Auth Password',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input"
									id="password"
									type="password"
									name="password"
									placeholder={ __(
										'Optional',
										'performance-optimisation'
									) }
									value={ settings.password }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
							<div>
								<label
									className="wppo-field-label"
									htmlFor="database"
								>
									{ __(
										'Database ID',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input"
									id="database"
									type="number"
									name="database"
									value={ settings.database }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
						</div>

						<div className="wppo-mt-24 wppo-flex-gap-12">
							<LoadingSubmitButton
								type="button"
								className="wppo-button wppo-button--secondary"
								onClick={ () => handleAction( 'ping' ) }
								disabled={ isActionLoading }
								isLoading={ isActionLoading }
								label={
									<>
										<FontAwesomeIcon
											icon={ faNetworkWired }
										/>{ ' ' }
										{ __(
											'Test Connection',
											'performance-optimisation'
										) }
									</>
								}
							/>
							<LoadingSubmitButton
								type="submit"
								className="wppo-button wppo-button--primary"
								isLoading={ isLoading }
								label={ __(
									'Save Changes',
									'performance-optimisation'
								) }
							/>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Enterprise Performance',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faShieldAlt } /> }
				>
					<div className="wppo-field-group">
						<div>
							<label
								className="wppo-field-label"
								htmlFor="compression"
							>
								{ __(
									'Memory Compression',
									'performance-optimisation'
								) }
							</label>
							<select
								className="wppo-select"
								id="compression"
								name="compression"
								value={ settings.compression }
								onChange={ handleChange( setSettings ) }
								aria-describedby="compression-desc"
							>
								<option value="none">
									{ __(
										'None (Fastest)',
										'performance-optimisation'
									) }
								</option>
								<option
									value="lzf"
									disabled={
										cacheStatus.supported_compressors &&
										! cacheStatus.supported_compressors.lzf
									}
								>
									{ __( 'LZF', 'performance-optimisation' ) }{ ' ' }
									{ cacheStatus.supported_compressors &&
									! cacheStatus.supported_compressors.lzf
										? __(
												'(Disabled)',
												'performance-optimisation'
										  )
										: '' }
								</option>
								<option
									value="zstd"
									disabled={
										cacheStatus.supported_compressors &&
										! cacheStatus.supported_compressors.zstd
									}
								>
									{ __( 'ZSTD', 'performance-optimisation' ) }{ ' ' }
									{ cacheStatus.supported_compressors &&
									! cacheStatus.supported_compressors.zstd
										? __(
												'(Disabled)',
												'performance-optimisation'
										  )
										: __(
												'(Recommended)',
												'performance-optimisation'
										  ) }
								</option>
								<option
									value="lz4"
									disabled={
										cacheStatus.supported_compressors &&
										! cacheStatus.supported_compressors.lz4
									}
								>
									{ __( 'LZ4', 'performance-optimisation' ) }{ ' ' }
									{ cacheStatus.supported_compressors &&
									! cacheStatus.supported_compressors.lz4
										? __(
												'(Disabled)',
												'performance-optimisation'
										  )
										: '' }
								</option>
							</select>
							<p
								id="compression-desc"
								className="wppo-text-muted"
								style={ {
									marginTop: '12px',
									fontSize: '13px',
								} }
							>
								{ __(
									'Reduces memory footprint for enterprise caches.',
									'performance-optimisation'
								) }
							</p>
						</div>

						<SwitchField
							label={ __(
								'Persistent Connections',
								'performance-optimisation'
							) }
							description={ __(
								'Keep connections alive between PHP requests.',
								'performance-optimisation'
							) }
							name="persistent"
							checked={ settings.persistent }
							onChange={ handleChange( setSettings ) }
						/>

						<SwitchField
							label={ __(
								'TLS / SSL Encryption',
								'performance-optimisation'
							) }
							description={ __(
								'Encrypt traffic between WordPress and Redis.',
								'performance-optimisation'
							) }
							name="use_tls"
							checked={ settings.use_tls }
							onChange={ handleChange( setSettings ) }
						/>
					</div>
				</FeatureCard>
			</form>
		</div>
	);
};

export default ObjectCache;
