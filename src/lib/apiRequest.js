// Utility function to handle API calls
export const apiCall = (action, method, body) => {
	return fetch(qtpoSettings.apiUrl + action, {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': qtpoSettings.nonce
		},
		body: JSON.stringify(body)
	}).then((response) => response.json());
};

export const fetchRecentActivities = () => {
	return fetch(qtpoSettings.apiUrl + 'recent_activities', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': qtpoSettings.nonce
		},
		body: JSON.stringify({ page: '1' })
	})
		.then(response => response.json())
		.catch(error => {
			console.error('Error fetching recent activities:', error);
			throw error; // Re-throw the error for further handling if needed
		});
};
