<?php
/**
 * Performance Monitor Service
 *
 * @package PerformanceOptimisation\Services
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Utils\LoggingUtil;

/**
 * Performance Monitor Service Class
 *
 * Real-time performance monitoring with intelligent alerts and insights
 */
class PerformanceMonitorService {

	private AnalyticsService $analytics;
	private IntelligentOptimizationService $optimizer;
	private array $monitoring_config;
	private array $alert_thresholds;

	public function __construct( AnalyticsService $analytics, IntelligentOptimizationService $optimizer ) {
		$this->analytics = $analytics;
		$this->optimizer = $optimizer;
		$this->initializeMonitoringConfig();
		$this->initializeAlertThresholds();
	}

	/**
	 * Get real-time performance dashboard data
	 */
	public function getDashboardData(): array {
		$current_metrics = $this->getCurrentMetrics();
		$historical_data = $this->getHistoricalData();
		$alerts          = $this->getActiveAlerts();
		$recommendations = $this->optimizer->getOptimizationRecommendations();

		return array(
			'overview'             => array(
				'performance_score' => $current_metrics['performance_score'],
				'status'            => $this->getPerformanceStatus( $current_metrics['performance_score'] ),
				'trend'             => $this->calculateTrend( $historical_data ),
				'last_updated'      => current_time( 'mysql' ),
			),
			'core_metrics'         => array(
				'page_load_time'       => $current_metrics['page_load_time'],
				'cache_hit_rate'       => $current_metrics['cache_hit_rate'],
				'core_vitals'          => $current_metrics['core_vitals'],
				'server_response_time' => $this->getServerResponseTime(),
				'memory_usage'         => $this->getMemoryUsage(),
				'database_queries'     => $this->getDatabaseQueries(),
			),
			'real_time_stats'      => array(
				'active_users'        => $this->getActiveUsers(),
				'requests_per_minute' => $this->getRequestsPerMinute(),
				'error_rate'          => $this->getErrorRate(),
				'cache_operations'    => $this->getCacheOperations(),
			),
			'performance_insights' => array(
				'bottlenecks'                => $this->identifyBottlenecks(),
				'optimization_opportunities' => $this->getOptimizationOpportunities(),
				'resource_usage'             => $this->getResourceUsageAnalysis(),
				'user_experience_metrics'    => $this->getUserExperienceMetrics(),
			),
			'alerts'               => $alerts,
			'recommendations'      => array_slice( $recommendations, 0, 3 ), // Top 3 recommendations
			'charts_data'          => array(
				'performance_trend'    => $this->getPerformanceTrendData(),
				'cache_performance'    => $this->getCachePerformanceData(),
				'core_vitals_trend'    => $this->getCoreVitalsTrendData(),
				'resource_usage_trend' => $this->getResourceUsageTrendData(),
			),
		);
	}

	/**
	 * Monitor performance in real-time
	 */
	public function startRealTimeMonitoring(): void {
		// Set up real-time monitoring hooks
		add_action( 'wp_loaded', array( $this, 'trackPageLoad' ) );
		add_action( 'wp_footer', array( $this, 'injectPerformanceTracking' ) );
		add_filter( 'wp_die_handler', array( $this, 'trackErrors' ) );

		// Schedule performance checks
		if ( ! wp_next_scheduled( 'wppo_performance_check' ) ) {
			wp_schedule_event( time(), 'every_minute', 'wppo_performance_check' );
		}

		add_action( 'wppo_performance_check', array( $this, 'performScheduledCheck' ) );
	}

	/**
	 * Track page load performance
	 */
	public function trackPageLoad(): void {
		$start_time = microtime( true );

		add_action(
			'wp_footer',
			function () use ( $start_time ) {
				$load_time = microtime( true ) - $start_time;

				$metrics = array(
					'load_time'   => $load_time,
					'memory_peak' => memory_get_peak_usage( true ),
					'db_queries'  => get_num_queries(),
					'url'         => $_SERVER['REQUEST_URI'] ?? '',
					'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
					'is_mobile'   => wp_is_mobile(),
					'timestamp'   => current_time( 'mysql' ),
				);

				$this->analytics->trackPageLoad( $load_time, $metrics );
				$this->checkPerformanceThresholds( $metrics );
			},
			999
		);
	}

