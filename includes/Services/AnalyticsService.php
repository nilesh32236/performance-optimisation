<?php
/**
 * Analytics Service
 *
 * @package PerformanceOptimisation\Services
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Utils\LoggingUtil;

/**
 * Analytics Service Class
 */
class AnalyticsService {

	private string $table_name;
	private array $metrics_cache = array();

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'wppo_performance_stats';
	}

	public function trackPageLoad( float $load_time, array $metrics = array() ): void {
		$data = array(
			'load_time'    => $load_time,
			'url'          => $_SERVER['REQUEST_URI'] ?? '',
			'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'is_mobile'    => wp_is_mobile(),
			'memory_usage' => memory_get_peak_usage( true ),
			'db_queries'   => get_num_queries(),
			'timestamp'    => current_time( 'mysql' ),
			...$metrics,
		);

		$this->storeMetric( 'page_load', $data );
		$this->updateAverages( $load_time );
	}

	public function trackCacheHit( string $cache_type, string $key, bool $hit ): void {
		$this->storeMetric(
			'cache_hit',
			array(
				'cache_type' => $cache_type,
				'cache_key'  => $key,
				'hit'        => $hit,
				'timestamp'  => current_time( 'mysql' ),
			)
		);
	}

	public function trackImageOptimization( array $data ): void {
		$this->storeMetric(
			'image_optimization',
			array(
				'original_size'     => $data['original_size'],
				'optimized_size'    => $data['optimized_size'],
				'compression_ratio' => $data['compression_ratio'],
				'format'            => $data['format'],
				'timestamp'         => current_time( 'mysql' ),
			)
		);
	}

	public function getPerformanceScore(): int {
		$score = 100;

		// Page load time impact (40% of score)
		$avg_load_time = $this->getAverageLoadTime();
		if ( $avg_load_time > 3 ) {
			$score -= 40;
		} elseif ( $avg_load_time > 2 ) {
			$score -= 25;
		} elseif ( $avg_load_time > 1.5 ) {
			$score -= 15;
		} elseif ( $avg_load_time > 1 ) {
			$score -= 5;
		}

		// Cache hit rate impact (30% of score)
		$cache_hit_rate = $this->getCacheHitRate();
		if ( $cache_hit_rate < 50 ) {
			$score -= 30;
		} elseif ( $cache_hit_rate < 70 ) {
			$score -= 20;
		} elseif ( $cache_hit_rate < 85 ) {
			$score -= 10;
		}

		// Image optimization impact (20% of score)
		$image_score = $this->getImageOptimizationScore();
		$score      -= ( 20 - ( $image_score * 0.2 ) );

		// Database performance impact (10% of score)
		$db_score = $this->getDatabasePerformanceScore();
		$score   -= ( 10 - ( $db_score * 0.1 ) );

		return max( 0, min( 100, round( $score ) ) );
	}

	public function getCacheHitRate(): float {
		$cache_key = 'wppo_cache_hit_rate_' . date( 'Y-m-d' );

		if ( isset( $this->metrics_cache[ $cache_key ] ) ) {
			return $this->metrics_cache[ $cache_key ];
		}

		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"
            SELECT 
                SUM(CASE WHEN JSON_EXTRACT(metric_value, '$.hit') = true THEN 1 ELSE 0 END) as hits,
                COUNT(*) as total
            FROM {$this->table_name} 
            WHERE metric_name = 'cache_hit' 
            AND recorded_at >= %s
        ",
				date( 'Y-m-d 00:00:00' )
			)
		);

		$hit_rate = $result && $result->total > 0 ?
			( $result->hits / $result->total ) * 100 : 0;

		$this->metrics_cache[ $cache_key ] = $hit_rate;
		return $hit_rate;
	}

	public function getAverageLoadTime( int $days = 7 ): float {
		$cache_key = "wppo_avg_load_time_{$days}d";

		if ( isset( $this->metrics_cache[ $cache_key ] ) ) {
			return $this->metrics_cache[ $cache_key ];
		}

		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"
            SELECT AVG(JSON_EXTRACT(metric_value, '$.load_time'))
            FROM {$this->table_name} 
            WHERE metric_name = 'page_load' 
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
        ",
				$days
			)
		);

		$avg_time                          = $result ? (float) $result : 0;
		$this->metrics_cache[ $cache_key ] = $avg_time;

		return $avg_time;
	}

	public function getOptimizedImagesCount(): int {
		return (int) get_option( 'wppo_optimized_images_count', 0 );
	}

	public function getCacheSize(): string {
		$cache_dir = WP_CONTENT_DIR . '/cache/wppo/';
		if ( ! is_dir( $cache_dir ) ) {
			return '0 MB';
		}

		$size = $this->getDirectorySize( $cache_dir );
		return $this->formatBytes( $size );
	}

	public function getRecommendations(): array {
		$recommendations = array();

		// Performance-based recommendations
		$avg_load_time = $this->getAverageLoadTime();
		if ( $avg_load_time > 2 ) {
			$recommendations[] = array(
				'type'        => 'warning',
				'title'       => __( 'Slow Page Load Times', 'performance-optimisation' ),
				'description' => sprintf(
					__( 'Average load time is %.2fs. Consider enabling caching and minification.', 'performance-optimisation' ),
					$avg_load_time
				),
				'action'      => 'enable_caching',
			);
		}

		// Cache hit rate recommendations
		$cache_hit_rate = $this->getCacheHitRate();
		if ( $cache_hit_rate < 70 ) {
			$recommendations[] = array(
				'type'        => 'info',
				'title'       => __( 'Low Cache Hit Rate', 'performance-optimisation' ),
				'description' => sprintf(
					__( 'Cache hit rate is %.1f%%. Review cache exclusions and TTL settings.', 'performance-optimisation' ),
					$cache_hit_rate
				),
				'action'      => 'optimize_cache',
			);
		}

		// Image optimization recommendations
		$unoptimized_images = $this->getUnoptimizedImagesCount();
		if ( $unoptimized_images > 0 ) {
			$recommendations[] = array(
				'type'        => 'info',
				'title'       => __( 'Unoptimized Images', 'performance-optimisation' ),
				'description' => sprintf(
					__( '%d images can be optimized. Enable WebP conversion and compression.', 'performance-optimisation' ),
					$unoptimized_images
				),
				'action'      => 'optimize_images',
			);
		}

		return $recommendations;
	}

	public function getDashboardMetrics(): array {
		return array(
			'performance_score' => $this->getPerformanceScore(),
			'cache_hit_rate'    => $this->getCacheHitRate(),
			'page_load_time'    => $this->getAverageLoadTime(),
			'optimized_images'  => $this->getOptimizedImagesCount(),
			'cache_size'        => $this->getCacheSize(),
			'recommendations'   => $this->getRecommendations(),
			'core_vitals'       => $this->getCoreWebVitals(),
			'last_updated'      => current_time( 'mysql' ),
		);
	}

	public function getCoreWebVitals(): array {
		global $wpdb;

		$vitals = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT 
                JSON_EXTRACT(metric_value, '$.fcp') as fcp,
                JSON_EXTRACT(metric_value, '$.lcp') as lcp,
                JSON_EXTRACT(metric_value, '$.fid') as fid,
                JSON_EXTRACT(metric_value, '$.cls') as cls
            FROM {$this->table_name} 
            WHERE metric_name = 'core_vitals' 
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY recorded_at DESC
            LIMIT 100
        "
			)
		);

		if ( empty( $vitals ) ) {
			return array(
				'fcp'       => 0,
				'lcp'       => 0,
				'fid'       => 0,
				'cls'       => 0,
				'fcp_score' => 'unknown',
				'lcp_score' => 'unknown',
				'fid_score' => 'unknown',
				'cls_score' => 'unknown',
			);
		}

		$avg_fcp = array_sum( array_column( $vitals, 'fcp' ) ) / count( $vitals );
		$avg_lcp = array_sum( array_column( $vitals, 'lcp' ) ) / count( $vitals );
		$avg_fid = array_sum( array_column( $vitals, 'fid' ) ) / count( $vitals );
		$avg_cls = array_sum( array_column( $vitals, 'cls' ) ) / count( $vitals );

		return array(
			'fcp'       => round( $avg_fcp, 2 ),
			'lcp'       => round( $avg_lcp, 2 ),
			'fid'       => round( $avg_fid, 2 ),
			'cls'       => round( $avg_cls, 3 ),
			'fcp_score' => $this->getVitalScore( 'fcp', $avg_fcp ),
			'lcp_score' => $this->getVitalScore( 'lcp', $avg_lcp ),
			'fid_score' => $this->getVitalScore( 'fid', $avg_fid ),
			'cls_score' => $this->getVitalScore( 'cls', $avg_cls ),
		);
	}

	public function generateReport( string $period = '7d' ): array {
		$days = (int) str_replace( 'd', '', $period );

		return array(
			'period'          => $period,
			'generated_at'    => current_time( 'mysql' ),
			'summary'         => array(
				'performance_score' => $this->getPerformanceScore(),
				'avg_load_time'     => $this->getAverageLoadTime( $days ),
				'cache_hit_rate'    => $this->getCacheHitRate(),
				'total_page_views'  => $this->getPageViews( $days ),
				'cache_savings'     => $this->calculateCacheSavings( $days ),
			),
			'trends'          => $this->getPerformanceTrends( $days ),
			'top_slow_pages'  => $this->getSlowPages( $days ),
			'recommendations' => $this->getRecommendations(),
			'core_vitals'     => $this->getCoreWebVitals(),
		);
	}

	private function storeMetric( string $name, array $data ): void {
		global $wpdb;

		$wpdb->insert(
			$this->table_name,
			array(
				'metric_name'  => $name,
				'metric_value' => wp_json_encode( $data ),
				'recorded_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	private function updateAverages( float $load_time ): void {
		$load_times   = get_option( 'wppo_load_times', array() );
		$load_times[] = $load_time;

		// Keep only last 100 measurements
		if ( count( $load_times ) > 100 ) {
			$load_times = array_slice( $load_times, -100 );
		}

		update_option( 'wppo_load_times', $load_times );
	}

	private function getImageOptimizationScore(): int {
		$total_images     = $this->getTotalImagesCount();
		$optimized_images = $this->getOptimizedImagesCount();

		return $total_images > 0 ?
			round( ( $optimized_images / $total_images ) * 100 ) : 100;
	}

	private function getDatabasePerformanceScore(): int {
		$avg_queries = $this->getAverageDbQueries();

		if ( $avg_queries < 20 ) {
			return 100;
		}
		if ( $avg_queries < 50 ) {
			return 80;
		}
		if ( $avg_queries < 100 ) {
			return 60;
		}
		if ( $avg_queries < 200 ) {
			return 40;
		}
		return 20;
	}

	private function getVitalScore( string $vital, float $value ): string {
		$thresholds = array(
			'fcp' => array(
				'good'              => 1.8,
				'needs_improvement' => 3.0,
			),
			'lcp' => array(
				'good'              => 2.5,
				'needs_improvement' => 4.0,
			),
			'fid' => array(
				'good'              => 100,
				'needs_improvement' => 300,
			),
			'cls' => array(
				'good'              => 0.1,
				'needs_improvement' => 0.25,
			),
		);

		if ( ! isset( $thresholds[ $vital ] ) ) {
			return 'unknown';
		}

		$threshold = $thresholds[ $vital ];

		if ( $value <= $threshold['good'] ) {
			return 'good';
		}
		if ( $value <= $threshold['needs_improvement'] ) {
			return 'needs_improvement';
		}
		return 'poor';
	}

	private function getDirectorySize( string $dir ): int {
		$size = 0;
		foreach ( glob( rtrim( $dir, '/' ) . '/*', GLOB_NOSORT ) as $file ) {
			$size += is_file( $file ) ? filesize( $file ) : $this->getDirectorySize( $file );
		}
		return $size;
	}

	private function formatBytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		for ( $i = 0; $bytes > 1024 && $i < 3; $i++ ) {
			$bytes /= 1024;
		}
		return round( $bytes, 2 ) . ' ' . $units[ $i ];
	}

	private function getTotalImagesCount(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
        "
		);
	}

	private function getUnoptimizedImagesCount(): int {
		return max( 0, $this->getTotalImagesCount() - $this->getOptimizedImagesCount() );
	}

	private function getAverageDbQueries(): float {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"
            SELECT AVG(JSON_EXTRACT(metric_value, '$.db_queries'))
            FROM {$this->table_name} 
            WHERE metric_name = 'page_load' 
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        "
			)
		);

		return $result ? (float) $result : 0;
	}

	private function getPageViews( int $days ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"
            SELECT COUNT(*)
            FROM {$this->table_name} 
            WHERE metric_name = 'page_load' 
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
        ",
				$days
			)
		);
	}

	private function calculateCacheSavings( int $days ): array {
		$cache_hits    = $this->getCacheHitRate();
		$page_views    = $this->getPageViews( $days );
		$avg_load_time = $this->getAverageLoadTime( $days );

		$cached_requests = ( $cache_hits / 100 ) * $page_views;
		$time_saved      = $cached_requests * ( $avg_load_time * 0.8 ); // Assume 80% time saving

		return array(
			'cached_requests'    => round( $cached_requests ),
			'time_saved_seconds' => round( $time_saved, 2 ),
			'bandwidth_saved_mb' => round( $cached_requests * 0.5, 2 ), // Estimate
		);
	}

	private function getPerformanceTrends( int $days ): array {
		global $wpdb;

		$trends = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT 
                DATE(recorded_at) as date,
                AVG(JSON_EXTRACT(metric_value, '$.load_time')) as avg_load_time,
                COUNT(*) as page_views
            FROM {$this->table_name} 
            WHERE metric_name = 'page_load' 
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(recorded_at)
            ORDER BY date ASC
        ",
				$days
			)
		);

		return array_map(
			function ( $row ) {
				return array(
					'date'          => $row->date,
					'avg_load_time' => round( (float) $row->avg_load_time, 2 ),
					'page_views'    => (int) $row->page_views,
				);
			},
			$trends
		);
	}

	private function getSlowPages( int $days, int $limit = 10 ): array {
		global $wpdb;

		$pages = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT 
                JSON_EXTRACT(metric_value, '$.url') as url,
                AVG(JSON_EXTRACT(metric_value, '$.load_time')) as avg_load_time,
                COUNT(*) as views
            FROM {$this->table_name} 
            WHERE metric_name = 'page_load' 
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY JSON_EXTRACT(metric_value, '$.url')
            HAVING views >= 5
            ORDER BY avg_load_time DESC
            LIMIT %d
        ",
				$days,
				$limit
			)
		);

		return array_map(
			function ( $row ) {
				return array(
					'url'           => trim( $row->url, '"' ),
					'avg_load_time' => round( (float) $row->avg_load_time, 2 ),
					'views'         => (int) $row->views,
				);
			},
			$pages
		);
	}
}
