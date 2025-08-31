<?php
/**
 * Unit tests for RecommendationEngine class.
 *
 * @package PerformanceOptimisation\Tests\Unit\Analytics
 */

namespace PerformanceOptimisation\Tests\Unit\Analytics;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Analytics\RecommendationEngine;
use PerformanceOptimisation\Core\Analytics\MetricsCollector;
use PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer;

/**
 * Test class for RecommendationEngine.
 */
class RecommendationEngineTest extends TestCase {

	/**
	 * RecommendationEngine instance.
	 *
	 * @var RecommendationEngine
	 */
	private RecommendationEngine $engine;

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

		if ( ! function_exists( 'get_option' ) ) {
			function get_option( $option, $default = false ) {
				$options = array(
					'wppo_settings' => array(
						'cache_settings'     => array( 'enablePageCaching' => false ),
						'file_optimisation'  => array(
							'minifyCSS'  => false,
							'minifyJS'   => false,
							'minifyHTML' => false,
						),
						'image_optimisation' => array(
							'lazyLoadImages' => false,
							'convertImg'     => false,
						),
					),
					'wppo_img_info' => array(
						'completed' => array(
							'webp' => array(),
							'avif' => array(),
						),
						'pending'   => array(
							'webp' => array_fill( 0, 15, 'image.jpg' ),
							'avif' => array_fill( 0, 5, 'image.jpg' ),
						),
						'failed'    => array(
							'webp' => array_fill( 0, 3, 'image.jpg' ),
							'avif' => array(),
						),
					),
				);
				return $options[ $option ] ?? $default;
			}
		}

