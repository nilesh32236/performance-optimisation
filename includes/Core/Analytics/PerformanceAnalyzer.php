<?php
/**
 * Performance Analyzer Class
 *
 * Analyzes performance metrics and provides insights, recommendations,
 * and performance scoring based on collected data.
 *
 * @package PerformanceOptimisation\Core\Analytics
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Analyzer class for analyzing performance metrics.
 */
class PerformanceAnalyzer {

	/**
	 * Metrics collector instance.
	 *
	 * @var MetricsCollector
	 */
	private MetricsCollector $metrics_collector;

	/**
	 * Constructor.
	 *
	 * @param MetricsCollector $metrics_collector Metrics collector instance.
	 */
	public function __construct( MetricsCollector $metrics_collector ) {
		$this->metrics_collector = $metrics_collector;
	}

	/**
	 * Generate performance report for a time period.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date End date (Y-m-d).
	 * @return array<string, mixed> Performance report.
	 */
	public function generate_performance_report( string $start_date, string $end_date ): array {
		$report = array(
			'period'                => array(
				'start' => $start_date,
				'end'   => $end_date,
			),
			'overview'              => $this->get_performance_overview( $start_date, $end_date ),
			'page_load_performance' => $this->analyze_page_load_performance( $start_date, $end_date ),
			'cache_performance'     => $this->analyze_cache_performance( $start_date, $end_date ),
			'optimization_impact'   => $this->analyze_optimization_impact( $start_date, $end_date ),
			'recommendations'       => $this->generate_recommendations( $start_date, $end_date ),
			'trends'                => $this->analyze_trends( $start_date, $end_date ),
			'generated_at'          => current_time( 'mysql' ),
		);

		return $report;
	}

