<?php
/**
 * Unit tests for PerformanceAnalyzer class.
 *
 * @package PerformanceOptimisation\Tests\Unit\Analytics
 */

namespace PerformanceOptimisation\Tests\Unit\Analytics;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer;
use PerformanceOptimisation\Core\Analytics\MetricsCollector;

/**
 * Test class for PerformanceAnalyzer.
 */
class PerformanceAnalyzerTest extends TestCase {

	/**
	 * PerformanceAnalyzer instance.
	 *
	 * @var PerformanceAnalyzer
	 */
	private PerformanceAnalyzer $analyzer;

	/**
	 * Mock MetricsCollector instance.
	 *
	 * @var MetricsCollector
	 */
	private MetricsCollector $metrics_collector;

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

		if ( ! function_exists( 'get_option' ) ) {
			function get_option( $option, $default = false ) {
				$options = array(
					'wppo_settings' => array(
						'cache_settings'     => array( 'enablePageCaching' => true ),
						'file_optimisation'  => array( 'minifyCSS' => true ),
						'image_optimisation' => array( 'lazyLoadImages' => true ),
					),
					'wppo_img_info' => array(
						'completed' => array(
							'webp' => array(),
							'avif' => array(),
						),
						'pending'   => array(
							'webp' => array(),
							'avif' => array(),
						),
						'failed'    => array(
							'webp' => array(),
							'avif' => array(),
						),
					),
				);
				return $options[ $option ] ?? $default;
			}
		}