		$this->metrics_collector    = $this->createMock( MetricsCollector::class );
		$this->performance_analyzer = $this->createMock( PerformanceAnalyzer::class );
		$this->engine               = new RecommendationEngine( $this->metrics_collector, $this->performance_analyzer );
	}

	/**
	 * Test recommendations generation.
	 *
	 * @return void
	 */
	public function test_generate_recommendations(): void {
		// Mock performance report with poor metrics
		$mock_report = array(
			'overview'          => array(
				'performance_score' => 45,
				'average_load_time' => 4500, // 4.5 seconds - poor
				'cache_hit_ratio'   => 35,     // 35% - poor
				'total_page_views'  => 1000,
			),
			'cache_performance' => array(
				'overall_hit_ratio'   => 35,
				'cache_effectiveness' => 'poor',
			),
		);

		$this->performance_analyzer
			->method( 'generate_performance_report' )
			->willReturn( $mock_report );

		$recommendations = $this->engine->generate_recommendations( '2024-01-01', '2024-01-07' );

		$this->assertIsArray( $recommendations );
		$this->assertArrayHasKey( 'recommendations', $recommendations );
		$this->assertArrayHasKey( 'summary', $recommendations );
		$this->assertArrayHasKey( 'generated_at', $recommendations );

		$recs = $recommendations['recommendations'];
		$this->assertNotEmpty( $recs );

		// Should generate recommendations for poor performance
		$rec_ids = array_column( $recs, 'id' );
		$this->assertContains( 'slow_page_load', $rec_ids );
		$this->assertContains( 'enable_page_caching', $rec_ids );
		$this->assertContains( 'improve_cache_hit_ratio', $rec_ids );

		// Check recommendation structure
		foreach ( $recs as $rec ) {
			$this->assertArrayHasKey( 'id', $rec );
			$this->assertArrayHasKey( 'type', $rec );
			$this->assertArrayHasKey( 'priority', $rec );
			$this->assertArrayHasKey( 'impact', $rec );
			$this->assertArrayHasKey( 'title', $rec );
			$this->assertArrayHasKey( 'description', $rec );
			$this->assertArrayHasKey( 'actions', $rec );
			$this->assertContains( $rec['priority'], array( 'high', 'medium', 'low' ) );
			$this->assertContains( $rec['impact'], array( 'high', 'medium', 'low' ) );
		}
	}

	/**
	 * Test performance metrics analysis.
	 *
	 * @return void
	 */
	public function test_analyze_performance_metrics(): void {
		$mock_report = array(
			'overview' => array(
				'average_load_time' => 5000, // 5 seconds - very poor
				'performance_score' => 30,   // Very low score
			),
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->engine );
		$method     = $reflection->getMethod( 'analyze_performance_metrics' );
		$method->setAccessible( true );

		$recommendations = $method->invoke( $this->engine, $mock_report );

		$this->assertIsArray( $recommendations );
		$this->assertNotEmpty( $recommendations );

		// Should recommend fixing slow page load
		$slow_load_rec = array_filter(
			$recommendations,
			function ( $rec ) {
				return $rec['id'] === 'slow_page_load';
			}
		);
		$this->assertNotEmpty( $slow_load_rec );

		$slow_load_rec = array_values( $slow_load_rec )[0];
		$this->assertEquals( 'high', $slow_load_rec['priority'] );
		$this->assertEquals( 'high', $slow_load_rec['impact'] );
		$this->assertArrayHasKey( 'automated_fix', $slow_load_rec );
	}

	/**
	 * Test feature usage analysis.
	 *
	 * @return void
	 */
	public function test_analyze_feature_usage(): void {
		$mock_settings = array(
			'cache_settings'     => array( 'enablePageCaching' => false ),
			'file_optimisation'  => array(
				'minifyCSS'  => false,
				'minifyJS'   => false,
				'minifyHTML' => false,
			),
			'image_optimisation' => array( 'lazyLoadImages' => false ),
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->engine );
		$method     = $reflection->getMethod( 'analyze_feature_usage' );
		$method->setAccessible( true );

		$recommendations = $method->invoke( $this->engine, $mock_settings );

		$this->assertIsArray( $recommendations );
		$this->assertNotEmpty( $recommendations );

		// Should recommend enabling disabled features
		$rec_ids = array_column( $recommendations, 'id' );
		$this->assertContains( 'enable_page_caching', $rec_ids );
		$this->assertContains( 'enable_minification', $rec_ids );
		$this->assertContains( 'enable_lazy_loading', $rec_ids );

		// Check that each recommendation has automated fix
		foreach ( $recommendations as $rec ) {
			$this->assertArrayHasKey( 'automated_fix', $rec );
			$this->assertArrayHasKey( 'action', $rec['automated_fix'] );
			$this->assertArrayHasKey( 'settings', $rec['automated_fix'] );
		}
	}

	/**
	 * Test image optimization analysis.
	 *
	 * @return void
	 */
	public function test_analyze_image_optimization(): void {
		$mock_img_info = array(
			'pending' => array(
				'webp' => array_fill( 0, 15, 'image.jpg' ),
				'avif' => array_fill( 0, 5, 'image.jpg' ),
			),
			'failed'  => array(
				'webp' => array_fill( 0, 8, 'image.jpg' ),
				'avif' => array_fill( 0, 2, 'image.jpg' ),
			),
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->engine );
		$method     = $reflection->getMethod( 'analyze_image_optimization' );
		$method->setAccessible( true );

		$recommendations = $method->invoke( $this->engine, $mock_img_info );

		$this->assertIsArray( $recommendations );
		$this->assertNotEmpty( $recommendations );

		// Should recommend optimizing pending images (20 > 10 threshold)
		$pending_rec = array_filter(
			$recommendations,
			function ( $rec ) {
				return $rec['id'] === 'optimize_pending_images';
			}
		);
		$this->assertNotEmpty( $pending_rec );

		// Should recommend fixing failed optimizations (10 > 5 threshold)
		$failed_rec = array_filter(
			$recommendations,
			function ( $rec ) {
				return $rec['id'] === 'fix_failed_optimizations';
			}
		);
		$this->assertNotEmpty( $failed_rec );
	}

	/**
	 * Test optimization suggestions generation.
	 *
	 * @return void
	 */
	public function test_generate_optimization_suggestions(): void {
		$suggestions = $this->engine->generate_optimization_suggestions();

		$this->assertIsArray( $suggestions );
		$this->assertNotEmpty( $suggestions );

		foreach ( $suggestions as $suggestion ) {
			$this->assertArrayHasKey( 'category', $suggestion );
			$this->assertArrayHasKey( 'title', $suggestion );
			$this->assertArrayHasKey( 'description', $suggestion );
			$this->assertArrayHasKey( 'items', $suggestion );
			$this->assertArrayHasKey( 'estimated_time', $suggestion );
			$this->assertArrayHasKey( 'impact', $suggestion );
			$this->assertContains( $suggestion['category'], array( 'quick_wins', 'advanced', 'maintenance' ) );
		}
	}

	/**
	 * Test optimization progress tracking.
	 *
	 * @return void
	 */
	public function test_track_optimization_progress(): void {
		// Mock current and historical reports
		$current_report = array(
			'overview' => array(
				'performance_score' => 75,
				'average_load_time' => 2000,
				'cache_hit_ratio'   => 80,
			),
		);

		$historical_report = array(
			'overview' => array(
				'performance_score' => 60,
				'average_load_time' => 3000,
				'cache_hit_ratio'   => 60,
			),
		);

		$this->performance_analyzer
			->method( 'generate_performance_report' )
			->willReturnOnConsecutiveCalls( $current_report, $historical_report );

		$progress = $this->engine->track_optimization_progress( '2024-01-01', '2024-01-07' );

		$this->assertIsArray( $progress );
		$this->assertArrayHasKey( 'current_period', $progress );
		$this->assertArrayHasKey( 'previous_period', $progress );
		$this->assertArrayHasKey( 'improvements', $progress );
		$this->assertArrayHasKey( 'next_steps', $progress );

		// Check improvements calculation
		$improvements = $progress['improvements'];
		$this->assertArrayHasKey( 'performance_score', $improvements );
		$this->assertArrayHasKey( 'average_load_time', $improvements );
		$this->assertArrayHasKey( 'cache_hit_ratio', $improvements );

		// Should show improvements
		$this->assertEquals( 'improved', $improvements['performance_score']['direction'] );
		$this->assertEquals( 'improved', $improvements['average_load_time']['direction'] );
		$this->assertEquals( 'improved', $improvements['cache_hit_ratio']['direction'] );
	}

	/**
	 * Test recommendation sorting by priority.
	 *
	 * @return void
	 */
	public function test_sort_recommendations_by_priority(): void {
		$recommendations = array(
			array(
				'id'       => 'low_priority',
				'priority' => 'low',
				'impact'   => 'low',
			),
			array(
				'id'       => 'high_priority',
				'priority' => 'high',
				'impact'   => 'high',
			),
			array(
				'id'       => 'medium_priority',
				'priority' => 'medium',
				'impact'   => 'medium',
			),
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->engine );
		$method     = $reflection->getMethod( 'sort_recommendations_by_priority' );
		$method->setAccessible( true );

		// Sort the array
		usort( $recommendations, array( $this->engine, 'sort_recommendations_by_priority' ) );

		// High priority should be first
		$this->assertEquals( 'high_priority', $recommendations[0]['id'] );
		$this->assertEquals( 'medium_priority', $recommendations[1]['id'] );
		$this->assertEquals( 'low_priority', $recommendations[2]['id'] );
	}

	/**
	 * Test load time improvement calculation.
	 *
	 * @return void
	 */
	public function test_calculate_load_time_improvement(): void {
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->engine );
		$method     = $reflection->getMethod( 'calculate_load_time_improvement' );
		$method->setAccessible( true );

		$improvement = $method->invoke( $this->engine, 4000 ); // 4 seconds

		$this->assertIsString( $improvement );
		$this->assertStringContainsString( '%', $improvement );
		$this->assertStringContainsString( 's improvement', $improvement );
	}

	/**
	 * Test quick wins identification.
	 *
	 * @return void
	 */
	public function test_identify_quick_wins(): void {
		$mock_settings = array(
			'cache_settings'     => array( 'enablePageCaching' => false ),
			'image_optimisation' => array( 'lazyLoadImages' => false ),
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->engine );
		$method     = $reflection->getMethod( 'identify_quick_wins' );
		$method->setAccessible( true );

		$quick_wins = $method->invoke( $this->engine, $mock_settings );

		$this->assertIsArray( $quick_wins );
		$this->assertNotEmpty( $quick_wins );

		// Should identify page caching and lazy loading as quick wins
		$titles = array_column( $quick_wins, 'title' );
		$this->assertContains( 'Enable Page Caching', $titles );
		$this->assertContains( 'Enable Lazy Loading', $titles );
	}

	/**
	 * Test recommendations summary generation.
	 *
	 * @return void
	 */
	public function test_generate_recommendations_summary(): void {
		$recommendations = array(
			array(
				'priority' => 'high',
				'type'     => 'performance',
			),
			array(
				'priority' => 'high',
				'type'     => 'caching',
			),
			array(
				'priority' => 'medium',
				'type'     => 'optimization',
			),
			array(
				'priority' => 'low',
				'type'     => 'maintenance',
			),
		);

		// Use reflection to access private method
		$reflection = new \ReflectionClass( $this->engine );
		$method     = $reflection->getMethod( 'generate_recommendations_summary' );
		$method->setAccessible( true );

		$summary = $method->invoke( $this->engine, $recommendations );

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'total', $summary );
		$this->assertArrayHasKey( 'by_priority', $summary );
		$this->assertArrayHasKey( 'by_type', $summary );

		$this->assertEquals( 4, $summary['total'] );
		$this->assertEquals( 2, $summary['by_priority']['high'] );
		$this->assertEquals( 1, $summary['by_priority']['medium'] );
		$this->assertEquals( 1, $summary['by_priority']['low'] );
		$this->assertEquals( 1, $summary['by_type']['performance'] );
		$this->assertEquals( 1, $summary['by_type']['caching'] );
	}
}
