/**
 * E2E tests for Analytics Dashboard
 */

describe( 'Analytics Dashboard', () => {
	beforeEach( () => {
		cy.loginToWordPress();
		cy.mockAPIResponses();
	} );

	it( 'should display analytics dashboard correctly', () => {
		cy.visitPluginPage();

		// Navigate to analytics tab
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();
		cy.checkAnalyticsDashboard();

		// Verify main sections are visible
		cy.get( '.wppo-analytics-dashboard__header' ).should( 'be.visible' );
		cy.get( '.wppo-metrics-overview' ).should( 'be.visible' );
		cy.get( '.wppo-optimization-status' ).should( 'be.visible' );
		cy.get( '.wppo-analytics-dashboard__charts-grid' ).should( 'be.visible' );
	} );

	it( 'should show performance metrics correctly', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		cy.checkPerformanceMetrics();

		// Verify metric cards
		cy.get( '.wppo-metric-card' ).should( 'have.length', 4 );

		// Check performance score
		cy.get( '.wppo-metric-card' )
			.first()
			.within( () => {
				cy.get( '.wppo-metric-card__label' ).should( 'contain', 'Performance Score' );
				cy.get( '.wppo-metric-card__value' ).should( 'not.be.empty' );
				cy.get( '.wppo-metric-card__description' ).should( 'be.visible' );
			} );

		// Check load time
		cy.get( '.wppo-metric-card' )
			.eq( 1 )
			.within( () => {
				cy.get( '.wppo-metric-card__label' ).should( 'contain', 'Average Load Time' );
				cy.get( '.wppo-metric-card__value' ).should( 'match', /\d+\.\d+s/ );
			} );

		// Check cache hit ratio
		cy.get( '.wppo-metric-card' )
			.eq( 2 )
			.within( () => {
				cy.get( '.wppo-metric-card__label' ).should( 'contain', 'Cache Hit Ratio' );
				cy.get( '.wppo-metric-card__value' ).should( 'match', /\d+\.\d+%/ );
			} );
	} );

	it( 'should display interactive charts', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		// Verify charts are rendered
		cy.get( '.wppo-chart-container' ).should( 'have.length.at.least', 3 );

		// Check page load time chart
		cy.get( '[data-testid="chart-page_load_time"]' ).should( 'be.visible' );
		cy.get( '[data-testid="chart-page_load_time"] .recharts-wrapper' ).should( 'be.visible' );

		// Check cache performance chart
		cy.get( '[data-testid="chart-cache_hit_ratio"]' ).should( 'be.visible' );

		// Check memory usage chart
		cy.get( '[data-testid="chart-memory_usage"]' ).should( 'be.visible' );

		// Test chart interactions
		cy.get( '.wppo-chart-container' )
			.first()
			.within( () => {
				cy.get( '.recharts-dot' ).first().trigger( 'mouseover' );
				cy.get( '.recharts-tooltip-wrapper' ).should( 'be.visible' );
			} );
	} );

	it( 'should handle period selection', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		// Test period selector
		cy.get( '.wppo-period-selector select' ).should( 'have.value', 'week' );

		// Change to monthly view
		cy.get( '.wppo-period-selector select' ).select( 'month' );
		cy.get( '.wppo-period-selector select' ).should( 'have.value', 'month' );

		// Verify charts update (mock API should be called)
		cy.wait( '@getMetrics' );

		// Change to daily view
		cy.get( '.wppo-period-selector select' ).select( 'day' );
		cy.get( '.wppo-period-selector select' ).should( 'have.value', 'day' );
	} );

	it( 'should show optimization status', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		cy.checkOptimizationStatus();

		// Verify optimization features
		cy.get( '.wppo-features-list .wppo-feature-item' ).should( 'have.length.at.least', 5 );

		// Check feature status indicators
		cy.get( '.wppo-feature-status--enabled' ).should( 'exist' );
		cy.get( '.wppo-feature-status--disabled' ).should( 'exist' );

		// Verify optimization score
		cy.get( '.wppo-optimization-score__value' ).should( 'not.be.empty' );
		cy.get( '.wppo-optimization-progress__fill' ).should( 'have.css', 'width' );

		// Check image optimization stats
		cy.get( '.wppo-image-stats' ).should( 'be.visible' );
		cy.get( '.wppo-image-stat__value' ).should( 'not.be.empty' );
	} );

	it( 'should display and handle recommendations', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		// Wait for recommendations to load
		cy.wait( '@getRecommendations' );

		// Verify recommendations section
		cy.get( '.wppo-recommendations-list' ).should( 'be.visible' );
		cy.get( '.wppo-recommendation-item' ).should( 'have.length.at.least', 1 );

		// Check recommendation structure
		cy.get( '.wppo-recommendation-item' )
			.first()
			.within( () => {
				cy.get( '.wppo-recommendation-item__title' ).should( 'not.be.empty' );
				cy.get( '.wppo-recommendation-item__description' ).should( 'not.be.empty' );
				cy.get( '.wppo-recommendation-item__priority' ).should( 'be.visible' );
				cy.get( '.wppo-recommendation-actions-list' ).should( 'be.visible' );
			} );

		// Test applying a recommendation
		cy.intercept( 'POST', '**/recommendations/apply', {
			statusCode: 200,
			body: { success: true, message: 'Recommendation applied successfully' },
		} ).as( 'applyRecommendation' );

		cy.get( '.wppo-recommendation-item' )
			.first()
			.within( () => {
				cy.get( '.apply-fix-button' ).click();
			} );

		cy.wait( '@applyRecommendation' );
		cy.get( '.success-message' ).should( 'be.visible' );
	} );

	it( 'should handle data refresh', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		// Wait for initial load
		cy.wait( '@getDashboard' );

		// Test refresh button
		cy.get( '.refresh-button' ).click();

		// Verify loading state
		cy.get( '.refresh-button' ).should( 'contain', 'Refreshing' ).or( 'be.disabled' );

		// Wait for refresh to complete
		cy.wait( '@getDashboard' );
		cy.get( '.refresh-button' ).should( 'not.contain', 'Refreshing' ).and( 'not.be.disabled' );

		// Verify last updated time is updated
		cy.get( '.wppo-analytics-dashboard__last-updated' ).should( 'contain', 'Last updated:' );
	} );

	it( 'should export analytics data', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		// Test JSON export
		cy.exportAnalytics( 'json' );

		// Test CSV export
		cy.exportAnalytics( 'csv' );

		// Verify download was triggered (in a real test, you'd check the download)
		cy.get( '.export-success-message' ).should( 'be.visible' );
	} );

	it( 'should handle empty data states', () => {
		// Mock empty data response
		cy.intercept( 'GET', '**/analytics/dashboard', {
			statusCode: 200,
			body: { success: true, data: null },
		} ).as( 'getEmptyDashboard' );

		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		cy.wait( '@getEmptyDashboard' );

		// Verify empty state
		cy.get( '.wppo-analytics-dashboard__empty' ).should( 'be.visible' );
		cy.get( '.wppo-empty-icon' ).should( 'be.visible' );
		cy.shouldContainText( 'No Analytics Data Available' );
	} );

	it( 'should handle loading states', () => {
		// Mock slow API response
		cy.intercept( 'GET', '**/analytics/dashboard', ( req ) => {
			req.reply( ( res ) => {
				return new Promise( ( resolve ) => {
					setTimeout( () => {
						resolve( res.send( { fixture: 'dashboard-data.json' } ) );
					}, 2000 );
				} );
			} );
		} ).as( 'getSlowDashboard' );

		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		// Verify loading state
		cy.get( '.wppo-analytics-dashboard__loading' ).should( 'be.visible' );
		cy.get( '.wppo-loading-spinner' ).should( 'be.visible' );
		cy.shouldContainText( 'Loading analytics dashboard' );

		// Wait for data to load
		cy.wait( '@getSlowDashboard' );
		cy.get( '.wppo-analytics-dashboard__loading' ).should( 'not.exist' );
	} );

	it( 'should handle API errors gracefully', () => {
		// Mock API error
		cy.intercept( 'GET', '**/analytics/dashboard', {
			statusCode: 500,
			body: { success: false, message: 'Internal server error' },
		} ).as( 'getDashboardError' );

		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		cy.wait( '@getDashboardError' );

		// Verify error state
		cy.get( '.wppo-analytics-dashboard__error' ).should( 'be.visible' );
		cy.get( '.wppo-error-icon' ).should( 'be.visible' );
		cy.shouldContainText( 'Failed to Load Analytics' );
		cy.get( '.retry-button' ).should( 'be.visible' );

		// Test retry functionality
		cy.intercept( 'GET', '**/analytics/dashboard', {
			fixture: 'dashboard-data.json',
		} ).as( 'getDashboardRetry' );

		cy.get( '.retry-button' ).click();
		cy.wait( '@getDashboardRetry' );
		cy.get( '.wppo-analytics-dashboard__error' ).should( 'not.exist' );
	} );

	it( 'should be responsive on mobile devices', () => {
		cy.viewport( 'iphone-x' );
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		// Verify mobile layout
		cy.get( '.wppo-analytics-dashboard' ).should( 'be.visible' );
		cy.get( '.wppo-analytics-dashboard__header' ).should( 'have.css', 'flex-direction', 'column' );

		// Check that charts are responsive
		cy.get( '.wppo-chart-container' )
			.should( 'have.css', 'width' )
			.and( 'match', /100%|auto/ );

		// Verify mobile navigation
		cy.get( '.wppo-analytics-dashboard__header-actions' ).should( 'be.visible' );
	} );

	it( 'should maintain performance with large datasets', () => {
		// Mock large dataset
		cy.intercept( 'GET', '**/analytics/dashboard', {
			fixture: 'large-dashboard-data.json',
		} ).as( 'getLargeDashboard' );

		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		cy.wait( '@getLargeDashboard' );

		// Verify dashboard loads within reasonable time
		cy.get( '.wppo-analytics-dashboard' ).should( 'be.visible' );
		cy.get( '.wppo-chart-container' ).should( 'be.visible' );

		// Test chart interactions with large data
		cy.get( '.recharts-dot' ).should( 'have.length.at.least', 10 );
		cy.get( '.recharts-dot' ).first().trigger( 'mouseover' );
		cy.get( '.recharts-tooltip-wrapper' ).should( 'be.visible' );
	} );

	it( 'should handle real-time updates', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		// Wait for initial load
		cy.wait( '@getDashboard' );

		// Simulate real-time update
		cy.intercept( 'GET', '**/analytics/dashboard', {
			fixture: 'updated-dashboard-data.json',
		} ).as( 'getUpdatedDashboard' );

		// Trigger auto-refresh (if implemented)
		cy.wait( 30000 ); // Wait for auto-refresh interval
		cy.wait( '@getUpdatedDashboard' );

		// Verify data is updated
		cy.get( '.wppo-analytics-dashboard__last-updated' ).should( 'contain', 'Last updated:' );
	} );

	it( 'should be accessible', () => {
		cy.visitPluginPage();
		cy.get( '.nav-tab[data-tab="analytics"]' ).click();

		cy.checkA11y();

		// Test keyboard navigation
		cy.get( 'body' ).tab();
		cy.focused().should( 'be.visible' );

		// Test ARIA labels on charts
		cy.get( '.wppo-chart-container' ).should( 'have.attr', 'role' );
		cy.get( '.wppo-metric-card' ).should( 'have.attr', 'aria-label' );

		// Test screen reader content
		cy.get( '.sr-only' ).should( 'exist' );
	} );
} );
