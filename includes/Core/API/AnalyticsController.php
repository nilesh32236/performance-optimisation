<?php
/**
 * Analytics Controller Class
 *
 * Handles REST API endpoints for analytics and performance metrics.
 *
 * @package PerformanceOptimisation\Core\API
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics Controller class for REST API endpoints.
 */
class AnalyticsController {

	/**
	 * Metrics collector instance.
	 *
	 * @var \PerformanceOptimisation\Core\Analytics\MetricsCollector
	 */
	private \PerformanceOptimisation\Core\Analytics\MetricsCollector $metrics_collector;

	/**
	 * Performance analyzer instance.
	 *
	 * @var \PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer
	 */
	private \PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer $performance_analyzer;

	/**
	 * Constructor.
	 *
	 * @param \PerformanceOptimisation\Core\Analytics\MetricsCollector    $metrics_collector Metrics collector instance.
	 * @param \PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer $performance_analyzer Performance analyzer instance.
	 */
	public function __construct( \PerformanceOptimisation\Core\Analytics\MetricsCollector $metrics_collector, \PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer $performance_analyzer ) {
		$this->metrics_collector    = $metrics_collector;
		$this->performance_analyzer = $performance_analyzer;
	}

	/**
	 * Register analytics REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'performance-optimisation/v1',
			'/analytics/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard_data' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			'performance-optimisation/v1',
			'/analytics/metrics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_metrics_data' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'metric'     => array(
						'required'    => true,
						'type'        => 'string',
						'description' => 'Metric name to retrieve',
					),
					'period'     => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'day',
						'enum'     => array( 'hour', 'day', 'week', 'month' ),
					),
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
				),
			)
		);

		register_rest_route(
			'performance-optimisation/v1',
			'/analytics/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_performance_report' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
				),
			)
		);

		register_rest_route(
			'performance-optimisation/v1',
			'/analytics/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_analytics_data' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'format'     => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'json',
						'enum'     => array( 'json', 'csv' ),
					),
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
						'format'   => 'date',
					),
				),
			)
		);
	}

	/**
	 * Check user permissions for analytics endpoints.
	 *
	 * @return bool True if user has permission.
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get dashboard analytics data.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function get_dashboard_data( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$end_date   = current_time( 'Y-m-d' );
			$start_date = date( 'Y-m-d', strtotime( '-7 days' ) );

			// Get performance report
			$report = $this->performance_analyzer->generate_performance_report( $start_date, $end_date );

			// Get current optimization status
			$optimization_status = $this->get_optimization_status();

			// Get recent metrics for charts
			$chart_data = $this->get_chart_data( $start_date, $end_date );

			$dashboard_data = array(
				'overview'            => $report['overview'],
				'optimization_status' => $optimization_status,
				'charts'              => $chart_data,
				'recommendations'     => array_slice( $report['recommendations'], 0, 3 ), // Top 3 recommendations
				'last_updated'        => current_time( 'mysql' ),
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $dashboard_data,
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'analytics_error',
				__( 'Failed to retrieve dashboard data.', 'performance-optimisation' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get specific metrics data.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function get_metrics_data( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$metric     = $request->get_param( 'metric' );
			$period     = $request->get_param( 'period' );
			$start_date = $request->get_param( 'start_date' );
			$end_date   = $request->get_param( 'end_date' );

			// Set default date range if not provided
			if ( ! $start_date || ! $end_date ) {
				$end_date = current_time( 'Y-m-d' );
				switch ( $period ) {
					case 'hour':
						$start_date = date( 'Y-m-d', strtotime( '-1 day' ) );
						break;
					case 'week':
						$start_date = date( 'Y-m-d', strtotime( '-4 weeks' ) );
						break;
					case 'month':
						$start_date = date( 'Y-m-d', strtotime( '-3 months' ) );
						break;
					default:
						$start_date = date( 'Y-m-d', strtotime( '-7 days' ) );
				}
			}

			// Get aggregated metrics
			$metrics_data = $this->metrics_collector->get_aggregated_metrics(
				$metric,
				$period,
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			);

			// Format data for charts
			$formatted_data = $this->format_metrics_for_chart( $metrics_data, $metric );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $formatted_data,
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'metrics_error',
				__( 'Failed to retrieve metrics data.', 'performance-optimisation' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get performance report.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function get_performance_report( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$start_date = $request->get_param( 'start_date' ) ?: date( 'Y-m-d', strtotime( '-30 days' ) );
			$end_date   = $request->get_param( 'end_date' ) ?: current_time( 'Y-m-d' );

			$report = $this->performance_analyzer->generate_performance_report( $start_date, $end_date );

			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => $report,
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'report_error',
				__( 'Failed to generate performance report.', 'performance-optimisation' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Export analytics data.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response REST response object.
	 */
	public function export_analytics_data( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$format     = $request->get_param( 'format' );
			$start_date = $request->get_param( 'start_date' ) ?: date( 'Y-m-d', strtotime( '-30 days' ) );
			$end_date   = $request->get_param( 'end_date' ) ?: current_time( 'Y-m-d' );

			$report = $this->performance_analyzer->generate_performance_report( $start_date, $end_date );

			if ( $format === 'csv' ) {
				$csv_data = $this->convert_report_to_csv( $report );
				return rest_ensure_response(
					array(
						'success'  => true,
						'data'     => $csv_data,
						'filename' => 'performance-report-' . $start_date . '-to-' . $end_date . '.csv',
					)
				);
			} else {
				return rest_ensure_response(
					array(
						'success'  => true,
						'data'     => $report,
						'filename' => 'performance-report-' . $start_date . '-to-' . $end_date . '.json',
					)
				);
			}
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'export_error',
				__( 'Failed to export analytics data.', 'performance-optimisation' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get current optimization status.
	 *
	 * @return array<string, mixed> Optimization status data.
	 */
	private function get_optimization_status(): array {
		$settings = get_option( 'wppo_settings', array() );
		$img_info = get_option( 'wppo_img_info', array() );

		$total_optimized_images = 0;
		$total_pending_images   = 0;
		foreach ( array( 'webp', 'avif' ) as $format ) {
			$total_optimized_images += count( $img_info['completed'][ $format ] ?? array() );
			$total_pending_images   += count( $img_info['pending'][ $format ] ?? array() );
		}

		return array(
			'features'           => array(
				'page_caching'       => $settings['cache_settings']['enablePageCaching'] ?? false,
				'css_minification'   => $settings['file_optimisation']['minifyCSS'] ?? false,
				'js_minification'    => $settings['file_optimisation']['minifyJS'] ?? false,
				'html_minification'  => $settings['file_optimisation']['minifyHTML'] ?? false,
				'image_lazy_loading' => $settings['image_optimisation']['lazyLoadImages'] ?? false,
				'image_conversion'   => $settings['image_optimisation']['convertImg'] ?? false,
			),
			'image_optimization' => array(
				'total_optimized'    => $total_optimized_images,
				'total_pending'      => $total_pending_images,
				'optimization_ratio' => $total_optimized_images + $total_pending_images > 0
					? ( $total_optimized_images / ( $total_optimized_images + $total_pending_images ) ) * 100
					: 0,
			),
		);
	}

	/**
	 * Get chart data for dashboard.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<string, mixed> Chart data.
	 */
	private function get_chart_data( string $start_date, string $end_date ): array {
		$charts = array();

		// Page load time chart
		$load_time_data           = $this->metrics_collector->get_aggregated_metrics(
			'page_load_time',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);
		$charts['page_load_time'] = $this->format_metrics_for_chart( $load_time_data, 'page_load_time' );

		// Cache hit ratio chart
		$cache_data                = $this->metrics_collector->get_aggregated_metrics(
			'cache_hit',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);
		$charts['cache_hit_ratio'] = $this->format_cache_metrics_for_chart( $cache_data );

		// Memory usage chart
		$memory_data            = $this->metrics_collector->get_aggregated_metrics(
			'memory_usage',
			'day',
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);
		$charts['memory_usage'] = $this->format_metrics_for_chart( $memory_data, 'memory_usage' );

		return $charts;
	}

	/**
	 * Format metrics data for chart display.
	 *
	 * @param array<string, mixed> $metrics_data Raw metrics data.
	 * @param string               $metric_name Metric name.
	 * @return array<string, mixed> Formatted chart data.
	 */
	private function format_metrics_for_chart( array $metrics_data, string $metric_name ): array {
		$daily_trends = array();
		$avg_values   = array();

		if ( ! empty( $metrics_data['data'] ) ) {
			foreach ( $metrics_data['data'] as $row ) {
				if ( $row['aggregation_type'] === 'AVG' ) {
					$date           = date( 'Y-m-d', strtotime( $row['period_start'] ) );
					$daily_trends[] = array(
						'date'         => $date,
						'value'        => round( (float) $row['aggregated_value'], 2 ),
						'sample_count' => (int) $row['sample_count'],
					);
					$avg_values[]   = (float) $row['aggregated_value'];
				}
			}
		}

		return array(
			'daily_trends' => $daily_trends,
			'average'      => count( $avg_values ) > 0 ? round( array_sum( $avg_values ) / count( $avg_values ), 2 ) : 0,
			'metric_name'  => $metric_name,
		);
	}

	/**
	 * Format cache metrics data for chart display.
	 *
	 * @param array<string, mixed> $cache_data Raw cache metrics data.
	 * @return array<string, mixed> Formatted chart data.
	 */
	private function format_cache_metrics_for_chart( array $cache_data ): array {
		$daily_trends = array();
		$hit_ratios   = array();

		if ( ! empty( $cache_data['data'] ) ) {
			$grouped_data = array();

			// Group data by date
			foreach ( $cache_data['data'] as $row ) {
				$date = date( 'Y-m-d', strtotime( $row['period_start'] ) );
				$grouped_data[ $date ][ $row['aggregation_type'] ] = $row;
			}

			// Calculate hit ratios for each day
			foreach ( $grouped_data as $date => $day_data ) {
				$hits      = isset( $day_data['SUM'] ) ? (float) $day_data['SUM']['aggregated_value'] : 0;
				$total     = isset( $day_data['COUNT'] ) ? (float) $day_data['COUNT']['aggregated_value'] : 0;
				$hit_ratio = $total > 0 ? ( $hits / $total ) * 100 : 0;

				$daily_trends[] = array(
					'date'  => $date,
					'value' => round( $hit_ratio, 2 ),
					'hits'  => $hits,
					'total' => $total,
				);
				$hit_ratios[]   = $hit_ratio;
			}
		}

		return array(
			'daily_trends' => $daily_trends,
			'average'      => count( $hit_ratios ) > 0 ? round( array_sum( $hit_ratios ) / count( $hit_ratios ), 2 ) : 0,
			'metric_name'  => 'cache_hit_ratio',
		);
	}

	/**
	 * Convert performance report to CSV format.
	 *
	 * @param array<string, mixed> $report Performance report data.
	 * @return string CSV formatted data.
	 */
	private function convert_report_to_csv( array $report ): string {
		$csv_lines = array();

		// Header
		$csv_lines[] = 'Performance Report';
		$csv_lines[] = 'Generated: ' . $report['generated_at'];
		$csv_lines[] = 'Period: ' . $report['period']['start'] . ' to ' . $report['period']['end'];
		$csv_lines[] = '';

		// Overview
		$csv_lines[] = 'Overview';
		$csv_lines[] = 'Performance Score,' . $report['overview']['performance_score'];
		$csv_lines[] = 'Average Load Time (ms),' . $report['overview']['average_load_time'];
		$csv_lines[] = 'Cache Hit Ratio (%),' . $report['overview']['cache_hit_ratio'];
		$csv_lines[] = 'Total Page Views,' . $report['overview']['total_page_views'];
		$csv_lines[] = '';

		// Daily trends
		if ( ! empty( $report['page_load_performance']['daily_trends'] ) ) {
			$csv_lines[] = 'Daily Performance Trends';
			$csv_lines[] = 'Date,Average Load Time (ms),Sample Count';
			foreach ( $report['page_load_performance']['daily_trends'] as $trend ) {
				$csv_lines[] = $trend['date'] . ',' . $trend['average_load_time'] . ',' . $trend['sample_count'];
			}
			$csv_lines[] = '';
		}

		// Recommendations
		if ( ! empty( $report['recommendations'] ) ) {
			$csv_lines[] = 'Recommendations';
			$csv_lines[] = 'Priority,Title,Description';
			foreach ( $report['recommendations'] as $rec ) {
				$csv_lines[] = $rec['priority'] . ',"' . $rec['title'] . '","' . $rec['description'] . '"';
			}
		}

		return implode( "\n", $csv_lines );
	}
}
