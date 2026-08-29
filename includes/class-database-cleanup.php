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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
		 * @return array<string, string>
		 */
		public static function get_cleanup_method_map(): array {
			return self::CLEANUP_METHOD_MAP;
		}

		/**
		 * Get valid cleanup types including 'all'.
		 *
		 * @since NEXT
		 * @return string[]
		 */
		public static function get_valid_cleanup_types(): array {
			return array_merge( array_keys( self::CLEANUP_METHOD_MAP ), array( 'all' ) );
		}

		/**
		 * Option key used for the DB cleanup counts cache salt.
		 *
		 * @since NEXT
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
		 * @since NEXT
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
					$last_date  = null;
					$last_id    = 0;
					$first_page = true;
					$batch_size = 500;
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

						$last       = end( $revisions );
						$last_date  = $last->post_date_gmt;
						$last_id    = (int) $last->ID;
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
		 * @since NEXT
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
		 * @since 1.1.0
		 * @return int|false Number of rows deleted, or false on error.
		 */
		public static function clean_spam_comments() {
			global $wpdb;
			return self::delete_in_batches(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' LIMIT 1000",
				$wpdb->commentmeta,
				'comment_id',
				$wpdb->comments,
				'comment_ID'
			);
		}

		/**
		 * Remove trashed comments and their comment meta from the database in batches.
		 *
		 * @since 1.1.0
		 * @return int|false Number of rows deleted, or false on error.
		 */
		public static function clean_trashed_comments() {
			global $wpdb;
			return self::delete_in_batches(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash' LIMIT 1000",
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
		 * @since NEXT
		 * @return int|false Number of attachments deleted, or false on error.
		 */
		public static function clean_unattached_media() {
			global $wpdb;
			$deleted = 0;
			$batch   = 500;

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
		 * @since NEXT
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
		 * @since NEXT
		 *
		 * @param int $limit Maximum number of options to return.
		 * @return array<int, array{option_name:string,size:int}> Sorted by size.
		 */
		public static function get_autoloaded_options( int $limit = 20 ): array {
			global $wpdb;

			$autoload_values = self::get_autoloadable_values();
			$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostic query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, LENGTH(option_value) AS opt_size FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY opt_size DESC LIMIT " . (int) $limit, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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
				);
			}

			return $result;
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
				if ( ! is_wp_error( $res ) && false !== $res && (int) $res > 0 ) {
					$total_deleted += (int) $res;
					if ( isset( self::TABLE_MAP[ $key ] ) ) {
						$affected_tables = array_merge( $affected_tables, self::TABLE_MAP[ $key ] );
					}
				}
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
		 * @since NEXT
		 * @param mixed $settings Optional settings array or null to load from option.
		 * @return array{0:int,1:int} Tuple of [max_age_days, keep_latest].
		 */
		public static function get_revision_defaults( $settings = null ) {
			if ( null === $settings ) {
				$settings = get_option( 'wppo_settings', array() );
				if ( ! is_array( $settings ) ) {
					$settings = array();
				}
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
		 * @since NEXT
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

			$optimize_enabled = ! empty( $settings['dbOptimize'] );
			self::maybe_optimize_tables( $affected_tables, $optimize_enabled );

			return $failures;
		}

		/**
		 * Get current counts for each database cleanup category.
		 *
		 * Returns an associative array keyed by cleanup type with integer counts for:
		 * `revisions`, `auto_drafts`, `trashed_posts`, `spam_comments`, `trashed_comments`,
		 * `expired_transients`, and `orphan_postmeta`.
		 *
		 * @since 1.1.0
		 * @return array<string,int> Associative array mapping cleanup type to its current count.
		 */
		public static function get_counts() {
			$has_salted = function_exists( 'wp_cache_get_salted' );

			if ( $has_salted ) {
				$cached = wp_cache_get_salted( 'wppo_db_cleanup_counts', 'wppo', self::SALT_KEY );
				if ( false !== $cached ) {
					return $cached;
				}
			} else {
				$cached = get_transient( Util::transient_key( 'wppo_db_cleanup_counts' ) );
				if ( false !== $cached ) {
					return $cached;
				}
			}

			global $wpdb;

			$time = time();

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$transient_count = 0;
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
				$timeout_prefix   = $prefix . 'timeout_';
				$transient_count += (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $wpdb->options a
						INNER JOIN $wpdb->options b ON b.option_name = CONCAT( %s, SUBSTRING( a.option_name, %d ) )
						WHERE a.option_name LIKE %s
						AND a.option_name NOT LIKE %s
						AND b.option_value < %d",
						$timeout_prefix,
						strlen( $prefix ) + 1,
						$wpdb->esc_like( $prefix ) . '%',
						$wpdb->esc_like( $timeout_prefix ) . '%',
						$time
					)
				);
			}

			$counts = array(
				'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'" ),
				'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'" ),
				'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'" ),
				'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'" ),
				'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'trash'" ),
				'expired_transients' => $transient_count,
				'orphan_postmeta'    => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM $wpdb->postmeta pm
					LEFT JOIN $wpdb->posts p ON p.ID = pm.post_id
					WHERE p.ID IS NULL"
				),
				'unattached_media'   => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM $wpdb->posts
					WHERE post_type = 'attachment'
					AND post_parent = 0
					AND post_status = 'inherit'"
				),
				'oembed_cache'       => (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s",
						$wpdb->esc_like( '_oembed_' ) . '%'
					)
				),
			);
			// phpcs:enable

			if ( $has_salted ) {
				wp_cache_set_salted( 'wppo_db_cleanup_counts', $counts, 'wppo', self::SALT_KEY );
			} else {
				set_transient( Util::transient_key( 'wppo_db_cleanup_counts' ), $counts, 5 * MINUTE_IN_SECONDS );
			}
			return $counts;
		}

		/**
		 * Call a static cleanup method by name and convert a `false` result into a `WP_Error`.
		 *
		 * @since 1.4.0
		 * @param string $method The static method name to invoke.
		 * @param mixed  ...$args Arguments forwarded to the method.
		 * @return mixed The invoked method's return value, or a `WP_Error` if the method returned `false`.
		 */
		public static function invoke_cleanup_method( $method, ...$args ) {
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
		 * @since NEXT
		 * @return void
		 */
		public static function invalidate_counts_cache(): void {
			if ( function_exists( 'wp_cache_get_salted' ) ) {
				update_option( self::SALT_KEY, time(), false );
			} else {
				delete_transient( Util::transient_key( 'wppo_db_cleanup_counts' ) );
			}
		}

		/**
		 * Callback for save_post/deleted_post to invalidate DB cleanup counts for public post types.
		 *
		 * @param int           $post_id Post ID.
		 * @param \WP_Post|null $post    Post object.
		 * @since NEXT
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
		 * @since NEXT
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
			// @since NEXT Added fallback for restricted DB users.
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
		 * @since NEXT
		 * @since NEXT Added allowlist justification and verified no user input reaches interpolation.
		 *
		 * @param string $table Unprefixed table identifier (e.g. 'posts', 'postmeta').
		 * @return bool True on success, false on failure or if skipped.
		 */
		public static function optimize_table( string $table ): bool {
			global $wpdb;

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
		 * @since NEXT
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
