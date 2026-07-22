<?php
/**
 * Log Class
 *
 * This file contains the Log class, which handles the insertion and retrieval of activity logs in the database.
 * It supports logging activities with a description and allows fetching recent activity logs with pagination and caching.
 * The class provides methods for inserting log entries and retrieving them efficiently, with caching to improve performance.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
	/**
	 * Log Class
	 *
	 * A class to handle logging activities and fetching recent activity logs with pagination.
	 *
	 * @since 1.0.0
	 */
	class Log {

		/**
		 * Private constructor to prevent direct instantiation.
		 *
		 * @since 2.0.0
		 */
		private function __construct() {}

		/**
		 * Add a new activity log entry.
		 *
		 * @param string $activity The activity description to log.
		 * @return void
		 * @since 2.0.0
		 */
		public static function add( $activity ): void {
			global $wpdb;

			$table_name = $wpdb->prefix . 'wppo_activity_logs';

			/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery */
			// Direct query is required for inserting into a custom table.
			$result = $wpdb->insert(
				$table_name,
				array(
					'activity' => wp_kses_post( $activity ),
				),
				array(
					'%s',
				)
			);
			/* phpcs:enable */

			if ( $result ) {
				self::bump_cache_version();
			}
		}

		/**
		 * Bump the cache version to invalidate all cached activity log queries.
		 *
		 * @since 1.0.0
		 */
		private static function bump_cache_version() {
			$version = (int) get_option( 'wppo_activity_cache_version', 0 );
			update_option( 'wppo_activity_cache_version', $version + 1, false );
		}

		/**
		 * Get the current cache version.
		 *
		 * @since 1.0.0
		 * @return int Current cache version.
		 */
		private static function get_cache_version() {
			return (int) get_option( 'wppo_activity_cache_version', 0 );
		}

		/**
		 * Get recent activities with pagination and caching.
		 *
		 * Retrieves recent activity logs from the database, using cache if available.
		 *
		 * @param array $params Pagination parameters including 'page' and 'per_page'.
		 * @return array Cached or freshly queried results with pagination details.
		 * @since 1.0.0
		 */
		public static function get_recent_activities( $params ) {
			global $wpdb;

			$page     = max( 1, absint( $params['page'] ?? 1 ) );
			$per_page = min( 100, max( 1, absint( $params['per_page'] ?? 10 ) ) );

			// Calculate offset for pagination.
			$offset = ( $page - 1 ) * $per_page;

			// Cache key with versioning to support per-page invalidation.
			$cache_key = 'wppo_activity_logs_v' . self::get_cache_version() . '_page_' . $page . '_per_page_' . $per_page;

			// Attempt to fetch cached data.
			$data = wp_cache_get( $cache_key, 'wppo_activity_logs' );

			if ( false === $data ) {
				/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery */
				// Direct query is required for custom table operations.

				// Get total number of activities.
				$total_items = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wppo_activity_logs"
				);

				// Calculate total pages.
				$total_pages = ceil( $total_items / $per_page );

				// Fetch paginated results.
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}wppo_activity_logs ORDER BY created_at DESC LIMIT %d OFFSET %d",
						$per_page,
						$offset
					),
					ARRAY_A
				);
				/* phpcs:enable */

				// Prepare data for caching.
				$data = array(
					'activities'   => $results,
					'total_items'  => $total_items,
					'current_page' => $page,
					'total_pages'  => $total_pages,
					'per_page'     => $per_page,
				);

				// Store data in cache.
				wp_cache_set( $cache_key, $data, 'wppo_activity_logs', HOUR_IN_SECONDS );
			}

			return $data;
		}
	}
}
