/**
 * External dependencies
 */
const { defineConfig } = require( 'cypress' );

module.exports = defineConfig( {
	e2e: {
		baseUrl: 'http://localhost:8080',
		supportFile: 'tests/e2e/support/e2e.js',
		specPattern: 'tests/e2e/specs/**/*.cy.js',
		fixturesFolder: 'tests/e2e/fixtures',
		screenshotsFolder: 'tests/e2e/screenshots',
		videosFolder: 'tests/e2e/videos',
		viewportWidth: 1280,
		viewportHeight: 720,
		video: true,
		screenshotOnRunFailure: true,
		defaultCommandTimeout: 10000,
		requestTimeout: 10000,
		responseTimeout: 10000,
		env: {
			adminUsername: 'admin',
			adminPassword: 'password',
			pluginPath: '/wp-admin/admin.php?page=performance-optimisation',
			wizardPath: '/wp-admin/admin.php?page=performance-optimisation-setup',
		},
		setupNodeEvents( on, config ) {
			// implement node event listeners here
			on( 'task', {
				log( message ) {
					console.log( message );
					return null;
				},
			} );
		},
	},
} );
