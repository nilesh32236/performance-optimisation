const wpPlugin = require( '@wordpress/eslint-plugin' );

const config = [
	...wpPlugin.configs.recommended,

	...wpPlugin.configs[ 'test-unit' ].map( ( c ) => ( {
		...c,
		files: [ '**/@(test|__tests__)/**/*.js', '**/?(*.)test.js' ],
	} ) ),

	{
		files: [ '**/*.js' ],
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
			},
		},
		rules: {
			'no-console': [ 'error', { allow: [ 'error', 'warn' ] } ],
		},
	},

	// setupFiles (like setupTests.js) run in Jest environment.
	{
		files: [ 'src/setupTests.js' ],
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
