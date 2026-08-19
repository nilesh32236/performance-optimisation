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
		 * Option key used for the activity log cache salt.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const SALT_KEY = 'wppo_activity_log_salt';

		/**
		 * Private constructor to prevent direct instantiation.
		 *
		 * @since NEXT
		 */
		private function __construct() {}

		/**
		 * Add a new activity log entry.
		 *
		 * @param string $activity The activity description to log.
		 * @return void
		 * @since NEXT
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
				if ( function_exists( 'wp_cache_get_salted' ) ) {
					update_option( self::SALT_KEY, time(), false );
				} else {
					update_option( 'wppo_activity_cache_version', (int) get_option( 'wppo_activity_cache_version', 0 ) + 1, false );
				}
			}
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

			// Cache key with salt-based invalidation (WP 6.9+) or versioned fallback.
			$has_salted = function_exists( 'wp_cache_get_salted' );

			if ( $has_salted ) {
				$cache_key = "wppo_activity_logs_{$page}_{$per_page}";
				$data      = wp_cache_get_salted( $cache_key, 'wppo', self::SALT_KEY );
			} else {
				$cache_key = 'wppo_activity_logs_v' . (int) get_option( 'wppo_activity_cache_version', 0 ) . '_page_' . $page . '_per_page_' . $per_page;
				$data      = wp_cache_get( $cache_key, 'wppo_activity_logs' );
			}

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
				if ( $has_salted ) {
					wp_cache_set_salted( $cache_key, $data, 'wppo', self::SALT_KEY, HOUR_IN_SECONDS );
				} else {
					wp_cache_set( $cache_key, $data, 'wppo_activity_logs', HOUR_IN_SECONDS );
				}
			}

			return $data;
		}
	}
}
