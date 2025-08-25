<?php
/**
 * Recommendation Engine Class
 *
 * Analyzes performance metrics and generates automated recommendations
 * for optimization improvements based on collected data and best practices.
 *
 * @package PerformanceOptimisation\Core\Analytics
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommendation Engine class for automated optimization suggestions.
 */
class RecommendationEngine {

	/**
	 * Metrics collector instance.
	 *
	 * @var MetricsCollector
	 */
	private MetricsCollector $metrics_collector;

	/**
	 * Performance analyzer instance.
	 *
	 * @var PerformanceAnalyzer
	 */
	private PerformanceAnalyzer $performance_analyzer;

	/**
	 * Recommendation thresholds.
	 *
	 * @var array<string, mixed>
	 */
	private array $thresholds;

	/**
	 * Constructor.
	 *
	 * @param MetricsCollector    $metrics_collector Metrics collector instance.
	 * @param PerformanceAnalyzer $performance_analyzer Performance analyzer instance.
	 */
	public function __construct( MetricsCollector $metrics_collector, PerformanceAnalyzer $performance_analyzer ) {
		$this->metrics_collector    = $metrics_collector;
		$this->performance_analyzer = $performance_analyzer;
		$this->thresholds           = $this->get_recommendation_thresholds();
	}

	/**
	 * Generate automated recommendations based on current performance.
	 *
	 * @param string $start_date Start date for analysis.
	 * @param string $end_date End date for analysis.
	 * @return array<string, mixed> Generated recommendations.
	 */
	public function generate_recommendations( string $start_date, string $end_date ): array {
		$report   = $this->performance_analyzer->generate_performance_report( $start_date, $end_date );
		$settings = get_option( 'wppo_settings', array() );
		$img_info = get_option( 'wppo_img_info', array() );

		$recommendations = array();

		// Performance-based recommendations
		$recommendations = array_merge( $recommendations, $this->analyze_performance_metrics( $report ) );

		// Feature-based recommendations
		$recommendations = array_merge( $recommendations, $this->analyze_feature_usage( $settings ) );

		// Image optimization recommendations
		$recommendations = array_merge( $recommendations, $this->analyze_image_optimization( $img_info ) );

		// Cache performance recommendations
		$recommendations = array_merge( $recommendations, $this->analyze_cache_performance( $report ) );

		// Resource usage recommendations
		$recommendations = array_merge( $recommendations, $this->analyze_resource_usage( $report ) );

		// Sort by priority and impact
		usort( $recommendations, array( $this, 'sort_recommendations_by_priority' ) );

		return array(
			'recommendations' => $recommendations,
			'summary'         => $this->generate_recommendations_summary( $recommendations ),
			'generated_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Analyze performance metrics and generate recommendations.
	 *
	 * @param array<string, mixed> $report Performance report.
	 * @return array<array<string, mixed>> Performance recommendations.
	 */
	private function analyze_performance_metrics( array $report ): array {
		$recommendations = array();
		$overview        = $report['overview'];

		// Page load time recommendations
		if ( $overview['average_load_time'] > $this->thresholds['page_load_time']['poor'] ) {
			$recommendations[] = array(
				'id'                    => 'slow_page_load',
				'type'                  => 'performance',
				'priority'              => 'high',
				'impact'                => 'high',
				'title'                 => 'Improve Page Load Speed',
				'description'           => sprintf(
					'Your average page load time is %.2f seconds, which is slower than recommended. Fast loading pages improve user experience and SEO rankings.',
					$overview['average_load_time'] / 1000
				),
				'current_value'         => $overview['average_load_time'],
				'target_value'          => $this->thresholds['page_load_time']['good'],
				'potential_improvement' => $this->calculate_load_time_improvement( $overview['average_load_time'] ),
				'actions'               => array(
					'Enable page caching to serve static versions of your pages',
					'Minify CSS and JavaScript files to reduce file sizes',
					'Optimize images and enable modern formats (WebP/AVIF)',
					'Enable lazy loading for images and videos',
					'Consider using a Content Delivery Network (CDN)',
				),
				'automated_fix'         => array(
					'action'   => 'enable_basic_optimizations',
					'settings' => array(
						'cache_settings.enablePageCaching' => true,
						'file_optimisation.minifyCSS'      => true,
						'file_optimisation.minifyJS'       => true,
						'image_optimisation.lazyLoadImages' => true,
					),
				),
			);
		} elseif ( $overview['average_load_time'] > $this->thresholds['page_load_time']['good'] ) {
			$recommendations[] = array(
				'id'                    => 'moderate_page_load',
				'type'                  => 'performance',
				'priority'              => 'medium',
				'impact'                => 'medium',
				'title'                 => 'Further Optimize Page Load Speed',
				'description'           => sprintf(
					'Your page load time of %.2f seconds is acceptable but could be improved for better user experience.',
					$overview['average_load_time'] / 1000
				),
				'current_value'         => $overview['average_load_time'],
				'target_value'          => $this->thresholds['page_load_time']['excellent'],
				'potential_improvement' => $this->calculate_load_time_improvement( $overview['average_load_time'] ),
				'actions'               => array(
					'Enable advanced minification and combination',
					'Implement critical CSS inlining',
					'Optimize database queries and enable object caching',
				),
				'automated_fix'         => array(
					'action'   => 'enable_advanced_optimizations',
					'settings' => array(
						'file_optimisation.combineCSS' => true,
						'file_optimisation.combineJS'  => true,
						'file_optimisation.deferJS'    => true,
					),
				),
			);
		}

		// Performance score recommendations
		if ( $overview['performance_score'] < $this->thresholds['performance_score']['good'] ) {
			$recommendations[] = array(
				'id'                    => 'low_performance_score',
				'type'                  => 'performance',
				'priority'              => $overview['performance_score'] < 50 ? 'high' : 'medium',
				'impact'                => 'high',
				'title'                 => 'Improve Overall Performance Score',
				'description'           => sprintf(
					'Your performance score is %d/100. A higher score indicates better optimization and user experience.',
					$overview['performance_score']
				),
				'current_value'         => $overview['performance_score'],
				'target_value'          => $this->thresholds['performance_score']['excellent'],
				'potential_improvement' => $this->thresholds['performance_score']['excellent'] - $overview['performance_score'],
				'actions'               => array(
					'Enable all available optimization features',
					'Review and optimize slow-loading pages',
					'Implement advanced caching strategies',
					'Optimize images and use modern formats',
				),
			);
		}

		return $recommendations;
	}

	/**
	 * Analyze feature usage and generate recommendations.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<array<string, mixed>> Feature recommendations.
	 */
	private function analyze_feature_usage( array $settings ): array {
		$recommendations = array();

		// Check if page caching is disabled
		if ( empty( $settings['cache_settings']['enablePageCaching'] ) ) {
			$recommendations[] = array(
				'id'                    => 'enable_page_caching',
				'type'                  => 'caching',
				'priority'              => 'high',
				'impact'                => 'high',
				'title'                 => 'Enable Page Caching',
				'description'           => 'Page caching can dramatically improve your site speed by serving static versions of your pages to visitors.',
				'potential_improvement' => '30-50% faster page load times',
				'actions'               => array(
					'Enable page caching in the cache settings',
					'Configure cache expiration times',
					'Set up cache preloading for popular pages',
				),
				'automated_fix'         => array(
					'action'   => 'enable_page_caching',
					'settings' => array(
						'cache_settings.enablePageCaching' => true,
						'cache_settings.cacheExpiration'   => 3600,
					),
				),
			);
		}

		// Check minification settings
		$minification_disabled = array();
		if ( empty( $settings['file_optimisation']['minifyCSS'] ) ) {
			$minification_disabled[] = 'CSS';
		}
		if ( empty( $settings['file_optimisation']['minifyJS'] ) ) {
			$minification_disabled[] = 'JavaScript';
		}
		if ( empty( $settings['file_optimisation']['minifyHTML'] ) ) {
			$minification_disabled[] = 'HTML';
		}

		if ( ! empty( $minification_disabled ) ) {
			$recommendations[] = array(
				'id'                    => 'enable_minification',
				'type'                  => 'optimization',
				'priority'              => 'medium',
				'impact'                => 'medium',
				'title'                 => 'Enable File Minification',
				'description'           => sprintf(
					'Minification is disabled for: %s. Minifying files reduces their size and improves load times.',
					implode( ', ', $minification_disabled )
				),
				'potential_improvement' => '10-20% reduction in file sizes',
				'actions'               => array(
					'Enable minification for CSS, JavaScript, and HTML files',
					'Test your site after enabling to ensure compatibility',
					'Consider combining files to reduce HTTP requests',
				),
				'automated_fix'         => array(
					'action'   => 'enable_minification',
					'settings' => array(
						'file_optimisation.minifyCSS'  => true,
						'file_optimisation.minifyJS'   => true,
						'file_optimisation.minifyHTML' => true,
					),
				),
			);
		}

		// Check lazy loading
		if ( empty( $settings['image_optimisation']['lazyLoadImages'] ) ) {
			$recommendations[] = array(
				'id'                    => 'enable_lazy_loading',
				'type'                  => 'optimization',
				'priority'              => 'medium',
				'impact'                => 'medium',
				'title'                 => 'Enable Lazy Loading',
				'description'           => 'Lazy loading defers the loading of images until they are needed, improving initial page load times.',
				'potential_improvement' => '15-25% faster initial page loads',
				'actions'               => array(
					'Enable lazy loading for images',
					'Configure lazy loading for videos and iframes',
					'Test on mobile devices for optimal performance',
				),
				'automated_fix'         => array(
					'action'   => 'enable_lazy_loading',
					'settings' => array(
						'image_optimisation.lazyLoadImages' => true,
					),
				),
			);
		}

		return $recommendations;
	}

	/**
	 * Analyze image optimization and generate recommendations.
	 *
	 * @param array<string, mixed> $img_info Image optimization info.
	 * @return array<array<string, mixed>> Image optimization recommendations.
	 */
	private function analyze_image_optimization( array $img_info ): array {
		$recommendations = array();

		$total_pending = 0;
		$total_failed  = 0;
		foreach ( array( 'webp', 'avif' ) as $format ) {
			$total_pending += count( $img_info['pending'][ $format ] ?? array() );
			$total_failed  += count( $img_info['failed'][ $format ] ?? array() );
		}

		// Recommend image optimization if many images are pending
		if ( $total_pending > 10 ) {
			$recommendations[] = array(
				'id'                    => 'optimize_pending_images',
				'type'                  => 'image_optimization',
				'priority'              => 'medium',
				'impact'                => 'medium',
				'title'                 => 'Optimize Pending Images',
				'description'           => sprintf(
					'You have %d images waiting to be optimized. Optimized images load faster and use less bandwidth.',
					$total_pending
				),
				'current_value'         => $total_pending,
				'target_value'          => 0,
				'potential_improvement' => '20-40% reduction in image file sizes',
				'actions'               => array(
					'Run bulk image optimization',
					'Enable automatic optimization for new uploads',
					'Consider using modern image formats (WebP, AVIF)',
				),
				'automated_fix'         => array(
					'action'   => 'optimize_images',
					'settings' => array(
						'image_optimisation.convertImg' => true,
						'image_optimisation.format'     => 'webp',
					),
				),
			);
		}

		// Recommend fixing failed optimizations
		if ( $total_failed > 5 ) {
			$recommendations[] = array(
				'id'            => 'fix_failed_optimizations',
				'type'          => 'image_optimization',
				'priority'      => 'low',
				'impact'        => 'low',
				'title'         => 'Review Failed Image Optimizations',
				'description'   => sprintf(
					'%d images failed to optimize. This might indicate compatibility issues or corrupted files.',
					$total_failed
				),
				'current_value' => $total_failed,
				'target_value'  => 0,
				'actions'       => array(
					'Review the failed images list',
					'Check for corrupted or unsupported image files',
					'Retry optimization with different settings',
					'Consider manual optimization for problematic images',
				),
			);
		}

		return $recommendations;
	}

	/**
	 * Analyze cache performance and generate recommendations.
	 *
	 * @param array<string, mixed> $report Performance report.
	 * @return array<array<string, mixed>> Cache performance recommendations.
	 */
	private function analyze_cache_performance( array $report ): array {
		$recommendations   = array();
		$cache_performance = $report['cache_performance'];

		if ( $cache_performance['overall_hit_ratio'] < $this->thresholds['cache_hit_ratio']['good'] ) {
			$priority = $cache_performance['overall_hit_ratio'] < $this->thresholds['cache_hit_ratio']['poor'] ? 'high' : 'medium';

			$recommendations[] = array(
				'id'                    => 'improve_cache_hit_ratio',
				'type'                  => 'caching',
				'priority'              => $priority,
				'impact'                => 'high',
				'title'                 => 'Improve Cache Hit Ratio',
				'description'           => sprintf(
					'Your cache hit ratio is %.1f%%. A higher ratio means more requests are served from cache, improving performance.',
					$cache_performance['overall_hit_ratio']
				),
				'current_value'         => $cache_performance['overall_hit_ratio'],
				'target_value'          => $this->thresholds['cache_hit_ratio']['excellent'],
				'potential_improvement' => sprintf(
					'%.1f%% improvement in cache efficiency',
					$this->thresholds['cache_hit_ratio']['excellent'] - $cache_performance['overall_hit_ratio']
				),
				'actions'               => array(
					'Review cache exclusion rules',
					'Increase cache expiration times for static content',
					'Enable cache preloading for popular pages',
					'Optimize cache warming strategies',
				),
				'automated_fix'         => array(
					'action'   => 'optimize_cache_settings',
					'settings' => array(
						'cache_settings.cacheExpiration' => 7200,
						'preload_settings.enablePreloadCache' => true,
					),
				),
			);
		}

		return $recommendations;
	}

	/**
	 * Analyze resource usage and generate recommendations.
	 *
	 * @param array<string, mixed> $report Performance report.
	 * @return array<array<string, mixed>> Resource usage recommendations.
	 */
	private function analyze_resource_usage( array $report ): array {
		$recommendations = array();

		// This would analyze memory usage, database queries, etc.
		// For now, we'll add a placeholder for future implementation

		return $recommendations;
	}

	/**
	 * Generate automated optimization suggestions.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @return array<array<string, mixed>> Optimization suggestions.
	 */
	public function generate_optimization_suggestions( array $context = array() ): array {
		$suggestions = array();
		$settings    = get_option( 'wppo_settings', array() );

		// Quick wins - easy optimizations with high impact
		$quick_wins = $this->identify_quick_wins( $settings );
		if ( ! empty( $quick_wins ) ) {
			$suggestions[] = array(
				'category'       => 'quick_wins',
				'title'          => 'Quick Performance Wins',
				'description'    => 'Easy optimizations that can provide immediate performance improvements.',
				'items'          => $quick_wins,
				'estimated_time' => '5-10 minutes',
				'impact'         => 'high',
			);
		}

		// Advanced optimizations
		$advanced_optimizations = $this->identify_advanced_optimizations( $settings );
		if ( ! empty( $advanced_optimizations ) ) {
			$suggestions[] = array(
				'category'       => 'advanced',
				'title'          => 'Advanced Optimizations',
				'description'    => 'More complex optimizations for experienced users.',
				'items'          => $advanced_optimizations,
				'estimated_time' => '15-30 minutes',
				'impact'         => 'medium',
			);
		}

		// Maintenance tasks
		$maintenance_tasks = $this->identify_maintenance_tasks();
		if ( ! empty( $maintenance_tasks ) ) {
			$suggestions[] = array(
				'category'       => 'maintenance',
				'title'          => 'Maintenance Tasks',
				'description'    => 'Regular maintenance to keep your site optimized.',
				'items'          => $maintenance_tasks,
				'estimated_time' => '10-20 minutes',
				'impact'         => 'low',
			);
		}

		return $suggestions;
	}

	/**
	 * Track optimization progress over time.
	 *
	 * @param string $start_date Start date for tracking.
	 * @param string $end_date End date for tracking.
	 * @return array<string, mixed> Progress tracking data.
	 */
	public function track_optimization_progress( string $start_date, string $end_date ): array {
		$current_report = $this->performance_analyzer->generate_performance_report( $start_date, $end_date );

		// Get historical data for comparison
		$historical_start  = date( 'Y-m-d', strtotime( $start_date . ' -30 days' ) );
		$historical_end    = date( 'Y-m-d', strtotime( $end_date . ' -30 days' ) );
		$historical_report = $this->performance_analyzer->generate_performance_report( $historical_start, $historical_end );

		$progress = array(
			'current_period'              => array(
				'start'   => $start_date,
				'end'     => $end_date,
				'metrics' => $current_report['overview'],
			),
			'previous_period'             => array(
				'start'   => $historical_start,
				'end'     => $historical_end,
				'metrics' => $historical_report['overview'],
			),
			'improvements'                => $this->calculate_improvements( $historical_report['overview'], $current_report['overview'] ),
			'recommendations_implemented' => $this->get_implemented_recommendations(),
			'next_steps'                  => $this->suggest_next_optimization_steps( $current_report ),
		);

		return $progress;
	}

	/**
	 * Get recommendation thresholds.
	 *
	 * @return array<string, mixed> Recommendation thresholds.
	 */
	private function get_recommendation_thresholds(): array {
		return array(
			'page_load_time'    => array(
				'excellent' => 1000, // 1 second
				'good'      => 2000,      // 2 seconds
				'fair'      => 3000,      // 3 seconds
				'poor'      => 4000,      // 4 seconds
			),
			'cache_hit_ratio'   => array(
				'excellent' => 90,   // 90%
				'good'      => 70,        // 70%
				'fair'      => 50,        // 50%
				'poor'      => 30,        // 30%
			),
			'performance_score' => array(
				'excellent' => 90,   // 90/100
				'good'      => 70,        // 70/100
				'fair'      => 50,        // 50/100
				'poor'      => 30,        // 30/100
			),
		);
	}

	/**
	 * Calculate potential load time improvement.
	 *
	 * @param float $current_load_time Current load time in milliseconds.
	 * @return string Potential improvement description.
	 */
	private function calculate_load_time_improvement( float $current_load_time ): string {
		$target_time = $this->thresholds['page_load_time']['good'];
		$improvement = max( 0, $current_load_time - $target_time );
		$percentage  = $current_load_time > 0 ? ( $improvement / $current_load_time ) * 100 : 0;

		return sprintf( '%.1f%% faster (%.2fs improvement)', $percentage, $improvement / 1000 );
	}

	/**
	 * Sort recommendations by priority and impact.
	 *
	 * @param array<string, mixed> $a First recommendation.
	 * @param array<string, mixed> $b Second recommendation.
	 * @return int Comparison result.
	 */
	private function sort_recommendations_by_priority( array $a, array $b ): int {
		$priority_order = array(
			'high'   => 3,
			'medium' => 2,
			'low'    => 1,
		);
		$impact_order   = array(
			'high'   => 3,
			'medium' => 2,
			'low'    => 1,
		);

		$a_score = ( $priority_order[ $a['priority'] ] ?? 0 ) + ( $impact_order[ $a['impact'] ?? 'low' ] ?? 0 );
		$b_score = ( $priority_order[ $b['priority'] ] ?? 0 ) + ( $impact_order[ $b['impact'] ?? 'low' ] ?? 0 );

		return $b_score - $a_score; // Sort descending
	}

	/**
	 * Generate recommendations summary.
	 *
	 * @param array<array<string, mixed>> $recommendations List of recommendations.
	 * @return array<string, mixed> Recommendations summary.
	 */
	private function generate_recommendations_summary( array $recommendations ): array {
		$summary = array(
			'total'                  => count( $recommendations ),
			'by_priority'            => array(
				'high'   => 0,
				'medium' => 0,
				'low'    => 0,
			),
			'by_type'                => array(),
			'potential_improvements' => array(),
		);

		foreach ( $recommendations as $rec ) {
			++$summary['by_priority'][ $rec['priority'] ];

			$type = $rec['type'];
			if ( ! isset( $summary['by_type'][ $type ] ) ) {
				$summary['by_type'][ $type ] = 0;
			}
			++$summary['by_type'][ $type ];
		}

		return $summary;
	}

	/**
	 * Identify quick wins for optimization.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return array<array<string, mixed>> Quick win suggestions.
	 */
	private function identify_quick_wins( array $settings ): array {
		$quick_wins = array();

		if ( empty( $settings['cache_settings']['enablePageCaching'] ) ) {
			$quick_wins[] = array(
				'title'       => 'Enable Page Caching',
				'description' => 'Instant performance boost with one click',
				'action'      => 'enable_page_caching',
			);
		}

		if ( empty( $settings['image_optimisation']['lazyLoadImages'] ) ) {
			$quick_wins[] = array(
				'title'       => 'Enable Lazy Loading',
				'description' => 'Improve initial page load times',
				'action'      => 'enable_lazy_loading',
			);
		}

		return $quick_wins;
	}

	/**
	 * Identify advanced optimization opportunities.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 * @return array<array<string, mixed>> Advanced optimization suggestions.
	 */
	private function identify_advanced_optimizations( array $settings ): array {
		$advanced = array();

		if ( empty( $settings['file_optimisation']['combineCSS'] ) ) {
			$advanced[] = array(
				'title'       => 'Enable CSS Combination',
				'description' => 'Reduce HTTP requests by combining CSS files',
				'action'      => 'enable_css_combination',
			);
		}

		return $advanced;
	}

	/**
	 * Identify maintenance tasks.
	 *
	 * @return array<array<string, mixed>> Maintenance task suggestions.
	 */
	private function identify_maintenance_tasks(): array {
		$tasks = array();

		// Check cache size
		$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
		if ( is_dir( $cache_dir ) ) {
			$cache_size = $this->get_directory_size( $cache_dir );
			if ( $cache_size > 100 * 1024 * 1024 ) { // 100MB
				$tasks[] = array(
					'title'       => 'Clear Old Cache Files',
					'description' => 'Cache directory is getting large, consider clearing old files',
					'action'      => 'clear_old_cache',
				);
			}
		}

		return $tasks;
	}

	/**
	 * Calculate improvements between periods.
	 *
	 * @param array<string, mixed> $previous Previous period metrics.
	 * @param array<string, mixed> $current Current period metrics.
	 * @return array<string, mixed> Calculated improvements.
	 */
	private function calculate_improvements( array $previous, array $current ): array {
		$improvements = array();

		$metrics = array( 'performance_score', 'average_load_time', 'cache_hit_ratio' );
		foreach ( $metrics as $metric ) {
			if ( isset( $previous[ $metric ], $current[ $metric ] ) ) {
				$change     = $current[ $metric ] - $previous[ $metric ];
				$percentage = $previous[ $metric ] > 0 ? ( $change / $previous[ $metric ] ) * 100 : 0;

				$improvements[ $metric ] = array(
					'change'     => $change,
					'percentage' => $percentage,
					'direction'  => $change > 0 ? 'improved' : ( $change < 0 ? 'declined' : 'stable' ),
				);
			}
		}

		return $improvements;
	}

	/**
	 * Get implemented recommendations.
	 *
	 * @return array<string, mixed> Implemented recommendations data.
	 */
	private function get_implemented_recommendations(): array {
		// This would track which recommendations were implemented
		// For now, return placeholder data
		return array(
			'total_implemented'      => 0,
			'recent_implementations' => array(),
		);
	}

	/**
	 * Suggest next optimization steps.
	 *
	 * @param array<string, mixed> $current_report Current performance report.
	 * @return array<array<string, mixed>> Next step suggestions.
	 */
	private function suggest_next_optimization_steps( array $current_report ): array {
		$next_steps = array();

		$score = $current_report['overview']['performance_score'];
		if ( $score < 70 ) {
			$next_steps[] = array(
				'title'       => 'Focus on Basic Optimizations',
				'description' => 'Enable caching and minification first',
				'priority'    => 'high',
			);
		} elseif ( $score < 90 ) {
			$next_steps[] = array(
				'title'       => 'Implement Advanced Features',
				'description' => 'Consider image optimization and advanced caching',
				'priority'    => 'medium',
			);
		}

		return $next_steps;
	}

	/**
	 * Get directory size.
	 *
	 * @param string $directory Directory path.
	 * @return int Directory size in bytes.
	 */
	private function get_directory_size( string $directory ): int {
		$size = 0;
		if ( is_dir( $directory ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
			}
		}
		return $size;
	}
}
