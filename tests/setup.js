/**
 * External dependencies
 */
import '@testing-library/jest-dom';

// Mock WordPress globals
global.wp = {
	i18n: {
		__: ( text ) => text,
		_x: ( text ) => text,
		_n: ( single, plural, number ) => ( number === 1 ? single : plural ),
	},
};

// Mock window.wppoWizardData
global.window.wppoWizardData = {
	apiUrl: 'http://test.com/wp-json/performance-optimisation/v1/',
	nonce: 'test-nonce',
	translations: {},
};

// Mock console methods to reduce noise in tests
global.console = {
	...console,
	log: jest.fn(),
	error: jest.fn(),
	warn: jest.fn(),
};

// Mock window.location
delete window.location;
window.location = {
	href: '',
	origin: 'http://test.com',
};
