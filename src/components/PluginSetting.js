import { useState, useRef } from '@wordpress/element';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faFileExport,
	faFileImport,
	faCheckCircle,
	faExclamationCircle,
	faTools,
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

	const getTimestamp = () => {
		return new Date()
			.toISOString()
			.replace( /[:T]/g, '-' )
			.split( '.' )[ 0 ];
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
				message: translations.selectFiles,
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
							message: data.message || translations.fileImported,
							success: data.success,
						} );
					} )
					.catch( () => {
						setNotification( {
							message: translations.fileErrorImport,
							success: false,
						} );
					} )
					.finally( () => {
						setIsImporting( false );
					} );
			} catch ( _error ) {
				setNotification( {
					message: translations.invalidFileFormat,
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
				description="Manage your configuration by exporting your current settings or importing a previously saved configuration file."
			/>

			{ notification.message && (
				<div className={ `wppo-notice wppo-notice--${ notification.success ? 'success' : 'error' }` }>
					<FontAwesomeIcon icon={ notification.success ? faCheckCircle : faExclamationCircle } />
					<span>{ notification.message }</span>
				</div>
			) }

			<div className="wppo-grid-2-col">
				<FeatureCard title="Export Configuration" icon={ <FontAwesomeIcon icon={ faFileExport } /> }>
					<p className="wppo-text-muted" style={ { marginBottom: '24px' } }>
						Download your current plugin settings as a JSON file for backup or migration.
					</p>
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						style={ { width: '100%' } }
						onClick={ exportSettings }
						label="Export Settings"
					/>
				</FeatureCard>

				<FeatureCard title="Import Configuration" icon={ <FontAwesomeIcon icon={ faFileImport } /> }>
					<p className="wppo-text-muted">
						Upload a previously exported settings file to restore your configuration.
					</p>
					<div className="wppo-field" style={ { margin: '20px 0' } }>
						<input
							type="file"
							accept="application/json"
							onChange={ handleFileSelection }
							ref={ fileInputRef }
							className="wppo-input"
							aria-label="Select configuration file"
						/>
					</div>
					<LoadingSubmitButton
						className="wppo-button wppo-button--secondary"
						style={ { width: '100%' } }
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
