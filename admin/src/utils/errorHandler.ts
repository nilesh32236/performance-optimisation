/**
 * Comprehensive error handling utilities
 */

export interface ErrorDetails {
	message: string;
	code?: string;
	context?: Record<string, any>;
	userMessage?: string;
}

export class WppoError extends Error {
	public code?: string;
	public context?: Record<string, any>;
	public userMessage?: string;

	constructor(details: ErrorDetails) {
		super(details.message);
		this.name = 'WppoError';
		this.code = details.code;
		this.context = details.context;
		this.userMessage = details.userMessage || details.message;
	}
}

/**
 * Handle API errors with user-friendly messages
 */
export const handleApiError = (error: any): string => {
	if (error instanceof WppoError) {
		return error.userMessage || error.message;
	}

	if (error?.response?.status) {
		switch (error.response.status) {
			case 401:
				return 'Authentication failed. Please refresh the page and try again.';
			case 403:
				return 'You do not have permission to perform this action.';
			case 404:
				return 'The requested resource was not found.';
			case 429:
				return 'Too many requests. Please wait a moment and try again.';
			case 500:
				return 'Server error occurred. Please try again later.';
			default:
				return 'An unexpected error occurred. Please try again.';
		}
	}

	if (error?.message) {
		// Don't expose technical error messages to users
		console.error('Technical error:', error.message);
		return 'An error occurred while processing your request.';
	}

	return 'An unexpected error occurred. Please try again.';
};

/**
 * Log errors for debugging
 */
export const logError = (error: any, context?: Record<string, any>) => {
	const errorData = {
		message: error?.message || 'Unknown error',
		stack: error?.stack,
		context,
		timestamp: new Date().toISOString(),
		url: window.location.href,
		userAgent: navigator.userAgent
	};

	console.error('WPPO Error:', errorData);

	// Send to backend for logging (optional)
	if (window.wppoAdmin?.debug) {
		try {
			fetch('/wp-json/performance-optimisation/v1/log-error', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.wppoAdmin.nonce
				},
				body: JSON.stringify(errorData)
			}).catch(() => {
				// Silently fail if logging endpoint is not available
			});
		} catch (e) {
			// Silently fail
		}
	}
};
