<?php
/**
 * Unit tests for AnalyticsController class.
 *
 * @package PerformanceOptimisation\Tests\Unit\API
 */

namespace PerformanceOptimisation\Tests\Unit\API;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\API\AnalyticsController;
use PerformanceOptimisation\Core\Analytics\MetricsCollector;
use PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer;

/**
 * Test class for AnalyticsController.
 */
class AnalyticsControllerTest extends TestCase {

	/**
	 * AnalyticsController instance.
	 *
	 * @var AnalyticsController
	 */
	private AnalyticsController $controller;

	/**
	 * Mock MetricsCollector instance.
	 *
	 * @var MetricsCollector
	 */
	private MetricsCollector $metrics_collector;

	/**
	 * Mock PerformanceAnalyzer instance.
	 *
	 * @var PerformanceAnalyzer
	 */
	private PerformanceAnalyzer $performance_analyzer;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress functions
		if ( ! function_exists( 'current_time' ) ) {
			function current_time( $type ) {
				return date( 'Y-m-d H:i:s' );
			}
		}

		if ( ! function_exists( 'current_user_can' ) ) {
			function current_user_can( $capability ) {
				return true; // Allow all capabilities for testing
			}
		}

		if ( ! function_exists( 'rest_ensure_response' ) ) {
			function rest_ensure_response( $response ) {
				return new \WP_REST_Response( $response );
			}
		}

		if ( ! function_exists( 'get_option' ) ) {
			function get_option( $option, $default = false ) {
				$options = array(
					'wppo_settings' => array(
						'cache_settings'     => array( 'enablePageCaching' => true ),
						'file_optimisation'  => array( 'minifyCSS' => true ),
						'image_optimisation' => array( 'lazyLoadImages' => true ),
					),
					'wppo_img_info' => array(
						'completed' => array( 'webp' => array( 'img1.jpg', 'img2.jpg' ) ),
						'pending'   => array( 'webp' => array( 'img3.jpg' ) ),
					),
				);
				return $options[ $option ] ?? $default;
			}
		}

