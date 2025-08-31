// ***********************************************
// This example commands.js shows you how to
// create various custom commands and overwrite
// existing commands.
//
// For more comprehensive examples of custom
// commands please read more here:
// https://on.cypress.io/custom-commands
// ***********************************************

/**
 * Login to WordPress admin
 */
Cypress.Commands.add( 'loginToWordPress', ( username, password ) => {
	const user = username || Cypress.env( 'adminUsername' );
	const pass = password || Cypress.env( 'adminPassword' );

	cy.visit( '/wp-admin' );

	// Check if already logged in
	cy.get( 'body' ).then( ( $body ) => {
		if ( $body.find( '#wpadminbar' ).length === 0 ) {
			// Not logged in, perform login
			cy.get( '#user_login' ).clear().type( user );
			cy.get( '#user_pass' ).clear().type( pass );
			cy.get( '#wp-submit' ).click();

			// Wait for dashboard to load
			cy.url().should( 'include', '/wp-admin' );
			cy.get( '#wpadminbar' ).should( 'be.visible' );
		}
	} );
} );

/**
 * Navigate to plugin page
 */
Cypress.Commands.add( 'visitPluginPage', () => {
	cy.loginToWordPress();
	cy.visit( Cypress.env( 'pluginPath' ) );
	cy.get( '.wppo-dashboard, .wppo-app' ).should( 'be.visible' );
} );

/**
 * Navigate to setup wizard
 */
Cypress.Commands.add( 'visitSetupWizard', () => {
	cy.loginToWordPress();
	cy.visit( Cypress.env( 'wizardPath' ) );
	cy.get( '.wppo-wizard, .setup-wizard' ).should( 'be.visible' );
} );

/**
 * Wait for API request to complete
 */
Cypress.Commands.add( 'waitForAPI', ( alias ) => {
	cy.wait( alias ).then( ( interception ) => {
		expect( interception.response.statusCode ).to.be.oneOf( [ 200, 201 ] );
	} );
} );

/**
 * Check if element contains text (case insensitive)
 */
Cypress.Commands.add( 'shouldContainText', { prevSubject: true }, ( subject, text ) => {
	cy.wrap( subject ).should( ( $el ) => {
		const elementText = $el.text().toLowerCase();
		const searchText = text.toLowerCase();
		expect( elementText ).to.include( searchText );
	} );
} );

/**
 * Wait for loading spinner to disappear
 */
Cypress.Commands.add( 'waitForLoading', () => {
	cy.get( '.wppo-loading-spinner, .loading-spinner, .spinner' ).should( 'not.exist' );
} );

/**
 * Select preset in wizard
 */
Cypress.Commands.add( 'selectPreset', ( presetName ) => {
	cy.get( `[data-preset="${ presetName }"], .preset-card` ).contains( presetName ).click();
	cy.get( `[data-preset="${ presetName }"]` ).should( 'have.class', 'selected' );
} );

/**
 * Complete wizard setup
 */
Cypress.Commands.add( 'completeWizardSetup', ( preset = 'recommended' ) => {
	cy.visitSetupWizard();

	// Step 1: Site Analysis
	cy.get( '.wizard-step-1, [data-step="1"]' ).should( 'be.visible' );
	cy.get( '.analyze-button, .next-button' ).click();
	cy.waitForLoading();

	// Step 2: Select Preset
	cy.get( '.wizard-step-2, [data-step="2"]' ).should( 'be.visible' );
	cy.selectPreset( preset );
	cy.get( '.next-button' ).click();

	// Step 3: Configure Features
	cy.get( '.wizard-step-3, [data-step="3"]' ).should( 'be.visible' );
	cy.get( '.next-button, .finish-button' ).click();

	// Wait for completion
	cy.waitForLoading();
	cy.get( '.wizard-complete, .success-message' ).should( 'be.visible' );
} );

/**
 * Clear all caches
 */
Cypress.Commands.add( 'clearCaches', () => {
	cy.intercept( 'POST', '**/cache/clear' ).as( 'clearCache' );
	cy.get( '.clear-cache-button, [data-action="clear-cache"]' ).click();
	cy.waitForAPI( '@clearCache' );
	cy.get( '.success-message, .notice-success' ).should( 'be.visible' );
} );

/**
 * Enable optimization feature
 */
Cypress.Commands.add( 'enableOptimization', ( feature ) => {
	cy.intercept( 'PUT', '**/settings' ).as( 'updateSettings' );

	cy.get( `[data-feature="${ feature }"] input[type="checkbox"], .${ feature }-toggle` ).then(
		( $checkbox ) => {
			if ( ! $checkbox.is( ':checked' ) ) {
				cy.wrap( $checkbox ).check();
			}
		}
	);

	cy.get( '.save-settings, .update-button' ).click();
	cy.waitForAPI( '@updateSettings' );
} );

/**
 * Check analytics dashboard
 */
