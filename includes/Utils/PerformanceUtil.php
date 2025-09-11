<?php
/**
 * Performance Utility
 *
 * Provides comprehensive performance monitoring, profiling, and optimization
 * utilities for the Performance Optimisation plugin.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PerformanceUtil Class
 *
 * Centralized performance monitoring with timing, memory tracking,
 * bottleneck identification, and optimization recommendations.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */
class PerformanceUtil {

	/**
	 * Performance timers storage.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private static array $timers = array();

	/**
	 * Memory usage snapshots.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private static array $memory_snapshots = array();

	/**
	 * Database query tracking.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private static array $query_log = array();

	/**
	 * Performance metrics storage.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private static array $metrics = array();

	/**
	 * Start performance timer.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name Timer name.
	 * @return void
	 */
	public static function startTimer( string $name ): void {
		self::$timers[ $name ] = array(
			'start' => microtime( true ),
			'memory_start' => memory_get_usage( true ),
		);
	}

	/**
	 * End performance timer and get duration.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name Timer name.
	 * @return float Duration in seconds, 0 if timer not found.
	 */
	public static function endTimer( string $name ): float {
		if ( ! isset( self::$timers[ $name ] ) ) {
			LoggingUtil::warning( "Timer '{$name}' not found" );
			return 0.0;
		}

		$timer = self::$timers[ $name ];
		$duration = microtime( true ) - $timer['start'];
		$memory_used = memory_get_usage( true ) - $timer['memory_start'];

		// Store the result
		self::$timers[ $name ]['end'] = microtime( true );
		self::$timers[ $name ]['duration'] = $duration;
		self::$timers[ $name ]['memory_used'] = $memory_used;

		LoggingUtil::debug( "Timer '{$name}' completed", array(
			'duration' => $duration,
			'memory_used' => $memory_used,
		) );

		return $duration;
	}

	/**
	 * Get current memory usage information.
	 *
	 * @since 2.0.0
	 *
	 * @return array Memory usage information.
	 */
	public static function getMemoryUsage(): array {
		return array(
			'current' => memory_get_usage( true ),
			'current_formatted' => FileSystemUtil::formatFileSize( memory_get_usage( true ) ),
			'peak' => memory_get_peak_usage( true ),
			'peak_formatted' => FileSystemUtil::formatFileSize( memory_get_peak_usage( true ) ),
			'limit' => self::getMemoryLimit(),
			'limit_formatted' => FileSystemUtil::formatFileSize( self::getMemoryLimit() ),
			'usage_percentage' => self::getMemoryUsagePercentage(),
		);
	}

	/**
	 * Profile function execution.
	 *
	 * @since 2.0.0
	 *
	 * @param callable $function Function to profile.
	 * @param array    $args     Function arguments.
	 * @return array Profiling results with return value.
	 */
	public static function profileFunction( callable $function, array $args = array() ): array {
		$timer_name = 'profile_' . uniqid();
		
		self::startTimer( $timer_name );
		$queries_before = self::getDatabaseQueryCount();
		
		try {
			$result = call_user_func_array( $function, $args );
			$success = true;
			$error = null;
		} catch ( \Exception $e ) {
			$result = null;
			$success = false;
			$error = $e->getMessage();
		}
		
		$duration = self::endTimer( $timer_name );
		$queries_after = self::getDatabaseQueryCount();
		
		return array(
			'success' => $success,
			'result' => $result,
			'error' => $error,
			'duration' => $duration,
			'memory_used' => self::$timers[ $timer_name ]['memory_used'] ?? 0,
			'queries_executed' => $queries_after - $queries_before,
		);
	}

