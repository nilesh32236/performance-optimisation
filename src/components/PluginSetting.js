import { useState, useRef } from '@wordpress/element';
import { apiCall, fetchRecentActivities } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faFileExport,
	faFileImport,
	faCheckCircle,
	faExclamationCircle,
	faHistory,
} from '@fortawesome/free-solid-svg-icons';
import ConfirmDialog from './common/ConfirmDialog';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';

const PluginSetting = ( { options } ) => {
	const translations = wppoSettings.translations;

	const [ selectedFile, setSelectedFile ] = useState( null );
	const [ isImporting, setIsImporting ] = useState( false );
	const [ notification, setNotification ] = useState( {
		message: '',
		success: false,
	} );
	const [ confirmImport, setConfirmImport ] = useState( false );
	const fileInputRef = useRef( null );

	// Activity log state
	const [ logEntries, setLogEntries ] = useState( [] );
	const [ logLoading, setLogLoading ] = useState( false );
	const [ logLoaded, setLogLoaded ] = useState( false );
	const [ logPage, setLogPage ] = useState( 1 );
	const [ logTotalPages, setLogTotalPages ] = useState( 1 );

	const getTimestamp = () => {
		return new Date()
			.toISOString()
			.replace( /[:T]/g, '-' )
			.split( '.' )[ 0 ];
	};

	const loadActivityLog = async ( page = 1 ) => {
		setLogLoading( true );
		try {
			const data = await fetchRecentActivities( page );
			if ( data?.activities ) {
				setLogEntries( data.activities );
				setLogPage( data.current_page || 1 );
				setLogTotalPages( data.total_pages || 1 );
				setLogLoaded( true );
			}
		} catch ( err ) {
			console.error( 'Failed to load activity log:', err );
		} finally {
			setLogLoading( false );
		}
	};

	const exportSettings = () => {
		const blob = new Blob( [ JSON.stringify( options, null, 2 ) ], {
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
				message:
					translations.selectFiles || 'Please select a file first.',
				success: false,
			} );
			return;
		}

		setIsImporting( true );

		const reader = new FileReader();

		reader.onerror = () => {
			setNotification( {
				message: translations.fileErrorImport || 'Error reading file',
				success: false,
			} );
			setIsImporting( false );
			resetFileInput();
		};

		reader.onabort = () => {
			setNotification( {
				message: translations.fileErrorImport || 'File read aborted',
				success: false,
			} );
			setIsImporting( false );
			resetFileInput();
		};

		reader.onload = ( e ) => {
			try {
				const fileData = JSON.parse( e.target.result );
				apiCall( 'import_settings', {
					action: 'import_settings',
					settings: fileData,
				} )
					.then( ( data ) => {
						if ( data.success ) {
							wppoSettings.settings = fileData;
							resetFileInput();
						}
						setNotification( {
							message:
								data.message ||
								( data.success
									? translations.fileImported ||
									  'Settings imported successfully.'
									: translations.importFailed ||
									  'Failed to import settings.' ),
							success: data.success,
						} );
					} )
					.catch( () => {
						setNotification( {
							message:
								translations.fileErrorImport ||
								'Error importing settings.',
							success: false,
						} );
					} )
					.finally( () => {
						setIsImporting( false );
					} );
			} catch ( _error ) {
				setNotification( {
					message:
						translations.invalidFileFormat ||
						'Invalid file format.',
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
				title="Tools"
				description="Manage your plugin configuration, view the full optimization activity log, and import or export settings."
			/>

			{ notification.message && (
				<div
					className={ `wppo-notice wppo-notice--${
						notification.success ? 'success' : 'error'
					}` }
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
					title="Optimization Activity Log"
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
									← Previous
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
									Next →
								</button>
							</div>
						) : null
					}
				>
					{ ! logLoaded && ! logLoading && (
						<div className="wppo-log-trigger">
							<p className="wppo-text-muted">
								A full timestamped record of every cache clear,
								image optimization, database cleanup, and
								settings change performed by the plugin.
							</p>
							<button
								type="button"
								className="wppo-button wppo-button--secondary"
								onClick={ () => loadActivityLog( 1 ) }
							>
								<FontAwesomeIcon icon={ faHistory } />
								Load Activity Log
							</button>
						</div>
					) }

					{ logLoading && (
						<p className="wppo-text-muted">Loading log…</p>
					) }

					{ logLoaded && (
						<>
							{ logEntries.length > 0 ? (
								<ul className="wppo-activity-list wppo-activity-list--full">
									{ logEntries.map( ( entry, i ) => (
										<li key={ i }>
											<div
												className="wppo-activity-text"
												// Activity text may contain safe anchor tags
												// (logged by class-log.php with wp_kses).
												// eslint-disable-next-line react/no-danger
												dangerouslySetInnerHTML={ {
													__html: entry.activity,
												} }
											/>
										</li>
									) ) }
								</ul>
							) : (
								<div className="wppo-empty-state">
									No activity recorded yet.
								</div>
							) }
						</>
					) }
				</FeatureCard>

				{ /* Export */ }
				<FeatureCard
					title="Export Configuration"
					icon={ <FontAwesomeIcon icon={ faFileExport } /> }
				>
					<p
						className="wppo-text-muted"
						style={ { marginBottom: '24px' } }
					>
						Download your current plugin settings as a JSON file for
						backup or migration to another site.
					</p>
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						onClick={ exportSettings }
						label="Export Settings"
					/>
				</FeatureCard>

				{ /* Import */ }
				<FeatureCard
					title="Import Configuration"
					icon={ <FontAwesomeIcon icon={ faFileImport } /> }
				>
					<p className="wppo-text-muted">
						Upload a previously exported settings file to restore
						your configuration. This will overwrite all current
						settings.
					</p>
					<div className="wppo-field wppo-mt-24">
						<label
							className="wppo-field-label"
							htmlFor="import-config"
						>
							Select configuration file
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
						label="Import Settings"
						loadingLabel="Importing..."
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
				title="Confirm Import"
				message="Importing this file will overwrite all current plugin settings. Continue?"
				confirmLabel="Confirm"
				variant="warning"
			/>
		</div>
	);
};

export default PluginSetting;
