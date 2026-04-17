import { useState, useEffect } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faServer,
	faBroom,
	faLink,
	faMicrochip,
	faMousePointer,
	faNetworkWired,
	faCheckCircle,
	faExclamationCircle,
	faShieldAlt,
	faTimes,
} from '@fortawesome/free-solid-svg-icons';

const ObjectCache = ( { options = {} } ) => {
	const translations = wppoSettings.translations;

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
	} );
	const [ actionMsg, setActionMsg ] = useState( null );

	useEffect( () => {
		fetchStatus();
	}, [] );

	const fetchStatus = async () => {
		try {
			const res = await apiCall( 'object_cache', { action: 'status' } );
			setCacheStatus( res.data );
		} catch ( error ) {
			console.error( 'Error fetching cache status', error );
		}
	};

	const handleSubmit = async ( e ) => {
		e.preventDefault();
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
		} catch ( error ) {
			setActionMsg( {
				type: 'error',
				text:
					translations.formSubmissionError ||
					'Error saving settings.',
			} );
		} finally {
			setIsLoading( false );
		}
	};

	const handleAction = async ( action ) => {
		setIsActionLoading( true );
		setActionMsg( null );
		try {
			const payload = {
				action,
				...settings,
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
		} catch ( error ) {
			setActionMsg( {
				type: 'error',
				text: error.message || 'Action failed.',
			} );
		} finally {
			setIsActionLoading( false );
		}
	};

	const getHitRatio = () => {
		if ( ! cacheStatus.telemetry ) {
			return 0;
		}
		const hits = parseInt( cacheStatus.telemetry.keyspace_hits ) || 0;
		const misses = parseInt( cacheStatus.telemetry.keyspace_misses ) || 0;
		const total = hits + misses;
		return total > 0 ? ( ( hits / total ) * 100 ).toFixed( 1 ) : 0;
	};

	return (
		<div className="wppo-dashboard-view fadeIn">
			{ /* --- Header Actions --- */ }
			<div className="wppo-feature-header">
				<div className="wppo-feature-title">
					<h2>
						<FontAwesomeIcon icon={ faServer } />
						{ translations.objectCache ||
							'Enterprise Redis Object Cache' }
					</h2>
					<p>
						{ translations.objectCacheDesc ||
							'High-performance persistent caching with Sentinel & Cluster support.' }
					</p>
				</div>
				<div className="wppo-feature-actions">
					{ cacheStatus.enabled ? (
						<>
							<button
								type="button"
								className="wppo-button wppo-button-outline"
								onClick={ () => handleAction( 'flush' ) }
								disabled={ isActionLoading }
							>
								<FontAwesomeIcon icon={ faBroom } />
								{ isActionLoading
									? '...'
									: translations.flushCache || 'Flush Cache' }
							</button>
							<button
								type="button"
								className="wppo-button wppo-button-danger"
								onClick={ () => handleAction( 'disable' ) }
								disabled={ isActionLoading }
							>
								<FontAwesomeIcon icon={ faTimes } />
								{ isActionLoading
									? '...'
									: translations.disableObjectCache ||
									  'Disable' }
							</button>
						</>
					) : (
						<button
							type="button"
							className="wppo-button wppo-button-primary"
							onClick={ () => handleAction( 'enable' ) }
							disabled={
								isActionLoading ||
								cacheStatus.redis_missing ||
								! cacheStatus.redis_reachable ||
								cacheStatus.foreign_dropin
							}
						>
							<FontAwesomeIcon icon={ faCheckCircle } />
							{ isActionLoading
								? '...'
								: translations.enableObjectCache ||
								  'Enable Object Cache' }
						</button>
					) }
				</div>
			</div>

			{ /* --- Status Notices --- */ }
			<div
				className="wppo-notices-container"
				style={ { marginBottom: '24px' } }
			>
				{ cacheStatus.redis_missing && (
					<div className="wppo-notice wppo-notice--error">
						<FontAwesomeIcon icon={ faExclamationCircle } />
						<div>
							<strong>
								{ translations.redisMissing ||
									'Extension Missing' }
							</strong>
							<p>
								{ translations.redisMissingDesc ||
									'The high-performance PhpRedis extension is not installed.' }
							</p>
						</div>
					</div>
				) }

				{ cacheStatus.foreign_dropin && (
					<div className="wppo-notice wppo-notice--warning">
						<FontAwesomeIcon icon={ faExclamationCircle } />
						<div>
							<strong>
								{ translations.foreignDropin ||
									'Conflict Detected' }
							</strong>
							<p>
								{ translations.foreignDropinDesc ||
									'Another object cache drop-in is active. Please disable it first.' }
							</p>
						</div>
					</div>
				) }

				{ ! cacheStatus.redis_missing &&
					! cacheStatus.redis_reachable && (
						<div className="wppo-notice wppo-notice--error">
							<FontAwesomeIcon icon={ faExclamationCircle } />
							<div>
								<strong>
									{ translations.redisUnreachable ||
										'Connection Failed' }
								</strong>
								<p>
									{ cacheStatus.telemetry_error ||
										'Could not connect to Redis with current settings.' }
								</p>
							</div>
						</div>
					) }
			</div>

			{ /* --- Live Telemetry Grid --- */ }
			{ cacheStatus.telemetry && cacheStatus.enabled && (
				<div
					className="wppo-stats-grid"
					style={ { marginBottom: '24px' } }
				>
					<div className="wppo-stat-card">
						<div className="stat-header">
							<FontAwesomeIcon icon={ faMicrochip } />
							Memory Usage
						</div>
						<div className="stat-value">
							{ cacheStatus.telemetry.used_memory_human }
						</div>
						<div className="stat-footer">
							Peak:{ ' ' }
							{ cacheStatus.telemetry.used_memory_peak_human }
						</div>
					</div>

					<div className="wppo-stat-card">
						<div className="stat-header">
							<FontAwesomeIcon icon={ faMousePointer } />
							Hit Ratio
						</div>
						<div className="stat-value">{ getHitRatio() }%</div>
						<div className="wppo-progress-wrapper">
							<div className="progress-bar-bg">
								<div
									className="progress-bar-fill"
									style={ { width: `${ getHitRatio() }%` } }
								></div>
							</div>
						</div>
					</div>

					<div className="wppo-stat-card">
						<div className="stat-header">
							<FontAwesomeIcon icon={ faNetworkWired } />
							Connections
						</div>
						<div className="stat-value">
							{ cacheStatus.telemetry.connected_clients }
						</div>
						<div className="stat-footer">
							Total:{ ' ' }
							{ cacheStatus.telemetry.total_connections_received }
						</div>
					</div>
				</div>
			) }

			{ /* --- Main Configuration --- */ }
			<div
				className="wppo-dashboard-columns"
				style={ {
					display: 'grid',
					gridTemplateColumns: '1.5fr 1fr',
					gap: '24px',
				} }
			>
				{ /* Connection Section */ }
				<div className="wppo-dashboard-column">
					<div className="feature-card">
						<h3>
							<FontAwesomeIcon icon={ faLink } />
							{ translations.connectionSettings ||
								'Connection Configuration' }
						</h3>

						<div style={ { marginTop: '20px' } }>
							<div
								className="setting-group"
								style={ { marginBottom: '20px' } }
							>
								<label className="field-label">
									{ translations.connectionMode ||
										'Cluster / HA Architecture' }
								</label>
								<select
									className="input-field"
									name="mode"
									value={ settings.mode }
									onChange={ handleChange( setSettings ) }
								>
									<option value="standalone">
										{ translations.standalone ||
											'Standalone (Single Node)' }
									</option>
									<option value="sentinel">
										{ translations.sentinel ||
											'Redis Sentinel (High Availability)' }
									</option>
									<option value="cluster">
										{ translations.cluster ||
											'Redis Cluster' }
									</option>
								</select>
							</div>

							<div
								className="settings-split-grid"
								style={ {
									display: 'grid',
									gridTemplateColumns: '1fr 1fr',
									gap: '20px',
								} }
							>
								{ settings.mode === 'standalone' ? (
									<>
										<div className="setting-group">
											<label
												className="field-label"
												htmlFor="host"
											>
												{ translations.redisHost ||
													'Host' }
											</label>
											<input
												className="input-field"
												type="text"
												id="host"
												name="host"
												value={ settings.host }
												onChange={ handleChange(
													setSettings
												) }
											/>
										</div>
										<div className="setting-group">
											<label
												className="field-label"
												htmlFor="port"
											>
												{ translations.redisPort ||
													'Port' }
											</label>
											<input
												className="input-field"
												type="number"
												id="port"
												name="port"
												value={ settings.port }
												onChange={ handleChange(
													setSettings
												) }
											/>
										</div>
									</>
								) : (
									<div
										className="setting-group"
										style={ { gridColumn: 'span 2' } }
									>
										<label
											className="field-label"
											htmlFor="nodes"
										>
											{ translations.redisNodes ||
												'Server Nodes' }
										</label>
										<textarea
											className="input-field"
											id="nodes"
											name="nodes"
											rows="3"
											placeholder="host:port (one per line)"
											value={ settings.nodes }
											onChange={ handleChange(
												setSettings
											) }
										></textarea>
									</div>
								) }

								{ settings.mode === 'sentinel' && (
									<div
										className="setting-group"
										style={ { gridColumn: 'span 2' } }
									>
										<label
											className="field-label"
											htmlFor="master_name"
										>
											{ translations.masterName ||
												'Sentinel Master Name' }
										</label>
										<input
											className="input-field"
											type="text"
											id="master_name"
											name="master_name"
											value={ settings.master_name }
											onChange={ handleChange(
												setSettings
											) }
										/>
									</div>
								) }

								<div className="setting-group">
									<label
										className="field-label"
										htmlFor="password"
									>
										{ translations.redisPassword ||
											'Auth Password' }
									</label>
									<input
										className="input-field"
										type="password"
										id="password"
										name="password"
										placeholder="Leave empty if none"
										value={ settings.password }
										onChange={ handleChange( setSettings ) }
									/>
								</div>

								<div className="setting-group">
									<label
										className="field-label"
										htmlFor="database"
									>
										{ translations.redisDatabase ||
											'Database ID' }
									</label>
									<input
										className="input-field"
										type="number"
										id="database"
										name="database"
										value={ settings.database }
										onChange={ handleChange( setSettings ) }
									/>
								</div>
							</div>

							<div
								style={ {
									marginTop: '24px',
									display: 'flex',
									gap: '12px',
								} }
							>
								<button
									type="button"
									className="wppo-button wppo-button-secondary"
									onClick={ () => handleAction( 'ping' ) }
									disabled={ isActionLoading }
								>
									<FontAwesomeIcon icon={ faNetworkWired } />
									{ isActionLoading
										? '...'
										: translations.testConnection ||
										  'Test Connectivity' }
								</button>
								<button
									type="button"
									className="wppo-button wppo-button-primary"
									onClick={ handleSubmit }
									disabled={ isLoading }
								>
									<FontAwesomeIcon icon={ faCheckCircle } />
									{ isLoading
										? '...'
										: translations.saveSettings ||
										  'Save Changes' }
								</button>
							</div>
						</div>
					</div>
				</div>

				{ /* Performance Section */ }
				<div className="wppo-dashboard-column">
					<div className="feature-card" style={ { height: '100%' } }>
						<h3>
							<FontAwesomeIcon icon={ faShieldAlt } />
							{ translations.enterpriseOptions ||
								'Enterprise Performance' }
						</h3>

						<div style={ { marginTop: '20px' } }>
							<div
								className="setting-group"
								style={ { marginBottom: '24px' } }
							>
								<label className="field-label">
									{ translations.compressionAlgorithm ||
										'Memory Compression' }
								</label>
								<select
									className="input-field"
									name="compression"
									value={ settings.compression }
									onChange={ handleChange( setSettings ) }
								>
									<option value="none">
										{ translations.none ||
											'None (Fastest)' }
									</option>
									<option
										value="lzf"
										disabled={
											cacheStatus.supported_compressors &&
											! cacheStatus.supported_compressors
												.lzf
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
											! cacheStatus.supported_compressors
												.zstd
										}
									>
										ZSTD{ ' ' }
										{ cacheStatus.supported_compressors &&
										! cacheStatus.supported_compressors.zstd
											? '(Disabled)'
											: ' (Recommended)' }
									</option>
									<option
										value="lz4"
										disabled={
											cacheStatus.supported_compressors &&
											! cacheStatus.supported_compressors
												.lz4
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
									className="field-desc"
									style={ { marginTop: '8px' } }
								>
									{ translations.compressionDesc ||
										'Significantly reduces memory footprint for enterprise-size caches.' }
								</p>
							</div>

							<div className="checkbox-options-list">
								<div
									className="checkbox-option"
									style={ { marginBottom: '20px' } }
								>
									<label className="wppo-switch">
										<input
											type="checkbox"
											name="persistent"
											checked={ settings.persistent }
											onChange={ handleChange(
												setSettings
											) }
										/>
										<span className="wppo-slider"></span>
									</label>
									<div className="checkbox-info">
										<strong>
											{ translations.persistentConnection ||
												'Persistent Connections' }
										</strong>
										<p className="field-desc">
											Keep connections alive between PHP
											requests for lower latency.
										</p>
									</div>
								</div>

								<div className="checkbox-option">
									<label className="wppo-switch">
										<input
											type="checkbox"
											name="use_tls"
											checked={ settings.use_tls }
											onChange={ handleChange(
												setSettings
											) }
										/>
										<span className="wppo-slider"></span>
									</label>
									<div className="checkbox-info">
										<strong>
											{ translations.enableTls ||
												'TLS / SSL Encryption' }
										</strong>
										<p className="field-desc">
											Encrypt all traffic between
											WordPress and Redis nodes.
										</p>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			{ actionMsg && (
				<div
					className={ `wppo-footer-notice wppo-footer-notice--${ actionMsg.type }` }
					style={ { marginTop: '24px' } }
				>
					<FontAwesomeIcon
						icon={
							actionMsg.type === 'success'
								? faCheckCircle
								: faExclamationCircle
						}
					/>
					{ actionMsg.text }
				</div>
			) }
		</div>
	);
};

export default ObjectCache;
