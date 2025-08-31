module.exports = {
	extends: ['plugin:@wordpress/eslint-plugin/recommended'],
	settings: {
		'import/resolver': {
			typescript: {},
		},
	},
	globals: {
		wppoSettings: 'writable',
		wppoObject: 'writable',
	},
	parserOptions: {
		requireConfigFile: false,
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
		indent: 'off',

		// React specific rules
		'react/jsx-uses-react': 'error',
		'react/jsx-uses-vars': 'error',
		'react/prop-types': 'off', // We'll use TypeScript for prop validation
		'@typescript-eslint/no-explicit-any': 'off',
	},
	ignorePatterns: ['build/', 'dist/', 'vendor/', 'node_modules/', 'assets/js/*.js', '*.min.js', 'tests/', '*.config.js'],
};