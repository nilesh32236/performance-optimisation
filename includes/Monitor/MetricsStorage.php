<?php
/**
 * Metrics Storage Service for Performance Optimisation.
 *
 * Stores and retrieves historical performance metrics.
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PerformanceOptimisation\Monitor;

/**
 * Class MetricsStorage
 *
 * Handles storage and retrieval of performance metrics history.
 *
 * @since 2.0.0
 */
class MetricsStorage {

	/**
	 * Database table name (without prefix).
	 */
	const TABLE_NAME = 'performance_metrics';

	/**
	 * Maximum number of records to retain per URL.
	 */
	const MAX_RECORDS_PER_URL = 100;

	/**
	 * Get the full table name with WP prefix.
	 *
	 * @return string Full table name.
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the metrics table if it doesn't exist.
	 *
	 * @return bool True on success.
	 */
	public function create_table(): bool {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(500) NOT NULL,
			device ENUM('mobile','desktop') NOT NULL DEFAULT 'mobile',
			performance_score TINYINT UNSIGNED DEFAULT NULL,
			accessibility_score TINYINT UNSIGNED DEFAULT NULL,
			best_practices_score TINYINT UNSIGNED DEFAULT NULL,
			seo_score TINYINT UNSIGNED DEFAULT NULL,
			fcp_ms INT UNSIGNED DEFAULT NULL,
			lcp_ms INT UNSIGNED DEFAULT NULL,
			cls_value DECIMAL(5,3) DEFAULT NULL,
			tbt_ms INT UNSIGNED DEFAULT NULL,
			speed_index_ms INT UNSIGNED DEFAULT NULL,
			total_assets INT UNSIGNED DEFAULT NULL,
			total_size INT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY url_device (url(191), device),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return true;
	}

	/**
	 * Store a metrics record.
	 *
	 * @param array $data Metrics data.
	 *
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function store( array $data ) {
		global $wpdb;

		$defaults = array(
			'url'                  => home_url( '/' ),
			'device'               => 'mobile',
			'performance_score'    => null,
			'accessibility_score'  => null,
			'best_practices_score' => null,
			'seo_score'            => null,
			'fcp_ms'               => null,
			'lcp_ms'               => null,
			'cls_value'            => null,
			'tbt_ms'               => null,
			'speed_index_ms'       => null,
			'total_assets'         => null,
			'total_size'           => null,
		);

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$this->get_table_name(),
			array(
				'url'                  => $data['url'],
				'device'               => $data['device'],
				'performance_score'    => $data['performance_score'],
				'accessibility_score'  => $data['accessibility_score'],
				'best_practices_score' => $data['best_practices_score'],
				'seo_score'            => $data['seo_score'],
				'fcp_ms'               => $data['fcp_ms'],
				'lcp_ms'               => $data['lcp_ms'],
				'cls_value'            => $data['cls_value'],
				'tbt_ms'               => $data['tbt_ms'],
				'speed_index_ms'       => $data['speed_index_ms'],
				'total_assets'         => $data['total_assets'],
				'total_size'           => $data['total_size'],
			),
			array(
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%f',
				'%d',
				'%d',
				'%d',
				'%d',
			)
		);

		if ( false === $result ) {
			return false;
		}

		// Clean up old records.
		$this->cleanup_old_records( $data['url'], $data['device'] );

		return $wpdb->insert_id;
	}

	/**
	 * Get historical metrics for a URL.
	 *
	 * @param string $url    URL to get metrics for.
	 * @param string $device Device type (mobile or desktop).
	 * @param int    $limit  Number of records to retrieve.
	 * @param string $since  Date string (e.g., '-7 days').
	 *
	 * @return array Array of metrics records.
	 */
	public function get_history(
		string $url = '',
		string $device = 'mobile',
		int $limit = 30,
		string $since = '-30 days'
	): array {
		global $wpdb;

		if ( empty( $url ) ) {
			$url = home_url( '/' );
		}

		$since_date = gmdate( 'Y-m-d H:i:s', strtotime( $since ) );
		$table_name = $this->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE url = %s
			AND device = %s
			AND created_at >= %s
			ORDER BY created_at ASC
			LIMIT %d",
			$url,
			$device,
			$since_date,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get chart data formatted for frontend.
	 *
	 * @param string $url    URL to get data for.
	 * @param string $device Device type.
	 * @param string $since  Time period.
	 *
	 * @return array Chart-ready data structure.
	 */
	public function get_chart_data(
		string $url = '',
		string $device = 'mobile',
		string $since = '-7 days'
	): array {
		$history = $this->get_history( $url, $device, 100, $since );

		$labels         = array();
		$performance    = array();
		$accessibility  = array();
		$best_practices = array();
		$seo            = array();
		$lcp            = array();
		$fcp            = array();
		$cls            = array();

		foreach ( $history as $record ) {
			$labels[]         = gmdate( 'M j, H:i', strtotime( $record['created_at'] ) );
			$performance[]    = (int) $record['performance_score'];
			$accessibility[]  = (int) $record['accessibility_score'];
			$best_practices[] = (int) $record['best_practices_score'];
			$seo[]            = (int) $record['seo_score'];
			$lcp[]            = $record['lcp_ms'] ? round( $record['lcp_ms'] / 1000, 2 ) : null;
			$fcp[]            = $record['fcp_ms'] ? round( $record['fcp_ms'] / 1000, 2 ) : null;
			$cls[]            = $record['cls_value'] !== null ? (float) $record['cls_value'] : null;
		}

		return array(
			'labels'   => $labels,
			'datasets' => array(
				'scores' => array(
					array(
						'label' => 'Performance',
						'data'  => $performance,
						'color' => '#10b981',
					),
					array(
						'label' => 'Accessibility',
						'data'  => $accessibility,
						'color' => '#3b82f6',
					),
					array(
						'label' => 'Best Practices',
						'data'  => $best_practices,
						'color' => '#8b5cf6',
					),
					array(
						'label' => 'SEO',
						'data'  => $seo,
						'color' => '#f59e0b',
					),
				),
				'vitals' => array(
					array(
						'label' => 'LCP (sec)',
						'data'  => $lcp,
						'color' => '#ef4444',
					),
					array(
						'label' => 'FCP (sec)',
						'data'  => $fcp,
						'color' => '#f97316',
					),
					array(
						'label' => 'CLS',
						'data'  => $cls,
						'color' => '#06b6d4',
					),
				),
			),
			'count'    => count( $history ),
		);
	}

	/**
	 * Get the latest record for a URL.
	 *
	 * @param string $url    URL to check.
	 * @param string $device Device type.
	 *
	 * @return array|null Latest record or null.
	 */
	public function get_latest( string $url = '', string $device = 'mobile' ): ?array {
		global $wpdb;

		if ( empty( $url ) ) {
			$url = home_url( '/' );
		}

		$table_name = $this->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE url = %s AND device = %s
			ORDER BY created_at DESC
			LIMIT 1",
			$url,
			$device
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_row( $query, ARRAY_A );

		return $result ?: null;
	}

	/**
	 * Clean up old records beyond the limit.
	 *
	 * @param string $url    URL to clean up.
	 * @param string $device Device type.
	 */
	private function cleanup_old_records( string $url, string $device ): void {
		global $wpdb;

		$table_name = $this->get_table_name();

		// Get count of records.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE url = %s AND device = %s",
				$url,
				$device
			)
		);

