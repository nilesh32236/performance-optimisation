<?php
/**
 * Integration tests for Analytics API endpoints.
 *
 * @package PerformanceOptimisation\Tests\Integration
 */

namespace PerformanceOptimisation\Tests\Integration;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Test class for Analytics API integration.
 */
class AnalyticsAPITest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up REST server
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create admin user
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Initialize plugin components
		$this->init_plugin_components();
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();

		global $wp_rest_server;
		$wp_rest_server = null;
	}

	/**
	 * Initialize plugin components for testing.
	 *
	 * @return void
	 */
	private function init_plugin_components(): void {
		// Load plugin classes
		require_once WPPO_PLUGIN_PATH . 'includes/Core/Analytics/MetricsCollector.php';
		require_once WPPO_PLUGIN_PATH . 'includes/Core/Analytics/PerformanceAnalyzer.php';
		require_once WPPO_PLUGIN_PATH . 'includes/Core/Analytics/RecommendationEngine.php';
		require_once WPPO_PLUGIN_PATH . 'includes/Core/API/BaseController.php';
		require_once WPPO_PLUGIN_PATH . 'includes/Core/API/AnalyticsController.php';
		require_once WPPO_PLUGIN_PATH . 'includes/Core/API/RecommendationsController.php';
		require_once WPPO_PLUGIN_PATH . 'includes/Core/API/ApiRouter.php';

		// Initialize API router
		$api_router = new \PerformanceOptimisation\Core\API\ApiRouter();
		$api_router->init();

		// Create database tables
		$metrics_collector = new \PerformanceOptimisation\Core\Analytics\MetricsCollector();
		$metrics_collector->create_tables();
	}

	/**
	 * Test analytics dashboard endpoint.
	 *
	 * @return void
	 */
	public function test_analytics_dashboard_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		$request  = new WP_REST_Request( 'GET', '/performance-optimisation/v1/analytics/dashboard' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );

		$dashboard_data = $data['data'];
		$this->assertArrayHasKey( 'overview', $dashboard_data );
		$this->assertArrayHasKey( 'optimization_status', $dashboard_data );
		$this->assertArrayHasKey( 'charts', $dashboard_data );
		$this->assertArrayHasKey( 'recommendations', $dashboard_data );
		$this->assertArrayHasKey( 'last_updated', $dashboard_data );
	}

	/**
	 * Test analytics metrics endpoint.
	 *
	 * @return void
	 */
	public function test_analytics_metrics_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		// Insert some test metrics first
		$this->insert_test_metrics();

		$request = new WP_REST_Request( 'GET', '/performance-optimisation/v1/analytics/metrics' );
		$request->set_param( 'metric', 'page_load_time' );
		$request->set_param( 'period', 'day' );
		$request->set_param( 'start_date', '2024-01-01' );
		$request->set_param( 'end_date', '2024-01-07' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );

		$metrics_data = $data['data'];
		$this->assertArrayHasKey( 'daily_trends', $metrics_data );
		$this->assertArrayHasKey( 'average', $metrics_data );
		$this->assertArrayHasKey( 'metric_name', $metrics_data );
		$this->assertEquals( 'page_load_time', $metrics_data['metric_name'] );
	}

	/**
	 * Test analytics report endpoint.
	 *
	 * @return void
	 */
	public function test_analytics_report_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/performance-optimisation/v1/analytics/report' );
		$request->set_param( 'start_date', '2024-01-01' );
		$request->set_param( 'end_date', '2024-01-07' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );

		$report_data = $data['data'];
		$this->assertArrayHasKey( 'period', $report_data );
		$this->assertArrayHasKey( 'overview', $report_data );
		$this->assertArrayHasKey( 'page_load_performance', $report_data );
		$this->assertArrayHasKey( 'cache_performance', $report_data );
		$this->assertArrayHasKey( 'recommendations', $report_data );
		$this->assertArrayHasKey( 'generated_at', $report_data );
	}

	/**
	 * Test recommendations endpoint.
	 *
	 * @return void
	 */
	public function test_recommendations_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		// Set up poor performance settings to trigger recommendations
		update_option(
			'wppo_settings',
			array(
				'cache_settings'     => array( 'enablePageCaching' => false ),
				'file_optimisation'  => array( 'minifyCSS' => false ),
				'image_optimisation' => array( 'lazyLoadImages' => false ),
			)
		);

		$request = new WP_REST_Request( 'GET', '/performance-optimisation/v1/recommendations' );
		$request->set_param( 'start_date', '2024-01-01' );
		$request->set_param( 'end_date', '2024-01-07' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );

		$recommendations_data = $data['data'];
		$this->assertArrayHasKey( 'recommendations', $recommendations_data );
		$this->assertArrayHasKey( 'summary', $recommendations_data );
		$this->assertArrayHasKey( 'generated_at', $recommendations_data );

		// Should have recommendations for disabled features
		$this->assertNotEmpty( $recommendations_data['recommendations'] );
	}

	/**
	 * Test apply recommendation endpoint.
	 *
	 * @return void
	 */
	public function test_apply_recommendation_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		// Set up settings with page caching disabled
		update_option(
			'wppo_settings',
			array(
				'cache_settings' => array( 'enablePageCaching' => false ),
			)
		);

		$request = new WP_REST_Request( 'POST', '/performance-optimisation/v1/recommendations/apply' );
		$request->set_param( 'recommendation_id', 'enable_page_caching' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );

		// Verify that page caching was enabled
		$updated_settings = get_option( 'wppo_settings' );
		$this->assertTrue( $updated_settings['cache_settings']['enablePageCaching'] );
	}

	/**
	 * Test optimization suggestions endpoint.
	 *
	 * @return void
	 */
	public function test_optimization_suggestions_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		$request  = new WP_REST_Request( 'GET', '/performance-optimisation/v1/recommendations/suggestions' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );

		$suggestions_data = $data['data'];
		$this->assertArrayHasKey( 'suggestions', $suggestions_data );
		$this->assertArrayHasKey( 'generated_at', $suggestions_data );

		// Should have suggestions
		$this->assertIsArray( $suggestions_data['suggestions'] );
	}

	/**
	 * Test optimization progress endpoint.
	 *
	 * @return void
	 */
	public function test_optimization_progress_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/performance-optimisation/v1/recommendations/progress' );
		$request->set_param( 'start_date', '2024-01-01' );
		$request->set_param( 'end_date', '2024-01-07' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );

		$progress_data = $data['data'];
		$this->assertArrayHasKey( 'current_period', $progress_data );
		$this->assertArrayHasKey( 'previous_period', $progress_data );
		$this->assertArrayHasKey( 'improvements', $progress_data );
		$this->assertArrayHasKey( 'next_steps', $progress_data );
	}

	/**
	 * Test analytics export endpoint (JSON).
	 *
	 * @return void
	 */
	public function test_analytics_export_json_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/performance-optimisation/v1/analytics/export' );
		$request->set_param( 'format', 'json' );
		$request->set_param( 'start_date', '2024-01-01' );
		$request->set_param( 'end_date', '2024-01-07' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'filename', $data );
		$this->assertStringContainsString( '.json', $data['filename'] );
	}

	/**
	 * Test analytics export endpoint (CSV).
	 *
	 * @return void
	 */
	public function test_analytics_export_csv_endpoint(): void {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/performance-optimisation/v1/analytics/export' );
		$request->set_param( 'format', 'csv' );
		$request->set_param( 'start_date', '2024-01-01' );
		$request->set_param( 'end_date', '2024-01-07' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'filename', $data );
		$this->assertStringContainsString( '.csv', $data['filename'] );
		$this->assertIsString( $data['data'] );
		$this->assertStringContainsString( 'Performance Report', $data['data'] );
	}

	/**
	 * Test permission checks.
	 *
	 * @return void
	 */
	public function test_permission_checks(): void {
		// Test without authentication
		$request  = new WP_REST_Request( 'GET', '/performance-optimisation/v1/analytics/dashboard' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );

		// Test with non-admin user
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/performance-optimisation/v1/analytics/dashboard' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test invalid parameters.
	 *
	 * @return void
	 */
	public function test_invalid_parameters(): void {
		wp_set_current_user( $this->admin_user_id );

		// Test metrics endpoint without required metric parameter
		$request  = new WP_REST_Request( 'GET', '/performance-optimisation/v1/analytics/metrics' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );

		// Test apply recommendation without recommendation_id
		$request  = new WP_REST_Request( 'POST', '/performance-optimisation/v1/recommendations/apply' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test error handling.
	 *
	 * @return void
	 */
	public function test_error_handling(): void {
		wp_set_current_user( $this->admin_user_id );

		// Test apply recommendation with invalid recommendation_id
		$request = new WP_REST_Request( 'POST', '/performance-optimisation/v1/recommendations/apply' );
		$request->set_param( 'recommendation_id', 'invalid_recommendation' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'message', $data );
	}

	/**
	 * Insert test metrics data.
	 *
	 * @return void
	 */
	private function insert_test_metrics(): void {
		global $wpdb;

		$metrics_table    = $wpdb->prefix . 'wppo_metrics';
		$aggregated_table = $wpdb->prefix . 'wppo_metrics_aggregated';

		// Insert raw metrics
		$wpdb->insert(
			$metrics_table,
			array(
				'metric_name'  => 'page_load_time',
				'metric_value' => '1500',
				'tags'         => wp_json_encode( array( 'page_type' => 'home' ) ),
				'recorded_at'  => '2024-01-01 12:00:00',
				'user_id'      => $this->admin_user_id,
				'ip_address'   => '127.0.0.1',
				'url'          => '/',
			)
		);

		// Insert aggregated metrics
		$wpdb->insert(
			$aggregated_table,
			array(
				'metric_name'      => 'page_load_time',
				'aggregation_type' => 'AVG',
				'aggregated_value' => '1500',
				'sample_count'     => '100',
				'period_type'      => 'day',
				'period_start'     => '2024-01-01 00:00:00',
				'period_end'       => '2024-01-01 23:59:59',
				'created_at'       => '2024-01-02 00:00:00',
			)
		);
	}
}
