import React, { useState, useRef } from 'react';

const PluginSetting = ({ options }) => {
	const [selectedFile, setSelectedFile] = useState(null);
	const [notification, setNotification] = useState('');
	const [status, setStatus] = useState(false);
	const fileInputRef = useRef(null);

	const getTimestamp = () => {
		const now = new Date();
		return now.toISOString().replace(/[:T]/g, '-').split('.')[0];
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
		setNotification('');
	};

	const resetFileInput = () => {
		setSelectedFile(null);
		setNotification('');
		setStatus(false);
		if (fileInputRef.current) fileInputRef.current.value = '';
	};

	const importSettings = () => {
		if (!selectedFile) {
			setNotification('Please select the file.');
			setStatus(false);
			return;
		}

		const reader = new FileReader();
		reader.onload = async (e) => {
			try {
				const fileData = JSON.parse(e.target.result);

				const response = await fetch(`${qtpoSettings.apiUrl}import_settings`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': qtpoSettings.nonce,
					},
					body: JSON.stringify({
						action: 'import_settings',
						settings: fileData,
					}),
				});

				const data = await response.json();

				if (data.success) {
					qtpoSettings.settings = fileData;
					resetFileInput();
				}

				setNotification(data.message || 'File imported successfully.');
				setStatus(data.success);

			} catch (error) {
				console.error('Error importing settings:', error);
				setNotification('An error occurred during import.');
				setStatus(false);
			}
		};

		reader.readAsText(selectedFile);
	};

	return (
		<div>
			<h2>Tools</h2>
			<button className="submit-button" onClick={exportSettings}>
				Export Settings
			</button>
			<p>Export performance optimization plugin settings.</p>

			<input
				type="file"
				accept="application/json"
				onChange={handleFileSelection}
				ref={fileInputRef}
			/>

			<button
				onClick={importSettings}
				className="submit-button"
				disabled={!selectedFile}
			>
				Import Settings
			</button>

			<p>Import performance optimization plugin settings.</p>

			{notification && (
				<div style={{ color: status ? 'green' : 'red', marginTop: '10px' }}>
					{notification}
				</div>
			)}
		</div>
	);
};

export default PluginSetting;
