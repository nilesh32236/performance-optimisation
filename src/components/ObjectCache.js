import { useState, useEffect } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faServer, faBroom, faLink } from '@fortawesome/free-solid-svg-icons';

const ObjectCache = ( { options = {} } ) => {
	const translations = wppoSettings.translations;

	const defaultSettings = {
		host: '127.0.0.1',
		port: 6379,
		password: '',
		database: 0,
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isActionLoading, setIsActionLoading ] = useState( false );
	const [ cacheStatus, setCacheStatus ] = useState( {
		enabled: false,
		redis_missing: false,
		foreign_dropin: false,
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
				host: settings.host,
				port: settings.port,
				password: settings.password,
				database: settings.database,
			};
			const res = await apiCall( 'object_cache', payload );

			if ( ! res?.success ) {
				setActionMsg( {
					type: 'error',
					text: res?.message || 'Action failed.',
				} );
				return;
			}

			// Re-fetch status if enabling or disabling
			if ( [ 'enable', 'disable' ].includes( action ) ) {
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

	return (
		<form onSubmit={ handleSubmit } className="settings-form fadeIn">
			<h2>{ translations.objectCache || 'Object Cache (Redis)' }</h2>

			{ cacheStatus.redis_missing && (
				<div
					className="wppo-notice wppo-notice-error"
					style={ { marginBottom: '20px' } }
				>
					<strong>
						{ translations.redisMissing ||
							'PhpRedis Extension Missing:' }
					</strong>{ ' ' }
					{ translations.redisMissingDesc ||
						'The high-performance PhpRedis PHP extension is not installed.' }
				</div>
			) }

			{ cacheStatus.foreign_dropin && (
				<div
					className="wppo-notice wppo-notice-warning"
					style={ { marginBottom: '20px' } }
				>
					<strong>
						{ translations.foreignDropin ||
							'Foreign Drop-in Detected:' }
					</strong>{ ' ' }
					{ translations.foreignDropinDesc ||
						'Another object cache plugin is currently active.' }
				</div>
			) }

			<div
				className="feature-card"
				style={ {
					display: 'flex',
					flexDirection: 'column',
					gap: '15px',
				} }
			>
				<h3>
					<FontAwesomeIcon icon={ faServer } />{ ' ' }
					{ translations.cacheStatus || 'Status' }
				</h3>
				<p style={ { margin: 0 } }>
					<strong>
						{ translations.currentState || 'Current State:' }
					</strong>
					{ cacheStatus.enabled ? (
						<span
							style={ {
								color: 'var(--wppo-success)',
								fontWeight: 'bold',
								marginLeft: '8px',
							} }
						>
							{ translations.enabled || 'Enabled' }
						</span>
					) : (
						<span
							style={ {
								color: 'var(--wppo-warning)',
								fontWeight: 'bold',
								marginLeft: '8px',
							} }
						>
							{ translations.disabled || 'Disabled' }
						</span>
					) }
				</p>
				<div style={ { display: 'flex', gap: '10px' } }>
					{ cacheStatus.enabled ? (
						<>
							<button
								type="button"
								className="submit-button danger"
								onClick={ () => handleAction( 'disable' ) }
								disabled={ isActionLoading }
							>
								{ isActionLoading
									? translations.disabling || 'Disabling...'
									: translations.disableObjectCache ||
									  'Disable Object Cache' }
							</button>
							<button
								type="button"
								className="submit-button secondary"
								onClick={ () => handleAction( 'flush' ) }
								disabled={ isActionLoading }
							>
								<FontAwesomeIcon icon={ faBroom } />{ ' ' }
								{ isActionLoading
									? translations.flushing || 'Flushing...'
									: translations.flushCache || 'Flush Cache' }
							</button>
						</>
					) : (
						<button
							type="button"
							className="submit-button"
							onClick={ () => handleAction( 'enable' ) }
							disabled={
								isActionLoading ||
								cacheStatus.redis_missing ||
								cacheStatus.foreign_dropin
							}
						>
							{ isActionLoading
								? translations.enabling || 'Enabling...'
								: translations.enableObjectCache ||
								  'Enable Object Cache' }
						</button>
					) }
				</div>
			</div>

			{ cacheStatus.telemetry && cacheStatus.enabled && (
				<div
					className="feature-card"
					style={ { marginBottom: '20px' } }
				>
					<h3>
						<FontAwesomeIcon icon={ faServer } />{ ' ' }
						{ translations.telemetryData || 'Live Telemetry' }
					</h3>
					<div
						style={ {
							display: 'grid',
							gridTemplateColumns: '1fr 1fr',
							gap: '15px',
							marginTop: '15px',
						} }
					>
						<div>
							<strong>Uptime:</strong>{ ' ' }
							{ cacheStatus.telemetry.uptime_in_days } days
						</div>
						<div>
							<strong>Connected Clients:</strong>{ ' ' }
							{ cacheStatus.telemetry.connected_clients }
						</div>
						<div>
							<strong>Memory Used:</strong>{ ' ' }
							{ cacheStatus.telemetry.used_memory_human }
						</div>
						<div>
							<strong>Peak Memory:</strong>{ ' ' }
							{ cacheStatus.telemetry.used_memory_peak_human }
						</div>
						<div>
							<strong>Total Connections:</strong>{ ' ' }
							{ cacheStatus.telemetry.total_connections_received }
						</div>
						<div>
							<strong>Keyspace Hits:</strong>{ ' ' }
							{ cacheStatus.telemetry.keyspace_hits }
						</div>
						<div>
							<strong>Keyspace Misses:</strong>{ ' ' }
							{ cacheStatus.telemetry.keyspace_misses }
						</div>
					</div>
				</div>
			) }

			<div className="feature-card">
				<h3>
					<FontAwesomeIcon icon={ faLink } />{ ' ' }
					{ translations.connectionSettings || 'Connection Settings' }
				</h3>
				<p>
					{ translations.connectionSettingsDesc ||
						'Configure your Redis server credentials here.' }
				</p>

				<div className="wppo-form-group">
					<label htmlFor="host">
						{ translations.redisHost || 'Redis Host' }
					</label>
					<input
						type="text"
						id="host"
						name="host"
						value={ settings.host }
						onChange={ handleChange( setSettings ) }
						style={ { width: '100%', maxWidth: '400px' } }
					/>
				</div>

				<div className="wppo-form-group">
					<label htmlFor="port">
						{ translations.redisPort || 'Redis Port' }
					</label>
					<input
						type="number"
						id="port"
						name="port"
						value={ settings.port }
						onChange={ handleChange( setSettings ) }
						style={ { width: '100%', maxWidth: '200px' } }
					/>
				</div>

				<div className="wppo-form-group">
					<label htmlFor="password">
						{ translations.redisPassword || 'Redis Password' }
					</label>
					<input
						type="password"
						id="password"
						name="password"
						value={ settings.password }
						onChange={ handleChange( setSettings ) }
						style={ { width: '100%', maxWidth: '400px' } }
					/>
				</div>

				<div
					className="wppo-form-group"
					style={ { marginBottom: '20px' } }
				>
					<label htmlFor="database">
						{ translations.redisDatabase || 'Redis Database ID' }
					</label>
					<input
						type="number"
						id="database"
						name="database"
						value={ settings.database }
						onChange={ handleChange( setSettings ) }
						style={ { width: '100%', maxWidth: '200px' } }
					/>
				</div>

				<button
					type="button"
					className="submit-button secondary"
					onClick={ () => handleAction( 'ping' ) }
					disabled={ isActionLoading }
				>
					{ isActionLoading
						? translations.testing || 'Testing...'
						: translations.testConnection || 'Test Connection' }
				</button>
			</div>

			{ actionMsg && (
				<div
					className={ `wppo-notice wppo-notice-${ actionMsg.type }` }
					style={ { marginTop: '20px' } }
				>
					<p>{ actionMsg.text }</p>
				</div>
			) }

			<div
				style={ {
					marginTop: '40px',
					display: 'flex',
					justifyContent: 'flex-end',
				} }
			>
				<LoadingSubmitButton
					isLoading={ isLoading }
					label={ translations.saveSettings }
					loadingLabel={ translations.saving }
				/>
			</div>
		</form>
	);
};

export default ObjectCache;
