import { useState, useRef, useEffect } from '@wordpress/element';
import { apiCall, fetchRecentActivities } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faFileExport,
	faFileImport,
	faCheckCircle,
	faExclamationCircle,
	faHistory,
	faTachometerAlt,
} from '@fortawesome/free-solid-svg-icons';
import ConfirmDialog from './common/ConfirmDialog';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';

import { __ } from '@wordpress/i18n';

const ALLOWED_IMPORT_KEYS = [
	'file_optimisation',
	'preload_settings',
	'image_optimisation',
	'database_cleanup',
	'object_cache',
	'performance_audit',
	'core_tweaks',
	'cache_settings',
];

const validateImportData = ( data ) => {
	if ( ! data || typeof data !== 'object' || Array.isArray( data ) ) {
		return false;
	}
	const keys = Object.keys( data );
	if ( keys.length === 0 ) {
		return false;
	}
	return keys.every(
		( key ) =>
			ALLOWED_IMPORT_KEYS.includes( key ) &&
			typeof data[ key ] === 'object' &&
			data[ key ] !== null &&
			! Array.isArray( data[ key ] )
	);
};

const PluginSetting = ( { options } ) => {
	const [ selectedFile, setSelectedFile ] = useState( null );
	const [ isImporting, setIsImporting ] = useState( false );
	const [ notification, setNotification ] = useState( {
		message: '',
		success: false,
	} );
	const [ confirmImport, setConfirmImport ] = useState( false );
	const fileInputRef = useRef( null );
	const cancelledRef = useRef( false );

	useEffect( () => {
		return () => {
			cancelledRef.current = true;
		};
	}, [] );

	// Phase 2 — PageSpeed API key state.
	const [ pagespeedApiKey, setPagespeedApiKey ] = useState(
		options?.performance_audit?.pagespeed_api_key ?? ''
	);
	const [ savingApiKey, setSavingApiKey ] = useState( false );
	const [ apiKeyNotification, setApiKeyNotification ] = useState( {
		message: '',
		success: false,
	} );

	const saveApiKey = async () => {
		setSavingApiKey( true );
		setApiKeyNotification( { message: '', success: false } );
		try {
			const currentSettings =
				wppoSettings?.settings?.performance_audit ?? {};
			const response = await apiCall( 'update_settings', {
				tab: 'performance_audit',
				settings: {
					...currentSettings,
					pagespeed_api_key: pagespeedApiKey,
				},
			} );
			if ( response.success ) {
				setApiKeyNotification( {
					message: __( 'API key saved.', 'performance-optimisation' ),
					success: true,
				} );
			} else {
				setApiKeyNotification( {
					message:
						response.message ||
						__(
							'Failed to save API key.',
							'performance-optimisation'
						),
					success: false,
				} );
			}
		} catch ( err ) {
			setApiKeyNotification( {
				message: __(
					'Error saving API key.',
					'performance-optimisation'
				),
				success: false,
			} );
			console.error( 'Save API key error:', err );
		} finally {
			setSavingApiKey( false );
		}
	};

	// Activity log state
	const [ logEntries, setLogEntries ] = useState( [] );
	const [ logLoading, setLogLoading ] = useState( false );
	const [ logLoaded, setLogLoaded ] = useState( false );
	const [ logPage, setLogPage ] = useState( 1 );
	const [ logTotalPages, setLogTotalPages ] = useState( 1 );
	const [ logError, setLogError ] = useState( null );

	const getTimestamp = () => {
		return new Date()
			.toISOString()
			.replace( /[:T]/g, '-' )
			.split( '.' )[ 0 ];
	};

	const loadActivityLog = async ( page = 1 ) => {
		setLogLoading( true );
		setLogError( null );
		try {
			const data = await fetchRecentActivities( page );
			if ( data?.activities ) {
				setLogEntries( data.activities );
				setLogPage( data.current_page || 1 );
				setLogTotalPages( data.total_pages || 1 );
				setLogLoaded( true );
			}
		} catch ( err ) {
			setLogError( err.message || String( err ) );
			console.error( 'Failed to load activity log:', err );
		} finally {
			setLogLoading( false );
		}
	};

	const exportSettings = () => {
		// Security: redact sensitive API keys from export.
		const safeOptions = JSON.parse( JSON.stringify( options ) );
		if ( safeOptions.performance_audit?.pagespeed_api_key ) {
			safeOptions.performance_audit.pagespeed_api_key = 'REDACTED';
		}

		const blob = new Blob( [ JSON.stringify( safeOptions, null, 2 ) ], {
			type: 'application/json',
		} );
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL( blob );
		link.download = `plugin-settings_${ getTimestamp() }.json`;
		link.click();
		URL.revokeObjectURL( link.href );
	};

	const handleFileSelection = ( event ) => {
		const file = event.target.files[ 0 ];
		setSelectedFile( file || null );
		setNotification( { message: '', success: false } );
	};

	const resetFileInput = () => {
		setSelectedFile( null );
		if ( fileInputRef.current ) {
			fileInputRef.current.value = '';
		}
	};

	const importSettings = () => {
		if ( ! selectedFile ) {
			setNotification( {
				message: __(
					'Please select a file first.',
					'performance-optimisation'
				),
				success: false,
			} );
			return;
		}

		setIsImporting( true );

		const reader = new FileReader();

		reader.onerror = () => {
			if ( cancelledRef.current ) {
				return;
			}
			setNotification( {
				message: __( 'Error reading file', 'performance-optimisation' ),
				success: false,
			} );
			setIsImporting( false );
			resetFileInput();
		};

		reader.onabort = () => {
			if ( cancelledRef.current ) {
				return;
			}
			setNotification( {
				message: __( 'Error reading file', 'performance-optimisation' ),
				success: false,
			} );
			setIsImporting( false );
			resetFileInput();
		};

		reader.onload = ( e ) => {
			if ( cancelledRef.current ) {
				return;
			}
			try {
				const fileData = JSON.parse( e.target.result );

				if ( ! validateImportData( fileData ) ) {
					setNotification( {
						message: __(
							'Invalid settings file. The file must contain valid plugin settings.',
							'performance-optimisation'
						),
						success: false,
					} );
					setIsImporting( false );
					resetFileInput();
					return;
				}

				apiCall( 'import_settings', {
					action: 'import_settings',
					settings: fileData,
				} )
					.then( ( data ) => {
						if ( cancelledRef.current ) {
							return;
						}
						setNotification( {
							message:
								data.message ||
								( data.success
									? __(
											'File imported successfully',
											'performance-optimisation'
									  )
									: __(
											'Import failed',
											'performance-optimisation'
									  ) ),
							success: data.success,
						} );
						if ( data.success ) {
							resetFileInput();
						}
					} )
					.catch( () => {
						if ( cancelledRef.current ) {
							return;
						}
						setNotification( {
							message: __(
								'Error reading file',
								'performance-optimisation'
							),
							success: false,
						} );
					} )
					.finally( () => {
						if ( ! cancelledRef.current ) {
							setIsImporting( false );
						}
					} );
			} catch ( _error ) {
				if ( cancelledRef.current ) {
					return;
				}
				setNotification( {
					message: __(
						'Invalid file format. Please select a valid JSON file.',
						'performance-optimisation'
					),
					success: false,
				} );
				setIsImporting( false );
			}
		};
		reader.readAsText( selectedFile );
	};

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Tools', 'performance-optimisation' ) }
				description={ __(
					'Manage your plugin configuration, view the full optimization activity log, and import or export settings.',
					'performance-optimisation'
				) }
			/>

			{ notification.message && (
				<div
					className={ `wppo-notice wppo-notice--${
						notification.success ? 'success' : 'error'
					}` }
					role="alert"
					aria-live="polite"
				>
					<FontAwesomeIcon
						icon={
							notification.success
								? faCheckCircle
								: faExclamationCircle
						}
					/>
					<span>{ notification.message }</span>
				</div>
			) }

			<div className="wppo-stacked-cards">
				{ /* Activity Log */ }
				<FeatureCard
					title={ __(
						'Optimization Activity Log',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faHistory } /> }
					footer={
						logLoaded && logTotalPages > 1 ? (
							<div className="wppo-log-pagination">
								<button
									type="button"
									className="wppo-button wppo-button--secondary wppo-button--sm"
									disabled={ logPage <= 1 || logLoading }
									onClick={ () =>
										loadActivityLog( logPage - 1 )
									}
								>
									{ __(
										'← Previous',
										'performance-optimisation'
									) }
								</button>
								<span className="wppo-log-pagination__info">
									Page { logPage } of { logTotalPages }
								</span>
								<button
									type="button"
									className="wppo-button wppo-button--secondary wppo-button--sm"
									disabled={
										logPage >= logTotalPages || logLoading
									}
									onClick={ () =>
										loadActivityLog( logPage + 1 )
									}
								>
									{ __(
										'Next →',
										'performance-optimisation'
									) }
								</button>
							</div>
						) : null
					}
				>
					{ ! logLoaded && (
						<div className="wppo-log-trigger">
							<p className="wppo-text-muted">
								{ __(
									'A full timestamped record of every cache clear, image optimization, database cleanup, and settings change performed by the plugin.',
									'performance-optimisation'
								) }
							</p>
							<LoadingSubmitButton
								type="button"
								className="wppo-button wppo-button--secondary"
								onClick={ () => loadActivityLog( 1 ) }
								isLoading={ logLoading }
								loadingLabel={ __(
									'Loading log…',
									'performance-optimisation'
								) }
							>
								<FontAwesomeIcon icon={ faHistory } />
								{ __(
									'Load Activity Log',
									'performance-optimisation'
								) }
							</LoadingSubmitButton>
						</div>
					) }

					{ logError && (
						<div
							className="wppo-notice wppo-notice--error"
							role="alert"
							aria-live="assertive"
						>
							{ logError }
							<button
								type="button"
								className="wppo-button wppo-button--secondary wppo-button--sm"
								style={ { marginLeft: '12px' } }
								onClick={ () => loadActivityLog( logPage ) }
							>
								{ __( 'Retry', 'performance-optimisation' ) }
							</button>
						</div>
					) }

					{ logLoaded && (
						<>
							{ logEntries.length > 0 ? (
								<ul className="wppo-activity-list wppo-activity-list--full">
									{ logEntries.map( ( entry ) => (
										<li key={ entry.id }>
											<div className="wppo-activity-text">
												{ entry.activity }
											</div>
										</li>
									) ) }
								</ul>
							) : (
								<div className="wppo-empty-state">
									{ __(
										'No activity recorded yet.',
										'performance-optimisation'
									) }
								</div>
							) }
						</>
					) }
				</FeatureCard>

				{ /* Phase 2 — PageSpeed API Key (v1.6.0) */ }
				<FeatureCard
					title={ __(
						'Google PageSpeed API Key',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faTachometerAlt } /> }
				>
					<p
						id="pagespeed-api-key-desc"
						className="wppo-text-muted"
						style={ { marginBottom: '16px' } }
					>
						{ __(
							'Required to run PageSpeed Insights scans. Get a free key from Google Cloud Console.',
							'performance-optimisation'
						) }
					</p>

					{ apiKeyNotification.message && (
						<div
							className={ `wppo-notice wppo-notice--${
								apiKeyNotification.success ? 'success' : 'error'
							}` }
							style={ { marginBottom: '16px' } }
							role="alert"
							aria-live="polite"
						>
							<FontAwesomeIcon
								icon={
									apiKeyNotification.success
										? faCheckCircle
										: faExclamationCircle
								}
								style={ { marginRight: '8px' } }
							/>
							{ apiKeyNotification.message }
						</div>
					) }

					<div className="wppo-field">
						<label
							className="wppo-field-label"
							htmlFor="pagespeed-api-key"
						>
							{ __(
								'Google PageSpeed API Key',
								'performance-optimisation'
							) }
						</label>
						<input
							type="password"
							id="pagespeed-api-key"
							className="wppo-input"
							value={ pagespeedApiKey }
							onChange={ ( e ) =>
								setPagespeedApiKey( e.target.value )
							}
							placeholder="AIza..."
							autoComplete="off"
							aria-describedby="pagespeed-api-key-desc"
						/>
					</div>

					<LoadingSubmitButton
						className="wppo-button wppo-button--primary wppo-mt-16"
						onClick={ saveApiKey }
						isLoading={ savingApiKey }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					/>
				</FeatureCard>

				{ /* Export */ }
				<FeatureCard
					title={ __(
						'Export Configuration',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faFileExport } /> }
				>
					<p
						className="wppo-text-muted"
						style={ { marginBottom: '24px' } }
					>
						{ __(
							'Download your current plugin settings as a JSON file for backup or migration to another site.',
							'performance-optimisation'
						) }
					</p>
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						onClick={ exportSettings }
						label={ __(
							'Export Settings',
							'performance-optimisation'
						) }
					/>
				</FeatureCard>

				{ /* Import */ }
				<FeatureCard
					title={ __(
						'Import Configuration',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faFileImport } /> }
				>
					<p className="wppo-text-muted">
						{ __(
							'Upload a previously exported settings file to restore your configuration. This will overwrite all current settings.',
							'performance-optimisation'
						) }
					</p>
					<div className="wppo-field wppo-mt-24">
						<label
							className="wppo-field-label"
							htmlFor="import-config"
						>
							{ __(
								'Select configuration file',
								'performance-optimisation'
							) }
						</label>
						<input
							type="file"
							id="import-config"
							accept="application/json"
							onChange={ handleFileSelection }
							ref={ fileInputRef }
							className="wppo-input"
						/>
					</div>
					<LoadingSubmitButton
						className="wppo-button wppo-button--secondary wppo-mt-24"
						onClick={ () => {
							if ( selectedFile ) {
								setConfirmImport( true );
							}
						} }
						disabled={ ! selectedFile || isImporting }
						isLoading={ isImporting }
						label={ __(
							'Import Settings',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Importing…',
							'performance-optimisation'
						) }
					/>
				</FeatureCard>
			</div>

			<ConfirmDialog
				isOpen={ confirmImport }
				onConfirm={ () => {
					setConfirmImport( false );
					importSettings();
				} }
				onCancel={ () => setConfirmImport( false ) }
				title={ __( 'Confirm Import', 'performance-optimisation' ) }
				message={ __(
					'Importing this file will overwrite all current plugin settings. Continue?',
					'performance-optimisation'
				) }
				confirmLabel={ __( 'Confirm', 'performance-optimisation' ) }
				variant="warning"
			/>
		</div>
	);
};

export default PluginSetting;
