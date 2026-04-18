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

const translations = wppoSettings.translations;

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
					text:
						translations.formSubmitted ||
						'Settings saved successfully.',
				} );
			} else {
				setActionMsg( {
					type: 'error',
					text:
						res.message ||
						translations.formSubmissionError ||
						'Error saving settings.',
				} );
			}
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
					text: res?.message || 'Action failed.',
				} );
				return;
			}

			if ( [ 'enable', 'disable', 'ping' ].includes( action ) ) {
				await fetchStatus();
			}
			setActionMsg( {
				type: 'success',
				text: res.message || 'Action successful.',
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
				title="Object Cache"
				description="Enterprise-grade Redis object caching with Sentinel and Cluster support."
				actions={
					<div className="wppo-feature-header__actions">
						{ cacheStatus.enabled ? (
							<>
								<button
									className="wppo-button wppo-button--secondary"
									onClick={ () => handleAction( 'flush' ) }
									disabled={ isActionLoading }
								>
									<FontAwesomeIcon icon={ faBroom } /> Flush
									Cache
								</button>
								<button
									className="wppo-button wppo-button--danger"
									onClick={ () => handleAction( 'disable' ) }
									disabled={ isActionLoading }
								>
									<FontAwesomeIcon icon={ faTimes } /> Disable
								</button>
							</>
						) : (
							<button
								className="wppo-button wppo-button--primary"
								onClick={ () => handleAction( 'enable' ) }
								disabled={
									isActionLoading ||
									cacheStatus.redis_missing ||
									! cacheStatus.redis_reachable ||
									cacheStatus.foreign_dropin
								}
							>
								<FontAwesomeIcon icon={ faCheckCircle } />{ ' ' }
								Enable Object Cache
							</button>
						) }
					</div>
				}
			/>

			{ actionMsg && (
				<div
					className={ `wppo-notice wppo-notice--${ actionMsg.type }` }
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
							<strong>Extension Missing</strong>
							<p>
								The PhpRedis extension is not installed. Native
								performance will be limited.
							</p>
						</div>
					</div>
				) }
				{ cacheStatus.foreign_dropin && (
					<div className="wppo-notice wppo-notice--warning">
						<FontAwesomeIcon icon={ faExclamationCircle } />
						<div>
							<strong>Conflict Detected</strong>
							<p>
								Another object cache plugin is currently active.
								Please disable it to avoid site crashes.
							</p>
						</div>
					</div>
				) }
			</div>

			{ cacheStatus.telemetry && cacheStatus.enabled && (
				<div className="wppo-stats-grid">
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">Memory Usage</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry?.used_memory_human || '0B' }
						</span>
						<span className="wppo-text-muted">
							Peak:{ ' ' }
							{ cacheStatus.telemetry?.used_memory_peak_human ||
								'0B' }
						</span>
					</div>
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">Hit Ratio</span>
						<span className="wppo-stat-value">{ hitRatio }%</span>
						<div className="wppo-progress-bar">
							<div
								className="wppo-progress-bar__fill"
								style={ { width: `${ hitRatio }%` } }
							></div>
						</div>
					</div>
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">Active Clients</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry.connected_clients }
						</span>
						<span className="wppo-text-muted">
							Total:{ ' ' }
							{ cacheStatus.telemetry.total_connections_received }
						</span>
					</div>
					<div className="wppo-stat-item">
						<span className="wppo-stat-label">Redis Version</span>
						<span className="wppo-stat-value">
							{ cacheStatus.telemetry?.redis_version || 'N/A' }
						</span>
						<span className="wppo-text-muted">
							Uptime:{ ' ' }
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

			<form className="wppo-grid-2-col" onSubmit={ handleSubmit }>
				<FeatureCard
					title="Connection Settings"
					icon={ <FontAwesomeIcon icon={ faLink } /> }
				>
					<div className="wppo-field-group">
						<div className="wppo-field">
							<label className="wppo-field-label" htmlFor="mode">
								Deployment Mode
							</label>
							<select
								className="wppo-select"
								id="mode"
								name="mode"
								value={ settings.mode }
								onChange={ handleChange( setSettings ) }
							>
								<option value="standalone">
									Standalone (Single Node)
								</option>
								<option value="sentinel">
									Redis Sentinel (HA)
								</option>
								<option value="cluster">Redis Cluster</option>
							</select>
						</div>

						{ settings.mode === 'standalone' ? (
							<div className="wppo-grid-2-col wppo-mt-20">
								<div>
									<label
										className="wppo-field-label"
										htmlFor="host"
									>
										Host
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
										Port
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
									Server Nodes
								</label>
								<textarea
									className="wppo-textarea"
									id="nodes"
									name="nodes"
									rows="3"
									placeholder="host:port (one per line)"
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
									Sentinel Master Name
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

						<div className="wppo-grid-2-col wppo-mt-20">
							<div>
								<label
									className="wppo-field-label"
									htmlFor="password"
								>
									Auth Password
								</label>
								<input
									className="wppo-input"
									id="password"
									type="password"
									name="password"
									placeholder="Optional"
									value={ settings.password }
									onChange={ handleChange( setSettings ) }
								/>
							</div>
							<div>
								<label
									className="wppo-field-label"
									htmlFor="database"
								>
									Database ID
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

						<div className="wppo-mt-12 wppo-flex-gap-12">
							<button
								type="button"
								className="wppo-button wppo-button--secondary"
								onClick={ () => handleAction( 'ping' ) }
								disabled={ isActionLoading }
							>
								<FontAwesomeIcon icon={ faNetworkWired } />{ ' ' }
								{ isActionLoading ? '...' : 'Test Connection' }
							</button>
							<LoadingSubmitButton
								className="wppo-button wppo-button--primary"
								onClick={ handleSubmit }
								isLoading={ isLoading }
								label="Save Changes"
							/>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title="Enterprise Performance"
					icon={ <FontAwesomeIcon icon={ faShieldAlt } /> }
				>
					<div className="wppo-field-group">
						<div>
							<label
								className="wppo-field-label"
								htmlFor="compression"
							>
								Memory Compression
							</label>
							<select
								className="wppo-select"
								id="compression"
								name="compression"
								value={ settings.compression }
								onChange={ handleChange( setSettings ) }
							>
								<option value="none">None (Fastest)</option>
								<option
									value="lzf"
									disabled={
										cacheStatus.supported_compressors &&
										! cacheStatus.supported_compressors.lzf
									}
								>
									LZF{ ' ' }
									{ cacheStatus.supported_compressors &&
									! cacheStatus.supported_compressors.lzf
										? '(Disabled)'
										: '' }
								</option>
								<option
									value="zstd"
									disabled={
										cacheStatus.supported_compressors &&
										! cacheStatus.supported_compressors.zstd
									}
								>
									ZSTD{ ' ' }
									{ cacheStatus.supported_compressors &&
									! cacheStatus.supported_compressors.zstd
										? '(Disabled)'
										: '(Recommended)' }
								</option>
								<option
									value="lz4"
									disabled={
										cacheStatus.supported_compressors &&
										! cacheStatus.supported_compressors.lz4
									}
								>
									LZ4{ ' ' }
									{ cacheStatus.supported_compressors &&
									! cacheStatus.supported_compressors.lz4
										? '(Disabled)'
										: '' }
								</option>
							</select>
							<p
								className="wppo-text-muted"
								style={ { marginTop: '8px', fontSize: '13px' } }
							>
								Reduces memory footprint for enterprise caches.
							</p>
						</div>

						<SwitchField
							label="Persistent Connections"
							description="Keep connections alive between PHP requests."
							name="persistent"
							checked={ settings.persistent }
							onChange={ handleChange( setSettings ) }
						/>

						<SwitchField
							label="TLS / SSL Encryption"
							description="Encrypt traffic between WordPress and Redis."
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
