import React, { useState, useRef } from 'react';

const PluginSetting = ({ options }) => {
	const [selectedFile, setSelectedFile] = useState(null);
	const [notification, setNotification] = useState(''); 
	const [status, setStatus]             = useState(false);
	const fileInputRef                    = useRef(null);

	const getTimestamp = () => {
		const now = new Date();
		return now.toISOString().replace(/[:T]/g, '-').split('.')[0];
	};

	const exportSettings = () => {
		const jsonData = JSON.stringify(options, null, 2);
		const blob     = new Blob([jsonData], { type: 'application/json' });
		const link     = document.createElement('a');
		link.href      = URL.createObjectURL(blob);
		link.download  = `plugin-settings_${getTimestamp()}.json`;

		link.click();
		URL.revokeObjectURL(link.href);
	};

	const handleFileSelection = ( event ) => {
		const file = event.target.files[0];

		setSelectedFile(file || null);
		setNotification('');

	};

	const importSettings = () => {
		if (!selectedFile) {
			setStatus(false);
			setNotification('Please select the file.');
			return;
		}

		const reader = new FileReader();
		reader.onload = async (e) => {
			try {
				const fileData = JSON.parse( e.target.result );

				const response = await fetch(qtpoSettings.apiUrl + 'import_settings', {
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
					setSelectedFile(null);
					setStatus(true);
					setNotification(data.message  || 'Import the setting successfully.');
					if (fileInputRef.current) {
						fileInputRef.current.value = '';
					}

				} else {
					setStatus(true);
					setNotification(data.message || 'File already imported.');
				}
			} catch (error) {
				console.error('Error importing settings:', error);
				setStatus(false);
				setNotification('An error occurred during import.');
			}
		};

		reader.readAsText(selectedFile);
	}

	return (
		<div>
			<h2>Tools</h2>
			<button className='submit-button' onClick={exportSettings}>Export Settings</button>
			<p>Export performance optimization plugin settings.</p>

			<input
				type="file"
				accept="application/json"
				onChange={handleFileSelection}
				style={{ marginTop: '10px' }}
				ref={fileInputRef}
			/>

			<button
				onClick={importSettings}
				className="submit-button"
				style={{
					marginTop: '10px',
				}}
				disabled={!selectedFile}
			>
				Import Settings
			</button>

			<p>Import performance optimization plugin settings.</p>

			{notification && (
				<div style={{ marginTop: '15px', color: status ? 'green' : 'red' }}>
					{notification}
				</div>
			)}
		</div>
	);
};

export default PluginSetting;
