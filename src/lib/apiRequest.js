// Utility function to handle API calls
export const apiCall = (action, body) => {
	return fetch(wppoSettings.apiUrl + action, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': wppoSettings.nonce
		},
		body: JSON.stringify(body)
	}).then(async (response) => {
		const data = await response.json();
		if ('update_settings' === action && data.success) {
			wppoSettings.settings = data.data;
		}
		return data;
	});
};

export const fetchRecentActivities = () => {
	return fetch(wppoSettings.apiUrl + 'recent_activities', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': wppoSettings.nonce
		},
		body: JSON.stringify({ page: '1' })
	})
		.then(response => response.json())
		.catch(error => {
			console.error('Error fetching recent activities:', error);
			throw error; // Re-throw the error for further handling if needed
		});
};
