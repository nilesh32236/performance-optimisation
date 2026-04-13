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

const PluginSetting = ( { options } ) => {
	const translations = wppoSettings.translations;

	const [ selectedFile, setSelectedFile ] = useState( null );
	const [ isImporting, setIsImporting ] = useState( false );
	const [ notification, setNotification ] = useState( {
		message: '',
		success: false,
	} );
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
		setNotification( { message: '', success: false } );
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
		<div className="settings-form fadeIn">
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					marginBottom: '40px',
				} }
			>
				<h2 style={ { margin: 0 } }>
					<FontAwesomeIcon
						icon={ faTools }
						style={ {
							color: 'var(--wppo-primary)',
							marginRight: '12px',
						} }
					/>
					{ translations.tools }
				</h2>
			</div>

			<p
				style={ {
					fontSize: '16px',
					color: 'var(--wppo-text-muted)',
					marginBottom: '40px',
					maxWidth: '800px',
				} }
			>
				Manage your configuration by exporting your current settings or
				importing a previously saved configuration file.
			</p>

			<div className="dashboard-overview">
				{ /* Export Settings Card */ }
				<div className="wppo-card">
					<h3>
						<FontAwesomeIcon
							icon={ faFileExport }
							style={ { color: 'var(--wppo-primary)' } }
						/>{ ' ' }
						{ translations.exportSettings }
					</h3>
					<p style={ { marginBottom: '32px' } }>
						{ translations.exportPluginSettings }
					</p>
					<LoadingSubmitButton
						className="submit-button"
						style={ { width: '100%' } }
						onClick={ exportSettings }
						label={ translations.exportSettings }
					/>
				</div>

				{ /* Import Settings Card */ }
				<div className="wppo-card">
					<h3>
						<FontAwesomeIcon
							icon={ faFileImport }
							style={ { color: 'var(--wppo-primary)' } }
						/>{ ' ' }
						{ translations.importSettings }
					</h3>
					<p>{ translations.importPluginSettings }</p>
					<div
						className="import-field-wrapper"
						style={ { margin: '24px 0' } }
					>
						<input
							type="file"
							accept="application/json"
							onChange={ handleFileSelection }
							ref={ fileInputRef }
							className="input-field"
							style={ { padding: '12px' } }
							aria-label={ translations.selectFiles || 'Select configuration file' }
						/>
					</div>
					<LoadingSubmitButton
						className="submit-button secondary"
						style={ { width: '100%' } }
						onClick={ importSettings }
						disabled={ ! selectedFile || isImporting }
						isLoading={ isImporting }
						label={ translations.importSettings }
						loadingLabel={ translations.importing || 'Importing...' }
					/>
				</div>
			</div>

			{ notification.message && (
				<div
					className={ `db-notification db-notification--${
						notification.success ? 'success' : 'error'
					}` }
					style={ { marginTop: '40px' } }
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
		</div>
	);
};

export default PluginSetting;
