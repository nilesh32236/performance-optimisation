/**
 * Testing utilities for React components
 */

export interface TestResult {
	passed: boolean;
	message: string;
	details?: any;
}

/**
 * Test API connectivity and authentication
 */
export const testApiConnection = async (): Promise<TestResult> => {
	try {
		const apiUrl = window.wppoAdmin?.apiUrl;
		if (!apiUrl) {
			throw new Error('API URL not available');
		}
		
		const response = await fetch(`${apiUrl}/test`, {
			headers: {
				'X-WP-Nonce': window.wppoAdmin?.nonce || ''
			}
		});
		
		if (response.ok) {
			return {
				passed: true,
				message: 'API connection successful'
			};
		} else {
			return {
				passed: false,
				message: `API connection failed: ${response.status}`,
				details: { status: response.status, statusText: response.statusText }
			};
		}
	} catch (error) {
		return {
			passed: false,
			message: 'API connection error',
			details: error
		};
	}
};

/**
 * Test component accessibility
 */
export const testAccessibility = (element: HTMLElement): TestResult[] => {
	const results: TestResult[] = [];
	
	// Check for missing alt text on images
	const images = element.querySelectorAll('img');
	images.forEach((img, index) => {
		if (!img.alt && !img.getAttribute('aria-hidden')) {
			results.push({
				passed: false,
				message: `Image ${index + 1} missing alt text`,
				details: { element: img }
			});
		}
	});
	
	// Check for form labels
	const inputs = element.querySelectorAll('input, select, textarea');
	inputs.forEach((input, index) => {
		const id = input.id;
		const label = id ? element.querySelector(`label[for="${id}"]`) : null;
		const ariaLabel = input.getAttribute('aria-label');
		
		if (!label && !ariaLabel) {
			results.push({
				passed: false,
				message: `Form control ${index + 1} missing label`,
				details: { element: input }
			});
		}
	});
	
	// Check for proper heading hierarchy
	const headings = element.querySelectorAll('h1, h2, h3, h4, h5, h6');
	let lastLevel = 0;
	headings.forEach((heading, index) => {
		const level = parseInt(heading.tagName.charAt(1));
		if (level > lastLevel + 1) {
			results.push({
				passed: false,
				message: `Heading level skip detected at heading ${index + 1}`,
				details: { element: heading, level, previousLevel: lastLevel }
			});
		}
		lastLevel = level;
	});
	
	return results;
};

/**
 * Test component performance
 */
export const testPerformance = (componentName: string, renderTime: number): TestResult => {
	const threshold = 100; // 100ms threshold
	
	if (renderTime > threshold) {
		return {
			passed: false,
			message: `${componentName} render time (${renderTime}ms) exceeds threshold (${threshold}ms)`,
			details: { renderTime, threshold }
		};
	}
	
	return {
		passed: true,
		message: `${componentName} render time acceptable (${renderTime}ms)`
	};
};

/**
 * Test security measures
 */
export const testSecurity = (): TestResult[] => {
	const results: TestResult[] = [];
	
	// Check for global variables exposure
	if (window.wppoAdmin) {
		if (!window.wppoAdmin.nonce) {
			results.push({
				passed: false,
				message: 'Security nonce not available'
			});
		}
		
		// Check for sensitive data exposure
		const sensitiveKeys = ['password', 'secret', 'key', 'token'];
		Object.keys(window.wppoAdmin).forEach(key => {
			if (sensitiveKeys.some(sensitive => key.toLowerCase().includes(sensitive))) {
				results.push({
					passed: false,
					message: `Potentially sensitive data exposed in global object: ${key}`
				});
			}
		});
	}
	
	// Check for XSS vulnerabilities (basic check)
	const dangerousElements = document.querySelectorAll('[onclick], [onload], [onerror]');
	if (dangerousElements.length > 0) {
		results.push({
			passed: false,
			message: `Found ${dangerousElements.length} elements with inline event handlers`,
			details: { count: dangerousElements.length }
		});
	}
	
	return results;
};

/**
 * Run comprehensive component tests
 */
export const runComponentTests = async (element: HTMLElement, componentName: string): Promise<{
	accessibility: TestResult[];
	security: TestResult[];
	api?: TestResult;
}> => {
	const results = {
		accessibility: testAccessibility(element),
		security: testSecurity()
	};
	
	// Only test API if component uses it
	if (componentName.includes('Tab') || componentName.includes('Dashboard')) {
		results.api = await testApiConnection();
	}
	
	return results;
};
