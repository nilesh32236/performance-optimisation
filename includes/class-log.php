<?php

namespace PerformanceOptimise\Inc;

class Log {
	public function __construct( $activity ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'qtpo_activity_logs';

		/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery */
		// Direct query is required for inserting into a custom table.
		$result = $wpdb->insert(
			$table_name,
			array(
				'activity' => sanitize_text_field( $activity ),
			),
			array(
				'%s',
			)
		);
		/* phpcs:enable */

		if ( $result ) {
			wp_cache_delete( 'qtpo_activity_logs' );
		}
	}

	/**
	 * Get recent activities with pagination and caching.
	 *
	 * @param array $params Pagination parameters, including 'page' and 'per_page'.
	 * @return array Cached or freshly queried results.
	 */
	public static function get_recent_activities( $params ) {
		global $wpdb;

		$page     = isset( $params['page'] ) ? absint( $params['page'] ) : 1;
		$per_page = isset( $params['per_page'] ) ? absint( $params['per_page'] ) : 10;

		// Calculate offset for pagination
		$offset = ( $page - 1 ) * $per_page;

		// Cache key
		$cache_key = 'qtpo_activity_logs_page_' . $page . '_per_page_' . $per_page;

		// Attempt to fetch cached data
		$data = wp_cache_get( $cache_key, 'qtpo_activity_logs' );

		if ( false === $data ) {
			/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery */
			// Direct query is required for custom table operations.

			// Get total number of activities
			$total_items = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}qtpo_activity_logs"
			);

			// Calculate total pages
			$total_pages = ceil( $total_items / $per_page );

			// Fetch paginated results
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}qtpo_activity_logs ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
			/* phpcs:enable */

			// Append additional data
			foreach ( $results as $index => $result ) {
				$results[ $index ]['activity'] .= ' ' . esc_html( $result['created_at'] );
			}

			// Prepare data for caching
			$data = array(
				'activities'   => $results,
				'total_items'  => $total_items,
				'current_page' => $page,
				'total_pages'  => $total_pages,
				'per_page'     => $per_page,
			);

			// Store data in cache
			wp_cache_set( $cache_key, $data, 'qtpo_activity_logs', HOUR_IN_SECONDS );
		}

		return $data;
	}
}