	/**
	 * Optimize database query.
	 *
	 * @since 2.0.0
	 *
	 * @param string $query SQL query to optimize.
	 * @return string Optimized query with suggestions.
	 */
	public static function optimizeQuery( string $query ): string {
		// Basic query optimization suggestions
		$optimized = $query;
		
		// Add LIMIT if SELECT without LIMIT
		if ( preg_match( '/^SELECT/i', $query ) && ! preg_match( '/LIMIT/i', $query ) ) {
			LoggingUtil::info( 'Query optimization suggestion: Consider adding LIMIT clause', array( 'query' => $query ) );
		}
		
		// Check for SELECT *
		if ( preg_match( '/SELECT\s+\*/i', $query ) ) {
			LoggingUtil::info( 'Query optimization suggestion: Avoid SELECT *, specify columns', array( 'query' => $query ) );
		}
		
		// Check for missing WHERE clause in UPDATE/DELETE
		if ( preg_match( '/^(UPDATE|DELETE)/i', $query ) && ! preg_match( '/WHERE/i', $query ) ) {
			LoggingUtil::warning( 'Query optimization warning: UPDATE/DELETE without WHERE clause', array( 'query' => $query ) );
		}
		
		return $optimized;
	}

	/**
	 * Measure page load time.
	 *
	 * @since 2.0.0
	 *
	 * @return float Page load time in seconds.
	 */
	public static function measurePageLoadTime(): float {
		if ( ! defined( 'WPPO_START_TIME' ) ) {
			// Define start time if not already set
			define( 'WPPO_START_TIME', $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) );
		}
		
