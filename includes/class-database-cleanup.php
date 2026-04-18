<?php
/**
 * Database Cleanup functionality.
 *
 * Provides methods to clean various types of database bloat including
 * post revisions, auto-drafts, trashed posts/comments, spam comments,
 * expired transients, and orphaned post meta.
 *
 * @package PerformanceOptimise
 * @since 1.1.0
 */

namespace PerformanceOptimise\Inc;

use WP_Error;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Delete all post revisions from the database.
	 *
	 * @since 1.1.0
	 * @return int|false The number of rows deleted, or `false` on SQL error.
	 */
	public static function clean_revisions() {
		global $wpdb;
		$deleted = 0;

		do {
			// Select a batch of post IDs to delete.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' LIMIT 1000" );

			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}

			if ( empty( $post_ids ) ) {
				break;
			}

			// Delete associated meta data for these specific revisions.
			$wpdb->last_error = '';
			$placeholders     = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE post_id IN ($placeholders)", $post_ids ) );

			if ( false === $meta_deleted ) {
				return false;
			}

			// Delete the revision posts.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$posts_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE ID IN ($placeholders)", $post_ids ) );

			if ( false === $posts_deleted ) {
				return false;
			}

			if ( $posts_deleted ) {
				$deleted += (int) $posts_deleted;
			}
			$ids_count = count( $post_ids );
		} while ( $ids_count >= 1000 );

		return $deleted;
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
		global $wpdb;
		$deleted = 0;

		$max_age_seconds = $max_age_days * DAY_IN_SECONDS;
		$cutoff_date     = gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds );

		// PERFORMANCE FIX: Apply strict batching mechanism limit by adding "LIMIT 200".
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$parent_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent FROM $wpdb->posts WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > %d LIMIT 200",
				$keep_latest
			)
		);

		if ( ! empty( $wpdb->last_error ) ) {
			return false;
		}

		if ( empty( $parent_ids ) ) {
			return 0;
		}

		$revisions_to_delete = array();

		foreach ( $parent_ids as $parent_id ) {
			// Select exactly the cutoff entries so PHP handles almost no object data.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$revisions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_date FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'revision' ORDER BY post_date DESC LIMIT 500",
					$parent_id
				)
			);

			// Keep the latest X revisions; dump others onto our purge list.
			$older_revisions = array_slice( $revisions, $keep_latest );

			foreach ( $older_revisions as $rev ) {
				// Delete if older than cutoff.
				if ( $rev->post_date < $cutoff_date ) {
					$revisions_to_delete[] = $rev->ID;
				}
			}
		}

		if ( ! empty( $revisions_to_delete ) ) {
			// Safe deletions capped exactly per max length boundaries.
			$chunks = array_chunk( $revisions_to_delete, 50 );

			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

				// SubQuery Meta Purge.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$meta_deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM $wpdb->postmeta WHERE post_id IN (" . $placeholders . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$chunk
					)
				);

				if ( false === $meta_deleted ) {
					error_log( sprintf( 'WPPO Database Cleanup: Failed to delete postmeta for posts: %s. Error: %s', implode( ',', $chunk ), $wpdb->last_error ) );
					return false;
				}

				// Post Database Delete Executions.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$result = $wpdb->query(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->prepare(
						"DELETE FROM $wpdb->posts WHERE ID IN (" . $placeholders . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$chunk
					)
				);

				if ( $result ) {
					$deleted += $result;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Remove all auto-draft posts and their associated postmeta in batched operations.
	 *
	 * @since 1.1.0
	 * @return int|false Total number of posts deleted, or `false` on SQL error.
	 */
	public static function clean_auto_drafts() {
		global $wpdb;
		$deleted = 0;

		do {
			// Select a batch of post IDs to delete.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status = 'auto-draft' LIMIT 1000" );

			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}

			if ( empty( $post_ids ) ) {
				break;
			}

			// Delete associated meta data for these specific posts.
			$wpdb->last_error = '';
			$placeholders     = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE post_id IN ($placeholders)", $post_ids ) );

			if ( false === $meta_deleted ) {
				return false;
			}

			// Delete the posts.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$posts_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE ID IN ($placeholders)", $post_ids ) );

			if ( false === $posts_deleted ) {
				return false;
			}

			if ( $posts_deleted ) {
				$deleted += (int) $posts_deleted;
			}
			$ids_count = count( $post_ids );
		} while ( $ids_count >= 1000 );

		return $deleted;
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
		$deleted = 0;

		do {
			// Select a batch of post IDs to delete.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status = 'trash' LIMIT 1000" );

			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}

			if ( empty( $post_ids ) ) {
				break;
			}

			// Delete associated meta data for these specific posts.
			$wpdb->last_error = '';
			$placeholders     = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE post_id IN ($placeholders)", $post_ids ) );

			if ( false === $meta_deleted ) {
				return false;
			}

			// Delete the posts.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$posts_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE ID IN ($placeholders)", $post_ids ) );

			if ( false === $posts_deleted ) {
				return false;
			}

			if ( $posts_deleted ) {
				$deleted += (int) $posts_deleted;
			}
			$ids_count = count( $post_ids );
		} while ( $ids_count >= 1000 );

		return $deleted;
	}

	/**
	 * Delete all spam comments.
	 *
	 * @since 1.1.0
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clean_spam_comments() {
		global $wpdb;
		$deleted = 0;

		do {
			// Select a batch of comment IDs to delete.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$comment_ids = $wpdb->get_col( "SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = 'spam' LIMIT 1000" );

			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}

			if ( empty( $comment_ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );

			// Delete comment meta for spam comments.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->commentmeta WHERE comment_id IN ($placeholders)", $comment_ids ) );

			if ( false === $meta_deleted ) {
				return false;
			}

			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$comments_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->comments WHERE comment_ID IN ($placeholders)", $comment_ids ) );

			if ( false === $comments_deleted ) {
				return false;
			}

			if ( $comments_deleted ) {
				$deleted += $comments_deleted;
			}
			$ids_count = count( $comment_ids );
		} while ( $ids_count >= 1000 );

		return $deleted;
	}

	/**
	 * Remove trashed comments and their comment meta from the database in batches.
	 *
	 * @since 1.1.0
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clean_trashed_comments() {
		global $wpdb;
		$deleted = 0;

		do {
			// Select a batch of comment IDs to delete.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$comment_ids = $wpdb->get_col( "SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = 'trash' LIMIT 1000" );

			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}

			if ( empty( $comment_ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );

			// Delete comment meta for trashed comments.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$meta_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->commentmeta WHERE comment_id IN ($placeholders)", $comment_ids ) );

			if ( false === $meta_deleted ) {
				return false;
			}

			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$comments_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->comments WHERE comment_ID IN ($placeholders)", $comment_ids ) );

			if ( false === $comments_deleted ) {
				return false;
			}

			if ( $comments_deleted ) {
				$deleted += $comments_deleted;
			}
			$ids_count = count( $comment_ids );
		} while ( $ids_count >= 1000 );

		return $deleted;
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

		$time    = time();
		$deleted = 0;
		$batch   = 1000;

		do {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT a.option_name FROM $wpdb->options a
					INNER JOIN $wpdb->options b ON b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
					WHERE a.option_name LIKE %s
					AND a.option_name NOT LIKE %s
					AND b.option_value < %d
					LIMIT %d",
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
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
			$to_delete = array();
			foreach ( $ids as $name ) {
				$to_delete[] = $name;
				$to_delete[] = '_transient_timeout_' . substr( $name, 11 );
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
	 * Execute all defined database cleanup routines and collect their results.
	 *
	 * @since 1.1.0
	 * @return array<string, int|WP_Error> Associative array keyed by cleanup type (e.g. 'revisions', 'auto_drafts') with each value set to the number of rows deleted or a `WP_Error` instance if that cleanup failed.
	 */
	public static function clean_all() {
		$methods = array(
			'revisions'          => 'clean_revisions',
			'auto_drafts'        => 'clean_auto_drafts',
			'trashed_posts'      => 'clean_trashed_posts',
			'spam_comments'      => 'clean_spam_comments',
			'trashed_comments'   => 'clean_trashed_comments',
			'expired_transients' => 'clean_expired_transients',
			'orphan_postmeta'    => 'clean_orphan_postmeta',
		);

		$results = array();
		foreach ( $methods as $key => $method ) {
			$results[ $key ] = self::invoke_cleanup_method( $method );
		}

		return $results;
	}

	/**
	 * Execute configured database cleanup routines according to provided settings.
	 *
	 * Calls a set of cleanup methods (including advanced revision cleanup, drafts, trashed posts,
	 * spam/trashed comments, expired transients, and orphan postmeta). If a cleanup fails,
	 * an error is logged via the Log class.
	 *
	 * @since 1.3.0
	 *
	 * @param array $settings Cleanup settings. Recognized keys:
	 *                        - 'dbRevMaxAge'     (int) Maximum age in days for revision pruning (default 30).
	 *                        - 'dbRevKeepLatest' (int) Number of latest revisions to retain per parent (default 5).
	 */
	public static function auto_clean( $settings ) {
		$max_age = isset( $settings['dbRevMaxAge'] ) ? (int) $settings['dbRevMaxAge'] : 30;
		$keep    = isset( $settings['dbRevKeepLatest'] ) ? (int) $settings['dbRevKeepLatest'] : 5;

		$methods = array(
			'clean_revisions_advanced',
			'clean_auto_drafts',
			'clean_trashed_posts',
			'clean_spam_comments',
			'clean_trashed_comments',
			'clean_expired_transients',
			'clean_orphan_postmeta',
		);

		foreach ( $methods as $method ) {
			if ( 'clean_revisions_advanced' === $method ) {
				$result = self::invoke_cleanup_method( $method, $max_age, $keep );
			} else {
				$result = self::invoke_cleanup_method( $method );
			}

			if ( is_wp_error( $result ) ) {
				new Log( "Auto cleanup failed: {$method}" );
			}
		}
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
		global $wpdb;

		$time = time();

		return array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching latest database counts for cleanup; caching is not required for these live stats.
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching latest database counts for cleanup; caching is not required for these live stats.
			'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching latest database counts for cleanup; caching is not required for these live stats.
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching latest database counts for cleanup; caching is not required for these live stats.
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching latest database counts for cleanup; caching is not required for these live stats.
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'trash'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching latest database counts for cleanup; caching is not required for these live stats.
			'expired_transients' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $wpdb->options a
					INNER JOIN $wpdb->options b ON b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
					WHERE a.option_name LIKE %s
					AND a.option_name NOT LIKE %s
					AND b.option_value < %d",
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					$time
				)
			),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching latest database counts for cleanup; caching is not required for these live stats.
			'orphan_postmeta'    => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM $wpdb->postmeta pm
				LEFT JOIN $wpdb->posts p ON p.ID = pm.post_id
				WHERE p.ID IS NULL"
			),
		);
	}

	/**
	 * Call a static cleanup method by name and convert a `false` result into a `WP_Error`.
	 *
	 * @since 1.4.0
	 * @param string $method The static method name to invoke.
	 * @param mixed  ...$args Arguments forwarded to the method.
	 * @return mixed The invoked method's return value, or a `WP_Error` if the method returned `false`.
	 */
	private static function invoke_cleanup_method( $method, ...$args ) {
		$res = self::$method( ...$args );
		if ( false === $res ) {
			return new WP_Error( 'db_cleanup_failed', sprintf( '%s failed', $method ) );
		}
		return $res;
	}
}