	/**
	 * Inject performance tracking JavaScript
	 */
	public function injectPerformanceTracking(): void {
		if ( ! $this->shouldTrackPerformance() ) {
			return;
		}

		?>
		<script>
		(function() {
			// Core Web Vitals tracking
			function trackCoreWebVitals() {
				if ('PerformanceObserver' in window) {
					// Track LCP
					new PerformanceObserver((list) => {
						const entries = list.getEntries();
						const lastEntry = entries[entries.length - 1];
						sendMetric('lcp', lastEntry.startTime);
					}).observe({entryTypes: ['largest-contentful-paint']});

					// Track FID
					new PerformanceObserver((list) => {
						const entries = list.getEntries();
						entries.forEach((entry) => {
							sendMetric('fid', entry.processingStart - entry.startTime);
						});
					}).observe({entryTypes: ['first-input']});

					// Track CLS
					let clsValue = 0;
					new PerformanceObserver((list) => {
						for (const entry of list.getEntries()) {
							if (!entry.hadRecentInput) {
								clsValue += entry.value;
								sendMetric('cls', clsValue);
							}
						}
					}).observe({entryTypes: ['layout-shift']});
				}

				// Track additional metrics
				window.addEventListener('load', function() {
					setTimeout(function() {
						const navigation = performance.getEntriesByType('navigation')[0];
						if (navigation) {
							sendMetric('fcp', navigation.responseStart - navigation.fetchStart);
							sendMetric('ttfb', navigation.responseStart - navigation.requestStart);
							sendMetric('dom_load', navigation.domContentLoadedEventEnd - navigation.domContentLoadedEventStart);
						}
					}, 0);
				});
			}

			function sendMetric(name, value) {
				if (typeof wppoAjax !== 'undefined') {
					fetch(wppoAjax.ajaxurl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: new URLSearchParams({
							action: 'wppo_track_metric',
							nonce: wppoAjax.nonce,
							metric: name,
							value: value,
							url: window.location.pathname
						})
					});
				}
			}

			// Initialize tracking
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', trackCoreWebVitals);
			} else {
				trackCoreWebVitals();
			}
		})();
		</script>
		<?php
	}

	/**
	 * Perform scheduled performance check
	 */
	public function performScheduledCheck(): void {
		$current_metrics = $this->getCurrentMetrics();

		// Check for performance degradation
		$this->checkPerformanceDegradation( $current_metrics );

		// Check resource usage
		$this->checkResourceUsage();

		// Check for errors
		$this->checkErrorRates();

		// Update performance trends
		$this->updatePerformanceTrends( $current_metrics );

		// Generate alerts if needed
		$this->generatePerformanceAlerts( $current_metrics );
	}

	/**
	 * Get current performance metrics
	 */
	private function getCurrentMetrics(): array {
		return array(
			'performance_score' => $this->analytics->getPerformanceScore(),
			'page_load_time'    => $this->analytics->getAverageLoadTime( 1 ), // Last 24 hours
			'cache_hit_rate'    => $this->analytics->getCacheHitRate(),
			'core_vitals'       => $this->analytics->getCoreWebVitals(),
		);
	}

	/**
	 * Get historical performance data
	 */
	private function getHistoricalData( int $days = 7 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wppo_performance_stats';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					DATE(recorded_at) as date,
					AVG(JSON_EXTRACT(metric_value, '$.load_time')) as avg_load_time,
					COUNT(*) as page_views
				FROM {$table_name} 
				WHERE metric_name = 'page_load' 
				AND recorded_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY DATE(recorded_at)
				ORDER BY date ASC",
				$days
			)
		);
	}

	/**
	 * Get active performance alerts
	 */
	private function getActiveAlerts(): array {
		$alerts = get_option( 'wppo_active_alerts', array() );

		// Filter out expired alerts
		$current_time = time();
		$alerts       = array_filter(
			$alerts,
			function ( $alert ) use ( $current_time ) {
				return ( $current_time - $alert['timestamp'] ) < 3600; // 1 hour expiry
			}
		);

		update_option( 'wppo_active_alerts', $alerts );

		return array_values( $alerts );
	}

	/**
	 * Identify performance bottlenecks
	 */
	private function identifyBottlenecks(): array {
		$bottlenecks = array();

		// Check database performance
		$avg_queries = $this->getDatabaseQueries();
		if ( $avg_queries > 50 ) {
			$bottlenecks[] = array(
				'type'           => 'database',
				'severity'       => 'high',
				'description'    => "High number of database queries ({$avg_queries} avg)",
				'recommendation' => 'Enable object caching and optimize database queries',
			);
		}

		// Check memory usage
		$memory_usage = $this->getMemoryUsage();
		if ( $memory_usage['percentage'] > 80 ) {
			$bottlenecks[] = array(
				'type'           => 'memory',
				'severity'       => 'warning',
				'description'    => "High memory usage ({$memory_usage['percentage']}%)",
				'recommendation' => 'Optimize plugins and increase memory limit if needed',
			);
		}

		// Check server response time
		$response_time = $this->getServerResponseTime();
		if ( $response_time > 1000 ) { // 1 second
			$bottlenecks[] = array(
				'type'           => 'server',
				'severity'       => 'high',
				'description'    => "Slow server response time ({$response_time}ms)",
				'recommendation' => 'Optimize server configuration and enable caching',
			);
		}

		return $bottlenecks;
	}

	/**
	 * Get optimization opportunities
	 */
	private function getOptimizationOpportunities(): array {
		$opportunities = array();

		// Check cache hit rate
		$cache_hit_rate = $this->analytics->getCacheHitRate();
		if ( $cache_hit_rate < 80 ) {
			$opportunities[] = array(
				'type'                  => 'caching',
				'impact'                => 'high',
				'description'           => 'Improve cache hit rate',
				'potential_improvement' => '20-30% faster load times',
			);
		}

		// Check image optimization
		$optimized_images = $this->analytics->getOptimizedImagesCount();
		$total_images     = $this->getTotalImagesCount();
		if ( $total_images > 0 && ( $optimized_images / $total_images ) < 0.8 ) {
			$opportunities[] = array(
				'type'                  => 'images',
				'impact'                => 'medium',
				'description'           => 'Optimize uncompressed images',
				'potential_improvement' => '15-25% bandwidth savings',
			);
		}

		return $opportunities;
	}

	/**
	 * Check performance thresholds and generate alerts
	 */
	private function checkPerformanceThresholds( array $metrics ): void {
		$alerts = array();

		// Check load time threshold
		if ( $metrics['load_time'] > $this->alert_thresholds['load_time'] ) {
			$alerts[] = array(
				'type'      => 'performance',
				'severity'  => 'warning',
				'message'   => sprintf( 'Slow page load detected: %.2fs', $metrics['load_time'] ),
				'timestamp' => time(),
				'url'       => $metrics['url'],
			);
		}

		// Check memory usage threshold
		if ( $metrics['memory_peak'] > $this->alert_thresholds['memory_usage'] ) {
			$alerts[] = array(
				'type'      => 'memory',
				'severity'  => 'warning',
				'message'   => sprintf( 'High memory usage: %s', $this->formatBytes( $metrics['memory_peak'] ) ),
				'timestamp' => time(),
				'url'       => $metrics['url'],
			);
		}

		// Check database queries threshold
		if ( $metrics['db_queries'] > $this->alert_thresholds['db_queries'] ) {
			$alerts[] = array(
				'type'      => 'database',
				'severity'  => 'info',
				'message'   => sprintf( 'High number of database queries: %d', $metrics['db_queries'] ),
				'timestamp' => time(),
				'url'       => $metrics['url'],
			);
		}

		if ( ! empty( $alerts ) ) {
			$this->addAlerts( $alerts );
		}
	}

	/**
	 * Initialize monitoring configuration
	 */
	private function initializeMonitoringConfig(): void {
		$this->monitoring_config = array(
			'track_core_vitals'       => true,
			'track_user_interactions' => true,
			'track_resource_timing'   => true,
			'track_navigation_timing' => true,
			'sample_rate'             => 0.1, // 10% sampling
			'excluded_urls'           => array(
				'/wp-admin/',
				'/wp-login.php',
				'/xmlrpc.php',
			),
		);
	}

	/**
	 * Initialize alert thresholds
	 */
	private function initializeAlertThresholds(): void {
		$this->alert_thresholds = array(
			'load_time'      => 3.0, // seconds
			'memory_usage'   => 128 * 1024 * 1024, // 128MB
			'db_queries'     => 100,
			'error_rate'     => 0.05, // 5%
			'cache_hit_rate' => 60, // percentage
		);
	}

	// Helper methods
	private function getPerformanceStatus( int $score ): string {
		if ( $score >= 90 ) {
			return 'excellent';
		}
		if ( $score >= 80 ) {
			return 'good';
		}
		if ( $score >= 60 ) {
			return 'fair';
		}
		return 'poor';
	}

	private function calculateTrend( array $historical_data ): string {
		if ( count( $historical_data ) < 2 ) {
			return 'stable';
		}

		$recent = array_slice( $historical_data, -3 );
		$older  = array_slice( $historical_data, 0, 3 );

		$recent_avg = array_sum( array_column( $recent, 'avg_load_time' ) ) / count( $recent );
		$older_avg  = array_sum( array_column( $older, 'avg_load_time' ) ) / count( $older );

		$change = ( $recent_avg - $older_avg ) / $older_avg;

		if ( $change > 0.1 ) {
			return 'declining';
		}
		if ( $change < -0.1 ) {
			return 'improving';
		}
		return 'stable';
	}

	private function shouldTrackPerformance(): bool {
		// Don't track admin pages
		if ( is_admin() ) {
			return false;
		}

		// Sample rate check
		if ( mt_rand() / mt_getrandmax() > $this->monitoring_config['sample_rate'] ) {
			return false;
		}

		// Check excluded URLs
		$current_url = $_SERVER['REQUEST_URI'] ?? '';
		foreach ( $this->monitoring_config['excluded_urls'] as $excluded ) {
			if ( strpos( $current_url, $excluded ) !== false ) {
				return false;
			}
		}

		return true;
	}

	private function addAlerts( array $alerts ): void {
		$existing_alerts = get_option( 'wppo_active_alerts', array() );
		$existing_alerts = array_merge( $existing_alerts, $alerts );
		update_option( 'wppo_active_alerts', $existing_alerts );
	}

	private function formatBytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		for ( $i = 0; $bytes > 1024 && $i < 3; $i++ ) {
			$bytes /= 1024;
		}
		return round( $bytes, 2 ) . ' ' . $units[ $i ];
	}

	// Placeholder methods for additional functionality
	private function getServerResponseTime(): int {
		return 500; }
	private function getMemoryUsage(): array {
		return array(
			'percentage' => 65,
			'used'       => '128MB',
			'limit'      => '256MB',
		); }
	private function getDatabaseQueries(): int {
		return 35; }
	private function getActiveUsers(): int {
		return 12; }
	private function getRequestsPerMinute(): int {
		return 45; }
	private function getErrorRate(): float {
		return 0.02; }
	private function getCacheOperations(): array {
		return array(
			'hits'   => 150,
			'misses' => 25,
		); }
	private function getResourceUsageAnalysis(): array {
		return array(); }
	private function getUserExperienceMetrics(): array {
		return array(); }
	private function getPerformanceTrendData(): array {
		return array(); }
	private function getCachePerformanceData(): array {
		return array(); }
	private function getCoreVitalsTrendData(): array {
		return array(); }
	private function getResourceUsageTrendData(): array {
		return array(); }
	private function checkPerformanceDegradation( array $metrics ): void {}
	private function checkResourceUsage(): void {}
	private function checkErrorRates(): void {}
	private function updatePerformanceTrends( array $metrics ): void {}
	private function generatePerformanceAlerts( array $metrics ): void {}
	private function getTotalImagesCount(): int {
		return 100; }
}
