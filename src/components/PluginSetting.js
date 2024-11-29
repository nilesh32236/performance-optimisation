import React, { useState, useRef } from 'react';

const PluginSetting = ({ options }) => {
	const [selectedFile, setSelectedFile] = useState(null);
	const [notification, setNotification] = useState(''); 
	const fileInputRef                    = useRef(null);

	const getTimeRange = () => {
		const now     = new Date();
		const year    = now.getFullYear();
		const month   = String(now.getMonth() + 1).padStart(2, '0');
		const day     = String(now.getDate()).padStart(2, '0');
		const hours   = String(now.getHours()).padStart(2, '0');
		const minutes = String(now.getMinutes()).padStart(2, '0');
		const seconds = String(now.getSeconds()).padStart(2, '0');

		return `${year}-${month}-${day}_${hours}-${minutes}-${seconds}`;
	};

	const exportSettings = () => {
		const jsonData = JSON.stringify(options, null, 2);
		const blob     = new Blob([jsonData], { type: 'application/json' });
		const link     = document.createElement('a');
		link.href      = URL.createObjectURL(blob);
		link.download  = `plugin-settings_${getTimeRange()}.json`;

		link.click();
		URL.revokeObjectURL(link.href);
	};

	const handleFileSelection = ( event ) => {
		const file = event.target.files[0];

		if ( file ) {
			setSelectedFile(file);
			setNotification('');
		}
	};

	const importSettings = () => {
		if (!selectedFile) {
			setNotification('Please select the file.');
			return;
		}
		const reader = new FileReader();
		reader.onload = (e) => {
			try {
				const fileData = JSON.parse( e.target.result );

				fetch(qtpoSettings.apiUrl + 'import_settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': qtpoSettings.nonce,
					},
					body: JSON.stringify({
						action: 'import_settings',
						settings: fileData,
					}),
				})
					.then((response) => response.json())
					.then((data) => {
						if (data.success) {
							qtpoSettings.settings = fileData;
							setSelectedFile(null);
							setNotification('Import the setting successfully.');
							if (fileInputRef.current) {
								fileInputRef.current.value = '';
							}

						} else {
							setNotification('File already imported.');
						}
					})
					.catch((error) => {
						console.error('Error importing settings:', error);
					});
			} catch (error) {
				console.error('Invalid file format:', error);
			}
		};

		reader.readAsText(selectedFile);
	}

	return (
		<div>
			<h2>Tools</h2>
			<button className='submit-button' onClick={exportSettings}>Export Settings</button>
			<p>Export performance optimization plugin settings.</p>
			<br />
			<input
				type="file"
				accept="application/json"
				onChange={handleFileSelection}
				style={{ marginTop: '10px' }}
				ref={fileInputRef}
			/>
			<br />
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
				<div style={{ marginTop: '15px', color: notification === 'Import the setting successfully.' ? 'green' : 'red' }}>
					{notification}
				</div>
			)}
		</div>
	);
};

export default PluginSetting;
