const wpPlugin = require( '@wordpress/eslint-plugin' );

const config = [
	...wpPlugin.configs.recommended,

	...wpPlugin.configs?.[ 'test-unit' ]?.map( ( c ) => ( {
		...c,
		files: [
			'**/@(test|__tests__)/**/*.{js,jsx}',
			'**/?(*.)test.{js,jsx}',
		],
	} ) ) ?? [],

	{
		files: [
			'**/@(test|__tests__)/**/*.{js,jsx}',
			'**/?(*.)test.{js,jsx}',
		],
		rules: {
			'no-console': [ 'error', { allow: [ 'error', 'warn' ] } ],
		},
	},

	{
		files: [ '**/*.{js,jsx}' ],
		ignores: [
			'**/@(test|__tests__)/**/*.{js,jsx}',
			'**/?(*.)test.{js,jsx}',
		],
		languageOptions: {
			globals: {
				wppoSettings: 'readonly',
				wppoObject: 'readonly',
				ScrollTrigger: 'readonly',
				jQuery: 'readonly',
				alert: 'readonly',
				FileReader: 'readonly',
				IntersectionObserver: 'readonly',
				MutationObserver: 'readonly',
				HTMLImageElement: 'readonly',
			},
		},
		rules: {
			'no-console': [ 'error', { allow: [ 'error', 'warn' ] } ],
		},
	},

	{
		files: [ 'src/setupTests.{js,jsx}' ],
		languageOptions: {
			globals: {
				jest: 'readonly',
				describe: 'readonly',
				it: 'readonly',
				expect: 'readonly',
				beforeEach: 'readonly',
				afterEach: 'readonly',
				beforeAll: 'readonly',
				afterAll: 'readonly',
			},
		},
	},
];

module.exports = config;
