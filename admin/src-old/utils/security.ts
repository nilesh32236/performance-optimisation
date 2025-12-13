/**
 * Security utilities for API calls and data validation
 */

interface ApiRequestOptions {
	method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
	data?: any;
	headers?: Record<string, string>;
}

/**
 * Secure API fetch with CSRF protection
 */
export const secureApiFetch = async (endpoint: string, options: ApiRequestOptions = {}) => {
	const { method = 'GET', data, headers = {} } = options;
	
	// Get nonce and API URL from WordPress localized data
	const nonce = window.wppoAdmin?.nonce;
	const apiUrl = window.wppoAdmin?.apiUrl;
	
	if (!nonce) {
		throw new Error('Security token not available');
	}

	if (!apiUrl) {
		throw new Error('API URL not available');
	}

	// Build full URL using WordPress provided API URL
	const fullUrl = `${apiUrl}/${endpoint}`;

	const requestHeaders = {
		'Content-Type': 'application/json',
		'X-WP-Nonce': nonce,
		...headers
	};

	const requestOptions: RequestInit = {
		method,
		headers: requestHeaders,
		credentials: 'same-origin'
	};

	if (data && method !== 'GET') {
		requestOptions.body = JSON.stringify(data);
	}

	const response = await fetch(fullUrl, requestOptions);
	
	if (!response.ok) {
		throw new Error(`API request failed: ${response.status}`);
	}

	return response.json();
};

/**
 * Sanitize user input
 */
export const sanitizeInput = (input: string): string => {
	return input
		.replace(/[<>]/g, '') // Remove potential HTML tags
		.trim()
		.substring(0, 1000); // Limit length
};

/**
 * Validate numeric input
 */
export const validateNumber = (value: any, min = 0, max = Number.MAX_SAFE_INTEGER): number => {
	const num = Number(value);
	if (isNaN(num)) throw new Error('Invalid number');
	if (num < min || num > max) throw new Error(`Number must be between ${min} and ${max}`);
	return num;
};
