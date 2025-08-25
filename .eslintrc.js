module.exports = {
	extends: [
		'@wordpress/eslint-config/recommended',
		'@wordpress/eslint-config/recommended-with-formatting',
	],
	env: {
		browser: true,
		es6: true,
		node: true,
	},
	parserOptions: {
		ecmaVersion: 2020,
		sourceType: 'module',
		ecmaFeatures: {
			jsx: true,
		},
	},
	settings: {
		react: {
			version: 'detect',
		},
	},
	rules: {
		// WordPress specific rules
		'@wordpress/no-unused-vars-before-return': 'error',
		'@wordpress/dependency-group': 'error',
		'@wordpress/react-no-unsafe-timeout': 'error',
		
		// General rules
		'no-console': 'warn',
		'no-debugger': 'error',
		'prefer-const': 'error',
		'no-var': 'error',
		
		// React specific rules
		'react/jsx-uses-react': 'error',
		'react/jsx-uses-vars': 'error',
		'react/prop-types': 'off', // We'll use TypeScript for prop validation
	},
	ignorePatterns: [
		'build/',
		'dist/',
		'vendor/',
		'node_modules/',
		'assets/js/*.js',
		'*.min.js',
	],
};