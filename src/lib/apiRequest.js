// Utility function to handle API calls
export const apiCall = (action, body, method = 'POST') => {
	const isGet = method === 'GET';
	return fetch(wppoSettings.apiUrl + action, {
		method,
		headers: {
			...( ! isGet && { 'Content-Type': 'application/json' }),
			'X-WP-Nonce': wppoSettings.nonce
		},
		...( ! isGet && { body: JSON.stringify(body) })
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
		method: 'GET',
		headers: {
			'X-WP-Nonce': wppoSettings.nonce
		},
	})
		.then(response => response.json())
		.catch(error => {
			console.error('Error fetching recent activities:', error);
			throw error; // Re-throw the error for further handling if needed
		});
};
