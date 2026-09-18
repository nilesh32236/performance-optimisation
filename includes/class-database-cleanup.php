<?php
/**
 * Database Cleanup functionality.
 *
 * Provides methods to clean various types of database bloat including
 * post revisions, auto-drafts, trashed posts/comments, spam comments,
 * expired transients, and orphaned post meta.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.1.0
 */

namespace PerformanceOptimise\Inc;

use WP_Error;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Database_Cleanup' ) ) {
	/**
	 * Class Database_Cleanup
	 *
	 * Handles database optimization operations using direct $wpdb queries
	 * for maximum efficiency.
	 *
	 * @since 1.1.0
	 */
	class Database_Cleanup {

		/**
		 * Maps cleanup type keys to their affected WordPress table identifiers.
		 *
		 * Keys correspond to cleanup types; values are unprefixed table identifiers
		 * passed to `$wpdb->{table}` for dynamic table name resolution.
		 *
		 * @since 2.0.0
		 * @var array<string, array<string>>
		 */
		public const TABLE_MAP = array(
			'revisions'          => array( 'posts', 'postmeta' ),
			'auto_drafts'        => array( 'posts', 'postmeta' ),
			'trashed_posts'      => array( 'posts', 'postmeta' ),
			'spam_comments'      => array( 'comments', 'commentmeta' ),
			'trashed_comments'   => array( 'comments', 'commentmeta' ),
			'expired_transients' => array( 'options' ),
			'orphan_postmeta'    => array( 'postmeta' ),
			'unattached_media'   => array( 'posts', 'postmeta' ),
			'oembed_cache'       => array( 'options' ),
		);

		/**
		 * Month length in seconds shared by the Action Scheduler bounds below.
		 *
		 * A single named constant so the 3-month purge cap and the retention
		 * defaults cannot drift apart when one of them is bumped (issue
		 * #1310 review). 31-day months, matching the upstream AS default.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const MONTH_SECONDS = 2678400;

		/**
		 * Upper bound for the opt-in failed-action purge lifespan.
		 *
		 * Three months in seconds (31-day months), mirroring the
		 * `action_scheduler_retention_period_for_failed` default read by
		 * {@see get_action_scheduler_retention()}. The purge uses
		 * `min( filtered retention, this cap )` so the retention filters
		 * stay authoritative while the documented 3-month bound holds even
		 * when an operator raises the upstream retention (issue #1310
		 * review).
		 *
		 * @since NEXT
		 * @var int
		 */
		private const FAILED_PURGE_MAX_LIFESPAN = 3 * self::MONTH_SECONDS;

		/**
		 * Bounds for the opt-in failed-action purge loop.
		 *
		 * Named so the batch clamp, iteration cap, wall-clock budget, and
		 * wrapper reserve cannot drift when one call site is bumped
		 * (issue #1310 review).
		 *
		 * @since NEXT
		 * @var int
		 */
		private const FAILED_PURGE_BATCH_MAX = 100;

		/**
		 * Maximum store passes per purge invocation.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const FAILED_PURGE_MAX_ITERATIONS = 10;

		/**
		 * Wall-clock budget in seconds shared by the upstream cleaner loop
		 * and the failed-action purge in one invocation.
		 *
		 * @since NEXT
		 * @var float
		 */
		private const FAILED_PURGE_BUDGET_SECONDS = 15.0;

		/**
		 * Budget reserve in seconds: the wrapper skips the purge when less
		 * than this remains so one invocation never stacks two full
		 * budgets back to back.
		 *
		 * @since NEXT
		 * @var float
		 */
		private const FAILED_PURGE_RESERVE_SECONDS = 2.0;

		/**
		 * Per-request memo for the Action Scheduler health payload plus its timestamp.
		 *
		 * @since NEXT
		 * @var array|null
		 */
		private static $health_memo = null;

		/**
		 * Microtime when the health memo was stored.
		 *
		 * @since NEXT
		 * @var float
		 */
		private static $health_memo_time = 0.0;

		/**
		 * Maps cleanup method names to their cleanup type keys for TABLE_MAP lookup.
		 *
		 * @since 2.0.0
		 * @var array<string, string>
		 */
		private const METHOD_TO_TYPE = array(
			'clean_revisions_advanced' => 'revisions',
			'clean_auto_drafts'        => 'auto_drafts',
			'clean_trashed_posts'      => 'trashed_posts',
			'clean_spam_comments'      => 'spam_comments',
			'clean_trashed_comments'   => 'trashed_comments',
			'clean_expired_transients' => 'expired_transients',
			'clean_orphan_postmeta'    => 'orphan_postmeta',
			'clean_unattached_media'   => 'unattached_media',
			'clean_oembed_cache'       => 'oembed_cache',
		);

		/**
		 * Single source of truth for cleanup type => method mapping.
		 *
		 * Used by Rest, Abilities and clean_all to avoid 4-way drift.
		 * Mirrors TABLE_MAP keys.
		 *
		 * @since 2.0.0
		 * @var array<string, string>
		 */
		public const CLEANUP_METHOD_MAP = array(
			'revisions'          => 'clean_revisions_advanced',
			'auto_drafts'        => 'clean_auto_drafts',
			'trashed_posts'      => 'clean_trashed_posts',
			'spam_comments'      => 'clean_spam_comments',
			'trashed_comments'   => 'clean_trashed_comments',
			'expired_transients' => 'clean_expired_transients',
			'orphan_postmeta'    => 'clean_orphan_postmeta',
			'unattached_media'   => 'clean_unattached_media',
			'oembed_cache'       => 'clean_oembed_cache',
		);

		/**
		 * Get the cleanup method map (type => method).
		 *
		 * @since 2.0.0
		 * @return array<string, string>
		 */
		public static function get_cleanup_method_map(): array {
			return self::CLEANUP_METHOD_MAP;
		}

		/**
		 * Standalone cleanup type for Action Scheduler queue health.
		 *
		 * Kept outside CLEANUP_METHOD_MAP/TABLE_MAP on purpose: those maps
		 * assume `$wpdb` table/meta deletes, while AS purging must delegate
		 * to `ActionScheduler_QueueCleaner` (issue #1106).
		 *
		 * @since NEXT
		 * @var string
		 */
		public const ACTION_SCHEDULER_TYPE = 'action_scheduler';

		/**
		 * Get valid cleanup types including 'all'.
		 *
		 * Includes the standalone `action_scheduler` type alongside the
		 * CLEANUP_METHOD_MAP keys.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		public static function get_valid_cleanup_types(): array {
			return array_merge( array_keys( self::CLEANUP_METHOD_MAP ), array( self::ACTION_SCHEDULER_TYPE, 'all' ) );
		}

		/**
		 * Option key used for the DB cleanup counts cache salt.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private const SALT_KEY = 'wppo_db_cleanup_salt';

		/**
		 * Batched DELETE helper for post/comment cleanup.
		 *
		 * Centralises the `SELECT IDs LIMIT 1000 → DELETE meta → DELETE rows` loop
		 * that was copy-pasted across 5 `clean_*` methods. Keeps error handling,
		 * placeholder generation and `while ( count >= batch )` semantics identical.
		 *
		 * @since 2.0.0
		 *
		 * Contract: $select_sql must be already-prepared internal SQL (never
		 * pass user input); callers are the hardcoded clean_* methods only.
		 * Static SQL only — dynamic values belong in the prepared DELETEs
		 * below, never interpolated into $select_sql (audit #1329).
		 * Destructive methods must only be reached via authorized callers
		 * (REST manage_options, cron, WP-CLI) — direct PHP calls bypass
		 * authorization.
		 *
		 * @param string $select_sql  SQL returning a single ID column (must include LIMIT).
		 * @param string $meta_table  Fully-qualified meta table name (e.g. $wpdb->postmeta).
		 * @param string $meta_column FK column in the meta table (e.g. post_id).
		 * @param string $main_table  Fully-qualified main table name (e.g. $wpdb->posts).
		 * @param string $id_column   PK column in the main table (e.g. ID).
		 * @param int    $batch       Batch size; matches the LIMIT in $select_sql.
		 * @return int|false Number of main rows deleted, or false on SQL error.
		 */
		private static function delete_in_batches( string $select_sql, string $meta_table, string $meta_column, string $main_table, string $id_column, int $batch = 1000 ): int|false {
			global $wpdb;
			// Allowlist identifiers (cannot use placeholders for table/column names).
			$allowed_tables  = array( $wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->commentmeta );
			$allowed_columns = array( 'ID', 'comment_ID', 'post_id', 'comment_id' );
			if ( ! in_array( $meta_table, $allowed_tables, true ) || ! in_array( $main_table, $allowed_tables, true ) ) {
				return false;
			}
			if ( ! in_array( $meta_column, $allowed_columns, true ) || ! in_array( $id_column, $allowed_columns, true ) ) {
				return false;
			}
			$deleted = 0;

			do {
				$wpdb->last_error = '';
				// Audit #1453: static-SQL-only sink — reject placeholders so a
				// future caller cannot interpolate dynamic values here.
				if ( false !== strpos( $select_sql, '%' ) ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct query necessary for batched cleanup helper with dynamic SELECT.
				$ids = $wpdb->get_col( $select_sql );

				if ( ! empty( $wpdb->last_error ) ) {
					return false;
				}

				if ( empty( $ids ) ) {
					break;
				}

				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$meta_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE {$meta_column} IN ($placeholders)", ...$ids ) );

				if ( false === $meta_deleted ) {
					return false;
				}

				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$rows_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$main_table} WHERE {$id_column} IN ($placeholders)", ...$ids ) );

				if ( false === $rows_deleted ) {
					return false;
				}

				if ( $rows_deleted ) {
					$deleted += (int) $rows_deleted;
				}
				$ids_count = count( $ids );
			} while ( $ids_count >= $batch );

			return $deleted;
		}

		/**
		 * Delete all post revisions from the database.
		 *
		 * Authorization contract: callers (REST/cron/CLI) must enforce
		 * manage_options; this method performs no capability check so
		 * scheduled/CLI paths keep working.
		 *
		 * @since 1.1.0
		 * @return int|false The number of rows deleted, or `false` on SQL error.
		 */
		public static function clean_revisions(): int|false {
			global $wpdb;
			return self::delete_in_batches(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT 1000",
				$wpdb->postmeta,
				'post_id',
				$wpdb->posts,
				'ID'
			);
		}

		/**
		 * Remove post revision records older than a computed cutoff while keeping the latest
		 * revisions per parent post.
		 *
		 * @since 1.3.0
		 *
		 * @param int $max_age_days Maximum age in days (clamped to 1-365; audit #1362); revisions older than now - $max_age_days will be eligible for deletion.
		 * @param int $keep_latest  Number of most recent revisions to retain per parent post.
		 * @return int|false Number of rows deleted, or `false` on database error.
		 */
		public static function clean_revisions_advanced( $max_age_days = 30, $keep_latest = 5 ) {
			// Audit #1362: clamp before the cutoff math — a negative value
			// would push the cutoff into the future and over-delete.
			$max_age_days = max( 1, min( 365, (int) $max_age_days ) );
			$keep_latest  = max( 1, $keep_latest );
			global $wpdb;
			$deleted = 0;

			$max_age_seconds = $max_age_days * DAY_IN_SECONDS;
			$cutoff_date_gmt = gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds );

			$greatest_parent_id = 0;
			$has_more           = true;

			do {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$parent_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_parent FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent > %d GROUP BY post_parent HAVING COUNT(*) > %d ORDER BY post_parent ASC LIMIT 200",
						$greatest_parent_id,
						$keep_latest
					)
				);

				if ( ! empty( $wpdb->last_error ) ) {
					return false;
				}

				if ( empty( $parent_ids ) ) {
					break;
				}

				$greatest_parent_id = (int) end( $parent_ids );
				$has_more           = ( count( $parent_ids ) === 200 );

				foreach ( $parent_ids as $parent_id ) {
					$last_date      = null;
					$last_id        = 0;
					$first_page     = true;
					$batch_size     = 500;
					$pending_delete = array();

					do {
						$wpdb->last_error = '';
						if ( $first_page && null === $last_date ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
							$revisions = $wpdb->get_results(
								$wpdb->prepare(
									"SELECT ID, post_date_gmt FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'revision' ORDER BY post_date_gmt DESC, ID DESC LIMIT %d",
									$parent_id,
									$batch_size
								)
							);
						} else {
							// Keyset pagination via row-value comparison (post_date_gmt, ID) < (%s, %d) is index-friendly
							// and avoids the OR that can defeat composite-index use. Falls back to OR semantics on very old MySQL
							// but MySQL 5.7+ (WP minimum) supports row-value comparison.
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
							$revisions = $wpdb->get_results(
								$wpdb->prepare(
									"SELECT ID, post_date_gmt FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'revision' AND (post_date_gmt, ID) < (%s, %d) ORDER BY post_date_gmt DESC, ID DESC LIMIT %d",
									$parent_id,
									$last_date,
									$last_id,
									$batch_size
								)
							);
						}

						if ( null === $revisions || ! empty( $wpdb->last_error ) ) {
							break;
						}

						if ( empty( $revisions ) ) {
							break;
						}

						// Keep the latest X revisions only on the first page; subsequent pages are all older.
						$eligible   = $first_page ? array_slice( $revisions, $keep_latest ) : $revisions;
						$first_page = false;

						foreach ( $eligible as $rev ) {
							// Delete if older than cutoff.
							if ( $rev->post_date_gmt < $cutoff_date_gmt ) {
								$pending_delete[] = $rev->ID;
							}
						}

						// Flush per-parent in chunks to avoid unbounded accumulation across 200 parents.
						if ( count( $pending_delete ) >= 500 ) {
							$flush_result = self::flush_revision_deletes( $pending_delete, $deleted );
							if ( false === $flush_result ) {
								return false;
							}
							$pending_delete = array();
						}

						$last           = end( $revisions );
						$last_date      = $last->post_date_gmt;
						$last_id        = (int) $last->ID;
						$revision_count = count( $revisions );
					} while ( $revision_count === $batch_size );

					if ( ! empty( $pending_delete ) ) {
						$flush_result = self::flush_revision_deletes( $pending_delete, $deleted );
						if ( false === $flush_result ) {
							return false;
						}
					}
				}
			} while ( $has_more );

			return $deleted;
		}

		/**
		 * Flush a batch of revision IDs to the database (postmeta + posts) in 50-row chunks.
		 *
		 * @since 2.0.0
		 * @param int[] $ids     Revision IDs to delete.
		 * @param int   $deleted Running deleted counter (passed by reference, incremented).
		 * @return bool True on success, false on SQL error.
		 */
		private static function flush_revision_deletes( array $ids, int &$deleted ): bool {
			global $wpdb;
			if ( empty( $ids ) ) {
				return true;
			}
			$chunks = array_chunk( $ids, 50 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$meta_deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM $wpdb->postmeta WHERE post_id IN (" . $placeholders . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$chunk
					)
				);
				if ( false === $meta_deleted ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM $wpdb->posts WHERE ID IN (" . $placeholders . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$chunk
					)
				);
				if ( false === $result ) {
					return false;
				}
				if ( $result ) {
					$deleted += (int) $result;
				}
			}
			return true;
		}

		/**
		 * Remove all auto-draft posts and their associated postmeta in batched operations.
		 *
		 * @since 1.1.0
		 * @return int|false Total number of posts deleted, or `false` on SQL error.
		 */
		public static function clean_auto_drafts() {
			global $wpdb;
			return self::delete_in_batches(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT 1000",
				$wpdb->postmeta,
				'post_id',
				$wpdb->posts,
				'ID'
			);
		}

		/**
		 * Remove all posts with status 'trash' and their associated postmeta.
		 *
		 * Performs deletions in batches and returns the total number of posts deleted, or `false` if a database error occurs.
		 *
		 * @since 1.1.0
		 * @return int|false Total number of posts deleted, or `false` on SQL error.
		 */
		public static function clean_trashed_posts() {
			global $wpdb;
			return self::delete_in_batches(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT 1000",
				$wpdb->postmeta,
				'post_id',
				$wpdb->posts,
				'ID'
			);
		}

		/**
		 * Delete all spam comments.
		 *
		 * Excludes WordPress 6.9+ Notes (`comment_type='note'`) from deletion —
		 * Notes are personal-note comment rows that can legitimately land in the
		 * spam approval state and must never be destroyed by cleanup. On WP <6.9
		 * no `note` rows exist so the predicate is a no-op (issue #884). The
		 * COALESCE makes the predicate NULL-safe so a row with an unexpected
		 * NULL `comment_type` stays eligible for cleanup.
		 *
		 * @since 1.1.0
		 * @since 2.0.0 Exclude WP 6.9+ Notes (`comment_type='note'`).
		 * @return int|false Number of rows deleted, or false on error.
		 */
		public static function clean_spam_comments() {
			global $wpdb;
			return self::delete_in_batches(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' AND COALESCE( comment_type, '' ) != 'note' LIMIT 1000",
				$wpdb->commentmeta,
				'comment_id',
				$wpdb->comments,
				'comment_ID'
			);
		}

		/**
		 * Remove trashed comments and their comment meta from the database in batches.
		 *
		 * Excludes WordPress 6.9+ Notes (`comment_type='note'`) from deletion —
		 * Notes are personal-note comment rows that can legitimately land in the
		 * trash approval state and must never be destroyed by cleanup. On WP <6.9
		 * no `note` rows exist so the predicate is a no-op (issue #884). The
		 * COALESCE makes the predicate NULL-safe so a row with an unexpected
		 * NULL `comment_type` stays eligible for cleanup.
		 *
		 * @since 1.1.0
		 * @since 2.0.0 Exclude WP 6.9+ Notes (`comment_type='note'`).
		 * @return int|false Number of rows deleted, or false on error.
		 */
		public static function clean_trashed_comments() {
			global $wpdb;
			return self::delete_in_batches(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND COALESCE( comment_type, '' ) != 'note' LIMIT 1000",
				$wpdb->commentmeta,
				'comment_id',
				$wpdb->comments,
				'comment_ID'
			);
		}

		/**
		 * Delete expired transients and their timeout entries from the options table.
		 *
		 * Scans for transient data options whose corresponding `_transient_timeout_*`
		 * value is less than the current time and removes both the data and timeout
		 * option rows.
		 *
		 * @since 1.1.0
		 * @return int|false `int` number of option rows deleted, `false` on SQL error.
		 */
		public static function clean_expired_transients() {
			global $wpdb;

			$time           = time();
			$deleted        = 0;
			$batch          = 1000;
			$transient_keys = array(
				'_transient_'      => '_transient_timeout_',
				'_site_transient_' => '_site_transient_timeout_',
			);

			foreach ( $transient_keys as $prefix => $timeout_prefix ) {
				// On multisite, _site_transient_ entries live in wp_sitemeta — skip them here
				// (the options table query below only applies to the wp_options table).
				// On single-site, _site_transient_ entries are stored in wp_options and must be cleaned.
				$is_multisite = false;
				if ( function_exists( 'is_multisite' ) ) {
					try {
						$is_multisite = is_multisite();
					} catch ( \Throwable $e ) {
						$is_multisite = false;
					}
				}
				if ( '_site_transient_' === $prefix && $is_multisite ) {
					continue;
				}

				do {
					$wpdb->last_error = '';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup.
					$ids = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT a.option_name FROM $wpdb->options a
							INNER JOIN $wpdb->options b ON b.option_name = CONCAT( %s, SUBSTRING( a.option_name, %d ) )
							WHERE a.option_name LIKE %s
							AND a.option_name NOT LIKE %s
							AND b.option_value < %d
							LIMIT %d",
							$timeout_prefix,
							strlen( $prefix ) + 1,
							$wpdb->esc_like( $prefix ) . '%',
							$wpdb->esc_like( $timeout_prefix ) . '%',
							$time,
							$batch
						)
					);

					if ( ! empty( $wpdb->last_error ) ) {
						return false;
					}

					$ids_count = is_array( $ids ) ? count( $ids ) : 0;
					if ( 0 === $ids_count ) {
						break;
					}

					// For each transient, we need to delete both the data and the timeout.
					$to_delete  = array();
					$prefix_len = strlen( $prefix );
					foreach ( $ids as $name ) {
						$to_delete[] = $name;
						$to_delete[] = $timeout_prefix . substr( $name, $prefix_len );
					}

					$placeholders = implode( ',', array_fill( 0, count( $to_delete ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$result = $wpdb->query(
						$wpdb->prepare(
							"DELETE FROM $wpdb->options WHERE option_name IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
							...$to_delete
						)
					);

					if ( false === $result ) {
						return false;
					}

					$deleted += (int) $result;
				} while ( $ids_count === $batch );
			} // End foreach transient prefix.

			return $deleted;
		}

		/**
		 * Delete orphaned post meta.
		 *
		 * Removes postmeta rows that have no matching post in the posts table.
		 *
		 * @since 1.1.0
		 * @return int|false Number of rows deleted, or false on error.
		 */
		public static function clean_orphan_postmeta() {
			global $wpdb;
			$deleted = 0;
			$batch   = 5000;

			do {
				$wpdb->last_error = '';
				// Step 1: Collect IDs of orphaned meta.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT pm.meta_id FROM $wpdb->postmeta pm
						LEFT JOIN $wpdb->posts p ON p.ID = pm.post_id
						WHERE p.ID IS NULL LIMIT %d",
						$batch
					)
				);

				if ( ! empty( $wpdb->last_error ) ) {
					return false;
				}

				$ids_count = is_array( $ids ) ? count( $ids ) : 0;
				if ( 0 === $ids_count ) {
					break;
				}

				// Step 2: Delete collected IDs.
				$placeholders = implode( ',', array_fill( 0, $ids_count, '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM $wpdb->postmeta WHERE meta_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$ids
					)
				);

				if ( false === $result ) {
					return false;
				}

				$deleted += (int) $result;
			} while ( $ids_count === $batch );

			return $deleted;
		}

		/**
		 * Delete media attachments with no parent post.
		 *
		 * Removes orphaned attachment posts (and their postmeta) that are not
		 * referenced by any post, page, or custom post type. Unattached media
		 * accumulates quickly on busy sites and bloats the database.
		 *
		 * @since 2.0.0
		 * @return int|false Number of attachments deleted, or false on error.
		 */
		public static function clean_unattached_media() {
			global $wpdb;
			$deleted = 0;
			$batch   = 100;
			// Time-box a single invocation so huge unattached-media backlogs
			// cannot exceed cron wall-clock limits; remaining items are
			// picked up on the next scheduled run.
			$budget = (int) apply_filters( 'wppo_unattached_media_time_budget', 20 );
			if ( $budget < 1 ) {
				$budget = 20;
			}
			$deadline = microtime( true ) + $budget;

			do {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup.
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM $wpdb->posts
						WHERE post_type = 'attachment'
						AND post_parent = 0
						AND post_status = 'inherit'
						LIMIT %d",
						$batch
					)
				);

				if ( ! empty( $wpdb->last_error ) ) {
					return false;
				}

				$ids_count = is_array( $ids ) ? count( $ids ) : 0;
				if ( 0 === $ids_count ) {
					break;
				}

				// Use the WordPress API so physical files, intermediate sizes, backups
				// and attachment deletion hooks are handled, not just the DB rows.
				foreach ( $ids as $id ) {
					if ( microtime( true ) >= $deadline ) {
						break 2;
					}
					if ( false !== wp_delete_attachment( (int) $id, true ) ) {
						++$deleted;
					}
				}
			} while ( $ids_count === $batch );

			return $deleted;
		}

		/**
		 * Autoload values that count as "autoloaded" for the options audit.
		 *
		 * Mirrors Site Health / Performance Lab: on WP 6.6+ the core API
		 * (`wp_autoload_values_to_autoload()`) defines the set (`yes`, `on`,
		 * `auto`, `auto-on`); on older cores only `yes` counts (yes/no
		 * legacy values). All 6.6 behavior is gated on `version_compare()`
		 * plus `function_exists()` so WP 6.2-6.5 uses the legacy path.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		public static function get_autoloadable_values(): array {
			// Single filterable version read: is_wp_version_at_least() and
			// the known-pre-6.6 check below must share one get_bloginfo()
			// call instead of two round trips per audit query path.
			$current = '';
			if ( function_exists( 'get_bloginfo' ) ) {
				try {
					$current = (string) get_bloginfo( 'version' );
				} catch ( \Throwable $e ) {
					unset( $e );
					$current = '';
				}
			}
			$is_66 = '' !== $current && version_compare( $current, '6.6', '>=' );
			if ( function_exists( 'wp_autoload_values_to_autoload' ) && $is_66 ) {
				return (array) wp_autoload_values_to_autoload();
			}
			// Known pre-6.6 core: only `yes` counts (yes/no legacy values).
			if ( '' !== $current && ! $is_66 ) {
				return array( 'yes' );
			}
			// Version unknown (or modern core without the helper, e.g. unit
			// tests): fail open to the full 6.6 list for Site Health parity.
			return array( 'yes', 'on', 'auto', 'auto-on' );
		}

		/**
		 * Whether the running WordPress version is at least the given version.
		 *
		 * Guarded helper for 6.6 Options-API gating: returns false when the
		 * version cannot be determined so callers fail open to legacy paths.
		 *
		 * @since NEXT
		 * @param string $version Minimum version (e.g. '6.6').
		 * @return bool
		 */
		public static function is_wp_version_at_least( string $version ): bool {
			if ( ! function_exists( 'get_bloginfo' ) ) {
				return false;
			}
			try {
				$current = (string) get_bloginfo( 'version' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			if ( '' === $current ) {
				return false;
			}
			return (bool) version_compare( $current, $version, '>=' );
		}

		/**
		 * Default autoload value for newly created options.
		 *
		 * Guarded wrapper around the 6.6 `wp_default_autoload_value()` helper
		 * (see https://developer.wordpress.org/reference/functions/wp_default_autoload_value/).
		 * Falls back to `'yes'` on older cores without the function.
		 *
		 * @since NEXT
		 * @return string
		 */
		public static function get_default_autoload_value(): string {
			if ( function_exists( 'wp_default_autoload_value' ) && self::is_wp_version_at_least( '6.6' ) ) {
				try {
					$value = (string) wp_default_autoload_value();
				} catch ( \Throwable $e ) {
					unset( $e );
					return 'yes';
				}
				return '' !== $value ? $value : 'yes';
			}
			return 'yes';
		}

		/**
		 * List the largest autoloaded options, by stored byte size.
		 *
		 * Mirrors the Performance Lab autoloaded-options health check so users can
		 * identify option bloat that inflates every page load.
		 *
		 * @since 2.0.0
		 *
		 * @param int $limit Maximum number of options to return.
		 * @return array<int, array{option_name:string,size:int,autoload:string}> Sorted by size.
		 */
		public static function get_autoloaded_options( int $limit = 20 ): array {
			global $wpdb;

			// Audit #1325: clamp like get_autoload_candidates() so a future
			// direct caller cannot force a full-table filesort.
			$limit = max( 1, min( 100, $limit ) );

			$autoload_values = self::get_autoloadable_values();
			$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostic query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, autoload, LENGTH(option_value) AS opt_size FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY opt_size DESC LIMIT " . (int) $limit, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...$autoload_values
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) ) {
				return array();
			}

			$result = array();
			foreach ( $rows as $row ) {
				$name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
				if ( '' === $name ) {
					continue;
				}
				$result[] = array(
					'option_name' => $name,
					'size'        => (int) ( $row['opt_size'] ?? 0 ),
					'autoload'    => isset( $row['autoload'] ) ? (string) $row['autoload'] : '',
				);
			}

			return $result;
		}

		/**
		 * Option name storing prior autoload values for remediated options.
		 *
		 * Maps option_name => prior autoload value so every flip is revertible.
		 * Site-specific via get_option/update_option (multisite-safe).
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const REMEDIATED_OPTION = 'wppo_autoload_remediated';

		/**
		 * Default minimum option size (bytes) for autoload remediation candidacy.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const AUTOLOAD_SIZE_THRESHOLD = 1024;

		/**
		 * Maximum number of options a single remediation pass will touch.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const AUTOLOAD_REMEDIATION_LIMIT = 100;

		/**
		 * Minimum remediation threshold in bytes (100 B).
		 *
		 * Shared by settings validation, the clamp helper, and the REST layer
		 * so direct PHP calls cannot bypass the bounds.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const AUTOLOAD_THRESHOLD_MIN = 100;

		/**
		 * Maximum remediation threshold in bytes (10 MB).
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const AUTOLOAD_THRESHOLD_MAX = 10485760;

		/**
		 * Maximum number of candidates a single remediation pass will consider.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const AUTOLOAD_LIMIT_MAX = 500;

		/**
		 * Maximum number of expired-transient rows a single export will return.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const EXPORT_LIMIT_MAX = 2000;

		/**
		 * Core option names that must never have their autoload value flipped.
		 *
		 * Filterable via `wppo_core_autoload_options`.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		public static function get_core_autoload_options(): array {
			$core = array(
				'siteurl',
				'home',
				'blogname',
				'blogdescription',
				'users_can_register',
				'admin_email',
				'start_of_week',
				'blog_charset',
				'date_format',
				'time_format',
				'default_category',
				'template',
				'stylesheet',
				'posts_per_page',
				'posts_per_rss',
				'show_on_front',
				'page_on_front',
				'page_for_posts',
				'default_post_format',
				'default_comments_page',
				'comment_moderation',
				'comment_max_links',
				'moderation_keys',
				'disallowed_keys',
				'gmt_offset',
				'timezone_string',
				'rewrite_rules',
				'active_plugins',
				'sidebars_widgets',
				'widget_text',
				'cron',
				'db_version',
				'permalink_structure',
				'category_base',
				'tag_base',
				'wp_user_roles',
				'default_role',
				'comments_notify',
				'moderation_notify',
				'comment_registration',
				'require_name_email',
				'comment_order',
				'close_comments_for_old_posts',
				'close_comments_days_old',
				'thread_comments',
				'thread_comments_depth',
				'page_comments',
				'comments_per_page',
				'default_comments_per_page',
				'comment_whitelist',
				'comment_previously_approved',
				'comment_agent_blacklist',
				'sticky_posts',
				'upload_path',
				'upload_url_path',
				'uploads_use_yearmonth_folders',
				'thumbnail_size_w',
				'thumbnail_size_h',
				'thumbnail_crop',
				'medium_size_w',
				'medium_size_h',
				'large_size_w',
				'large_size_h',
				'image_default_link_type',
				'image_default_size',
				'image_default_align',
				'mailserver_url',
				'mailserver_port',
				'mailserver_login',
				'mailserver_pass',
				'default_email_category',
				'ping_sites',
				'blog_public',
				'default_link_category',
				'show_avatars',
				'avatar_rating',
				'avatar_default',
				'use_smilies',
				'use_balanceTags',
				'links_updated_date_format',
				'links_recently_updated_prepend',
				'links_recently_updated_append',
				'links_recently_updated_time',
			);
			/**
			 * Filters the core option names excluded from autoload remediation.
			 *
			 * @since 2.0.0
			 * @param string[] $core Core option names.
			 */
			$filtered = apply_filters( 'wppo_core_autoload_options', $core );
			return is_array( $filtered ) ? array_values( array_unique( array_map( 'strval', $filtered ) ) ) : $core;
		}

		/**
		 * Whether the runtime supports flipping autoload values (WP 6.6+).
		 *
		 * WP 6.6 introduced wp_set_option_autoload() and the `auto-on` value.
		 * On older cores remediation is unavailable (fail-open: dry run still
		 * reports, apply leaves values unchanged).
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function is_autoload_remediation_supported(): bool {
			if ( ! function_exists( 'wp_set_option_autoload' ) ) {
				return false;
			}
			if ( function_exists( 'get_bloginfo' ) ) {
				try {
					$version = (string) get_bloginfo( 'version' );
				} catch ( \Throwable $e ) {
					return false;
				}
				if ( '' !== $version && version_compare( $version, '6.6', '<' ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Resolve the remediation size threshold from settings with bounds.
		 *
		 * Additive `database_cleanup.autoloadThreshold` key (default 1024,
		 * clamped to 100 .. 10MB) so existing installs migrate without change.
		 *
		 * @since 2.0.0
		 * @param mixed $settings Optional settings array or null to load from option.
		 * @return int Threshold in bytes.
		 */
		public static function get_autoload_remediation_threshold( $settings = null ): int {
			if ( null === $settings ) {
				$settings = Util::get_settings();
				$settings = $settings['database_cleanup'] ?? array();
				if ( ! is_array( $settings ) ) {
					$settings = array();
				}
			}
			$threshold = isset( $settings['autoloadThreshold'] ) ? (int) $settings['autoloadThreshold'] : self::AUTOLOAD_SIZE_THRESHOLD;
			return max( self::AUTOLOAD_THRESHOLD_MIN, min( self::AUTOLOAD_THRESHOLD_MAX, $threshold ) );
		}

		/**
		 * Get the total bytes currently held by autoloaded options.
		 *
		 * Uses {@see get_autoloadable_values()} so the total matches Site
		 * Health within rounding (same WHERE predicate as core).
		 *
		 * @since 2.0.0
		 * @return int Total bytes, or 0 on error.
		 */
		public static function get_autoload_total_bytes(): int {
			global $wpdb;
			$autoload_values = self::get_autoloadable_values();
			$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostic query.
			$total = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...$autoload_values
				)
			);
			return null === $total ? 0 : (int) $total;
		}

		/**
		 * Get the number of currently autoloaded options.
		 *
		 * Shares the {@see get_autoloadable_values()} predicate with
		 * {@see get_autoload_total_bytes()} and {@see get_autoloaded_options()}
		 * so all three agree with each other and with Site Health.
		 *
		 * @since NEXT
		 * @return int Count, or 0 on error.
		 */
		public static function get_autoload_count(): int {
			global $wpdb;
			if ( ! isset( $wpdb->options ) ) {
				return 0;
			}
			$autoload_values = self::get_autoloadable_values();
			$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostic query.
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$autoload_values
					)
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
			return null === $count ? 0 : (int) $count;
		}

		/**
		 * List non-core autoloaded options at or above the size threshold.
		 *
		 * Excludes core options, transients/timeouts (handled by the
		 * expired-transient purge), oEmbed cache entries, and the plugin's own
		 * `wppo_*` options (e.g. wppo_settings must stay autoloaded).
		 *
		 * @since 2.0.0
		 * @param int $threshold Minimum option size in bytes.
		 * @param int $limit     Maximum number of candidates to return.
		 * @return array<int, array{option_name:string,size:int,autoload:string}> Sorted by size desc.
		 */
		public static function get_autoload_candidates( int $threshold = self::AUTOLOAD_SIZE_THRESHOLD, int $limit = self::AUTOLOAD_REMEDIATION_LIMIT ): array {
			global $wpdb;
			$threshold = max( 1, $threshold );
			$limit     = max( 1, min( self::AUTOLOAD_LIMIT_MAX, $limit ) );

			$autoload_values = self::get_autoloadable_values();
			$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );

			// Fetch a wider window than $limit: PHP-side exclusions below (core,
			// already-remediated) would otherwise silently hide candidates
			// sitting below the SQL LIMIT cutoff. Prefix exclusions
			// (transients, oEmbed, wppo_*) are pushed into SQL (audit #1325)
			// so fewer rows enter the filesort; the PHP checks stay as
			// defense in depth.
			$fetch = $limit + 100;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostic query.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is count-derived and LIKE args ride the same spread; counts match at runtime (sniff cannot count spread args).
				$wpdb->prepare(
					"SELECT option_name, autoload, LENGTH(option_value) AS opt_size FROM {$wpdb->options} WHERE autoload IN ($placeholders) AND LENGTH(option_value) >= %d AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY opt_size DESC LIMIT " . (int) $fetch, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...array_merge(
						$autoload_values,
						array(
							$threshold,
							$wpdb->esc_like( '_transient_' ) . '%',
							$wpdb->esc_like( '_site_transient_' ) . '%',
							$wpdb->esc_like( '_oembed_' ) . '%',
							$wpdb->esc_like( 'wppo_' ) . '%',
						)
					)
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) ) {
				return array();
			}

			$core       = array_flip( self::get_core_autoload_options() );
			$result     = array();
			$remediated = self::get_remediated_options();
			foreach ( $rows as $row ) {
				$name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
				if ( '' === $name ) {
					continue;
				}
				if ( isset( $core[ $name ] ) ) {
					continue;
				}
				if ( 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) ) {
					continue;
				}
				if ( 0 === strpos( $name, '_oembed_' ) ) {
					continue;
				}
				if ( 0 === strpos( $name, 'wppo_' ) ) {
					continue;
				}
				if ( isset( $remediated[ $name ] ) ) {
					continue;
				}
				$result[] = array(
					'option_name' => $name,
					'size'        => (int) ( $row['opt_size'] ?? 0 ),
					'autoload'    => isset( $row['autoload'] ) ? (string) $row['autoload'] : '',
				);
			}

			return array_slice( $result, 0, $limit );
		}

		/**
		 * Clamp a caller-supplied remediation threshold to the supported range.
		 *
		 * Matches the settings clamp (100 B .. 10 MB) so direct PHP calls cannot
		 * bypass the bounds enforced by settings validation and the REST layer.
		 *
		 * @since 2.0.0
		 * @param int $threshold Threshold in bytes.
		 * @return int Clamped threshold in bytes.
		 */
		private static function clamp_autoload_threshold( int $threshold ): int {
			return max( self::AUTOLOAD_THRESHOLD_MIN, min( self::AUTOLOAD_THRESHOLD_MAX, $threshold ) );
		}

		/**
		 * Build a dry-run remediation report without changing anything.
		 *
		 * Includes a `backup` export payload (ordered `option_name`/`size`/`prior`
		 * triples) so a dry_run response can be archived before `apply` and
		 * later verified against `revert_all` for byte-identical restore.
		 *
		 * @since 2.0.0
		 * @param int|null $threshold Optional threshold override (bytes).
		 * @param int      $limit     Maximum number of candidates.
		 * @return array{threshold:int,supported:bool,total_autoload_bytes:int,count:int,bytes_saved:int,options:array,backup:array}
		 */
		public static function plan_autoload_remediation( ?int $threshold = null, int $limit = self::AUTOLOAD_REMEDIATION_LIMIT ): array {
			$threshold  = null === $threshold ? self::get_autoload_remediation_threshold() : self::clamp_autoload_threshold( $threshold );
			$candidates = self::get_autoload_candidates( $threshold, $limit );
			$saved      = 0;
			$backup     = array();
			// Hoisted: identical for every candidate, avoid up to 500 repeats.
			$default = self::get_default_autoload_value();
			foreach ( $candidates as $candidate ) {
				$saved   += (int) $candidate['size'];
				$backup[] = array(
					'option_name' => (string) $candidate['option_name'],
					'size'        => (int) $candidate['size'],
					'prior'       => '' !== (string) ( $candidate['autoload'] ?? '' ) ? (string) $candidate['autoload'] : $default,
				);
			}
			return array(
				'threshold'            => $threshold,
				'supported'            => self::is_autoload_remediation_supported(),
				'total_autoload_bytes' => self::get_autoload_total_bytes(),
				'count'                => count( $candidates ),
				'bytes_saved'          => $saved,
				'options'              => $candidates,
				'backup'               => $backup,
			);
		}

		/**
		 * Get stored prior autoload values for remediated options.
		 *
		 * @since 2.0.0
		 * @return array<string,string> Map of option_name => prior autoload value.
		 */
		public static function get_remediated_options(): array {
			$stored = get_option( self::REMEDIATED_OPTION, array() );
			return is_array( $stored ) ? $stored : array();
		}

		/**
		 * Flip one option's autoload value off, guarded for WP 6.6+ with legacy fallback.
		 *
		 * Uses wp_set_option_autoload() when available; otherwise falls back to
		 * a direct options-table update. Any failure returns false and leaves
		 * the value unchanged (fail-open, never fatal).
		 *
		 * @since 2.0.0
		 * @param string $option_name Option name.
		 * @param string $autoload_off Value disabling autoload ('off' on WP 6.6+, 'no' legacy).
		 * @return bool True on success, false on failure.
		 */
		private static function set_option_autoload_off( string $option_name, string $autoload_off = 'no' ): bool {
			try {
				if ( function_exists( 'wp_set_option_autoload' ) ) {
					$result = wp_set_option_autoload( $option_name, false );
					return (bool) $result;
				}
				global $wpdb;
				if ( ! isset( $wpdb->options ) ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Autoload flip fallback for WP < 6.6.
				$result = $wpdb->update(
					$wpdb->options,
					array( 'autoload' => $autoload_off ),
					array( 'option_name' => $option_name ),
					array( '%s' ),
					array( '%s' )
				);
				return false !== $result;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Restore one option's prior autoload value, guarded like the flip path.
		 *
		 * Restores the exact stored prior string (e.g. `yes` vs `auto` vs
		 * `auto-on` on WP 6.6+) via a direct options-table update after the
		 * `wp_set_option_autoload()` bool flip, so revert restores the exact
		 * flavor rather than just autoload-on semantics. Falls back to a
		 * direct update on WP < 6.6. Any failure returns false (fail-open).
		 *
		 * @since 2.0.0
		 * @param string $option_name Option name.
		 * @param string $prior       Prior autoload value to restore.
		 * @return bool True on success, false on failure.
		 */
		private static function restore_option_autoload( string $option_name, string $prior ): bool {
			$allowed_priors = array( 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' );
			try {
				if ( function_exists( 'wp_set_option_autoload' ) ) {
					$autoload = in_array( $prior, self::get_autoloadable_values(), true );
					$result   = wp_set_option_autoload( $option_name, $autoload );
					if ( ! $result ) {
						return false;
					}
					// wp_set_option_autoload() only flips on/off, losing the exact
					// 6.6+ flavor (yes vs auto vs auto-on). Restore the exact
					// prior string when it is a known value.
					if ( in_array( $prior, $allowed_priors, true ) ) {
						global $wpdb;
						if ( ! isset( $wpdb->options ) ) {
							return true;
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact-flavor restore after the core bool flip.
						$updated = $wpdb->update(
							$wpdb->options,
							array( 'autoload' => $prior ),
							array( 'option_name' => $option_name ),
							array( '%s' ),
							array( '%s' )
						);
						return false !== $updated;
					}
					return true;
				}
				global $wpdb;
				if ( ! isset( $wpdb->options ) ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Autoload restore fallback for WP < 6.6.
				$result = $wpdb->update(
					$wpdb->options,
					array( 'autoload' => $prior ),
					array( 'option_name' => $option_name ),
					array( '%s' ),
					array( '%s' )
				);
				return false !== $result;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Apply one-click autoload remediation: flip candidates to autoload off, one at a time.
		 *
		 * Only non-core options at or above the threshold are flipped. Each
		 * option's prior autoload value is stored for per-option revert. On
		 * WP < 6.6 the legacy fallback leaves values at autoload yes
		 * (fail-open to current behavior, never fatal) and reports
		 * `supported: false` with zero applied.
		 *
		 * @since 2.0.0
		 * @param int|null $threshold Optional threshold override (bytes).
		 * @param int      $limit     Maximum number of options to flip.
		 * @return array{threshold:int,supported:bool,applied:array,failed:array,bytes_saved:int,total_autoload_bytes:int,backup:array}
		 */
		public static function remediate_autoload( ?int $threshold = null, int $limit = self::AUTOLOAD_REMEDIATION_LIMIT ): array {
			$threshold = null === $threshold ? self::get_autoload_remediation_threshold() : self::clamp_autoload_threshold( $threshold );
			$limit     = max( 1, min( self::AUTOLOAD_LIMIT_MAX, $limit ) );
			$result    = array(
				'threshold'            => $threshold,
				'supported'            => self::is_autoload_remediation_supported(),
				'applied'              => array(),
				'failed'               => array(),
				'bytes_saved'          => 0,
				'total_autoload_bytes' => self::get_autoload_total_bytes(),
				'backup'               => array(),
			);

			$candidates = self::get_autoload_candidates( $threshold, $limit );
			if ( empty( $candidates ) ) {
				return $result;
			}

			// Single supported() predicate so unknown versions behave the same
			// here as in plan/supported reporting (no silent no-op apply).
			$legacy_fallback = ! self::is_autoload_remediation_supported();
			if ( $legacy_fallback ) {
				// Legacy fallback keeps autoload yes: report candidates as failed
				// (unsupported) without touching anything.
				$default = self::get_default_autoload_value();
				foreach ( $candidates as $candidate ) {
					$result['failed'][] = $candidate['option_name'];
					$result['backup'][] = array(
						'option_name' => (string) $candidate['option_name'],
						'size'        => (int) $candidate['size'],
						'prior'       => '' !== (string) ( $candidate['autoload'] ?? '' ) ? (string) $candidate['autoload'] : $default,
					);
				}
				return $result;
			}

			$priors = self::get_remediated_options();
			$saved  = 0;
			// Hoist the core list once: get_core_autoload_options() fires a filter.
			$core_options = array_flip( self::get_core_autoload_options() );
			// Hoisted: identical for every candidate, avoid up to 500 repeats.
			$apply_default = self::get_default_autoload_value();
			foreach ( $candidates as $candidate ) {
				$name = $candidate['option_name'];
				// Re-check core exclusion at apply time (filter may have changed).
				if ( isset( $core_options[ $name ] ) ) {
					$result['failed'][] = $name;
					continue;
				}
				$prior = '' !== (string) ( $candidate['autoload'] ?? '' ) ? (string) $candidate['autoload'] : $apply_default;
				if ( self::set_option_autoload_off( $name ) ) {
					$priors[ $name ]     = $prior;
					$result['applied'][] = array(
						'option_name' => $name,
						'size'        => (int) $candidate['size'],
						'prior'       => $prior,
					);
					$saved              += (int) $candidate['size'];
				} else {
					$result['failed'][] = $name;
				}
			}

			if ( ! empty( $result['applied'] ) ) {
				update_option( self::REMEDIATED_OPTION, $priors, false );
				self::invalidate_counts_cache();
			}
			$result['bytes_saved']          = $saved;
			$result['total_autoload_bytes'] = self::get_autoload_total_bytes();
			$result['backup']               = $result['applied'];

			if ( ! empty( $result['applied'] ) ) {
				Log::add(
					sprintf(
						/* translators: %d: Number of options remediated */
						__( 'Autoload remediation: %d options set to autoload off.', 'performance-optimisation' ),
						count( $result['applied'] )
					)
				);
			}

			return $result;
		}

		/**
		 * Revert a single remediated option to its prior autoload value.
		 *
		 * @since 2.0.0
		 * @param string $option_name Option name to revert.
		 * @return bool|WP_Error True on success, WP_Error when unknown/failed.
		 */
		public static function revert_autoload_option( string $option_name ) {
			$option_name = sanitize_text_field( $option_name );
			if ( '' === $option_name ) {
				return new WP_Error( 'wppo_invalid_option', __( 'Invalid option name.', 'performance-optimisation' ) );
			}
			$priors = self::get_remediated_options();
			if ( ! isset( $priors[ $option_name ] ) ) {
				return new WP_Error( 'wppo_unknown_option', __( 'Option was not remediated by this plugin.', 'performance-optimisation' ) );
			}
			$prior = (string) $priors[ $option_name ];
			if ( ! self::restore_option_autoload( $option_name, $prior ) ) {
				return new WP_Error( 'wppo_revert_failed', __( 'Failed to restore autoload value.', 'performance-optimisation' ) );
			}
			unset( $priors[ $option_name ] );
			update_option( self::REMEDIATED_OPTION, $priors, false );
			self::invalidate_counts_cache();
			Log::add(
				sprintf(
					/* translators: %s: Option name */
					__( 'Autoload remediation reverted for %s.', 'performance-optimisation' ),
					$option_name
				)
			);
			return true;
		}

		/**
		 * Revert all remediated options to their prior autoload values.
		 *
		 * Fail-open per option: failures are collected and reported while
		 * successful reverts are still persisted. Each option is restored to
		 * its exact stored prior string (byte-identical), not just bool
		 * autoload-on semantics — see {@see restore_option_autoload()}.
		 *
		 * @since 2.0.0
		 * @return array{reverted:string[],failed:string[],restored:array,total_autoload_bytes:int}
		 */
		public static function revert_autoload_all(): array {
			$priors   = self::get_remediated_options();
			$reverted = array();
			$failed   = array();
			$restored = array();
			foreach ( $priors as $name => $prior ) {
				if ( self::restore_option_autoload( (string) $name, (string) $prior ) ) {
					$reverted[] = (string) $name;
					$restored[] = array(
						'option_name' => (string) $name,
						'prior'       => (string) $prior,
					);
				} else {
					$failed[] = (string) $name;
				}
			}
			if ( ! empty( $reverted ) ) {
				$remaining = array_diff_key( $priors, array_flip( $reverted ) );
				update_option( self::REMEDIATED_OPTION, $remaining, false );
				self::invalidate_counts_cache();
			}
			if ( ! empty( $reverted ) ) {
				Log::add(
					sprintf(
						/* translators: %d: Number of options reverted */
						__( 'Autoload remediation reverted for %d options.', 'performance-optimisation' ),
						count( $reverted )
					)
				);
			}
			return array(
				'reverted'             => $reverted,
				'failed'               => $failed,
				'restored'             => $restored,
				'total_autoload_bytes' => self::get_autoload_total_bytes(),
			);
		}

		/**
		 * Export expired transients for pre-run review (read-only, expired rows only).
		 *
		 * Mirrors the expired-only predicate of clean_expired_transients() so
		 * the export shows exactly what a purge would delete.
		 *
		 * @since 2.0.0
		 * @param int $limit Maximum number of rows to export.
		 * @return array<int, array{option_name:string,timeout_option:string,expired_at:int,size:int}>
		 */
		public static function export_expired_transients( int $limit = 500 ): array {
			global $wpdb;
			$limit = max( 1, min( self::EXPORT_LIMIT_MAX, $limit ) );
			$time  = time();
			$rows  = array();

			$transient_keys = array(
				'_transient_'      => '_transient_timeout_',
				'_site_transient_' => '_site_transient_timeout_',
			);

			foreach ( $transient_keys as $prefix => $timeout_prefix ) {
				$is_multisite = false;
				if ( function_exists( 'is_multisite' ) ) {
					try {
						$is_multisite = is_multisite();
					} catch ( \Throwable $e ) {
						$is_multisite = false;
					}
				}
				if ( '_site_transient_' === $prefix && $is_multisite ) {
					continue;
				}

				// Split the per-family limit so the first family cannot starve the
				// second: each query only asks for the rows still needed to fill
				// $limit, and the combined list is capped in PHP as a backstop.
				$remaining = $limit - count( $rows );
				if ( $remaining <= 0 ) {
					break;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only pre-run export.
				$batch = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT a.option_name, b.option_name AS timeout_name, b.option_value AS timeout_value, LENGTH(a.option_value) AS opt_size FROM $wpdb->options a
						INNER JOIN $wpdb->options b ON b.option_name = CONCAT( %s, SUBSTRING( a.option_name, %d ) )
						WHERE a.option_name LIKE %s
						AND a.option_name NOT LIKE %s
						AND b.option_value < %d
						LIMIT %d",
						$timeout_prefix,
						strlen( $prefix ) + 1,
						$wpdb->esc_like( $prefix ) . '%',
						$wpdb->esc_like( $timeout_prefix ) . '%',
						$time,
						$remaining
					),
					ARRAY_A
				);

				if ( ! is_array( $batch ) ) {
					continue;
				}

				foreach ( $batch as $row ) {
					$name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
					if ( '' === $name ) {
						continue;
					}
					$rows[] = array(
						'option_name'    => $name,
						'timeout_option' => isset( $row['timeout_name'] ) ? (string) $row['timeout_name'] : '',
						'expired_at'     => (int) ( $row['timeout_value'] ?? 0 ),
						'size'           => (int) ( $row['opt_size'] ?? 0 ),
					);
					if ( count( $rows ) >= $limit ) {
						break 2;
					}
				}
			}

			return $rows;
		}

		/**
		 * Clean oEmbed cache.
		 *
		 * @return bool|int Number of deleted options or false on error.
		 */
		public static function clean_oembed_cache() {
			global $wpdb;
			$deleted = 0;
			$batch   = 1000;

			do {
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup.
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT option_name FROM $wpdb->options
						WHERE option_name LIKE %s
						LIMIT %d",
						$wpdb->esc_like( '_oembed_' ) . '%',
						$batch
					)
				);

				if ( ! empty( $wpdb->last_error ) ) {
					return false;
				}
				$ids_count = is_array( $ids ) ? count( $ids ) : 0;
				if ( 0 === $ids_count ) {
					break;
				}

				$placeholders = implode( ',', array_fill( 0, $ids_count, '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM $wpdb->options WHERE option_name IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$ids
					)
				);

				if ( false === $result ) {
					return false;
				}

				$deleted += (int) $result;
			} while ( $ids_count === $batch );

			return $deleted;
		}

		/**
		 * Whether the Action Scheduler store/cleaner API is available.
		 *
		 * Fail-open helper: another plugin may have loaded a different AS
		 * version (newest wins via `ActionScheduler::autoload()`), so every
		 * AS touchpoint must guard on this first and never fatal.
		 *
		 * @since NEXT
		 * @return bool True when the store + cleaner classes exist.
		 */
		public static function is_action_scheduler_available(): bool {
			try {
				return class_exists( 'ActionScheduler_Store' ) && class_exists( 'ActionScheduler_QueueCleaner' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the Action Scheduler store API alone is available.
		 *
		 * Narrower than {@see is_action_scheduler_available()}: store-only
		 * readers/purgers (failed-action purge, queue health) must not
		 * require the Cleaner class (issue #1310 review).
		 *
		 * @since NEXT
		 * @return bool True when the store class exists.
		 */
		public static function is_action_scheduler_store_available(): bool {
			try {
				return class_exists( 'ActionScheduler_Store' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Resolve the Action Scheduler retention cutoffs using AS's own filters.
		 *
		 * Reads `action_scheduler_retention_period` (complete/canceled) and
		 * `action_scheduler_retention_period_for_failed` verbatim so AS stays
		 * the single source of truth — no plugin setting duplicates it
		 * (issue #1106).
		 *
		 * @since NEXT
		 * @return array{lifespan:int,lifespan_failed:int}
		 */
		private static function get_action_scheduler_retention(): array {
			$month = self::MONTH_SECONDS;
			try {
				$lifespan = function_exists( 'apply_filters' ) ? apply_filters( 'action_scheduler_retention_period', $month ) : $month;
				$lifespan = is_numeric( $lifespan ) ? max( 0, (int) $lifespan ) : $month;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- upstream AS filter, must stay verbatim.
				$lifespan_failed = function_exists( 'apply_filters' ) ? apply_filters( 'action_scheduler_retention_period_for_failed', 3 * $month ) : 3 * $month;
				$lifespan_failed = is_numeric( $lifespan_failed ) ? max( 0, (int) $lifespan_failed ) : 3 * $month;
			} catch ( \Throwable $e ) {
				unset( $e );
				$lifespan        = $month;
				$lifespan_failed = 3 * $month;
			}
			return array(
				'lifespan'        => $lifespan,
				'lifespan_failed' => $lifespan_failed,
			);
		}

		/**
		 * Build a cutoff DateTime N seconds ago via AS when possible.
		 *
		 * @since NEXT
		 * @param int $seconds Age in seconds.
		 * @return \DateTime|null Null when no datetime API is available.
		 */
		private static function get_action_scheduler_cutoff( int $seconds ): ?\DateTime {
			try {
				if ( function_exists( 'as_get_datetime_object' ) ) {
					$cutoff = as_get_datetime_object( $seconds . ' seconds ago' );
					return $cutoff instanceof \DateTime ? $cutoff : null;
				}
				return new \DateTime( '@' . max( 0, time() - $seconds ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Queue health for the Action Scheduler tables (read-only).
		 *
		 * Surfaces pending/failed counts, the oldest pending age, the
		 * terminal-past-retention reclaimable count, and table sizes so a
		 * retry storm is visible from the dashboard instead of only in the
		 * database (issue #1106). Fail-open: returns zeros with
		 * `available: false` when AS is absent or any query throws.
		 *
		 * @since NEXT
		 * @return array{available:bool,pending:int,failed:int,oldest_pending_age_seconds:int|null,reclaimable:int,reclaimable_bytes:int,total_bytes:int}
		 */
		public static function get_action_scheduler_health(): array {
			$empty = array(
				'available'                  => false,
				'pending'                    => 0,
				'failed'                     => 0,
				'oldest_pending_age_seconds' => null,
				'reclaimable'                => 0,
				'reclaimable_bytes'          => 0,
				'total_bytes'                => 0,
			);
			// Per-request memo plus a ~60s transient (issue #1310 review):
			// the health payload issues ~6-7 heavy store queries and is
			// read twice per dashboard poll (counts rebuild + REST), so a
			// second call within the TTL reuses the first result instead
			// of hammering the AS tables.
			try {
				if ( is_array( self::$health_memo ) && ( microtime( true ) - self::$health_memo_time ) < 60.0 ) {
					return self::$health_memo;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( function_exists( 'get_transient' ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					$cached = get_transient( Util::transient_key( 'wppo_as_health' ) );
					if ( is_array( $cached ) && isset( $cached['available'] ) ) {
						self::$health_memo      = $cached;
						self::$health_memo_time = microtime( true );
						return $cached;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( ! self::is_action_scheduler_available() ) {
				return $empty;
			}
			try {
				$store = \ActionScheduler_Store::instance();
				if ( ! $store ) {
					return $empty;
				}
				$counts  = $store->action_counts();
				$pending = isset( $counts[ \ActionScheduler_Store::STATUS_PENDING ] ) ? (int) $counts[ \ActionScheduler_Store::STATUS_PENDING ] : 0;
				$failed  = isset( $counts[ \ActionScheduler_Store::STATUS_FAILED ] ) ? (int) $counts[ \ActionScheduler_Store::STATUS_FAILED ] : 0;

				$oldest_age = null;
				if ( $pending > 0 ) {
					$oldest = $store->query_actions(
						array(
							'status'   => \ActionScheduler_Store::STATUS_PENDING,
							'per_page' => 1,
							'offset'   => 0,
							'orderby'  => 'date',
							'order'    => 'ASC',
						)
					);
					if ( is_array( $oldest ) && ! empty( $oldest ) ) {
						$date = $store->get_date( $oldest[0] );
						if ( $date instanceof \DateTime ) {
							$oldest_age = max( 0, time() - $date->getTimestamp() );
						}
					}
				}

				$retention     = self::get_action_scheduler_retention();
				$cutoff        = self::get_action_scheduler_cutoff( $retention['lifespan'] );
				$cutoff_failed = self::get_action_scheduler_cutoff( $retention['lifespan_failed'] );

				$reclaimable = 0;
				if ( null !== $cutoff && null !== $cutoff_failed ) {
					// Mirror the upstream cleaner's `action_scheduler_default_cleaner_statuses`
					// filter so the advertised reclaimable count only covers statuses
					// the cleaner would actually purge (non-array falls back to the
					// defaults, exactly like upstream).
					$default_statuses = array( \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler_Store::STATUS_CANCELED );
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- upstream AS filter, must stay verbatim.
					$statuses_to_purge = function_exists( 'apply_filters' ) ? apply_filters( 'action_scheduler_default_cleaner_statuses', $default_statuses ) : $default_statuses;
					if ( ! is_array( $statuses_to_purge ) ) {
						$statuses_to_purge = $default_statuses;
					}
					/**
					 * Filters whether failed actions count toward the reclaimable total.
					 *
					 * Mirrors the upstream `action_scheduler_enable_failed_action_cleanup`
					 * toggle so operators who disabled failed-action purging do not
					 * see those rows advertised as reclaimable.
					 *
					 * @since NEXT
					 * @param bool $clean_failed Whether failed actions are purged.
					 */
					$clean_failed = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'action_scheduler_enable_failed_action_cleanup', true ) : true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- upstream AS filter, must stay verbatim.
					// Note: query_actions() with 'count' ignores per_page/LIMIT
					// (the DB store only appends LIMIT for 'select'), so no
					// per_page is passed here — the count is never capped.
					// Merged COMPLETE + CANCELED count (issue #1310 review):
					// one COUNT range scan over the large AS tables instead
					// of two with the identical cutoff; very old stores
					// that reject a status array fall back to two queries.
					$want_complete = in_array( \ActionScheduler_Store::STATUS_COMPLETE, $statuses_to_purge, true );
					$want_canceled = in_array( \ActionScheduler_Store::STATUS_CANCELED, $statuses_to_purge, true );
					if ( $want_complete && $want_canceled ) {
						try {
							$reclaimable += (int) $store->query_actions(
								array(
									'status'           => array( \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler_Store::STATUS_CANCELED ),
									'modified'         => $cutoff,
									'modified_compare' => '<=',
								),
								'count'
							);
						} catch ( \Throwable $e ) {
							unset( $e );
							$reclaimable += (int) $store->query_actions(
								array(
									'status'           => \ActionScheduler_Store::STATUS_COMPLETE,
									'modified'         => $cutoff,
									'modified_compare' => '<=',
								),
								'count'
							);
							$reclaimable += (int) $store->query_actions(
								array(
									'status'           => \ActionScheduler_Store::STATUS_CANCELED,
									'modified'         => $cutoff,
									'modified_compare' => '<=',
								),
								'count'
							);
						}
					} else {
						if ( $want_complete ) {
							$reclaimable += (int) $store->query_actions(
								array(
									'status'           => \ActionScheduler_Store::STATUS_COMPLETE,
									'modified'         => $cutoff,
									'modified_compare' => '<=',
								),
								'count'
							);
						}
						if ( $want_canceled ) {
							$reclaimable += (int) $store->query_actions(
								array(
									'status'           => \ActionScheduler_Store::STATUS_CANCELED,
									'modified'         => $cutoff,
									'modified_compare' => '<=',
								),
								'count'
							);
						}
					}
					if ( $clean_failed && ! in_array( \ActionScheduler_Store::STATUS_FAILED, $statuses_to_purge, true ) ) {
						// Failed actions are purged on their own (longer) retention
						// unless the statuses filter already includes them, in
						// which case upstream purges them with the normal cutoff.
						$reclaimable += (int) $store->query_actions(
							array(
								'status'           => \ActionScheduler_Store::STATUS_FAILED,
								'modified'         => $cutoff_failed,
								'modified_compare' => '<=',
							),
							'count'
						);
					} elseif ( $clean_failed && in_array( \ActionScheduler_Store::STATUS_FAILED, $statuses_to_purge, true ) ) {
						$reclaimable += (int) $store->query_actions(
							array(
								'status'           => \ActionScheduler_Store::STATUS_FAILED,
								'modified'         => $cutoff,
								'modified_compare' => '<=',
							),
							'count'
						);
					}
				}

				global $wpdb;
				$total_bytes = 0;
				$total_rows  = 0;
				if ( isset( $wpdb->prefix ) ) {
					$actions_table = $wpdb->prefix . 'actionscheduler_actions';
					$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
					// Single SUM query over both tables (issue #1310
					// review): halves the information_schema cost on the
					// dashboard-polled path instead of two sequential
					// lookups (plus up to two SHOW TABLE STATUS fallbacks).
					$total_bytes = self::get_tables_size( array( $actions_table, $logs_table ) );
					foreach ( array( \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler_Store::STATUS_CANCELED, \ActionScheduler_Store::STATUS_FAILED, \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
						$total_rows += (int) ( $counts[ $status ] ?? 0 );
					}
				}
				$reclaimable_bytes = 0;
				if ( 0 < $reclaimable && 0 < $total_rows && 0 < $total_bytes ) { // Audit #1434: Yoda.
					$reclaimable_bytes = (int) ( $total_bytes * ( $reclaimable / $total_rows ) );
				}

				$result                 = array(
					'available'                  => true,
					'pending'                    => max( 0, $pending ),
					'failed'                     => max( 0, $failed ),
					'oldest_pending_age_seconds' => $oldest_age,
					'reclaimable'                => max( 0, $reclaimable ),
					'reclaimable_bytes'          => max( 0, $reclaimable_bytes ),
					'total_bytes'                => max( 0, $total_bytes ),
				);
				self::$health_memo      = $result;
				self::$health_memo_time = microtime( true );
				try {
					if ( function_exists( 'set_transient' ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
						set_transient( Util::transient_key( 'wppo_as_health' ), $result, 60 );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return $result;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $empty;
			}
		}

		/**
		 * Reset the Action Scheduler health memo (test seam).
		 *
		 * Production code never needs to clear the ~60s memo within a
		 * request; unit tests that swap store stubs between cases must
		 * reset it (and the transient) to observe the new stub.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_action_scheduler_health_cache(): void {
			self::$health_memo      = null;
			self::$health_memo_time = 0.0;
			try {
				if ( function_exists( 'delete_transient' ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					delete_transient( Util::transient_key( 'wppo_as_health' ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Whether the opt-in failed-action purge is enabled.
		 *
		 * Additive opt-in (issue #1310): reads the
		 * `database_cleanup.purgeFailedActions` setting (default off, absent
		 * key migrates to off) and applies the `wppo_purge_failed_actions`
		 * filter last so operators can opt in via code. Defaults to off so
		 * failed-action debug history is retained unless the site opts in.
		 * Purely additive — upstream
		 * `action_scheduler_enable_failed_action_cleanup` defaults are never
		 * altered here.
		 *
		 * @since NEXT
		 * @return bool True when failed actions older than 3 months may be purged.
		 */
		public static function is_failed_action_purge_enabled(): bool {
			try {
				// Util is always loaded via Main::includes(), so no
				// class_exists/method_exists guard is needed here (issue
				// #1310 review); read-time normalization mirrors the
				// sanitizer so a raw string 'false' (e.g. via direct
				// update_option/DB edit bypassing sanitize) cannot enable
				// deletion through !empty() truthiness.
				$settings = Util::get_settings();
				$raw      = $settings['database_cleanup']['purgeFailedActions'] ?? false;
				if ( is_bool( $raw ) ) {
					$opt_in = $raw;
				} else {
					$parsed = filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$opt_in = true === $parsed;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$opt_in = false;
			}
			/**
			 * Filters whether failed Action Scheduler actions older than 3 months are purged.
			 *
			 * Default off (debug retention). Return true to opt in; the
			 * `database_cleanup.purgeFailedActions` setting value is passed as
			 * the default so either path enables the purge.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether the failed-action purge is enabled.
			 */
			return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'wppo_purge_failed_actions', $opt_in ) : (bool) $opt_in;
		}

		/**
		 * Purge failed Action Scheduler actions older than 3 months in batches.
		 *
		 * Opt-in companion to {@see clean_action_scheduler()}: guarantees a
		 * 3-month bound on failed rows even when upstream failed-action
		 * cleanup is disabled, without touching pending/in-progress/claimed
		 * rows. Batched via `query_actions()` + `delete_action()` (so log
		 * cascades are honored) with a per-batch cap, an iteration cap, and a
		 * wall-clock budget to avoid long table locks. Fail-open: returns 0
		 * when AS is absent, the opt-in is off, or the store throws.
		 *
		 * Only the store is touched, so this gates on
		 * {@see is_action_scheduler_store_available()} (Store alone) rather
		 * than {@see is_action_scheduler_available()} (Store + Cleaner): a
		 * missing Cleaner class must not disable the Store-only purge
		 * (issue #1310 review).
		 *
		 * @since NEXT
		 * @param int        $batch_size Maximum rows deleted per iteration. Default 50, clamped to 1-100.
		 * @param bool       $log        Whether to write the activity-log row and invalidate the counts cache. Pass false when a wrapper (e.g. clean_action_scheduler()) logs the combined total itself, so one purge emits one row and invalidates once (issue #1310 review).
		 * @param float|null $deadline   Optional shared wall-clock deadline (microtime(true) value) inherited from a wrapper that already spent part of its budget. Null starts a fresh 15s budget for standalone calls.
		 * @return int Number of failed actions deleted.
		 */
		public static function purge_failed_actions( int $batch_size = 50, bool $log = true, ?float $deadline = null ): int {
			if ( ! self::is_action_scheduler_store_available() ) {
				return 0;
			}
			if ( ! self::is_failed_action_purge_enabled() ) {
				return 0;
			}
			$batch_size = max( 1, min( self::FAILED_PURGE_BATCH_MAX, $batch_size ) );
			try {
				$store = \ActionScheduler_Store::instance();
				if ( ! $store || ! method_exists( $store, 'query_actions' ) || ! method_exists( $store, 'delete_action' ) ) {
					return 0;
				}
				// Single source of truth for the bound (issue #1310
				// review): the filtered failed-action retention, capped at
				// the 3-month FAILED_PURGE_MAX_LIFESPAN so the documented
				// bound holds even when an operator raises the upstream
				// retention. Floored at one day so a lowered/rogue
				// retention filter returning 0 cannot make cutoff=now and
				// destroy just-failed debug history (fail-open to the
				// floor instead of fail-destructive).
				$day       = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
				$retention = self::get_action_scheduler_retention();
				$lifespan  = min( max( $day, (int) $retention['lifespan_failed'] ), self::FAILED_PURGE_MAX_LIFESPAN );
				$cutoff    = self::get_action_scheduler_cutoff( $lifespan );
				if ( null === $cutoff ) {
					return 0;
				}
				$total          = 0;
				$iterations     = 0;
				$max_iterations = self::FAILED_PURGE_MAX_ITERATIONS;
				if ( null === $deadline ) {
					$deadline = microtime( true ) + self::FAILED_PURGE_BUDGET_SECONDS;
				}
				do {
					// Guard the top of the loop before the query (issue
					// #1310 review): when a wrapper passes an already-expired
					// shared deadline this skips one wasted heavy SELECT past
					// budget; the per-delete check below still bounds slow
					// stores inside a batch.
					if ( microtime( true ) >= $deadline ) {
						break;
					}
					$ids = $store->query_actions(
						array(
							'status'           => \ActionScheduler_Store::STATUS_FAILED,
							'modified'         => $cutoff,
							'modified_compare' => '<=',
							'per_page'         => $batch_size,
							'orderby'          => 'none',
						)
					);
					if ( ! is_array( $ids ) || empty( $ids ) ) {
						break;
					}
					$count = 0;
					foreach ( $ids as $action_id ) {
						// Per-delete deadline (issue #1310 review): a slow
						// store must not overshoot the shared budget inside
						// one up-to-100-row batch, stacking on the upstream
						// cleaner loop.
						if ( microtime( true ) >= $deadline ) {
							break 2;
						}
						try {
							$store->delete_action( $action_id );
							++$count;
						} catch ( \Throwable $e ) {
							unset( $e );
							continue;
						}
					}
					$total += $count;
					++$iterations;
					if ( 0 === $count ) {
						// Zero progress (every delete threw): the next
						// query would return the same batch, so stop
						// instead of burning the remaining iterations
						// re-scanning identical IDs (issue #1310 review).
						break;
					}
					$fetched = count( $ids );
					if ( $fetched < $batch_size ) {
						break;
					}
				} while ( $iterations < $max_iterations && microtime( true ) < $deadline );
				if ( $log && $total > 0 ) {
					self::invalidate_counts_cache();
					self::reset_action_scheduler_health_cache();
					$lifespan_days = max( 1, (int) round( $lifespan / $day ) );
					Log::add(
						sprintf(
							/* translators: 1: Number of failed Action Scheduler actions purged 2: Retention bound in days */
							__( 'Action Scheduler cleanup: %1$d failed actions past retention (%2$d days) purged.', 'performance-optimisation' ),
							$total,
							$lifespan_days
						)
					);
				}
				return $total;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Purge terminal Action Scheduler actions past AS retention.
		 *
		 * Thin delegation to `ActionScheduler_QueueCleaner::delete_old_actions()`
		 * — never raw DELETEs against the AS tables, so `delete_action()`
		 * cascades to `actionscheduler_logs` and the upstream retention
		 * filters (`action_scheduler_retention_period`,
		 * `action_scheduler_retention_period_for_failed`,
		 * `action_scheduler_default_cleaner_statuses`,
		 * `action_scheduler_enable_failed_action_cleanup`,
		 * `action_scheduler_cleanup_batch_size`) stay authoritative
		 * (issue #1106). Only terminal statuses past their cutoffs are ever
		 * touched by the upstream cleaner — pending/in-progress/claimed rows
		 * are never purged. Fail-open: returns 0 when AS is absent, disabled
		 * via filter, or the cleaner throws.
		 *
		 * A single upstream pass purges at most one batch (~20 per status by
		 * default), so this loops `delete_old_actions()` until a pass deletes
		 * nothing — bounded by an iteration cap and a wall-clock budget so a
		 * 168 MB backlog is actually reclaimed in one invocation without
		 * risking a REST timeout (issue #1106 review).
		 *
		 * The opt-in failed-action purge below runs after the upstream loop
		 * against the same shared deadline (issue #1310 review), so one
		 * invocation is bounded by a single 15s budget across both passes.
		 * The purge is called with `$log = false` so the combined total is
		 * logged once and the counts cache is invalidated once, in this
		 * wrapper only.
		 *
		 * @since NEXT
		 * @return int Number of actions deleted (0 when AS absent, disabled, or nothing past retention).
		 */
		public static function clean_action_scheduler(): int {
			if ( ! self::is_action_scheduler_available() ) {
				return 0;
			}
			/**
			 * Filters whether the plugin may delegate to Action Scheduler's cleaner.
			 *
			 * Cautious operators can return false to narrow the scope to a
			 * no-op (visibility only); site-specific narrowing beyond that
			 * should use the upstream `action_scheduler_*` filters.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether AS cleanup delegation is enabled.
			 */
			$enabled = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'wppo_action_scheduler_cleanup_enabled', true ) : true;
			if ( ! $enabled ) {
				return 0;
			}
			try {
				// A larger-than-default batch plus bounded looping: each pass
				// still honors the upstream `action_scheduler_cleanup_batch_size`
				// filter via the cleaner's get_batch_size(), the constructor
				// value is only the default when the filter is unhooked.
				$cleaner = new \ActionScheduler_QueueCleaner( null, 100 );
				if ( ! method_exists( $cleaner, 'delete_old_actions' ) ) {
					return 0;
				}
				$total          = 0;
				$iterations     = 0;
				$max_iterations = self::FAILED_PURGE_MAX_ITERATIONS;
				$deadline       = microtime( true ) + self::FAILED_PURGE_BUDGET_SECONDS;
				do {
					$deleted = $cleaner->delete_old_actions();
					$count   = is_array( $deleted ) ? count( $deleted ) : 0;
					$total  += $count;
					++$iterations;
					// Preserve the failed-purge window (issue #1310 review):
					// one pass deletes up to 100 actions with log cascades
					// (200+ DELETEs) and can overshoot the shared budget, so
					// the loop yields while the reserve remains instead of
					// eating the failed-purge window.
				} while ( $count > 0 && $iterations < $max_iterations && microtime( true ) < $deadline - self::FAILED_PURGE_RESERVE_SECONDS );
				$count = $total;
				// Opt-in 3-month failed-action bound (issue #1310): purely
				// additive — upstream defaults are untouched, and the purge
				// is a no-op (returns 0) unless the site opted in via the
				// `wppo_purge_failed_actions` filter or the
				// `database_cleanup.purgeFailedActions` setting. Logging is
				// suppressed here ($log = false) so the combined total below
				// is the single activity-log row and the counts cache is
				// invalidated once. The wrapper deadline is shared (issue
				// #1310 review) so one invocation cannot stack two
				// independent 15s budgets back to back; when under 2s of
				// budget remains the purge is deferred to the next run.
				if ( microtime( true ) < $deadline - self::FAILED_PURGE_RESERVE_SECONDS ) {
					$count += self::purge_failed_actions( 50, false, $deadline );
				}
				if ( $count > 0 ) {
					self::invalidate_counts_cache();
					Log::add(
						sprintf(
							/* translators: %d: Number of Action Scheduler actions purged */
							__( 'Action Scheduler cleanup: %d actions purged (terminal retention plus opt-in failed-action purge).', 'performance-optimisation' ),
							$count
						)
					);
				}
				return $count;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Execute all defined database cleanup routines and collect their results.
		 *
		 * @since 1.1.0
		 * @return array<string, int|WP_Error> Associative array keyed by cleanup type (e.g. 'revisions', 'auto_drafts') with each value set to the number of rows deleted or a `WP_Error` instance if that cleanup failed.
		 */
		public static function clean_all() {
			$methods = self::CLEANUP_METHOD_MAP;

			$results         = array();
			$total_deleted   = 0;
			$affected_tables = array();

			list( $rev_max_age, $rev_keep ) = self::get_revision_defaults();
			foreach ( $methods as $key => $method ) {
				if ( 'revisions' === $key ) {
					$res = self::invoke_cleanup_method( $method, $rev_max_age, $rev_keep );
				} else {
					$res = self::invoke_cleanup_method( $method );
				}
				$results[ $key ] = $res;
				/**
				 * Fires after each individual cleanup type completes.
				 *
				 * @since 2.0.0
				 *
				 * @param string $type  Cleanup type.
				 * @param int    $count Number of rows deleted.
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- documented hook
				do_action( 'wppo_database_cleanup_completed', $key, is_wp_error( $res ) || false === $res ? 0 : (int) $res );
				if ( ! is_wp_error( $res ) && false !== $res && (int) $res > 0 ) {
					$total_deleted += (int) $res;
					if ( isset( self::TABLE_MAP[ $key ] ) ) {
						$affected_tables = array_merge( $affected_tables, self::TABLE_MAP[ $key ] );
					}
				}
			}

			// Standalone Action Scheduler branch (own method, not in CLEANUP_METHOD_MAP).
			// clean_action_scheduler() returns int only (fail-open 0), so no
			// is_wp_error()/false handling is needed here.
			$as_result                              = self::clean_action_scheduler();
			$results[ self::ACTION_SCHEDULER_TYPE ] = $as_result;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- documented hook
			do_action( 'wppo_database_cleanup_completed', self::ACTION_SCHEDULER_TYPE, (int) $as_result );
			$total_deleted += (int) $as_result;

			do_action( 'wppo_database_cleanup_completed', 'all', $total_deleted, $results );

			self::maybe_optimize_tables( $affected_tables, true );

			return $results;
		}

		/**
		 * Get revision cleanup defaults.
		 *
		 * Resolves maximum age and keep-latest values from settings with bounds.
		 *
		 * @since 2.0.0
		 * @param mixed $settings Optional settings array or null to load from option.
		 * @return array{0:int,1:int} Tuple of [max_age_days, keep_latest].
		 */
		public static function get_revision_defaults( $settings = null ) {
			if ( null === $settings ) {
				$settings = Util::get_settings();
				$settings = $settings['database_cleanup'] ?? array();
				if ( ! is_array( $settings ) ) {
					$settings = array();
				}
			}
			$max_age = isset( $settings['dbRevMaxAge'] ) ? (int) $settings['dbRevMaxAge'] : 30;
			$max_age = max( 1, min( 365, $max_age ) );
			$keep    = isset( $settings['dbRevKeepLatest'] ) ? (int) $settings['dbRevKeepLatest'] : 5;
			$keep    = max( 1, min( 100, $keep ) );
			return array( $max_age, $keep );
		}

		/**
		 * Execute configured database cleanup routines according to provided settings.
		 *
		 * Calls a set of cleanup methods (including advanced revision cleanup, drafts, trashed posts,
		 * spam/trashed comments, expired transients, and orphan postmeta). If a cleanup fails,
		 * an error is logged via the Log class.
		 *
		 * @since 2.0.0
		 * @param array $settings Cleanup settings. Recognized keys:
		 *                        - 'dbRevMaxAge'     (int) Maximum age in days for revision pruning (default 30).
		 *                        - 'dbRevKeepLatest' (int) Number of latest revisions to retain per parent (default 5).
		 * @return string[] List of methods that failed.
		 */
		public static function auto_clean( $settings ) {
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			list( $max_age, $keep ) = self::get_revision_defaults( $settings );

			$methods = array_values( self::CLEANUP_METHOD_MAP );

			$failures        = array();
			$affected_tables = array();

			foreach ( $methods as $method ) {
				if ( 'clean_revisions_advanced' === $method ) {
					$result = self::invoke_cleanup_method( $method, $max_age, $keep );
				} else {
					$result = self::invoke_cleanup_method( $method );
				}

				if ( is_wp_error( $result ) ) {
					$labels = array(
						'clean_revisions_advanced' => __( 'Revisions', 'performance-optimisation' ),
						'clean_auto_drafts'        => __( 'Auto Drafts', 'performance-optimisation' ),
						'clean_trashed_posts'      => __( 'Trashed Posts', 'performance-optimisation' ),
						'clean_spam_comments'      => __( 'Spam Comments', 'performance-optimisation' ),
						'clean_trashed_comments'   => __( 'Trashed Comments', 'performance-optimisation' ),
						'clean_expired_transients' => __( 'Expired Transients', 'performance-optimisation' ),
						'clean_orphan_postmeta'    => __( 'Orphan Post Meta', 'performance-optimisation' ),
						'clean_unattached_media'   => __( 'Unattached Media', 'performance-optimisation' ),
						'clean_oembed_cache'       => __( 'oEmbed Cache', 'performance-optimisation' ),
					);
					$label  = $labels[ $method ] ?? $method;
					// Translators: %s is the cleanup type label.
					Log::add( sprintf( __( 'Auto cleanup failed: %s', 'performance-optimisation' ), $label ) );
					$failures[] = $method;
				} elseif ( 0 < $result ) { // Audit #1434: Yoda.
					$type = self::METHOD_TO_TYPE[ $method ] ?? '';
					if ( isset( self::TABLE_MAP[ $type ] ) ) {
						$affected_tables = array_merge( $affected_tables, self::TABLE_MAP[ $type ] );
					}
				}
			}

			// Thin AS delegation so WP-Cron starvation does not leave the queue
			// to grow unbounded (issue #1106). Fail-open: a 0 return is not a
			// failure (AS absent, disabled, or nothing past retention), and
			// clean_action_scheduler() returns int only, so there is no error
			// branch to log here.
			self::clean_action_scheduler();

			$optimize_enabled = ! empty( $settings['dbOptimize'] );
			self::maybe_optimize_tables( $affected_tables, $optimize_enabled );

			return $failures;
		}

		/**
		 * Get current counts for each database cleanup category.
		 *
		 * Returns an associative array keyed by cleanup type with integer counts for:
		 * `revisions`, `auto_drafts`, `trashed_posts`, `spam_comments`, `trashed_comments`,
		 * `expired_transients`, `orphan_postmeta`, `unattached_media`, `oembed_cache`,
		 * plus the standalone `action_scheduler` reclaimable count (terminal AS
		 * actions past retention, issue #1106).
		 *
		 * The `spam_comments`/`trashed_comments` counts exclude WordPress 6.9+ Notes
		 * (`comment_type='note'`) using the same predicate as
		 * clean_spam_comments()/clean_trashed_comments() so the advertised counts
		 * match what cleanup would actually delete (issue #884).
		 *
		 * @since 1.1.0
		 * @since 2.0.0 Exclude WP 6.9+ Notes from spam/trashed comment counts.
		 * @since NEXT Add `action_scheduler` reclaimable count.
		 * @return array<string,int> Associative array mapping cleanup type to its current count.
		 */
		public static function get_counts() {
			// Salted layer requires a persistent object cache; the transient
			// fallback keeps counts across requests otherwise (issue #882 review).
			$has_salted = function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();

			// Always blog-aware: the guard derives its lock key from this value
			// (md5), so a bare key would make every blog of a multisite network
			// contend on one lock while stale copies stay per-site. The salted
			// get/set closures keep using this same qualified key.
			$value_key = Util::transient_key( 'wppo_db_cleanup_counts' );

			$get_cached = function ( string $k ) use ( $value_key, $has_salted ): mixed {
				if ( $has_salted ) {
					return wp_cache_get_salted( $value_key, 'wppo', Util::cache_salt( self::SALT_KEY ) );
				}
				return get_transient( $k );
			};
			$set_cached = function ( string $k, mixed $v, int $t ) use ( $value_key, $has_salted ): bool {
				if ( $has_salted ) {
					wp_cache_set_salted( $value_key, $v, 'wppo', Util::cache_salt( self::SALT_KEY ), $t );
					return true;
				}
				return (bool) set_transient( $k, $v, $t );
			};

			$rebuild = function (): array|false {
				global $wpdb;

				$time = time();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				// Single UNION ALL round-trip (audit #982): this previously ran
				// one query per cleanup type on a TTL miss. Predicates are verbatim
				// copies of the originals (including the `comment_type != 'note'`
				// exclusion from issue #884, the transient CONCAT self-JOIN, and
				// the multisite `_site_transient_` skip). The 'expired_transients'
				// label may appear twice (one row per prefix) — rows are summed
				// per key.
				$selects = array();

				$selects[] = "SELECT 'revisions' AS k, COUNT(*) AS c FROM $wpdb->posts WHERE post_type = 'revision'";
				$selects[] = "SELECT 'auto_drafts' AS k, COUNT(*) AS c FROM $wpdb->posts WHERE post_status = 'auto-draft'";
				$selects[] = "SELECT 'trashed_posts' AS k, COUNT(*) AS c FROM $wpdb->posts WHERE post_status = 'trash'";
				// Exclude WP 6.9+ Notes (`comment_type='note'`) so counts match what
				// clean_spam_comments()/clean_trashed_comments() would actually delete (issue #884).
				$selects[] = "SELECT 'spam_comments' AS k, COUNT(*) AS c FROM $wpdb->comments WHERE comment_approved = 'spam' AND COALESCE( comment_type, '' ) != 'note'";
				$selects[] = "SELECT 'trashed_comments' AS k, COUNT(*) AS c FROM $wpdb->comments WHERE comment_approved = 'trash' AND COALESCE( comment_type, '' ) != 'note'";

				foreach ( array( '_transient_', '_site_transient_' ) as $prefix ) {
					$is_multisite = false;
					if ( function_exists( 'is_multisite' ) ) {
						try {
							$is_multisite = is_multisite();
						} catch ( \Throwable $e ) {
							$is_multisite = false;
						}
					}
					if ( '_site_transient_' === $prefix && $is_multisite ) {
						continue;
					}
					$timeout_prefix = $prefix . 'timeout_';
					$selects[]      = $wpdb->prepare(
						"SELECT 'expired_transients' AS k, COUNT(*) AS c FROM $wpdb->options a
						INNER JOIN $wpdb->options b ON b.option_name = CONCAT( %s, SUBSTRING( a.option_name, %d ) )
						WHERE a.option_name LIKE %s
						AND a.option_name NOT LIKE %s
						AND b.option_value < %d",
						$timeout_prefix,
						strlen( $prefix ) + 1,
						$wpdb->esc_like( $prefix ) . '%',
						$wpdb->esc_like( $timeout_prefix ) . '%',
						$time
					);
				}

				$selects[] = "SELECT 'orphan_postmeta' AS k, COUNT(*) AS c FROM $wpdb->postmeta pm
					LEFT JOIN $wpdb->posts p ON p.ID = pm.post_id
					WHERE p.ID IS NULL";
				$selects[] = "SELECT 'unattached_media' AS k, COUNT(*) AS c FROM $wpdb->posts
					WHERE post_type = 'attachment'
					AND post_parent = 0
					AND post_status = 'inherit'";
				$selects[] = $wpdb->prepare(
					"SELECT 'oembed_cache' AS k, COUNT(*) AS c FROM $wpdb->options WHERE option_name LIKE %s",
					$wpdb->esc_like( '_oembed_' ) . '%'
				);

				$sql  = implode( ' UNION ALL ', $selects );
				$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each fragment with placeholders is prepared above; the rest are static.

				// Distinguish a DB failure (false/null, or a drop-in that reports
				// via $wpdb->last_error while returning an empty array) from a
				// legitimately empty result (array()). On failure the all-zero
				// counts are returned for this request but NOT cached, so a
				// transient error is retried on the next request instead of
				// serving stale zeros for 5 minutes.
				$query_failed = ! is_array( $rows ) || ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) );

				$totals = array(
					'revisions'          => 0,
					'auto_drafts'        => 0,
					'trashed_posts'      => 0,
					'spam_comments'      => 0,
					'trashed_comments'   => 0,
					'expired_transients' => 0,
					'orphan_postmeta'    => 0,
					'unattached_media'   => 0,
					'oembed_cache'       => 0,
				);
				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						$k = is_array( $row ) ? ( $row['k'] ?? '' ) : '';
						$c = is_array( $row ) ? (int) ( $row['c'] ?? 0 ) : 0;
						if ( array_key_exists( $k, $totals ) ) {
							$totals[ $k ] += $c;
						}
					}
				}

				$counts = array(
					'revisions'          => (int) $totals['revisions'],
					'auto_drafts'        => (int) $totals['auto_drafts'],
					'trashed_posts'      => (int) $totals['trashed_posts'],
					'spam_comments'      => (int) $totals['spam_comments'],
					'trashed_comments'   => (int) $totals['trashed_comments'],
					'expired_transients' => (int) $totals['expired_transients'],
					'orphan_postmeta'    => (int) $totals['orphan_postmeta'],
					'unattached_media'   => (int) $totals['unattached_media'],
					'oembed_cache'       => (int) $totals['oembed_cache'],
				);
			// phpcs:enable

				// Skip the cache write on a failed query so zeros are never served
				// from a 5-minute cache after a transient DB error. Returning false
				// lets the stampede guard serve stale (when available) instead of
				// caching zeros; the caller maps false to uncached zeros below.
				if ( $query_failed ) {
					return false;
				}

				// Standalone AS reclaimable count (own branch, not part of the
				// UNION ALL above). Fail-open to 0 when AS is absent.
				try {
					$as_health                             = self::get_action_scheduler_health();
					$counts[ self::ACTION_SCHEDULER_TYPE ] = isset( $as_health['reclaimable'] ) ? (int) $as_health['reclaimable'] : 0;
				} catch ( \Throwable $e ) {
					unset( $e );
					$counts[ self::ACTION_SCHEDULER_TYPE ] = 0;
				}

				return $counts;
			};

			// Stampede guard (issue #1101): concurrent dashboard mounts on expiry
			// collapse toward a single UNION ALL round-trip; losers bounded-retry
			// the fresh key then serve the stale copy. Fail-open on lock/Redis
			// failure (stale or dynamic zeros, never fatal).
			$result = Util::get_with_stampede_lock(
				$value_key,
				$rebuild,
				array(
					'ttl'            => 5 * MINUTE_IN_SECONDS,
					'stale_ttl'      => defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400,
					'retries'        => 4,
					'retry_delay_us' => 0,
					'get_cached'     => $get_cached,
					'set_cached'     => $set_cached,
				)
			);

			if ( is_array( $result ) ) {
				return $result;
			}

			return array(
				'revisions'          => 0,
				'auto_drafts'        => 0,
				'trashed_posts'      => 0,
				'spam_comments'      => 0,
				'trashed_comments'   => 0,
				'expired_transients' => 0,
				'orphan_postmeta'    => 0,
				'unattached_media'   => 0,
				'oembed_cache'       => 0,
				'action_scheduler'   => 0,
			);
		}

		/**
		 * Call a static cleanup method by name and convert a `false` result into a `WP_Error`.
		 *
		 * Guarded against invalid `$method` values (audit #888 finding 11): the
		 * name must be a whitelisted cleanup method (METHOD_TO_TYPE keys) and
		 * callable, otherwise a WP_Error is returned instead of triggering a
		 * fatal error that would crash the REST database_cleanup endpoint.
		 * The method name is passed through raw into the WP_Error payload;
		 * escaping happens at display time.
		 *
		 * @since 1.4.0
		 * @since 2.0.0 Added method whitelist + is_callable guard returning WP_Error.
		 * @param mixed $method The static method name to invoke (string; other types are guarded).
		 * @param mixed ...$args Arguments forwarded to the method.
		 * @return mixed The invoked method's return value, or a `WP_Error` if the method returned `false` or is not callable.
		 */
		public static function invoke_cleanup_method( $method, ...$args ) {
			// Whitelist against the canonical CLEANUP_METHOD_MAP values (single
			// source of truth). The legacy public clean_revisions() remains
			// callable directly (WP-CLI) but is intentionally excluded here —
			// it is superseded by clean_revisions_advanced().
			if ( ! is_string( $method )
				|| ! in_array( $method, array_values( self::CLEANUP_METHOD_MAP ), true )
				|| ! is_callable( array( self::class, $method ) ) ) {
				// wp_json_encode() can return false (invalid UTF-8, recursion);
				// fall back to a type label so the diagnostic payload is never empty.
				if ( is_string( $method ) ) {
					$method_label = $method;
				} else {
					$encoded      = wp_json_encode( $method );
					$method_label = is_string( $encoded ) && '' !== $encoded ? $encoded : gettype( $method );
				}
				return new WP_Error(
					'wppo_invalid_cleanup_method',
					sprintf(
						/* translators: %s: cleanup method name. */
						__( 'Invalid database cleanup method: %s', 'performance-optimisation' ),
						$method_label
					)
				);
			}
			$res = self::$method( ...$args );
			if ( false === $res ) {
				return new WP_Error( 'db_cleanup_failed', __( 'Database cleanup failed.', 'performance-optimisation' ) );
			}
			self::invalidate_counts_cache();
			return $res;
		}

		/**
		 * Invalidate the DB cleanup counts cache by incrementing the salt or deleting the transient.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function invalidate_counts_cache(): void {
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				// Monotonic increment: same-second mutations must produce
				// distinct salts (issue #882 review).
				update_option( self::SALT_KEY, (int) get_option( self::SALT_KEY, 0 ) + 1, false );
			} else {
				delete_transient( Util::transient_key( 'wppo_db_cleanup_counts' ) );
			}
			// Stampede stale copies (issue #1101): the guard writes a
			// `<key>_stale` transient that must not survive an explicit purge.
			// Fail-open: a missing transient API never breaks invalidation.
			try {
				if ( function_exists( 'delete_transient' ) ) {
					delete_transient( Util::transient_key( 'wppo_db_cleanup_counts_stale' ) );
					delete_transient( Util::stampede_stale_key( Util::transient_key( 'wppo_db_cleanup_counts' ) ) );
					// Remediate/revert change autoload totals: the Abilities
					// transient (10-min TTL) must not serve stale parity data.
					delete_transient( Util::transient_key( 'wppo_abilities_autoloaded' ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Callback for save_post/deleted_post to invalidate DB cleanup counts for public post types.
		 *
		 * @param int           $post_id Post ID.
		 * @param \WP_Post|null $post    Post object.
		 * @since 2.0.0
		 * @return void
		 */
		public static function on_post_change( $post_id, ?\WP_Post $post = null ): void {
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
			$post_type = $post ? $post->post_type : get_post_type( $post_id );
			if ( $post_type && is_post_type_viewable( $post_type ) ) {
				self::invalidate_counts_cache();
			}
		}

		/**
		 * Get the combined size (data + index) of several database tables in bytes.
		 *
		 * Single `SUM()` query over `information_schema.TABLES` so callers
		 * that need both AS tables pay one lookup instead of two (issue
		 * #1310 review); falls back to per-table {@see get_table_size()}
		 * (which itself falls back to `SHOW TABLE STATUS`) when the SUM
		 * query is unavailable or fails.
		 *
		 * @since NEXT
		 *
		 * @param string[] $tables Full table names (including prefix).
		 * @return int Combined size in bytes, or 0 if unknown.
		 */
		private static function get_tables_size( array $tables ): int {
			$tables = array_values( array_filter( array_map( 'strval', $tables ) ) );
			if ( empty( $tables ) ) {
				return 0;
			}
			global $wpdb;
			try {
				if ( defined( 'DB_NAME' ) && is_string( DB_NAME ) && '' !== DB_NAME ) {
					$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only size probe; $placeholders is count-derived, table names bound as values.
					$size = $wpdb->get_var(
						// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is count-derived; values spread below.
						$wpdb->prepare(
							"SELECT SUM( data_length + index_length ) FROM information_schema.TABLES WHERE table_schema = %s AND table_name IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is count-derived.
							...array_merge( array( DB_NAME ), $tables )
						)
					);
					if ( null !== $size && '' !== $size ) {
						return max( 0, (int) $size );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$total = 0;
			foreach ( $tables as $table ) {
				$total += self::get_table_size( $table );
			}
			return $total;
		}

		/**
		 * Get the size (data + index) of a database table in bytes.
		 *
		 * Queries `information_schema.TABLES` to determine the total size.
		 *
		 * @since 2.0.0
		 *
		 * @param string $table Full table name (including prefix).
		 * @return int Table size in bytes, or 0 if unknown.
		 */
		private static function get_table_size( string $table ): int {
			global $wpdb;

			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$size = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ( data_length + index_length ) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s',
					DB_NAME,
					$table
				)
			);

			if ( null !== $size && '' !== $size ) {
				return (int) $size;
			}

			// Fallback when information_schema is not readable (permission denied).
			// SHOW TABLE STATUS does not require information_schema SELECT privilege.
			// @since 2.0.0 Added fallback for restricted DB users.
			if ( ! empty( $wpdb->last_error ) ) {
				$wpdb->last_error = '';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );

			if ( is_array( $row ) && isset( $row['Data_length'], $row['Index_length'] ) ) {
				return (int) $row['Data_length'] + (int) $row['Index_length'];
			}

			return 0;
		}

		/**
		 * Run OPTIMIZE TABLE on a given table to reclaim disk space and rebuild indexes.
		 *
		 * Skips tables larger than 1 GB to avoid long table locks.
		 * Logs the result via {@see Log::add()}.
		 *
		 * Security: $table is an unprefixed identifier (e.g. 'posts') that must be
		 * allowlisted via {@see TABLE_MAP} / {@see METHOD_TO_TYPE}. Callers
		 * {@see clean_all()} and {@see auto_clean()} only pass allowlisted values
		 * through {@see maybe_optimize_tables()}, so no user input reaches this
		 * interpolation. Table names cannot be passed as %s placeholders (identifiers
		 * vs values), so direct interpolation with allowlist check is the correct
		 * WordPress pattern. Verified: no REST/CLI path forwards raw user input here.
		 *
		 * @since 2.0.0 Added allowlist justification and verified no user input reaches interpolation.
		 *
		 * @param string $table Unprefixed table identifier (e.g. 'posts', 'postmeta').
		 * @return bool True on success, false on invalid identifier, empty table, skipped due to size (>1GB), or query failure.
		 */
		public static function optimize_table( string $table ): bool {
			global $wpdb;

			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html__( 'Invalid table identifier passed to optimize_table.', 'performance-optimisation' ),
					'2.0.0'
				);
				return false;
			}

			// Allowlist against TABLE_MAP values: any $wpdb property table
			// could otherwise be locked via this public static method.
			$allowed = array();
			foreach ( self::TABLE_MAP as $tables ) {
				foreach ( (array) $tables as $candidate ) {
					$allowed[] = $candidate;
				}
			}
			if ( ! in_array( $table, array_unique( $allowed ), true ) ) {
				return false;
			}

			if ( ! isset( $wpdb->{$table} ) || ! is_string( $wpdb->{$table} ) ) {
				return false;
			}
			$full_table_name = $wpdb->{$table}; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

			if ( empty( $full_table_name ) ) {
				return false;
			}

			// Skip tables larger than 1 GB to avoid long locks.
			$size = self::get_table_size( $full_table_name );
			if ( $size > 1073741824 ) {
				Log::add(
					sprintf(
						/* translators: %s: Table name */
						__( 'Skipped OPTIMIZE TABLE for %s — table exceeds 1 GB.', 'performance-optimisation' ),
						$full_table_name
					)
				);
				return false;
			}

			// Allowlisted identifier: $full_table_name is derived from $wpdb->{allowlisted key}
			// (TABLE_MAP), not from user input. Cannot use $wpdb->prepare() for identifiers.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $full_table_name is allowlisted via TABLE_MAP + $wpdb property; safe identifier interpolation.
			$result = $wpdb->query( "OPTIMIZE TABLE {$full_table_name}" );

			if ( false === $result ) {
				Log::add(
					sprintf(
						/* translators: %s: Table name */
						__( 'OPTIMIZE TABLE failed for %s.', 'performance-optimisation' ),
						$full_table_name
					)
				);
				return false;
			}

			Log::add(
				sprintf(
					/* translators: %1$s: Table name, %2$d: Table size in bytes */
					__( 'Optimized table %1$s (size: %2$d bytes).', 'performance-optimisation' ),
					$full_table_name,
					$size
				)
			);

			return true;
		}

		/**
		 * Conditionally optimize a list of unique database tables.
		 *
		 * Deduplicates table names and calls {@see optimize_table()} for each.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string> $table_names Unprefixed table identifiers (e.g. 'posts', 'commentmeta').
		 * @param bool          $enabled     Whether optimization is enabled.
		 * @return void
		 */
		public static function maybe_optimize_tables( array $table_names, bool $enabled ): void {
			if ( ! $enabled || empty( $table_names ) ) {
				return;
			}

			$unique_tables = array_unique( $table_names );

			foreach ( $unique_tables as $table ) {
				self::optimize_table( $table );
			}
		}
	}
}
