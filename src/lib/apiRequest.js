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
