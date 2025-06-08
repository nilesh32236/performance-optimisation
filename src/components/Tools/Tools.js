// src/components/Tools/Tools.js

import React, { useState, useRef } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTools, faFileExport, faFileImport, faSpinner, faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';

const Tools = ({
	settings, // Current settings from App.js, used for export
	translations,
	apiUrl,
	nonce,
	setIsLoading, // General loading state from App.js
	isLoading,
	// toast, // If using react-toastify
	// The App.js needs to be updated to pass the setSettings function if import should update App's state
	onSettingsImported, // Callback to update App.js settings state: (newSettings) => void
}) => {
	const [selectedFile, setSelectedFile] = useState(null);
	const [importError, setImportError] = useState('');
	const [importSuccess, setImportSuccess] = useState('');
	const fileInputRef = useRef(null);

	const handleExportSettings = () => {
		try {
			const settingsJson = JSON.stringify(settings, null, 2); // Pretty print JSON
			const blob = new Blob([settingsJson], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = 'performance_optimisation_settings.json';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
			// toast?.success(translations.settingsExported || 'Settings exported successfully!');
			console.log(translations.settingsExported || 'Settings exported successfully!');
		} catch (error) {
			// toast?.error(translations.errorExportingSettings || 'Error exporting settings.');
			console.error(translations.errorExportingSettings || 'Error exporting settings:', error);
		}
	};

	const handleFileChange = (event) => {
		const file = event.target.files[0];
		if (file && file.type === 'application/json') {
			setSelectedFile(file);
			setImportError('');
			setImportSuccess('');
		} else {
			setSelectedFile(null);
			setImportError(translations.invalidJsonFile || 'Invalid file type. Please select a JSON file.');
			// toast?.error(translations.invalidJsonFile || 'Invalid file type. Please select a JSON file.');
		}
	};

	const handleImportSettings = async () => {
		if (!selectedFile) {
			setImportError(translations.noFileSelected || 'No file selected for import.');
			// toast?.warn(translations.noFileSelected || 'No file selected for import.');
			return;
		}

		setIsLoading(true);
		setImportError('');
		setImportSuccess('');

		const reader = new FileReader();
		reader.onload = async (event) => {
			try {
				const fileContent = event.target.result;
				// Validate if it's JSON, though the REST API will do a more thorough check
				JSON.parse(fileContent); // This will throw if not valid JSON

				const response = await fetch(`${apiUrl}import-settings`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json', // Sending JSON directly
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify({ settings_json: fileContent }), // Send raw JSON string
				});

				const result = await response.json();

				if (result.success) {
					setImportSuccess(result.data.message || translations.settingsImported || 'Settings imported successfully!');
					// toast?.success(result.data.message || translations.settingsImported || 'Settings imported successfully!');
					if (result.data.new_settings && onSettingsImported) {
						onSettingsImported(result.data.new_settings); // Update App.js state
					}
					setSelectedFile(null); // Clear selected file
					if (fileInputRef.current) {
						fileInputRef.current.value = ''; // Reset file input
					}
				} else {
					setImportError(result.message || translations.errorImporting || 'Error importing settings.');
					// toast?.error(result.message || translations.errorImporting || 'Error importing settings.');
				}
			} catch (e) { // Catches JSON.parse error or other FileReader errors
				setImportError(translations.invalidJsonFileContent || 'File content is not valid JSON or an error occurred reading the file.');
				// toast?.error(translations.invalidJsonFileContent || 'File content is not valid JSON or an error occurred reading the file.');
				console.error("Error during file read or JSON parse for import:", e);
			} finally {
				setIsLoading(false);
			}
		};
		reader.onerror = () => {
			setImportError(translations.errorReadingFile || 'Error reading the selected file.');
			// toast?.error(translations.errorReadingFile || 'Error reading the selected file.');
			setIsLoading(false);
		};
		reader.readAsText(selectedFile);
	};

	return (
		<div className="wppo-settings-form wppo-tools-settings">
			<h2 className="wppo-section-title">
				<FontAwesomeIcon icon={faTools} style={{ marginRight: '10px' }} />
				{translations.tools || 'Tools'}
			</h2>
			<p className="wppo-section-description">
				{translations.toolsDesc || 'Manage plugin settings through import/export and access other utility tools.'}
			</p>

			{/* Export Settings */}
			<div className="wppo-form-section">
				<h3>
					<FontAwesomeIcon icon={faFileExport} style={{ marginRight: '8px' }} />
					{translations.exportSettings || 'Export Settings'}
				</h3>
				<p className="wppo-option-description" style={{ marginLeft: 0 }}>{translations.exportPluginSettings || 'Download all current Performance Optimisation plugin settings as a JSON file. This file can be used as a backup or to import settings into another site.'}</p>
				<button
					className="wppo-button"
					onClick={handleExportSettings}
					disabled={isLoading}
				>
					<FontAwesomeIcon icon={faFileExport} style={{ marginRight: '5px' }} />
					{translations.exportNow || 'Export Settings Now'}
				</button>
			</div>

			{/* Import Settings */}
			<div className="wppo-form-section">
				<h3>
					<FontAwesomeIcon icon={faFileImport} style={{ marginRight: '8px' }} />
					{translations.importSettings || 'Import Settings'}
				</h3>
				<p className="wppo-option-description" style={{ marginLeft: 0 }}>{translations.importPluginSettings || 'Import plugin settings from a previously exported JSON file. This will overwrite your current settings.'}</p>
				<div className="wppo-field-group">
					<label htmlFor="wppoImportFile" className="wppo-label" style={{ display: 'block', marginBottom: '10px' }}>
						{translations.selectJsonFileToImport || 'Select JSON File:'}
					</label>
					<input
						type="file"
						id="wppoImportFile"
						ref={fileInputRef}
						accept=".json,application/json"
						onChange={handleFileChange}
						style={{ display: 'block', marginBottom: '10px' }}
						disabled={isLoading}
					/>
					{selectedFile && <p style={{ fontSize: '0.9em', fontStyle: 'italic' }}>Selected: {selectedFile.name}</p>}
					<button
						className="wppo-button"
						onClick={handleImportSettings}
						disabled={!selectedFile || isLoading}
					>
						{isLoading && <FontAwesomeIcon icon={faSpinner} spin style={{ marginRight: '5px' }} />}
						<FontAwesomeIcon icon={faFileImport} style={{ marginRight: '5px' }} />
						{translations.importNow || 'Import Settings Now'}
					</button>
					{importError && <p className="wppo-notice wppo-notice--error" style={{ marginTop: '10px' }}>{importError}</p>}
					{importSuccess && <p className="wppo-notice wppo-notice--success" style={{ marginTop: '10px' }}>{importSuccess}</p>}
				</div>
				<p className="wppo-option-description wppo-warning-text" style={{ marginLeft: 0 }}>
					<FontAwesomeIcon icon={faExclamationTriangle} /> {translations.importWarning || 'Warning: Importing settings will overwrite all your current Performance Optimisation settings. It is recommended to export your current settings as a backup before importing.'}
				</p>
			</div>

			{/* Other tools could be added here, e.g., "Regenerate Critical CSS", "Clear Specific Caches" */}
		</div>
	);
};

export default Tools;