Cypress.Commands.add( 'checkAnalyticsDashboard', () => {
	cy.get( '.wppo-analytics-dashboard' ).should( 'be.visible' );
	cy.get( '.wppo-metrics-overview' ).should( 'be.visible' );
	cy.get( '.wppo-chart-container' ).should( 'have.length.at.least', 1 );
	cy.get( '.wppo-recommendations-list, .wppo-recommendations-empty' ).should( 'be.visible' );
} );

/**
 * Apply recommendation
 */
Cypress.Commands.add( 'applyRecommendation', ( recommendationId ) => {
	cy.intercept( 'POST', '**/recommendations/apply' ).as( 'applyRecommendation' );

	cy.get(
		`[data-recommendation="${ recommendationId }"] .apply-button, .recommendation-item .apply-fix`
	).click();
	cy.waitForAPI( '@applyRecommendation' );
	cy.get( '.success-message' ).should( 'be.visible' );
} );

/**
 * Check performance metrics
 */
Cypress.Commands.add( 'checkPerformanceMetrics', () => {
	cy.get( '.wppo-metric-card' ).should( 'have.length.at.least', 3 );
	cy.get( '.wppo-metric-value' ).each( ( $metric ) => {
		cy.wrap( $metric ).should( 'not.be.empty' );
	} );
} );

/**
 * Export analytics data
 */
Cypress.Commands.add( 'exportAnalytics', ( format = 'json' ) => {
	cy.intercept( 'GET', `**/analytics/export?format=${ format }` ).as( 'exportData' );

	cy.get( `.export-${ format }, [data-export="${ format }"]` ).click();
	cy.waitForAPI( '@exportData' );
} );

/**
 * Optimize images
 */
Cypress.Commands.add( 'optimizeImages', () => {
	cy.intercept( 'POST', '**/optimization/images' ).as( 'optimizeImages' );

	cy.get( '.optimize-images-button, [data-action="optimize-images"]' ).click();
	cy.waitForAPI( '@optimizeImages' );
	cy.get( '.success-message, .optimization-started' ).should( 'be.visible' );
} );

/**
 * Check optimization status
 */
Cypress.Commands.add( 'checkOptimizationStatus', () => {
	cy.get( '.wppo-optimization-status' ).should( 'be.visible' );
	cy.get( '.wppo-feature-item' ).should( 'have.length.at.least', 1 );
	cy.get( '.wppo-optimization-score' ).should( 'be.visible' );
} );

/**
 * Reset wizard
 */
Cypress.Commands.add( 'resetWizard', () => {
	cy.intercept( 'POST', '**/wizard/reset' ).as( 'resetWizard' );

	cy.get( '.reset-wizard-button, [data-action="reset-wizard"]' ).click();
	cy.get( '.confirm-reset, .confirm-button' ).click();
	cy.waitForAPI( '@resetWizard' );
} );

/**
 * Check for JavaScript errors
 */
Cypress.Commands.add( 'checkForJSErrors', () => {
	cy.window().then( ( win ) => {
		const errors = win.console.error.calls?.all() || [];
		const jsErrors = errors.filter( ( call ) =>
			call.args.some(
				( arg ) =>
					typeof arg === 'string' &&
					( arg.includes( 'Error' ) ||
						arg.includes( 'TypeError' ) ||
						arg.includes( 'ReferenceError' ) )
			)
		);
		expect( jsErrors ).to.have.length( 0 );
	} );
} );

/**
 * Check accessibility
 */
Cypress.Commands.add( 'checkA11y', () => {
	// Basic accessibility checks
	cy.get( 'img' ).each( ( $img ) => {
		cy.wrap( $img ).should( 'have.attr', 'alt' );
	} );

	cy.get( 'button, input[type="submit"]' ).each( ( $btn ) => {
		cy.wrap( $btn ).should( 'not.have.attr', 'disabled' ).or( 'have.attr', 'aria-disabled' );
	} );

	cy.get( 'input, select, textarea' ).each( ( $input ) => {
		const id = $input.attr( 'id' );
		if ( id ) {
			cy.get( `label[for="${ id }"]` ).should( 'exist' );
		}
	} );
} );

/**
 * Mock API responses for testing
 */
Cypress.Commands.add( 'mockAPIResponses', () => {
	// Mock dashboard data
	cy.intercept( 'GET', '**/analytics/dashboard', {
		fixture: 'dashboard-data.json',
	} ).as( 'getDashboard' );

	// Mock metrics data
	cy.intercept( 'GET', '**/analytics/metrics*', {
		fixture: 'metrics-data.json',
	} ).as( 'getMetrics' );

	// Mock recommendations
	cy.intercept( 'GET', '**/recommendations', {
		fixture: 'recommendations-data.json',
	} ).as( 'getRecommendations' );
} );

/**
 * Take screenshot with timestamp
 */
Cypress.Commands.add( 'screenshotWithTimestamp', ( name ) => {
	const timestamp = new Date().toISOString().replace( /[:.]/g, '-' );
	cy.screenshot( `${ name }-${ timestamp }` );
} );
