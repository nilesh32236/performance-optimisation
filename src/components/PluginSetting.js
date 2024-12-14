import React, { useState, useRef } from 'react';
import { apiCall } from '../lib/apiRequest';

const PluginSetting = ({ options }) => {
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
			setNotification({ message: 'Please select a file.', success: false });
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
							qtpoSettings.settings = fileData; // Update global settings
							resetFileInput();
						}

						setNotification({
							message: data.message || 'File imported successfully.',
							success: data.success,
						});
					})
					.catch((error) => {
						console.error('Error importing settings:', error);
						setNotification({ message: 'An error occurred during import.', success: false });
					});
			} catch (error) {
				console.error('Invalid JSON file:', error);
				setNotification({ message: 'Invalid file format. Please select a valid JSON file.', success: false });
			}
		};

		reader.readAsText(selectedFile);
	};

	return (
		<div className='settings-form'>
			<h2>Tools</h2>

			{/* Export Settings */}
			<button className="submit-button" onClick={exportSettings}>
				Export Settings
			</button>
			<p>Export performance optimization plugin settings.</p>

			{/* File Input for Import */}
			<input
				type="file"
				accept="application/json"
				onChange={handleFileSelection}
				ref={fileInputRef}
			/>

			{/* Import Settings */}
			<button
				onClick={importSettings}
				className="submit-button"
				disabled={!selectedFile}
			>
				Import Settings
			</button>

			<p>Import performance optimization plugin settings.</p>

			{/* Notification Message */}
			{notification.message && (
				<div style={{ color: notification.success ? 'green' : 'red', marginTop: '10px' }}>
					{notification.message}
				</div>
			)}
		</div>
	);
};

export default PluginSetting;