		return microtime( true ) - WPPO_START_TIME;
	}

	/**
	 * Get comprehensive performance metrics.
	 *
	 * @since 2.0.0
	 *
	 * @return array Performance metrics.
	 */
	public static function getPerformanceMetrics(): array {
		global $wpdb;
		
		$metrics = array(
			'page_load_time' => self::measurePageLoadTime(),
			'memory_usage' => self::getMemoryUsage(),
			'database_queries' => self::getDatabaseQueryCount(),
			'database_time' => $wpdb->time_taken ?? 0,
			'timers' => self::getTimerSummary(),
			'server_info' => self::getServerInfo(),
			'wordpress_info' => self::getWordPressInfo(),
			'plugin_info' => self::getPluginInfo(),
		);
		
		// Calculate performance score
		$metrics['performance_score'] = self::calculatePerformanceScore( $metrics );
		
		return $metrics;
	}

	/**
	 * Identify performance bottlenecks.
	 *
	 * @since 2.0.0
	 *
	 * @return array Array of identified bottlenecks with recommendations.
	 */
	public static function identifyBottlenecks(): array {
		$bottlenecks = array();
		$metrics = self::getPerformanceMetrics();
		
		// Check page load time
		if ( $metrics['page_load_time'] > 3.0 ) {
			$bottlenecks[] = array(
				'type' => 'page_load_time',
				'severity' => 'high',
				'message' => 'Page load time exceeds 3 seconds',
				'value' => $metrics['page_load_time'],
				'recommendation' => 'Enable caching, optimize images, minify CSS/JS',
			);
		}
		
		// Check memory usage
		if ( $metrics['memory_usage']['usage_percentage'] > 80 ) {
			$bottlenecks[] = array(
				'type' => 'memory_usage',
				'severity' => 'high',
				'message' => 'Memory usage exceeds 80%',
				'value' => $metrics['memory_usage']['usage_percentage'],
				'recommendation' => 'Optimize plugins, increase memory limit, enable object caching',
			);
		}
		
		// Check database queries
		if ( $metrics['database_queries'] > 100 ) {
			$bottlenecks[] = array(
				'type' => 'database_queries',
				'severity' => 'medium',
				'message' => 'High number of database queries',
				'value' => $metrics['database_queries'],
				'recommendation' => 'Enable query caching, optimize database queries, review plugins',
			);
		}
		
		// Check database time
		if ( $metrics['database_time'] > 1.0 ) {
			$bottlenecks[] = array(
				'type' => 'database_time',
				'severity' => 'medium',
				'message' => 'Database queries taking too long',
				'value' => $metrics['database_time'],
				'recommendation' => 'Optimize slow queries, add database indexes, enable query caching',
			);
		}
		
		return $bottlenecks;
	}

	/**
	 * Take memory usage snapshot.
	 *
	 * @since 2.0.0
	 *
	 * @param string $label Snapshot label.
	 * @return void
	 */
	public static function takeMemorySnapshot( string $label ): void {
		self::$memory_snapshots[ $label ] = array(
			'timestamp' => microtime( true ),
			'memory_usage' => memory_get_usage( true ),
			'peak_memory' => memory_get_peak_usage( true ),
		);
	}

	/**
	 * Compare memory snapshots.
	 *
	 * @since 2.0.0
	 *
	 * @param string $start_label Start snapshot label.
	 * @param string $end_label   End snapshot label.
	 * @return array Memory comparison results.
	 */
	public static function compareMemorySnapshots( string $start_label, string $end_label ): array {
		if ( ! isset( self::$memory_snapshots[ $start_label ] ) || ! isset( self::$memory_snapshots[ $end_label ] ) ) {
			return array();
		}
		
		$start = self::$memory_snapshots[ $start_label ];
		$end = self::$memory_snapshots[ $end_label ];
		
		return array(
			'memory_difference' => $end['memory_usage'] - $start['memory_usage'],
			'memory_difference_formatted' => FileSystemUtil::formatFileSize( abs( $end['memory_usage'] - $start['memory_usage'] ) ),
			'peak_difference' => $end['peak_memory'] - $start['peak_memory'],
			'time_elapsed' => $end['timestamp'] - $start['timestamp'],
		);
	}

	/**
	 * Log slow operation.
	 *
	 * @since 2.0.0
	 *
	 * @param string $operation Operation name.
	 * @param float  $duration  Operation duration.
	 * @param float  $threshold Slow operation threshold.
	 * @return void
	 */
	public static function logSlowOperation( string $operation, float $duration, float $threshold = 1.0 ): void {
		if ( $duration > $threshold ) {
			LoggingUtil::warning( 'Slow operation detected', array(
				'operation' => $operation,
				'duration' => $duration,
				'threshold' => $threshold,
			) );
		}
	}

	/**
	 * Get server performance information.
	 *
	 * @since 2.0.0
	 *
	 * @return array Server performance info.
	 */
	public static function getServerInfo(): array {
		return array(
			'php_version' => PHP_VERSION,
			'memory_limit' => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size' => ini_get( 'post_max_size' ),
			'opcache_enabled' => function_exists( 'opcache_get_status' ) && opcache_get_status() !== false,
			'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
		);
	}

	/**
	 * Get WordPress performance information.
	 *
	 * @since 2.0.0
	 *
	 * @return array WordPress performance info.
	 */
	public static function getWordPressInfo(): array {
		global $wp_version;
		
		return array(
			'version' => $wp_version,
			'multisite' => is_multisite(),
			'debug_mode' => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'object_cache' => wp_using_ext_object_cache(),
			'active_plugins' => count( get_option( 'active_plugins', array() ) ),
			'active_theme' => get_option( 'current_theme' ),
		);
	}

	/**
	 * Get plugin performance information.
	 *
	 * @since 2.0.0
	 *
	 * @return array Plugin performance info.
	 */
	public static function getPluginInfo(): array {
		return array(
			'version' => defined( 'WPPO_VERSION' ) ? WPPO_VERSION : 'Unknown',
			'cache_enabled' => CacheUtil::isCacheEnabled( 'page' ),
			'minification_enabled' => get_option( 'wppo_settings' )['minification']['minify_css'] ?? false,
			'image_optimization_enabled' => get_option( 'wppo_settings' )['images']['convert_to_webp'] ?? false,
		);
	}

	/**
	 * Calculate overall performance score.
	 *
	 * @since 2.0.0
	 *
	 * @param array $metrics Performance metrics.
	 * @return int Performance score (0-100).
	 */
	public static function calculatePerformanceScore( array $metrics ): int {
		$score = 100;
		
		// Deduct points for slow page load time
		if ( $metrics['page_load_time'] > 1.0 ) {
			$score -= min( 30, ( $metrics['page_load_time'] - 1.0 ) * 10 );
		}
		
		// Deduct points for high memory usage
		if ( $metrics['memory_usage']['usage_percentage'] > 50 ) {
			$score -= min( 25, ( $metrics['memory_usage']['usage_percentage'] - 50 ) / 2 );
		}
		
		// Deduct points for too many database queries
		if ( $metrics['database_queries'] > 50 ) {
			$score -= min( 20, ( $metrics['database_queries'] - 50 ) / 5 );
		}
		
		// Deduct points for slow database queries
		if ( $metrics['database_time'] > 0.5 ) {
			$score -= min( 15, ( $metrics['database_time'] - 0.5 ) * 20 );
		}
		
		return max( 0, (int) $score );
	}

	/**
	 * Get memory limit in bytes.
	 *
	 * @since 2.0.0
	 *
	 * @return int Memory limit in bytes.
	 */
	private static function getMemoryLimit(): int {
		$memory_limit = ini_get( 'memory_limit' );
		
		if ( -1 === (int) $memory_limit ) {
			return PHP_INT_MAX;
		}
		
		return wp_convert_hr_to_bytes( $memory_limit );
	}

	/**
	 * Get memory usage percentage.
	 *
	 * @since 2.0.0
	 *
	 * @return float Memory usage percentage.
	 */
	private static function getMemoryUsagePercentage(): float {
		$current = memory_get_usage( true );
		$limit = self::getMemoryLimit();
		
		if ( PHP_INT_MAX === $limit ) {
			return 0.0;
		}
		
		return round( ( $current / $limit ) * 100, 2 );
	}

	/**
	 * Get database query count.
	 *
	 * @since 2.0.0
	 *
	 * @return int Number of database queries.
	 */
	private static function getDatabaseQueryCount(): int {
		global $wpdb;
		return $wpdb->num_queries ?? 0;
	}

	/**
	 * Get timer summary.
	 *
	 * @since 2.0.0
	 *
	 * @return array Timer summary with statistics.
	 */
	private static function getTimerSummary(): array {
		$summary = array(
			'total_timers' => count( self::$timers ),
			'completed_timers' => 0,
			'total_duration' => 0.0,
			'total_memory_used' => 0,
			'slowest_timer' => null,
		);
		
		$slowest_duration = 0.0;
		
		foreach ( self::$timers as $name => $timer ) {
			if ( isset( $timer['duration'] ) ) {
				$summary['completed_timers']++;
				$summary['total_duration'] += $timer['duration'];
				$summary['total_memory_used'] += $timer['memory_used'] ?? 0;
				
				if ( $timer['duration'] > $slowest_duration ) {
					$slowest_duration = $timer['duration'];
					$summary['slowest_timer'] = array(
						'name' => $name,
						'duration' => $timer['duration'],
					);
				}
			}
		}
		
		return $summary;
	}

	/**
	 * Reset all performance tracking data.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$timers = array();
		self::$memory_snapshots = array();
		self::$query_log = array();
		self::$metrics = array();
		
		LoggingUtil::debug( 'Performance tracking data reset' );
	}

	/**
	 * Export performance data for analysis.
	 *
	 * @since 2.0.0
	 *
	 * @param string $format Export format (json, csv).
	 * @return string Exported performance data.
	 */
	public static function exportPerformanceData( string $format = 'json' ): string {
		$data = array(
			'metrics' => self::getPerformanceMetrics(),
			'timers' => self::$timers,
			'memory_snapshots' => self::$memory_snapshots,
			'bottlenecks' => self::identifyBottlenecks(),
			'export_timestamp' => current_time( 'mysql' ),
		);
		
		switch ( strtolower( $format ) ) {
			case 'csv':
				return self::exportToCsv( $data );
			case 'json':
			default:
				return wp_json_encode( $data, JSON_PRETTY_PRINT );
		}
	}

	/**
	 * Export performance data to CSV format.
	 *
	 * @since 2.0.0
	 *
	 * @param array $data Performance data.
	 * @return string CSV formatted data.
	 */
	private static function exportToCsv( array $data ): string {
		$csv = "Metric,Value,Unit\n";
		
		// Add basic metrics
		$csv .= "Page Load Time,{$data['metrics']['page_load_time']},seconds\n";
		$csv .= "Memory Usage,{$data['metrics']['memory_usage']['current']},bytes\n";
		$csv .= "Database Queries,{$data['metrics']['database_queries']},count\n";
		$csv .= "Performance Score,{$data['metrics']['performance_score']},score\n";
		
		// Add timer data
		foreach ( $data['timers'] as $name => $timer ) {
			if ( isset( $timer['duration'] ) ) {
				$csv .= "Timer: {$name},{$timer['duration']},seconds\n";
			}
		}
		
		return $csv;
	}
}