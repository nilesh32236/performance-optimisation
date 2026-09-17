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
		 * @since 2.0.0
		 * @var string
		 */
		private const SALT_KEY = 'wppo_activity_log_salt';

		/**
		 * Per-request memo of the activity cache version (audit #1325):
		 * avoids one option lookup per paginated recent_activities call.
		 * Blog-keyed so switch_to_blog() in multisite never serves another
		 * site's version. Null until first read; tests may reset via
		 * reset_version_memo().
		 *
		 * @since NEXT
		 * @var array<int, int>
		 */
		private static $version_memo = array();

		/**
		 * Reset the version memo (tests only).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_version_memo(): void {
			self::$version_memo = array();
		}

		/**
		 * Get the activity cache version, memoized per request per site.
		 *
		 * @since NEXT
		 * @return int
		 */
		private static function get_cache_version(): int {
			$bid = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			if ( ! isset( self::$version_memo[ $bid ] ) ) {
				self::$version_memo[ $bid ] = (int) get_option( 'wppo_activity_cache_version', 0 );
			}
			return self::$version_memo[ $bid ];
		}

		/**
		 * Private constructor to prevent direct instantiation.
		 *
		 * @since 2.0.0
		 */
		private function __construct() {}

		/**
		 * Whether the salted object-cache layer is usable for persistence.
		 *
		 * Requires both the WP 6.9+ salted family and an external (persistent)
		 * object cache: without one, wp_cache_get_salted() is per-request
		 * memory and salted entries would lose persistence between requests
		 * (issue #882 review).
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		private static function salted_cache_active(): bool {
			return function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		}

		/**
		 * Add a new activity log entry.
		 *
		 * Authorization contract: callers (REST manage_options) must enforce
		 * capability checks; this method performs no check so cron/CLI paths
		 * keep working.
		 *
		 * @param mixed $activity The activity description to log; non-strings are ignored.
		 * @return void
		 * @since 2.0.0
		 */
		public static function add( $activity ): void {
			global $wpdb;

			if ( ! is_string( $activity ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( '_doing_it_wrong' ) ) {
					_doing_it_wrong( __METHOD__, __( 'Log::add() expects a string activity description; non-string values are ignored.', 'performance-optimisation' ), 'NEXT' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() handles output escaping internally; pre-escaping corrupts non-HTML contexts.
				}
				return;
			}
			// Sanitize first, then truncate: kses entity-encoding can expand
			// length (& to &amp;), so truncating first could still exceed the
			// varchar(255) column under strict mode.
			$activity = wp_kses_post( $activity );
			if ( function_exists( 'mb_substr' ) ) {
				$activity = mb_substr( $activity, 0, 255, 'UTF-8' );
			} else {
				// No mbstring: cut at a valid UTF-8 boundary so a multibyte
				// sequence is never split mid-character into invalid UTF-8.
				$activity      = substr( $activity, 0, 255 );
				$utf8_attempts = 0;
				while ( '' !== $activity && 1 !== preg_match( '//u', $activity ) && $utf8_attempts < 3 ) {
					$activity = substr( $activity, 0, -1 );
					++$utf8_attempts;
				}
			}
			if ( '' === trim( $activity ) ) {
				return;
			}

			$table_name = $wpdb->prefix . 'wppo_activity_logs';

			/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery */
			// Direct query is required for inserting into a custom table.
			$result = $wpdb->insert(
				$table_name,
				array(
					'activity' => $activity,
				),
				array(
					'%s',
				)
			);
			/* phpcs:enable */

			if ( $result ) {
				if ( self::salted_cache_active() ) {
					// Monotonic increment so two mutations within the same
					// second always produce distinct salts (issue #882 review).
					update_option( self::SALT_KEY, (int) get_option( self::SALT_KEY, 0 ) + 1, false );
				} else {
					$new_version = (int) get_option( 'wppo_activity_cache_version', 0 ) + 1;
					update_option( 'wppo_activity_cache_version', $new_version, false );
					// Keep the per-request memo coherent with the bump so a
					// list-after-add in the same request cannot serve stale
					// cache (audit #1325). Blog-keyed like the reader.
					$memo_bid                        = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
					self::$version_memo[ $memo_bid ] = $new_version;
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

			// Cache key with salt-based invalidation (WP 6.9+, persistent
			// object cache only — without one the salted layer is per-request
			// memory and would lose persistence; issue #882 review) or
			// versioned fallback.
			$has_salted = self::salted_cache_active();

			if ( $has_salted ) {
				$cache_key = "wppo_activity_logs_{$page}_{$per_page}";
				// The salt is the current option VALUE (Util::cache_salt), not
				// the option key — passing the key made Log::add()'s bump a
				// no-op (issue #882).
				$data = wp_cache_get_salted( $cache_key, 'wppo', Util::cache_salt( self::SALT_KEY ) );
			} else {
				$cache_key = 'wppo_activity_logs_v' . self::get_cache_version() . '_page_' . $page . '_per_page_' . $per_page;
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
				$total_pages = (int) ceil( $total_items / $per_page );

				// Fetch paginated results. The `id DESC` secondary sort keeps
				// pagination deterministic when rows share a timestamp (audit
				// #888 finding 24).
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}wppo_activity_logs ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
						$per_page,
						$offset
					),
					ARRAY_A
				);
				/* phpcs:enable */

				// Prepare data for caching.
				$data = array(
					'activities'   => is_array( $results ) ? $results : array(),
					'total_items'  => $total_items,
					'current_page' => $page,
					'total_pages'  => $total_pages,
					'per_page'     => $per_page,
				);

				// Store data in cache.
				if ( $has_salted ) {
					wp_cache_set_salted( $cache_key, $data, 'wppo', Util::cache_salt( self::SALT_KEY ), HOUR_IN_SECONDS );
				} else {
					wp_cache_set( $cache_key, $data, 'wppo_activity_logs', HOUR_IN_SECONDS );
				}
			}

			return $data;
		}
	}
}