		if ( $count > self::MAX_RECORDS_PER_URL ) {
			$to_delete = $count - self::MAX_RECORDS_PER_URL;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_name}
					WHERE url = %s AND device = %s
					ORDER BY created_at ASC
					LIMIT %d",
					$url,
					$device,
					$to_delete
				)
			);
		}
	}

	/**
	 * Get comparison data between two periods.
	 *
	 * @param string $url      URL to compare.
	 * @param string $device   Device type.
	 * @param string $current  Current period (e.g., '-7 days').
	 * @param string $previous Previous period (e.g., '-14 days').
	 *
	 * @return array Comparison data.
	 */
	public function get_comparison(
		string $url = '',
		string $device = 'mobile',
		string $current = '-7 days',
		string $previous = '-14 days'
	): array {
		$current_data  = $this->get_history( $url, $device, 1000, $current );
		$previous_data = $this->get_history( $url, $device, 1000, $previous );

		$calc_avg = function ( array $records, string $field ): ?float {
			$values = array_filter( array_column( $records, $field ), 'is_numeric' );
			if ( empty( $values ) ) {
				return null;
			}
			return round( array_sum( $values ) / count( $values ), 2 );
		};

		$fields = array(
			'performance_score',
			'accessibility_score',
			'best_practices_score',
			'seo_score',
			'lcp_ms',
			'fcp_ms',
		);

		$comparison = array();

		foreach ( $fields as $field ) {
			$current_avg  = $calc_avg( $current_data, $field );
			$previous_avg = $calc_avg( $previous_data, $field );

			$change = null;
			if ( $current_avg !== null && $previous_avg !== null && $previous_avg > 0 ) {
				$change = round( ( ( $current_avg - $previous_avg ) / $previous_avg ) * 100, 1 );
			}

			$comparison[ $field ] = array(
				'current'  => $current_avg,
				'previous' => $previous_avg,
				'change'   => $change,
			);
		}

		return $comparison;
	}
}
