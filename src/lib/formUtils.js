export const handleChange = (setSettings) => (e) => {
	// console.log( 'setSettings : ' , setSettings );

	const { name, type, value, checked } = e.target;
	setSettings((prevState) => ({
		...prevState,
		[name]: 'checkbox' === type ? checked : value,
	}));
};

export const handleSubmit = (settings, tabName = 'settings') => {
	return new Promise((resolve, reject) => {
		fetch(qtpoSettings.apiUrl + 'update_settings', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': qtpoSettings.nonce,
			},
			body: JSON.stringify({ tab: tabName, settings }),
		})
			.then((response) => response.json())
			.then((response) => {
				console.log('Settings updated:', response.data);
				qtpoSettings.settings = response.data;
				resolve(response);
			})
			.catch((error) => {
				console.error('Error updating settings:', error)
				reject(error);
			});
	})
};
