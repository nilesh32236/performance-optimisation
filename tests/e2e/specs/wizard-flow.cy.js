/**
 * E2E tests for Setup Wizard workflow
 */

describe( 'Setup Wizard Flow', () => {
	beforeEach( () => {
		cy.loginToWordPress();
	} );

	it( 'should complete the setup wizard successfully', () => {
		cy.visitSetupWizard();

		// Step 1: Welcome and Site Analysis
		cy.get( '.wizard-welcome' ).should( 'be.visible' );
		cy.get( '.wizard-step-indicator' ).should( 'contain', '1' );

		cy.get( '.start-analysis-button' ).click();
		cy.waitForLoading();

		// Verify analysis results are displayed
		cy.get( '.analysis-results' ).should( 'be.visible' );
		cy.get( '.site-characteristics' ).should( 'be.visible' );
		cy.get( '.next-step-button' ).click();

		// Step 2: Preset Selection
		cy.get( '.wizard-step-indicator' ).should( 'contain', '2' );
		cy.get( '.preset-selection' ).should( 'be.visible' );

		// Verify all presets are available
		cy.get( '.preset-card' ).should( 'have.length', 3 );
		cy.get( '[data-preset="standard"]' ).should( 'be.visible' );
		cy.get( '[data-preset="recommended"]' ).should( 'be.visible' );
		cy.get( '[data-preset="aggressive"]' ).should( 'be.visible' );

		// Select recommended preset
		cy.selectPreset( 'recommended' );
		cy.get( '.next-step-button' ).click();

		// Step 3: Feature Configuration
		cy.get( '.wizard-step-indicator' ).should( 'contain', '3' );
		cy.get( '.feature-configuration' ).should( 'be.visible' );

		// Verify feature options are displayed
		cy.get( '.feature-toggle' ).should( 'have.length.at.least', 2 );
		cy.get( '[data-feature="cache-preloading"]' ).should( 'be.visible' );
		cy.get( '[data-feature="image-optimization"]' ).should( 'be.visible' );

		// Enable additional features
		cy.get( '[data-feature="cache-preloading"] input' ).check();
		cy.get( '[data-feature="image-optimization"] input' ).check();

		cy.get( '.finish-setup-button' ).click();

		// Step 4: Completion
		cy.waitForLoading();
		cy.get( '.wizard-complete' ).should( 'be.visible' );
		cy.get( '.success-message' ).should( 'contain', 'Setup completed successfully' );

		// Verify redirect to dashboard
		cy.get( '.go-to-dashboard-button' ).click();
		cy.url().should( 'include', 'performance-optimisation' );
		cy.get( '.wppo-dashboard' ).should( 'be.visible' );
	} );

	it( 'should handle wizard navigation correctly', () => {
		cy.visitSetupWizard();

		// Test forward navigation
		cy.get( '.start-analysis-button' ).click();
		cy.waitForLoading();
		cy.get( '.next-step-button' ).click();

		// Test backward navigation
		cy.get( '.previous-step-button' ).click();
		cy.get( '.wizard-step-indicator' ).should( 'contain', '1' );

		// Navigate forward again
		cy.get( '.next-step-button' ).click();
		cy.get( '.wizard-step-indicator' ).should( 'contain', '2' );

		// Test step indicators are clickable
		cy.get( '.step-indicator[data-step="1"]' ).click();
		cy.get( '.wizard-step-indicator' ).should( 'contain', '1' );
	} );

	it( 'should validate required selections', () => {
		cy.visitSetupWizard();

		// Try to proceed without analysis
		cy.get( '.next-step-button' ).should( 'be.disabled' );

		// Complete analysis
		cy.get( '.start-analysis-button' ).click();
		cy.waitForLoading();
		cy.get( '.next-step-button' ).should( 'not.be.disabled' ).click();

		// Try to proceed without preset selection
		cy.get( '.next-step-button' ).should( 'be.disabled' );

		// Select preset
		cy.selectPreset( 'standard' );
		cy.get( '.next-step-button' ).should( 'not.be.disabled' );
	} );

	it( 'should show different recommendations based on site analysis', () => {
		cy.visitSetupWizard();

		cy.get( '.start-analysis-button' ).click();
		cy.waitForLoading();

		// Verify recommendations are displayed
		cy.get( '.recommendations-section' ).should( 'be.visible' );
		cy.get( '.recommendation-item' ).should( 'have.length.at.least', 1 );

		// Check recommendation details
		cy.get( '.recommendation-item' )
			.first()
			.within( () => {
				cy.get( '.recommendation-title' ).should( 'not.be.empty' );
				cy.get( '.recommendation-description' ).should( 'not.be.empty' );
				cy.get( '.recommendation-impact' ).should( 'be.visible' );
			} );
	} );

	it( 'should handle preset customization', () => {
		cy.visitSetupWizard();

		// Complete analysis and go to preset selection
		cy.get( '.start-analysis-button' ).click();
		cy.waitForLoading();
		cy.get( '.next-step-button' ).click();

		// Select aggressive preset
		cy.selectPreset( 'aggressive' );

		// Verify preset details are shown
		cy.get( '.preset-details' ).should( 'be.visible' );
		cy.get( '.preset-features-list' ).should( 'be.visible' );

		// Customize preset
		cy.get( '.customize-preset-button' ).click();
		cy.get( '.preset-customization' ).should( 'be.visible' );

		// Toggle some features
		cy.get( '.feature-toggle' ).first().click();
		cy.get( '.save-customization-button' ).click();

		// Verify customization is saved
		cy.get( '.preset-card[data-preset="aggressive"]' ).should( 'have.class', 'customized' );
	} );

	it( 'should handle wizard reset', () => {
		// Complete wizard first
		cy.completeWizardSetup( 'standard' );

		// Go to plugin dashboard
		cy.visitPluginPage();

		// Reset wizard
		cy.get( '.reset-wizard-button' ).click();
		cy.get( '.confirm-reset-dialog' ).should( 'be.visible' );
		cy.get( '.confirm-reset-button' ).click();

		// Verify redirect to wizard
		cy.url().should( 'include', 'setup' );
		cy.get( '.wizard-welcome' ).should( 'be.visible' );
	} );

	it( 'should save progress and allow resuming', () => {
		cy.visitSetupWizard();

		// Complete first step
		cy.get( '.start-analysis-button' ).click();
		cy.waitForLoading();
		cy.get( '.next-step-button' ).click();

		// Select preset but don't complete
		cy.selectPreset( 'recommended' );

		// Leave and return to wizard
		cy.visitPluginPage();
		cy.visitSetupWizard();

		// Verify progress is saved
		cy.get( '.wizard-step-indicator' ).should( 'contain', '2' );
		cy.get( '[data-preset="recommended"]' ).should( 'have.class', 'selected' );
	} );

	it( 'should handle errors gracefully', () => {
		// Mock API error
		cy.intercept( 'POST', '**/wizard/setup', {
			statusCode: 500,
			body: { success: false, message: 'Server error' },
		} ).as( 'setupError' );

		cy.visitSetupWizard();

		// Complete wizard steps
		cy.get( '.start-analysis-button' ).click();
		cy.waitForLoading();
		cy.get( '.next-step-button' ).click();
		cy.selectPreset( 'standard' );
		cy.get( '.next-step-button' ).click();
		cy.get( '.finish-setup-button' ).click();

		// Verify error handling
		cy.wait( '@setupError' );
		cy.get( '.error-message' ).should( 'be.visible' );
		cy.get( '.error-message' ).should( 'contain', 'Server error' );
		cy.get( '.retry-button' ).should( 'be.visible' );
	} );

	it( 'should be accessible', () => {
		cy.visitSetupWizard();

		// Check basic accessibility
		cy.checkA11y();

		// Test keyboard navigation
		cy.get( 'body' ).tab();
		cy.focused().should( 'have.class', 'start-analysis-button' );

		// Test ARIA labels
		cy.get( '.wizard-step-indicator' ).should( 'have.attr', 'aria-label' );
		cy.get( '.preset-card' ).should( 'have.attr', 'role', 'button' );
		cy.get( '.preset-card' ).should( 'have.attr', 'aria-describedby' );
	} );

	it( 'should work on mobile devices', () => {
		cy.viewport( 'iphone-x' );
		cy.visitSetupWizard();

		// Verify mobile layout
		cy.get( '.wizard-container' ).should( 'be.visible' );
		cy.get( '.wizard-steps' ).should( 'have.class', 'mobile-layout' );

		// Test mobile interactions
		cy.get( '.start-analysis-button' ).should( 'be.visible' ).click();
		cy.waitForLoading();

		// Verify responsive design
		cy.get( '.preset-card' )
			.should( 'have.css', 'width' )
			.and( 'match', /100%|auto/ );
	} );

	it( 'should handle concurrent users', () => {
		// This test would require multiple browser contexts
		// For now, we'll test that the wizard handles state correctly

		cy.visitSetupWizard();

		// Start wizard in one "session"
		cy.get( '.start-analysis-button' ).click();
		cy.waitForLoading();

		// Simulate another user completing wizard
		cy.window().then( ( win ) => {
			win.localStorage.setItem( 'wppo_wizard_completed', 'true' );
		} );

		// Refresh and verify handling
		cy.reload();
		cy.get( '.wizard-already-completed' ).should( 'be.visible' );
		cy.get( '.go-to-dashboard-button' ).should( 'be.visible' );
	} );
} );
