module.exports = {
	testEnvironment: 'jsdom',
	setupFilesAfterEnv: [ '<rootDir>/tests/setup.js' ],
	moduleNameMapping: {
		'\\.(css|less|scss|sass)$': 'identity-obj-proxy',
	},
	testMatch: [ '<rootDir>/tests/**/*.test.js' ],
	collectCoverageFrom: [
		'src/**/*.{js,jsx}',
		'!src/**/*.test.{js,jsx}',
		'!src/index.js',
		'!src/wizard.js',
	],
	coverageThreshold: {
		global: {
			branches: 80,
			functions: 80,
			lines: 80,
			statements: 80,
		},
	},
	transform: {
		'^.+\\.(js|jsx)$': 'babel-jest',
	},
	moduleFileExtensions: [ 'js', 'jsx', 'json' ],
	testPathIgnorePatterns: [ '/node_modules/', '/build/' ],
};
