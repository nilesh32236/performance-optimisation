import React, { useState, useRef } from 'react';
import { apiCall } from '../lib/apiRequest';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faFileExport, faFileImport, faCheckCircle, faExclamationCircle } from '@fortawesome/free-solid-svg-icons';

const PluginSetting = ({ options }) => {
	const translations = wppoSettings.translations;

	const [selectedFile, setSelectedFile] = useState(null);
	const [notification, setNotification] = useState({ message: '', success: false });
	const fileInputRef = useRef(null);

	// Get timestamp for the export filename
	const getTimestamp = () => {
		return new Date().toISOString().replace(/[:T]/g, '-').split('.')[0];
	};

	const exportSettings = () => {
		const blob = new Blob([JSON.stringify(options, null, 2)], { type: 'application/json' });
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = `plugin-settings_${getTimestamp()}.json`;
		link.click();
		URL.revokeObjectURL(link.href);
	};

	const handleFileSelection = (event) => {
		const file = event.target.files[0];
		setSelectedFile(file || null);
		setNotification({ message: '', success: false });
	};

	const resetFileInput = () => {
		setSelectedFile(null);
		setNotification({ message: '', success: false });
		if (fileInputRef.current) fileInputRef.current.value = '';
	};

	const importSettings = () => {
		if (!selectedFile) {
			setNotification({ message: translations.selectFiles, success: false });
			return;
		}

		const reader = new FileReader();
		reader.onload = (e) => {
			try {
				const fileData = JSON.parse(e.target.result);

				apiCall('import_settings', {
					action: 'import_settings',
					settings: fileData
				})
					.then((data) => {
						if (data.success) {
							wppoSettings.settings = fileData; // Update global settings
							resetFileInput();
						}

						setNotification({
							message: data.message || translations.fileImported,
							success: data.success,
						});
					})
					.catch((error) => {
						console.error(translations.fileImporting, error);
						setNotification({ message: translations.fileErrorImport, success: false });
					});
			} catch (error) {
				console.error(translations.invalidJSON, error);
				setNotification({ message: translations.invalidFileFormat, success: false });
			}
		};

		reader.readAsText(selectedFile);
	};

	return (
		<div className='settings-form'>
			<h2>{translations.tools}</h2>

			<div className="dashboard-overview">
				{/* Export Settings Card */}
				<div className="dashboard-card">
					<h3>
						<FontAwesomeIcon icon={faFileExport} /> {translations.exportSettings}
					</h3>
					<p>{translations.exportPluginSettings}</p>
					<LoadingSubmitButton 
						onClick={exportSettings}
						label={translations.exportSettings}
					/>
				</div>

				{/* Import Settings Card */}
				<div className="dashboard-card">
					<h3>
						<FontAwesomeIcon icon={faFileImport} /> {translations.importSettings}
					</h3>
					<p>{translations.importPluginSettings}</p>
					<div className="import-field-wrapper" style={{ margin: '15px 0' }}>
						<input
							type="file"
							accept="application/json"
							onChange={handleFileSelection}
							ref={fileInputRef}
							className="input-field"
							style={{ margin: 0, width: '100%', maxWidth: 'none' }}
						/>
					</div>
					<LoadingSubmitButton
						onClick={importSettings}
						disabled={!selectedFile}
						label={translations.importSettings}
					/>
				</div>
			</div>

			{/* Notification Message */}
			{notification.message && (
				<div className={`db-notification db-notification--${notification.success ? 'success' : 'error'}`} style={{ marginTop: '20px' }}>
					<FontAwesomeIcon icon={notification.success ? faCheckCircle : faExclamationCircle} />
					<span>{notification.message}</span>
				</div>
			)}
		</div>
	);
};

export default PluginSetting;
