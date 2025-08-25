<?php
/**
 * Metrics Collector Class
 *
 * Collects and stores performance metrics including page load times,
 * optimization statistics, cache performance, and system metrics.
 *
 * @package PerformanceOptimisation\Core\Analytics
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metrics Collector class for performance metrics collection.
 */
class MetricsCollector {

	/**
	 * Metrics table name.
	 *
	 * @var string
	 */
	private string $metrics_table;

	/**
	 * Aggregated metrics table name.
	 *
	 * @var string
	 */
	private string $aggregated_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->metrics_table    = $wpdb->prefix . 'wppo_metrics';
		$this->aggregated_table = $wpdb->prefix . 'wppo_metrics_aggregated';
	}

	/**
	 * Initialize metrics collection.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'start_performance_tracking' ) );
		add_action( 'wp_footer', array( $this, 'collect_frontend_metrics' ) );
		add_action( 'admin_footer', array( $this, 'collect_admin_metrics' ) );
		add_action( 'wp_loaded', array( $this, 'collect_system_metrics' ) );
		add_action( 'wppo_collect_metrics', array( $this, 'collect_scheduled_metrics' ) );

		// Schedule metrics collection
		if ( ! wp_next_scheduled( 'wppo_collect_metrics' ) ) {
			wp_schedule_event( time(), 'hourly', 'wppo_collect_metrics' );
		}
	}

	/**
	 * Record a metric.
	 *
	 * @param string               $metric_name Metric name.
	 * @param mixed                $value Metric value.
	 * @param array<string, mixed> $tags Additional tags/metadata.
	 * @return bool True if recorded successfully.
	 */
	public function record_metric( string $metric_name, $value, array $tags = array() ): bool {
		global $wpdb;

		$data = array(
			'metric_name'  => $metric_name,
			'metric_value' => is_numeric( $value ) ? $value : wp_json_encode( $value ),
			'tags'         => wp_json_encode( $tags ),
			'recorded_at'  => current_time( 'mysql' ),
			'user_id'      => get_current_user_id(),
			'ip_address'   => $this->get_client_ip(),
			'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'url'          => $_SERVER['REQUEST_URI'] ?? '',
		);

		$result = $wpdb->insert( $this->metrics_table, $data );

		if ( $result === false ) {
			error_log( 'Failed to record metric: ' . $wpdb->last_error );
			return false;
		}

		return true;
	}

	/**
	 * Increment a counter metric.
	 *
	 * @param string               $metric_name Counter name.
	 * @param array<string, mixed> $tags Additional tags.
	 * @return bool True if incremented successfully.
	 */
	public function increment_counter( string $metric_name, array $tags = array() ): bool {
		return $this->record_metric( $metric_name, 1, array_merge( $tags, array( 'type' => 'counter' ) ) );
	}

	/**
	 * Record timing metric.
	 *
	 * @param string               $metric_name Timing metric name.
	 * @param float                $duration Duration in milliseconds.
	 * @param array<string, mixed> $tags Additional tags.
	 * @return bool True if recorded successfully.
	 */
	public function record_timing( string $metric_name, float $duration, array $tags = array() ): bool {
		return $this->record_metric(
			$metric_name,
			$duration,
			array_merge(
				$tags,
				array(
					'type' => 'timing',
					'unit' => 'ms',
				)
			)
		);
	}

	/**
	 * Start performance tracking for current request.
	 *
	 * @return void
	 */
	public function start_performance_tracking(): void {
		if ( ! defined( 'WPPO_START_TIME' ) ) {
			define( 'WPPO_START_TIME', microtime( true ) );
		}

		if ( ! defined( 'WPPO_START_MEMORY' ) ) {
			define( 'WPPO_START_MEMORY', memory_get_usage( true ) );
		}
	}

	/**
	 * Collect frontend performance metrics.
	 *
	 * @return void
	 */
	public function collect_frontend_metrics(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$this->collect_page_load_metrics();
		$this->collect_cache_metrics();
		$this->collect_optimization_metrics();
	}

	/**
	 * Collect admin performance metrics.
	 *
	 * @return void
	 */
	public function collect_admin_metrics(): void {
		if ( ! is_admin() ) {
			return;
		}

		$this->collect_page_load_metrics();
		$this->record_metric(
			'admin_page_view',
			1,
			array(
				'page' => $_GET['page'] ?? 'dashboard',
				'type' => 'admin',
			)
		);
	}

	/**
	 * Collect system metrics.
	 *
	 * @return void
	 */
	public function collect_system_metrics(): void {
		// Only collect system metrics occasionally to avoid overhead
		if ( rand( 1, 100 ) > 5 ) { // 5% chance
			return;
		}

		$this->record_metric(
			'memory_usage',
			memory_get_usage( true ),
			array(
				'type' => 'system',
				'unit' => 'bytes',
			)
		);

		$this->record_metric(
			'memory_peak',
			memory_get_peak_usage( true ),
			array(
				'type' => 'system',
				'unit' => 'bytes',
			)
		);

		global $wpdb;
		$this->record_metric(
			'database_queries',
			$wpdb->num_queries,
			array(
				'type' => 'system',
			)
		);
	}

	/**
	 * Collect scheduled metrics (runs hourly).
	 *
	 * @return void
	 */
	public function collect_scheduled_metrics(): void {
		$this->collect_cache_statistics();
		$this->collect_optimization_statistics();
		$this->collect_image_optimization_stats();
		$this->aggregate_metrics();
		$this->cleanup_old_metrics();
	}

	/**
	 * Collect page load metrics.
	 *
	 * @return void
	 */
	private function collect_page_load_metrics(): void {
		if ( defined( 'WPPO_START_TIME' ) ) {
			$load_time = ( microtime( true ) - WPPO_START_TIME ) * 1000; // Convert to milliseconds
			$this->record_timing(
				'page_load_time',
				$load_time,
				array(
					'page_type' => $this->get_page_type(),
				)
			);
		}

		if ( defined( 'WPPO_START_MEMORY' ) ) {
			$memory_used = memory_get_usage( true ) - WPPO_START_MEMORY;
			$this->record_metric(
				'memory_used',
				$memory_used,
				array(
					'type'      => 'memory',
					'unit'      => 'bytes',
					'page_type' => $this->get_page_type(),
				)
			);
		}
	}

	/**
	 * Collect cache metrics.
	 *
	 * @return void
	 */
	private function collect_cache_metrics(): void {
		// Check if page was served from cache
		$cache_status = $this->get_cache_status();

		$this->record_metric(
			'cache_hit',
			$cache_status === 'hit' ? 1 : 0,
			array(
				'status'    => $cache_status,
				'page_type' => $this->get_page_type(),
			)
		);

		// Record cache generation time if available
		if ( defined( 'WPPO_CACHE_GENERATION_TIME' ) ) {
			$this->record_timing( 'cache_generation_time', WPPO_CACHE_GENERATION_TIME );
		}
	}

	/**
	 * Collect optimization metrics.
	 *
	 * @return void
	 */
	private function collect_optimization_metrics(): void {
		// Track minification usage
		if ( $this->is_minification_active() ) {
			$this->increment_counter(
				'minification_served',
				array(
					'page_type' => $this->get_page_type(),
				)
			);
		}

		// Track lazy loading usage
		if ( $this->is_lazy_loading_active() ) {
			$this->increment_counter(
				'lazy_loading_served',
				array(
					'page_type' => $this->get_page_type(),
				)
			);
		}

		// Track image optimization
		$optimized_images = $this->count_optimized_images_on_page();
		if ( $optimized_images > 0 ) {
			$this->record_metric(
				'optimized_images_served',
				$optimized_images,
				array(
					'page_type' => $this->get_page_type(),
				)
			);
		}
	}

	/**
	 * Collect cache statistics.
	 *
	 * @return void
	 */
	private function collect_cache_statistics(): void {
		// Page cache statistics
		$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
		if ( is_dir( $cache_dir ) ) {
			$cache_stats = $this->get_directory_stats( $cache_dir );
			$this->record_metric(
				'cache_files_count',
				$cache_stats['files'],
				array(
					'type'       => 'cache',
					'cache_type' => 'page',
				)
			);
			$this->record_metric(
				'cache_size_bytes',
				$cache_stats['size'],
				array(
					'type'       => 'cache',
					'cache_type' => 'page',
					'unit'       => 'bytes',
				)
			);
		}

		// Object cache statistics
		if ( wp_using_ext_object_cache() ) {
			global $wp_object_cache;
			if ( isset( $wp_object_cache->cache_hits, $wp_object_cache->cache_misses ) ) {
				$total_requests = $wp_object_cache->cache_hits + $wp_object_cache->cache_misses;
				$hit_ratio      = $total_requests > 0 ? ( $wp_object_cache->cache_hits / $total_requests ) * 100 : 0;

				$this->record_metric(
					'object_cache_hit_ratio',
					$hit_ratio,
					array(
						'type'       => 'cache',
						'cache_type' => 'object',
						'unit'       => 'percentage',
					)
				);
			}
		}
	}

	/**
	 * Collect optimization statistics.
	 *
	 * @return void
	 */
	private function collect_optimization_statistics(): void {
		$settings = get_option( 'wppo_settings', array() );

		// Count enabled optimizations
		$enabled_optimizations = 0;
		$optimization_features = array(
			'cache_settings.enablePageCaching',
			'file_optimisation.minifyCSS',
			'file_optimisation.minifyJS',
			'file_optimisation.minifyHTML',
			'image_optimisation.lazyLoadImages',
			'image_optimisation.convertImg',
		);

		foreach ( $optimization_features as $feature ) {
			$keys  = explode( '.', $feature );
			$value = $settings;
			foreach ( $keys as $key ) {
				$value = $value[ $key ] ?? false;
			}
			if ( $value ) {
				++$enabled_optimizations;
			}
		}

		$this->record_metric(
			'enabled_optimizations',
			$enabled_optimizations,
			array(
				'type'           => 'optimization',
				'total_features' => count( $optimization_features ),
			)
		);
	}

	/**
	 * Collect image optimization statistics.
	 *
	 * @return void
	 */
	private function collect_image_optimization_stats(): void {
		$img_info = get_option( 'wppo_img_info', array() );

		$total_optimized = 0;
		$total_pending   = 0;
		$total_failed    = 0;

		foreach ( array( 'webp', 'avif' ) as $format ) {
			$total_optimized += count( $img_info['completed'][ $format ] ?? array() );
			$total_pending   += count( $img_info['pending'][ $format ] ?? array() );
			$total_failed    += count( $img_info['failed'][ $format ] ?? array() );
		}

		$this->record_metric(
			'images_optimized_total',
			$total_optimized,
			array(
				'type' => 'image_optimization',
			)
		);

		$this->record_metric(
			'images_pending_total',
			$total_pending,
			array(
				'type' => 'image_optimization',
			)
		);

		$this->record_metric(
			'images_failed_total',
			$total_failed,
			array(
				'type' => 'image_optimization',
			)
		);

		// Calculate optimization ratio
		$total_images = $total_optimized + $total_pending + $total_failed;
		if ( $total_images > 0 ) {
			$optimization_ratio = ( $total_optimized / $total_images ) * 100;
			$this->record_metric(
				'image_optimization_ratio',
				$optimization_ratio,
				array(
					'type' => 'image_optimization',
					'unit' => 'percentage',
				)
			);
		}
	}

	/**
	 * Get metrics for a specific time period.
	 *
	 * @param string               $metric_name Metric name.
	 * @param string               $start_date Start date (Y-m-d H:i:s).
	 * @param string               $end_date End date (Y-m-d H:i:s).
	 * @param array<string, mixed> $filters Additional filters.
	 * @return array<string, mixed> Metrics data.
	 */
	public function get_metrics( string $metric_name, string $start_date, string $end_date, array $filters = array() ): array {
		global $wpdb;

		$where_conditions = array(
			'metric_name = %s',
			'recorded_at >= %s',
			'recorded_at <= %s',
		);
		$where_values     = array( $metric_name, $start_date, $end_date );

		// Add tag filters
		foreach ( $filters as $key => $value ) {
			$where_conditions[] = 'JSON_EXTRACT(tags, %s) = %s';
			$where_values[]     = '$.' . $key;
			$where_values[]     = $value;
		}

		$where_clause = implode( ' AND ', $where_conditions );

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->metrics_table} WHERE {$where_clause} ORDER BY recorded_at ASC",
			$where_values
		);

		$results = $wpdb->get_results( $query, ARRAY_A );

		return array(
			'metric_name' => $metric_name,
			'start_date'  => $start_date,
			'end_date'    => $end_date,
			'count'       => count( $results ),
			'data'        => $results,
		);
	}

	/**
	 * Get aggregated metrics.
	 *
	 * @param string $metric_name Metric name.
	 * @param string $period Aggregation period (hour, day, week, month).
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<string, mixed> Aggregated metrics.
	 */
	public function get_aggregated_metrics( string $metric_name, string $period, string $start_date, string $end_date ): array {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->aggregated_table} 
			 WHERE metric_name = %s 
			 AND period_type = %s 
			 AND period_start >= %s 
			 AND period_start <= %s 
			 ORDER BY period_start ASC",
			$metric_name,
			$period,
			$start_date,
			$end_date
		);

		$results = $wpdb->get_results( $query, ARRAY_A );

		return array(
			'metric_name' => $metric_name,
			'period'      => $period,
			'start_date'  => $start_date,
			'end_date'    => $end_date,
			'data'        => $results,
		);
	}

	/**
	 * Aggregate metrics for reporting.
	 *
	 * @return void
	 */
	private function aggregate_metrics(): void {
		$this->aggregate_hourly_metrics();
		$this->aggregate_daily_metrics();
	}

	/**
	 * Aggregate hourly metrics.
	 *
	 * @return void
	 */
	private function aggregate_hourly_metrics(): void {
		global $wpdb;

		$hour_ago     = date( 'Y-m-d H:00:00', strtotime( '-1 hour' ) );
		$current_hour = date( 'Y-m-d H:00:00' );

		$metrics_to_aggregate = array(
			'page_load_time'   => array( 'AVG', 'MIN', 'MAX', 'COUNT' ),
			'cache_hit'        => array( 'SUM', 'COUNT' ),
			'memory_usage'     => array( 'AVG', 'MAX' ),
			'database_queries' => array( 'AVG', 'MAX' ),
		);

		foreach ( $metrics_to_aggregate as $metric_name => $aggregations ) {
			foreach ( $aggregations as $agg_type ) {
				$this->create_aggregated_metric( $metric_name, $agg_type, 'hour', $hour_ago, $current_hour );
			}
		}
	}

	/**
	 * Aggregate daily metrics.
	 *
	 * @return void
	 */
	private function aggregate_daily_metrics(): void {
		$yesterday = date( 'Y-m-d 00:00:00', strtotime( '-1 day' ) );
		$today     = date( 'Y-m-d 00:00:00' );

		$metrics_to_aggregate = array(
			'page_load_time'         => array( 'AVG', 'MIN', 'MAX', 'COUNT' ),
			'cache_hit'              => array( 'SUM', 'COUNT' ),
			'images_optimized_total' => array( 'MAX' ),
			'enabled_optimizations'  => array( 'MAX' ),
		);

		foreach ( $metrics_to_aggregate as $metric_name => $aggregations ) {
			foreach ( $aggregations as $agg_type ) {
				$this->create_aggregated_metric( $metric_name, $agg_type, 'day', $yesterday, $today );
			}
		}
	}

	/**
	 * Create aggregated metric entry.
	 *
	 * @param string $metric_name Metric name.
	 * @param string $aggregation_type Aggregation type (AVG, SUM, MIN, MAX, COUNT).
	 * @param string $period_type Period type (hour, day, week, month).
	 * @param string $period_start Period start time.
	 * @param string $period_end Period end time.
	 * @return void
	 */
	private function create_aggregated_metric( string $metric_name, string $aggregation_type, string $period_type, string $period_start, string $period_end ): void {
		global $wpdb;

		// Check if aggregation already exists
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->aggregated_table} 
			 WHERE metric_name = %s 
			 AND aggregation_type = %s 
			 AND period_type = %s 
			 AND period_start = %s",
				$metric_name,
				$aggregation_type,
				$period_type,
				$period_start
			)
		);

		if ( $existing ) {
			return; // Already aggregated
		}

		// Calculate aggregated value
		$agg_function = strtoupper( $aggregation_type );
		$value_column = is_numeric( $aggregation_type ) ? 'CAST(metric_value AS DECIMAL(10,2))' : 'metric_value';

		$query = $wpdb->prepare(
			"SELECT {$agg_function}({$value_column}) as aggregated_value, COUNT(*) as sample_count
			 FROM {$this->metrics_table} 
			 WHERE metric_name = %s 
			 AND recorded_at >= %s 
			 AND recorded_at < %s",
			$metric_name,
			$period_start,
			$period_end
		);

		$result = $wpdb->get_row( $query );

		if ( $result && $result->sample_count > 0 ) {
			$wpdb->insert(
				$this->aggregated_table,
				array(
					'metric_name'      => $metric_name,
					'aggregation_type' => $aggregation_type,
					'aggregated_value' => $result->aggregated_value,
					'sample_count'     => $result->sample_count,
					'period_type'      => $period_type,
					'period_start'     => $period_start,
					'period_end'       => $period_end,
					'created_at'       => current_time( 'mysql' ),
				)
			);
		}
	}

	/**
	 * Clean up old metrics data.
	 *
	 * @return void
	 */
	private function cleanup_old_metrics(): void {
		global $wpdb;

		// Keep raw metrics for 7 days
		$raw_cutoff = date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->metrics_table} WHERE recorded_at < %s",
				$raw_cutoff
			)
		);

		// Keep aggregated metrics for 90 days
		$aggregated_cutoff = date( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->aggregated_table} WHERE created_at < %s",
				$aggregated_cutoff
			)
		);
	}

	/**
	 * Get current page type.
	 *
	 * @return string Page type.
	 */
	private function get_page_type(): string {
		if ( is_admin() ) {
			return 'admin';
		} elseif ( is_front_page() ) {
			return 'home';
		} elseif ( is_single() ) {
			return 'post';
		} elseif ( is_page() ) {
			return 'page';
		} elseif ( is_category() || is_tag() || is_tax() ) {
			return 'archive';
		} elseif ( is_search() ) {
			return 'search';
		} elseif ( is_404() ) {
			return '404';
		} else {
			return 'other';
		}
	}

	/**
	 * Get cache status for current request.
	 *
	 * @return string Cache status (hit, miss, bypass).
	 */
	private function get_cache_status(): string {
		// Check for cache headers or constants set by caching system
		if ( defined( 'WPPO_CACHE_HIT' ) && WPPO_CACHE_HIT ) {
			return 'hit';
		} elseif ( defined( 'WPPO_CACHE_MISS' ) && WPPO_CACHE_MISS ) {
			return 'miss';
		} elseif ( defined( 'WPPO_CACHE_BYPASS' ) && WPPO_CACHE_BYPASS ) {
			return 'bypass';
		}

		// Default to miss if no cache status is set
		return 'miss';
	}

	/**
	 * Check if minification is active for current request.
	 *
	 * @return bool True if minification is active.
	 */
	private function is_minification_active(): bool {
		return defined( 'WPPO_MINIFICATION_ACTIVE' ) && WPPO_MINIFICATION_ACTIVE;
	}

	/**
	 * Check if lazy loading is active for current request.
	 *
	 * @return bool True if lazy loading is active.
	 */
	private function is_lazy_loading_active(): bool {
		return defined( 'WPPO_LAZY_LOADING_ACTIVE' ) && WPPO_LAZY_LOADING_ACTIVE;
	}

	/**
	 * Count optimized images on current page.
	 *
	 * @return int Number of optimized images.
	 */
	private function count_optimized_images_on_page(): int {
		// This would require analyzing the page content for optimized images
		// For now, return 0 as a placeholder
		return 0;
	}

	/**
	 * Get directory statistics.
	 *
	 * @param string $directory Directory path.
	 * @return array<string, int> Directory statistics.
	 */
	private function get_directory_stats( string $directory ): array {
		$files = 0;
		$size  = 0;

		if ( is_dir( $directory ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					++$files;
					$size += $file->getSize();
				}
			}
		}

		return array(
			'files' => $files,
			'size'  => $size,
		);
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip(): string {
		$ip_headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = $_SERVER[ $header ];
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	/**
	 * Create database tables for metrics storage.
	 *
	 * @return void
	 */
	public function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Raw metrics table
		$metrics_table_sql = "CREATE TABLE {$this->metrics_table} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			metric_name varchar(100) NOT NULL,
			metric_value text NOT NULL,
			tags longtext,
			recorded_at datetime NOT NULL,
			user_id bigint(20) DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			user_agent text,
			url text,
			PRIMARY KEY (id),
			KEY metric_name (metric_name),
			KEY recorded_at (recorded_at),
			KEY user_id (user_id)
		) $charset_collate;";

		// Aggregated metrics table
		$aggregated_table_sql = "CREATE TABLE {$this->aggregated_table} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			metric_name varchar(100) NOT NULL,
			aggregation_type varchar(20) NOT NULL,
			aggregated_value decimal(15,4) NOT NULL,
			sample_count int(11) NOT NULL,
			period_type varchar(20) NOT NULL,
			period_start datetime NOT NULL,
			period_end datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY unique_aggregation (metric_name, aggregation_type, period_type, period_start),
			KEY period_start (period_start),
			KEY metric_name (metric_name)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $metrics_table_sql );
		dbDelta( $aggregated_table_sql );
	}
}