		$this->metrics_collector    = $this->createMock( MetricsCollector::class );
		$this->performance_analyzer = $this->createMock( PerformanceAnalyzer::class );
		$this->controller           = new AnalyticsController( $this->metrics_collector, $this->performance_analyzer );
	}

	/**
	 * Test permissions check.
	 *
	 * @return void
	 */
	public function test_check_permissions(): void {
		$result = $this->controller->check_permissions();
		$this->assertTrue( $result );
	}

	/**
	 * Test dashboard data retrieval.
	 *
	 * @return void
	 */
	public function test_get_dashboard_data(): void {
		// Mock performance report
		$mock_report = array(
			'overview'        => array(
				'performance_score' => 85,
				'average_load_time' => 1500,
				'cache_hit_ratio'   => 80,
				'total_page_views'  => 1000,
			),
			'recommendations' => array(
				array(
					'id'       => 'test_rec',
					'title'    => 'Test Recommendation',
					'priority' => 'medium',
				),
			),
		);

		$this->performance_analyzer
			->method( 'generate_performance_report' )
			->willReturn( $mock_report );

		// Mock chart data
		$mock_chart_data = array(
			'daily_trends' => array(
				array(
					'date'  => '2024-01-01',
					'value' => 1500,
				),
				array(
					'date'  => '2024-01-02',
					'value' => 1400,
				),
			),
			'average'      => 1450,
			'metric_name'  => 'page_load_time',
		);

		$this->metrics_collector
			->method( 'get_aggregated_metrics' )
			->willReturn( array( 'data' => array() ) );

		// Create mock request
		$request = $this->createMock( \WP_REST_Request::class );

		$response = $this->controller->get_dashboard_data( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'overview', $data['data'] );
		$this->assertArrayHasKey( 'optimization_status', $data['data'] );
		$this->assertArrayHasKey( 'charts', $data['data'] );
		$this->assertArrayHasKey( 'recommendations', $data['data'] );
	}

	/**
	 * Test metrics data retrieval.
	 *
	 * @return void
	 */
	public function test_get_metrics_data(): void {
		// Mock aggregated metrics
		$mock_metrics = array(
			'data' => array(
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '1500',
					'sample_count'     => '100',
					'period_start'     => '2024-01-01 00:00:00',
				),
			),
		);

		$this->metrics_collector
			->method( 'get_aggregated_metrics' )
			->willReturn( $mock_metrics );

		// Create mock request
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_param' )
			->willReturnMap(
				array(
					array( 'metric', 'page_load_time' ),
					array( 'period', 'day' ),
					array( 'start_date', '2024-01-01' ),
					array( 'end_date', '2024-01-07' ),
				)
			);

		$response = $this->controller->get_metrics_data( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'daily_trends', $data['data'] );
		$this->assertArrayHasKey( 'average', $data['data'] );
		$this->assertArrayHasKey( 'metric_name', $data['data'] );
	}

	/**
	 * Test performance report generation.
	 *
	 * @return void
	 */
	public function test_get_performance_report(): void {
		// Mock performance report
		$mock_report = array(
			'period'          => array(
				'start' => '2024-01-01',
				'end'   => '2024-01-07',
			),
			'overview'        => array(
				'performance_score' => 85,
				'average_load_time' => 1500,
			),
			'recommendations' => array(),
			'generated_at'    => current_time( 'mysql' ),
		);

		$this->performance_analyzer
			->method( 'generate_performance_report' )
			->willReturn( $mock_report );

		// Create mock request
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_param' )
			->willReturnMap(
				array(
					array( 'start_date', '2024-01-01' ),
					array( 'end_date', '2024-01-07' ),
				)
			);

		$response = $this->controller->get_performance_report( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertEquals( $mock_report, $data['data'] );
	}

	/**
	 * Test analytics data export (JSON).
	 *
	 * @return void
	 */
	public function test_export_analytics_data_json(): void {
		// Mock performance report
		$mock_report = array(
			'overview'     => array( 'performance_score' => 85 ),
			'generated_at' => current_time( 'mysql' ),
		);

		$this->performance_analyzer
			->method( 'generate_performance_report' )
			->willReturn( $mock_report );

		// Create mock request
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_param' )
			->willReturnMap(
				array(
					array( 'format', 'json' ),
					array( 'start_date', '2024-01-01' ),
					array( 'end_date', '2024-01-07' ),
				)
			);

		$response = $this->controller->export_analytics_data( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'filename', $data );
		$this->assertStringContainsString( '.json', $data['filename'] );
		$this->assertEquals( $mock_report, $data['data'] );
	}

	/**
	 * Test analytics data export (CSV).
	 *
	 * @return void
	 */
	public function test_export_analytics_data_csv(): void {
		// Mock performance report
		$mock_report = array(
			'overview'              => array(
				'performance_score' => 85,
				'average_load_time' => 1500,
				'cache_hit_ratio'   => 80,
				'total_page_views'  => 1000,
			),
			'page_load_performance' => array(
				'daily_trends' => array(
					array(
						'date'              => '2024-01-01',
						'average_load_time' => 1500,
						'sample_count'      => 100,
					),
				),
			),
			'recommendations'       => array(
				array(
					'priority'    => 'high',
					'title'       => 'Test Rec',
					'description' => 'Test Description',
				),
			),
			'generated_at'          => current_time( 'mysql' ),
		);

		$this->performance_analyzer
			->method( 'generate_performance_report' )
			->willReturn( $mock_report );

		// Create mock request
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_param' )
			->willReturnMap(
				array(
					array( 'format', 'csv' ),
					array( 'start_date', '2024-01-01' ),
					array( 'end_date', '2024-01-07' ),
				)
			);

		$response = $this->controller->export_analytics_data( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'filename', $data );
		$this->assertStringContainsString( '.csv', $data['filename'] );
		$this->assertIsString( $data['data'] );
		$this->assertStringContainsString( 'Performance Report', $data['data'] );
	}

	/**
	 * Test optimization status retrieval.
	 *
	 * @return void
	 */
	public function test_get_optimization_status(): void {
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->controller );
		$method     = $reflection->getMethod( 'get_optimization_status' );
		$method->setAccessible( true );

		$status = $method->invoke( $this->controller );

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'features', $status );
		$this->assertArrayHasKey( 'image_optimization', $status );

		// Check features
		$features = $status['features'];
		$this->assertArrayHasKey( 'page_caching', $features );
		$this->assertArrayHasKey( 'css_minification', $features );
		$this->assertArrayHasKey( 'image_lazy_loading', $features );
		$this->assertTrue( $features['page_caching'] );
		$this->assertTrue( $features['css_minification'] );
		$this->assertTrue( $features['image_lazy_loading'] );

		// Check image optimization
		$img_opt = $status['image_optimization'];
		$this->assertArrayHasKey( 'total_optimized', $img_opt );
		$this->assertArrayHasKey( 'total_pending', $img_opt );
		$this->assertArrayHasKey( 'optimization_ratio', $img_opt );
		$this->assertEquals( 2, $img_opt['total_optimized'] );
		$this->assertEquals( 1, $img_opt['total_pending'] );
	}

	/**
	 * Test chart data formatting.
	 *
	 * @return void
	 */
	public function test_format_metrics_for_chart(): void {
		$mock_metrics = array(
			'data' => array(
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '1500',
					'sample_count'     => '100',
					'period_start'     => '2024-01-01 00:00:00',
				),
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '1400',
					'sample_count'     => '120',
					'period_start'     => '2024-01-02 00:00:00',
				),
			),
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->controller );
		$method     = $reflection->getMethod( 'format_metrics_for_chart' );
		$method->setAccessible( true );

		$formatted = $method->invoke( $this->controller, $mock_metrics, 'page_load_time' );

		$this->assertIsArray( $formatted );
		$this->assertArrayHasKey( 'daily_trends', $formatted );
		$this->assertArrayHasKey( 'average', $formatted );
		$this->assertArrayHasKey( 'metric_name', $formatted );

		$this->assertCount( 2, $formatted['daily_trends'] );
		$this->assertEquals( 1450, $formatted['average'] ); // Average of 1500 and 1400
		$this->assertEquals( 'page_load_time', $formatted['metric_name'] );

		// Check daily trends structure
		$trend = $formatted['daily_trends'][0];
		$this->assertArrayHasKey( 'date', $trend );
		$this->assertArrayHasKey( 'value', $trend );
		$this->assertArrayHasKey( 'sample_count', $trend );
		$this->assertEquals( '2024-01-01', $trend['date'] );
		$this->assertEquals( 1500, $trend['value'] );
		$this->assertEquals( 100, $trend['sample_count'] );
	}

	/**
	 * Test cache metrics formatting.
	 *
	 * @return void
	 */
	public function test_format_cache_metrics_for_chart(): void {
		$mock_cache_data = array(
			'data' => array(
				array(
					'aggregation_type' => 'SUM',
					'aggregated_value' => '80',
					'period_start'     => '2024-01-01 00:00:00',
				),
				array(
					'aggregation_type' => 'COUNT',
					'aggregated_value' => '100',
					'period_start'     => '2024-01-01 00:00:00',
				),
				array(
					'aggregation_type' => 'SUM',
					'aggregated_value' => '90',
					'period_start'     => '2024-01-02 00:00:00',
				),
				array(
					'aggregation_type' => 'COUNT',
					'aggregated_value' => '100',
					'period_start'     => '2024-01-02 00:00:00',
				),
			),
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->controller );
		$method     = $reflection->getMethod( 'format_cache_metrics_for_chart' );
		$method->setAccessible( true );

		$formatted = $method->invoke( $this->controller, $mock_cache_data );

		$this->assertIsArray( $formatted );
		$this->assertArrayHasKey( 'daily_trends', $formatted );
		$this->assertArrayHasKey( 'average', $formatted );
		$this->assertArrayHasKey( 'metric_name', $formatted );

		$this->assertCount( 2, $formatted['daily_trends'] );
		$this->assertEquals( 85, $formatted['average'] ); // Average of 80% and 90%
		$this->assertEquals( 'cache_hit_ratio', $formatted['metric_name'] );

		// Check daily trends structure
		$trend = $formatted['daily_trends'][0];
		$this->assertArrayHasKey( 'date', $trend );
		$this->assertArrayHasKey( 'value', $trend );
		$this->assertArrayHasKey( 'hits', $trend );
		$this->assertArrayHasKey( 'total', $trend );
		$this->assertEquals( '2024-01-01', $trend['date'] );
		$this->assertEquals( 80, $trend['value'] ); // 80/100 * 100 = 80%
		$this->assertEquals( 80, $trend['hits'] );
		$this->assertEquals( 100, $trend['total'] );
	}

	/**
	 * Test error handling.
	 *
	 * @return void
	 */
	public function test_error_handling(): void {
		// Mock exception in performance analyzer
		$this->performance_analyzer
			->method( 'generate_performance_report' )
			->willThrowException( new \Exception( 'Test error' ) );

		// Create mock request
		$request = $this->createMock( \WP_REST_Request::class );

		$response = $this->controller->get_dashboard_data( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertEquals( 'analytics_error', $response->get_error_code() );
	}
}
