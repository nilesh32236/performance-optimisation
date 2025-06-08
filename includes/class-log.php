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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log Class
 *
 * A class to handle logging activities and fetching recent activity logs with pagination.
 *
 * @since 1.0.0
 */
class Log {

	/**
	 * Database table name for activity logs.
	 *
	 * @var string
	 */
	private static string $table_name = '';

	/**
	 * Cache group for activity logs.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'wppo_activity_logs';

	/**
	 * Log constructor.
	 *
	 * Inserts a new activity log entry into the database.
	 *
	 * @since 1.0.0
	 * @param string $activity The activity description to log.
	 */
	public function __construct( string $activity ) {
		global $wpdb;
		self::init_table_name();

		$sanitized_activity = wp_kses(
			$activity,
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
				),
			)
		);

		if ( empty( $sanitized_activity ) ) {
			return;
		}

		/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		// Direct query is used for inserting into a custom table. Caching is handled at retrieval.
		$result = $wpdb->insert(
			self::$table_name,
			array(
				'activity'   => $sanitized_activity,
				'created_at' => current_time( 'mysql', 1 ),
			),
			array(
				'%s',
				'%s',
			)
		);
		/* phpcs:enable */

		if ( $result ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}

	/**
	 * Initializes the table name.
	 */
	private static function init_table_name(): void {
		global $wpdb;
		if ( empty( self::$table_name ) ) {
			self::$table_name = $wpdb->prefix . 'wppo_activity_logs';
		}
	}

	/**
	 * Get recent activities with pagination and caching.
	 *
	 * Retrieves recent activity logs from the database, using cache if available.
	 *
	 * @since 1.0.0
	 * @param array<string,int> $params Pagination parameters including 'page' and 'per_page'.
	 * @return array<string,mixed> Cached or freshly queried results with pagination details.
	 */
	public static function get_recent_activities( array $params ): array {
		global $wpdb;
		self::init_table_name();

		$page     = isset( $params['page'] ) ? absint( $params['page'] ) : 1;
		$per_page = isset( $params['per_page'] ) ? absint( $params['per_page'] ) : 10;
		$offset   = ( $page - 1 ) * $per_page;

		// Cache key.
		$cache_key = 'page_' . $page . '_per_page_' . $per_page;
		$data      = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $data ) {
			/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
			// Direct query is used for custom table operations. Caching is handled here.
			$total_items_query = 'SELECT COUNT(*) FROM ' . self::$table_name;
			$total_items       = (int) $wpdb->get_var( $total_items_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$results_query = $wpdb->prepare(
				'SELECT id, activity, created_at FROM ' . self::$table_name . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$per_page,
				$offset
			);
			$results       = $wpdb->get_results( $results_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			/* phpcs:enable */

			$total_pages = ( $per_page > 0 && $total_items > 0 ) ? ceil( $total_items / $per_page ) : 0;

			$formatted_activities = array();
			if ( is_array( $results ) ) {
				foreach ( $results as $result ) {
					$timestamp              = strtotime( $result['created_at'] );
					$formatted_date         = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
					$formatted_activities[] = array(
						'id'           => (int) $result['id'],
						'activity'     => $result['activity'],
						'created_at'   => $formatted_date,
						'raw_activity' => $result['activity'] . ' (' . $formatted_date . ')',
					);
				}
			}

			$data = array(
				'activities'   => $formatted_activities,
				'total_items'  => $total_items,
				'current_page' => $page,
				'total_pages'  => $total_pages,
				'per_page'     => $per_page,
			);

			wp_cache_set( $cache_key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		return $data;
	}
}
