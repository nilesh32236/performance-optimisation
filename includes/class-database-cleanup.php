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
		public static function clean_revisions() {
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
		 * @param int $max_age_days Maximum age in days; revisions older than now - $max_age_days will be eligible for deletion.
		 * @param int $keep_latest  Number of most recent revisions to retain per parent post.
		 * @return int|false Number of rows deleted, or `false` on database error.
		 */
		public static function clean_revisions_advanced( $max_age_days = 30, $keep_latest = 5 ) {
			$keep_latest = max( 1, $keep_latest );
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
		 * Uses the core API when available (WP 6.6+ introduced the `auto-on`
		 * value) and falls back to the full historical list otherwise.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		public static function get_autoloadable_values(): array {
			if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
				return (array) wp_autoload_values_to_autoload();
			}
			return array( 'yes', 'on', 'auto', 'auto-on' );
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
			// transients, oEmbed, wppo_*, already-remediated) would otherwise
			// silently hide candidates sitting below the SQL LIMIT cutoff.
			$fetch = max( $limit * 5, $limit + 100 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostic query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, autoload, LENGTH(option_value) AS opt_size FROM {$wpdb->options} WHERE autoload IN ($placeholders) AND LENGTH(option_value) >= %d ORDER BY opt_size DESC LIMIT " . (int) $fetch, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...array_merge( $autoload_values, array( $threshold ) )
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
		 * @since 2.0.0
		 * @param int|null $threshold Optional threshold override (bytes).
		 * @param int      $limit     Maximum number of candidates.
		 * @return array{threshold:int,supported:bool,total_autoload_bytes:int,count:int,bytes_saved:int,options:array}
		 */
		public static function plan_autoload_remediation( ?int $threshold = null, int $limit = self::AUTOLOAD_REMEDIATION_LIMIT ): array {
			$threshold  = null === $threshold ? self::get_autoload_remediation_threshold() : self::clamp_autoload_threshold( $threshold );
			$candidates = self::get_autoload_candidates( $threshold, $limit );
			$saved      = 0;
			foreach ( $candidates as $candidate ) {
				$saved += (int) $candidate['size'];
			}
			return array(
				'threshold'            => $threshold,
				'supported'            => self::is_autoload_remediation_supported(),
				'total_autoload_bytes' => self::get_autoload_total_bytes(),
				'count'                => count( $candidates ),
				'bytes_saved'          => $saved,
				'options'              => $candidates,
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
		 * @return array{threshold:int,supported:bool,applied:array,failed:array,bytes_saved:int,total_autoload_bytes:int}
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
			);

			$candidates = self::get_autoload_candidates( $threshold, $limit );
			if ( empty( $candidates ) ) {
				return $result;
			}

			$legacy_fallback = ! function_exists( 'wp_set_option_autoload' );
			if ( $legacy_fallback ) {
				// Legacy fallback keeps autoload yes: report candidates as failed
				// (unsupported) without touching anything.
				foreach ( $candidates as $candidate ) {
					$result['failed'][] = $candidate['option_name'];
				}
				return $result;
			}

			$priors = self::get_remediated_options();
			$saved  = 0;
			// Hoist the core list once: get_core_autoload_options() fires a filter.
			$core_options = array_flip( self::get_core_autoload_options() );
			foreach ( $candidates as $candidate ) {
				$name = $candidate['option_name'];
				// Re-check core exclusion at apply time (filter may have changed).
				if ( isset( $core_options[ $name ] ) ) {
					$result['failed'][] = $name;
					continue;
				}
				$prior = '' !== $candidate['autoload'] ? $candidate['autoload'] : 'yes';
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
		 * successful reverts are still persisted.
		 *
		 * @since 2.0.0
		 * @return array{reverted:string[],failed:string[]}
		 */
		public static function revert_autoload_all(): array {
			$priors   = self::get_remediated_options();
			$reverted = array();
			$failed   = array();
			foreach ( $priors as $name => $prior ) {
				if ( self::restore_option_autoload( (string) $name, (string) $prior ) ) {
					$reverted[] = (string) $name;
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
				'reverted' => $reverted,
				'failed'   => $failed,
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
			$month = 2678400;
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
					$reclaimable += (int) $store->query_actions(
						array(
							'status'           => \ActionScheduler_Store::STATUS_COMPLETE,
							'modified'         => $cutoff,
							'modified_compare' => '<=',
							'per_page'         => 1,
						),
						'count'
					);
					$reclaimable += (int) $store->query_actions(
						array(
							'status'           => \ActionScheduler_Store::STATUS_CANCELED,
							'modified'         => $cutoff,
							'modified_compare' => '<=',
							'per_page'         => 1,
						),
						'count'
					);
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
					if ( $clean_failed ) {
						$reclaimable += (int) $store->query_actions(
							array(
								'status'           => \ActionScheduler_Store::STATUS_FAILED,
								'modified'         => $cutoff_failed,
								'modified_compare' => '<=',
								'per_page'         => 1,
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
					$total_bytes   = self::get_table_size( $actions_table ) + self::get_table_size( $logs_table );
					foreach ( array( \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler_Store::STATUS_CANCELED, \ActionScheduler_Store::STATUS_FAILED, \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
						$total_rows += (int) ( $counts[ $status ] ?? 0 );
					}
				}
				$reclaimable_bytes = 0;
				if ( $reclaimable > 0 && $total_rows > 0 && $total_bytes > 0 ) {
					$reclaimable_bytes = (int) ( $total_bytes * ( $reclaimable / $total_rows ) );
				}

				return array(
					'available'                  => true,
					'pending'                    => max( 0, $pending ),
					'failed'                     => max( 0, $failed ),
					'oldest_pending_age_seconds' => $oldest_age,
					'reclaimable'                => max( 0, $reclaimable ),
					'reclaimable_bytes'          => max( 0, $reclaimable_bytes ),
					'total_bytes'                => max( 0, $total_bytes ),
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $empty;
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
		 * @since NEXT
		 * @return int|false Number of actions deleted, or false on SQL error.
		 */
		public static function clean_action_scheduler() {
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
				$cleaner = new \ActionScheduler_QueueCleaner();
				if ( ! method_exists( $cleaner, 'delete_old_actions' ) ) {
					return 0;
				}
				$deleted = $cleaner->delete_old_actions();
				$count   = is_array( $deleted ) ? count( $deleted ) : 0;
				if ( $count > 0 ) {
					self::invalidate_counts_cache();
					Log::add(
						sprintf(
							/* translators: %d: Number of Action Scheduler actions purged */
							__( 'Action Scheduler cleanup: %d terminal actions purged via Action Scheduler cleaner.', 'performance-optimisation' ),
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
			$as_result                              = self::clean_action_scheduler();
			$results[ self::ACTION_SCHEDULER_TYPE ] = $as_result;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- documented hook
			do_action( 'wppo_database_cleanup_completed', self::ACTION_SCHEDULER_TYPE, is_wp_error( $as_result ) || false === $as_result ? 0 : (int) $as_result );
			if ( ! is_wp_error( $as_result ) && false !== $as_result ) {
				$total_deleted += (int) $as_result;
			}

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
				} elseif ( $result > 0 ) {
					$type = self::METHOD_TO_TYPE[ $method ] ?? '';
					if ( isset( self::TABLE_MAP[ $type ] ) ) {
						$affected_tables = array_merge( $affected_tables, self::TABLE_MAP[ $type ] );
					}
				}
			}

			// Thin AS delegation so WP-Cron starvation does not leave the queue
			// to grow unbounded (issue #1106). Fail-open: a 0 return is not a
			// failure (AS absent, disabled, or nothing past retention).
			$as_result = self::clean_action_scheduler();
			if ( is_wp_error( $as_result ) || false === $as_result ) {
				// Translators: %s is the cleanup type label.
				Log::add( sprintf( __( 'Auto cleanup failed: %s', 'performance-optimisation' ), __( 'Action Scheduler', 'performance-optimisation' ) ) );
				$failures[] = 'clean_action_scheduler';
			}

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