	/**
	 * Get performance overview.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<string, mixed> Performance overview.
	 */
	private function get_performance_overview( string $start_date, string $end_date ): array {
		$page_load_data = $this->metrics_collector->get_aggregated_metrics(
			'page_load_time',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		$cache_hit_data = $this->metrics_collector->get_aggregated_metrics(
			'cache_hit',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		// Calculate averages.
		$avg_load_time   = $this->calculate_average_from_aggregated( $page_load_data['data'], 'AVG' );
		$cache_hit_ratio = $this->calculate_cache_hit_ratio( $cache_hit_data['data'] );

		// Calculate performance score.
		$performance_score = $this->calculate_performance_score( $avg_load_time, $cache_hit_ratio );

		return array(
			'performance_score'   => $performance_score,
			'average_load_time'   => round( $avg_load_time, 2 ),
			'cache_hit_ratio'     => round( $cache_hit_ratio, 2 ),
			'total_page_views'    => $this->get_total_page_views( $start_date, $end_date ),
			'optimization_status' => $this->get_optimization_status(),
		);
	}

	/**
	 * Analyze page load performance.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<string, mixed> Page load performance analysis.
	 */
	private function analyze_page_load_performance( string $start_date, string $end_date ): array {
		$load_time_data = $this->metrics_collector->get_aggregated_metrics(
			'page_load_time',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		$analysis = array(
			'average_load_time'   => 0,
			'median_load_time'    => 0,
			'p95_load_time'       => 0,
			'fastest_load_time'   => 0,
			'slowest_load_time'   => 0,
			'daily_trends'        => array(),
			'page_type_breakdown' => array(),
		);

		if ( ! empty( $load_time_data['data'] ) ) {
			$avg_values                    = array_column( $load_time_data['data'], 'aggregated_value' );
			$analysis['average_load_time'] = round( array_sum( $avg_values ) / count( $avg_values ), 2 );

			// Get min/max from aggregated data.
			$min_data = $this->metrics_collector->get_aggregated_metrics(
				'page_load_time',
				'day',
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			);
			$max_data = $this->metrics_collector->get_aggregated_metrics(
				'page_load_time',
				'day',
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			);

			// Build daily trends.
			foreach ( $load_time_data['data'] as $day_data ) {
				$analysis['daily_trends'][] = array(
					'date'              => $day_data['period_start'],
					'average_load_time' => round( $day_data['aggregated_value'], 2 ),
					'sample_count'      => $day_data['sample_count'],
				);
			}
		}

		return $analysis;
	}

	/**
	 * Analyze cache performance.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<string, mixed> Cache performance analysis.
	 */
	private function analyze_cache_performance( string $start_date, string $end_date ): array {
		$cache_data = $this->metrics_collector->get_aggregated_metrics(
			'cache_hit',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		$analysis = array(
			'overall_hit_ratio'   => 0,
			'daily_hit_ratios'    => array(),
			'cache_effectiveness' => 'unknown',
			'total_hits'          => 0,
			'total_requests'      => 0,
		);

		if ( ! empty( $cache_data['data'] ) ) {
			$total_hits     = 0;
			$total_requests = 0;

			foreach ( $cache_data['data'] as $day_data ) {
				if ( 'SUM' === $day_data['aggregation_type'] ) {
					$total_hits += $day_data['aggregated_value'];
				} elseif ( 'COUNT' === $day_data['aggregation_type'] ) {
					$total_requests += $day_data['aggregated_value'];
				}

				$daily_hit_ratio = 0 < $day_data['sample_count']
					? ( $day_data['aggregated_value'] / $day_data['sample_count'] ) * 100
					: 0;

				$analysis['daily_hit_ratios'][] = array(
					'date'      => $day_data['period_start'],
					'hit_ratio' => round( $daily_hit_ratio, 2 ),
					'requests'  => $day_data['sample_count'],
				);
			}

			$analysis['total_hits']        = $total_hits;
			$analysis['total_requests']    = $total_requests;
			$analysis['overall_hit_ratio'] = $total_requests > 0
				? round( ( $total_hits / $total_requests ) * 100, 2 )
				: 0;

			// Determine cache effectiveness.
			if ( $analysis['overall_hit_ratio'] >= 80 ) {
				$analysis['cache_effectiveness'] = 'excellent';
			} elseif ( $analysis['overall_hit_ratio'] >= 60 ) {
				$analysis['cache_effectiveness'] = 'good';
			} elseif ( $analysis['overall_hit_ratio'] >= 40 ) {
				$analysis['cache_effectiveness'] = 'fair';
			} else {
				$analysis['cache_effectiveness'] = 'poor';
			}
		}

		return $analysis;
	}

	/**
	 * Analyze optimization impact.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<string, mixed> Optimization impact analysis.
	 */
	private function analyze_optimization_impact( string $start_date, string $end_date ): array {
		$analysis = array(
			'enabled_optimizations'   => 0,
			'optimization_features'   => array(),
			'estimated_savings'       => array(),
			'before_after_comparison' => array(),
		);

		// Get current optimization status.
		$settings              = get_option( 'wppo_settings', array() );
		$optimization_features = array(
			'Page Caching'       => $settings['cache_settings']['enablePageCaching'] ?? false,
			'CSS Minification'   => $settings['file_optimisation']['minifyCSS'] ?? false,
			'JS Minification'    => $settings['file_optimisation']['minifyJS'] ?? false,
			'HTML Minification'  => $settings['file_optimisation']['minifyHTML'] ?? false,
			'Image Lazy Loading' => $settings['image_optimisation']['lazyLoadImages'] ?? false,
			'Image Conversion'   => $settings['image_optimisation']['convertImg'] ?? false,
		);

		$enabled_count = 0;
		foreach ( $optimization_features as $feature => $enabled ) {
			$analysis['optimization_features'][ $feature ] = $enabled;
			if ( $enabled ) {
				++$enabled_count;
			}
		}

		$analysis['enabled_optimizations'] = $enabled_count;

		// Calculate estimated savings.
		$analysis['estimated_savings'] = $this->calculate_optimization_savings( $optimization_features );

		return $analysis;
	}

	/**
	 * Generate performance recommendations.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<string, mixed> Performance recommendations.
	 */
	private function generate_recommendations( string $start_date, string $end_date ): array {
		$recommendations = array();

		// Analyze current performance.
		$overview       = $this->get_performance_overview( $start_date, $end_date );
		$cache_analysis = $this->analyze_cache_performance( $start_date, $end_date );

		// Page load time recommendations.
		if ( $overview['average_load_time'] > 3000 ) { // > 3 seconds.
			$recommendations[] = array(
				'type'        => 'performance',
				'priority'    => 'high',
				'title'       => 'Improve Page Load Times',
				'description' => sprintf(
					'Your average page load time is %.2f seconds. Consider enabling more optimizations.',
					$overview['average_load_time'] / 1000
				),
				'actions'     => array(
					'Enable page caching',
					'Enable CSS and JS minification',
					'Optimize images',
				),
			);
		}

		// Cache hit ratio recommendations.
		if ( $cache_analysis['overall_hit_ratio'] < 60 ) {
			$recommendations[] = array(
				'type'        => 'caching',
				'priority'    => 'medium',
				'title'       => 'Improve Cache Hit Ratio',
				'description' => sprintf(
					'Your cache hit ratio is %.1f%%. This could be improved.',
					$cache_analysis['overall_hit_ratio']
				),
				'actions'     => array(
					'Review cache exclusion rules',
					'Increase cache expiration time',
					'Enable cache preloading',
				),
			);
		}

		// Optimization recommendations.
		$settings = get_option( 'wppo_settings', array() );
		if ( empty( $settings['image_optimisation']['convertImg'] ) ) {
			$img_info     = get_option( 'wppo_img_info', array() );
			$total_images = 0;
			foreach ( array( 'webp', 'avif' ) as $format ) {
				$total_images += count( $img_info['pending'][ $format ] ?? array() );
			}

			if ( $total_images > 10 ) {
				$recommendations[] = array(
					'type'        => 'optimization',
					'priority'    => 'medium',
					'title'       => 'Enable Image Optimization',
					'description' => sprintf(
						'You have %d images that could be optimized for better performance.',
						$total_images
					),
					'actions'     => array(
						'Enable WebP conversion',
						'Set appropriate image quality',
						'Run bulk image optimization',
					),
				);
			}
		}

		return $recommendations;
	}

	/**
	 * Analyze performance trends.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<string, mixed> Trend analysis.
	 */
	private function analyze_trends( string $start_date, string $end_date ): array {
		$load_time_data = $this->metrics_collector->get_aggregated_metrics(
			'page_load_time',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		$trends = array(
			'load_time_trend'  => 'stable',
			'load_time_change' => 0,
			'cache_trend'      => 'stable',
			'cache_change'     => 0,
			'overall_trend'    => 'stable',
		);

		if ( count( $load_time_data['data'] ) >= 2 ) {
			$first_week = array_slice( $load_time_data['data'], 0, 7 );
			$last_week  = array_slice( $load_time_data['data'], -7 );

			$first_avg = $this->calculate_average_from_aggregated( $first_week, 'AVG' );
			$last_avg  = $this->calculate_average_from_aggregated( $last_week, 'AVG' );

			$change_percent             = $first_avg > 0 ? ( ( $last_avg - $first_avg ) / $first_avg ) * 100 : 0;
			$trends['load_time_change'] = round( $change_percent, 2 );

			if ( $change_percent < -5 ) {
				$trends['load_time_trend'] = 'improving';
			} elseif ( $change_percent > 5 ) {
				$trends['load_time_trend'] = 'declining';
			}
		}

		return $trends;
	}

	/**
	 * Calculate performance score.
	 *
	 * @param float $avg_load_time Average load time in milliseconds.
	 * @param float $cache_hit_ratio Cache hit ratio percentage.
	 * @return int Performance score (0-100).
	 */
	private function calculate_performance_score( float $avg_load_time, float $cache_hit_ratio ): int {
		$score = 100;

		// Deduct points for slow load times.
		if ( $avg_load_time > 1000 ) { // > 1 second.
			$score -= min( 40, ( $avg_load_time - 1000 ) / 100 );
		}

		// Deduct points for poor cache performance.
		if ( $cache_hit_ratio < 80 ) {
			$score -= ( 80 - $cache_hit_ratio ) / 2;
		}

		// Check optimization features.
		$settings              = get_option( 'wppo_settings', array() );
		$optimization_features = array(
			$settings['cache_settings']['enablePageCaching'] ?? false,
			$settings['file_optimisation']['minifyCSS'] ?? false,
			$settings['file_optimisation']['minifyJS'] ?? false,
			$settings['image_optimisation']['lazyLoadImages'] ?? false,
		);

		$enabled_optimizations = count( array_filter( $optimization_features ) );
		$total_optimizations   = count( $optimization_features );
		$optimization_ratio    = $total_optimizations > 0 ? $enabled_optimizations / $total_optimizations : 0;

		// Bonus points for enabled optimizations.
		$score += $optimization_ratio * 10;

		return max( 0, min( 100, round( $score ) ) );
	}

	/**
	 * Calculate average from aggregated data.
	 *
	 * @param array<array<string, mixed>> $data Aggregated data.
	 * @param string                      $aggregation_type Aggregation type to filter by.
	 * @return float Average value.
	 */
	private function calculate_average_from_aggregated( array $data, string $aggregation_type ): float {
		$values = array();
		foreach ( $data as $row ) {
			if ( $row['aggregation_type'] === $aggregation_type ) {
				$values[] = (float) $row['aggregated_value'];
			}
		}

		return count( $values ) > 0 ? array_sum( $values ) / count( $values ) : 0;
	}

	/**
	 * Calculate cache hit ratio from aggregated data.
	 *
	 * @param array<array<string, mixed>> $data Aggregated cache data.
	 * @return float Cache hit ratio percentage.
	 */
	private function calculate_cache_hit_ratio( array $data ): float {
		$total_hits     = 0;
		$total_requests = 0;

		foreach ( $data as $row ) {
			if ( 'SUM' === $row['aggregation_type'] ) {
				$total_hits += $row['aggregated_value'];
			} elseif ( 'COUNT' === $row['aggregation_type'] ) {
				$total_requests += $row['aggregated_value'];
			}
		}

		return 0 < $total_requests ? ( $total_hits / $total_requests ) * 100 : 0;
	}

	/**
	 * Get total page views for period.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return int Total page views.
	 */
	private function get_total_page_views( string $start_date, string $end_date ): int {
		$page_view_data = $this->metrics_collector->get_aggregated_metrics(
			'page_load_time',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		$total_views = 0;
		foreach ( $page_view_data['data'] as $row ) {
			if ( 'COUNT' === $row['aggregation_type'] ) {
				$total_views += $row['sample_count'];
			}
		}

		return $total_views;
	}

	/**
	 * Get current optimization status.
	 *
	 * @return array<string, mixed> Optimization status.
	 */
	private function get_optimization_status(): array {
		$settings = get_option( 'wppo_settings', array() );

		return array(
			'page_caching'       => $settings['cache_settings']['enablePageCaching'] ?? false,
			'css_minification'   => $settings['file_optimisation']['minifyCSS'] ?? false,
			'js_minification'    => $settings['file_optimisation']['minifyJS'] ?? false,
			'html_minification'  => $settings['file_optimisation']['minifyHTML'] ?? false,
			'image_lazy_loading' => $settings['image_optimisation']['lazyLoadImages'] ?? false,
			'image_conversion'   => $settings['image_optimisation']['convertImg'] ?? false,
		);
	}

	/**
	 * Calculate estimated savings from optimizations.
	 *
	 * @param array<string, bool> $optimization_features Enabled optimization features.
	 * @return array<string, mixed> Estimated savings.
	 */
	private function calculate_optimization_savings( array $optimization_features ): array {
		$savings = array(
			'load_time_reduction'   => 0, // Percentage.
			'bandwidth_savings'     => 0,   // Percentage.
			'server_load_reduction' => 0, // Percentage.
		);

		// Estimate savings based on enabled features.
		if ( $optimization_features['Page Caching'] ?? false ) {
			$savings['load_time_reduction']   += 30;
			$savings['server_load_reduction'] += 50;
		}

		if ( $optimization_features['CSS Minification'] ?? false ) {
			$savings['load_time_reduction'] += 5;
			$savings['bandwidth_savings']   += 10;
		}

		if ( $optimization_features['JS Minification'] ?? false ) {
			$savings['load_time_reduction'] += 5;
			$savings['bandwidth_savings']   += 15;
		}

		if ( $optimization_features['Image Lazy Loading'] ?? false ) {
			$savings['load_time_reduction'] += 15;
			$savings['bandwidth_savings']   += 20;
		}

		if ( $optimization_features['Image Conversion'] ?? false ) {
			$savings['bandwidth_savings'] += 25;
		}

		// Cap savings at reasonable maximums.
		$savings['load_time_reduction']   = min( 70, $savings['load_time_reduction'] );
		$savings['bandwidth_savings']     = min( 60, $savings['bandwidth_savings'] );
		$savings['server_load_reduction'] = min( 80, $savings['server_load_reduction'] );

		return $savings;
	}
}