		$this->metrics_collector = $this->createMock( MetricsCollector::class );
		$this->analyzer          = new PerformanceAnalyzer( $this->metrics_collector );
	}

	/**
	 * Test performance report generation.
	 *
	 * @return void
	 */
	public function test_generate_performance_report(): void {
		// Mock aggregated metrics data
		$mock_load_time_data = array(
			'data' => array(
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '2500',
					'sample_count'     => '100',
					'period_start'     => '2024-01-01 00:00:00',
				),
			),
		);

		$mock_cache_data = array(
			'data' => array(
				array(
					'aggregation_type' => 'SUM',
					'aggregated_value' => '80',
					'sample_count'     => '100',
				),
				array(
					'aggregation_type' => 'COUNT',
					'aggregated_value' => '100',
					'sample_count'     => '100',
				),
			),
		);

		$this->metrics_collector
			->method( 'get_aggregated_metrics' )
			->willReturnOnConsecutiveCalls( $mock_load_time_data, $mock_cache_data );

		$report = $this->analyzer->generate_performance_report( '2024-01-01', '2024-01-07' );

		$this->assertIsArray( $report );
		$this->assertArrayHasKey( 'period', $report );
		$this->assertArrayHasKey( 'overview', $report );
		$this->assertArrayHasKey( 'page_load_performance', $report );
		$this->assertArrayHasKey( 'cache_performance', $report );
		$this->assertArrayHasKey( 'recommendations', $report );
		$this->assertArrayHasKey( 'generated_at', $report );

		// Test overview data
		$overview = $report['overview'];
		$this->assertArrayHasKey( 'performance_score', $overview );
		$this->assertArrayHasKey( 'average_load_time', $overview );
		$this->assertArrayHasKey( 'cache_hit_ratio', $overview );
		$this->assertArrayHasKey( 'total_page_views', $overview );

		// Verify calculated values
		$this->assertEquals( 2500, $overview['average_load_time'] );
		$this->assertEquals( 80, $overview['cache_hit_ratio'] );
		$this->assertIsInt( $overview['performance_score'] );
		$this->assertGreaterThanOrEqual( 0, $overview['performance_score'] );
		$this->assertLessThanOrEqual( 100, $overview['performance_score'] );
	}

	/**
	 * Test performance score calculation.
	 *
	 * @return void
	 */
	public function test_calculate_performance_score(): void {
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'calculate_performance_score' );
		$method->setAccessible( true );

		// Test excellent performance
		$score = $method->invoke( $this->analyzer, 800, 90 ); // 0.8s load time, 90% cache hit
		$this->assertGreaterThanOrEqual( 90, $score );

		// Test poor performance
		$score = $method->invoke( $this->analyzer, 5000, 30 ); // 5s load time, 30% cache hit
		$this->assertLessThan( 50, $score );

		// Test moderate performance
		$score = $method->invoke( $this->analyzer, 2000, 70 ); // 2s load time, 70% cache hit
		$this->assertGreaterThan( 50, $score );
		$this->assertLessThan( 90, $score );
	}

	/**
	 * Test page load performance analysis.
	 *
	 * @return void
	 */
	public function test_analyze_page_load_performance(): void {
		$mock_data = array(
			'data' => array(
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '1500',
					'sample_count'     => '50',
					'period_start'     => '2024-01-01 00:00:00',
				),
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '1800',
					'sample_count'     => '60',
					'period_start'     => '2024-01-02 00:00:00',
				),
			),
		);

		$this->metrics_collector
			->method( 'get_aggregated_metrics' )
			->willReturn( $mock_data );

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'analyze_page_load_performance' );
		$method->setAccessible( true );

		$analysis = $method->invoke( $this->analyzer, '2024-01-01', '2024-01-02' );

		$this->assertIsArray( $analysis );
		$this->assertArrayHasKey( 'average_load_time', $analysis );
		$this->assertArrayHasKey( 'daily_trends', $analysis );
		$this->assertEquals( 1650, $analysis['average_load_time'] ); // Average of 1500 and 1800
		$this->assertCount( 2, $analysis['daily_trends'] );
	}

	/**
	 * Test cache performance analysis.
	 *
	 * @return void
	 */
	public function test_analyze_cache_performance(): void {
		$mock_data = array(
			'data' => array(
				array(
					'aggregation_type' => 'SUM',
					'aggregated_value' => '80',
					'sample_count'     => '100',
					'period_start'     => '2024-01-01 00:00:00',
				),
				array(
					'aggregation_type' => 'COUNT',
					'aggregated_value' => '100',
					'sample_count'     => '100',
					'period_start'     => '2024-01-01 00:00:00',
				),
			),
		);

		$this->metrics_collector
			->method( 'get_aggregated_metrics' )
			->willReturn( $mock_data );

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'analyze_cache_performance' );
		$method->setAccessible( true );

		$analysis = $method->invoke( $this->analyzer, '2024-01-01', '2024-01-01' );

		$this->assertIsArray( $analysis );
		$this->assertArrayHasKey( 'overall_hit_ratio', $analysis );
		$this->assertArrayHasKey( 'cache_effectiveness', $analysis );
		$this->assertArrayHasKey( 'total_hits', $analysis );
		$this->assertArrayHasKey( 'total_requests', $analysis );

		$this->assertEquals( 80, $analysis['overall_hit_ratio'] );
		$this->assertEquals( 'excellent', $analysis['cache_effectiveness'] );
		$this->assertEquals( 80, $analysis['total_hits'] );
		$this->assertEquals( 100, $analysis['total_requests'] );
	}

	/**
	 * Test optimization impact analysis.
	 *
	 * @return void
	 */
	public function test_analyze_optimization_impact(): void {
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'analyze_optimization_impact' );
		$method->setAccessible( true );

		$analysis = $method->invoke( $this->analyzer, '2024-01-01', '2024-01-07' );

		$this->assertIsArray( $analysis );
		$this->assertArrayHasKey( 'enabled_optimizations', $analysis );
		$this->assertArrayHasKey( 'optimization_features', $analysis );
		$this->assertArrayHasKey( 'estimated_savings', $analysis );

		// Based on our mock settings, we should have 3 enabled features
		$this->assertEquals( 3, $analysis['enabled_optimizations'] );
		$this->assertTrue( $analysis['optimization_features']['Page Caching'] );
		$this->assertTrue( $analysis['optimization_features']['CSS Minification'] );
		$this->assertTrue( $analysis['optimization_features']['Image Lazy Loading'] );
	}

	/**
	 * Test recommendations generation.
	 *
	 * @return void
	 */
	public function test_generate_recommendations(): void {
		// Mock poor performance data to trigger recommendations
		$mock_overview = array(
			'average_load_time' => 4000, // 4 seconds - poor
			'cache_hit_ratio'   => 40,     // 40% - poor
		);

		$mock_cache_analysis = array(
			'overall_hit_ratio' => 40,
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'generate_recommendations' );
		$method->setAccessible( true );

		$recommendations = $method->invoke( $this->analyzer, '2024-01-01', '2024-01-07' );

		$this->assertIsArray( $recommendations );
		$this->assertNotEmpty( $recommendations );

		// Check that recommendations have required fields
		foreach ( $recommendations as $rec ) {
			$this->assertArrayHasKey( 'type', $rec );
			$this->assertArrayHasKey( 'priority', $rec );
			$this->assertArrayHasKey( 'title', $rec );
			$this->assertArrayHasKey( 'description', $rec );
			$this->assertArrayHasKey( 'actions', $rec );
			$this->assertContains( $rec['priority'], array( 'high', 'medium', 'low' ) );
		}
	}

	/**
	 * Test trend analysis.
	 *
	 * @return void
	 */
	public function test_analyze_trends(): void {
		$mock_data = array(
			'data' => array(
				// First week data (higher load times)
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '2000',
					'sample_count'     => '50',
					'period_start'     => '2024-01-01 00:00:00',
				),
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '2100',
					'sample_count'     => '55',
					'period_start'     => '2024-01-02 00:00:00',
				),
				// Last week data (lower load times - improvement)
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '1500',
					'sample_count'     => '60',
					'period_start'     => '2024-01-13 00:00:00',
				),
				array(
					'aggregation_type' => 'AVG',
					'aggregated_value' => '1400',
					'sample_count'     => '65',
					'period_start'     => '2024-01-14 00:00:00',
				),
			),
		);

		$this->metrics_collector
			->method( 'get_aggregated_metrics' )
			->willReturn( $mock_data );

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'analyze_trends' );
		$method->setAccessible( true );

		$trends = $method->invoke( $this->analyzer, '2024-01-01', '2024-01-14' );

		$this->assertIsArray( $trends );
		$this->assertArrayHasKey( 'load_time_trend', $trends );
		$this->assertArrayHasKey( 'load_time_change', $trends );
		$this->assertArrayHasKey( 'overall_trend', $trends );

		// Should detect improvement (negative change is good for load time)
		$this->assertEquals( 'improving', $trends['load_time_trend'] );
		$this->assertLessThan( 0, $trends['load_time_change'] );
	}

	/**
	 * Test optimization savings calculation.
	 *
	 * @return void
	 */
	public function test_calculate_optimization_savings(): void {
		$optimization_features = array(
			'Page Caching'       => true,
			'CSS Minification'   => true,
			'JS Minification'    => false,
			'Image Lazy Loading' => true,
			'Image Conversion'   => false,
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->analyzer );
		$method     = $reflection->getMethod( 'calculate_optimization_savings' );
		$method->setAccessible( true );

		$savings = $method->invoke( $this->analyzer, $optimization_features );

		$this->assertIsArray( $savings );
		$this->assertArrayHasKey( 'load_time_reduction', $savings );
		$this->assertArrayHasKey( 'bandwidth_savings', $savings );
		$this->assertArrayHasKey( 'server_load_reduction', $savings );

		// Should have some savings from enabled features
		$this->assertGreaterThan( 0, $savings['load_time_reduction'] );
		$this->assertGreaterThan( 0, $savings['bandwidth_savings'] );
		$this->assertGreaterThan( 0, $savings['server_load_reduction'] );

		// Should not exceed maximum values
		$this->assertLessThanOrEqual( 70, $savings['load_time_reduction'] );
		$this->assertLessThanOrEqual( 60, $savings['bandwidth_savings'] );
		$this->assertLessThanOrEqual( 80, $savings['server_load_reduction'] );
	}
}
