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
	 * Delete all post revisions.
	 *
	 * @since 1.1.0
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clean_revisions() {
		global $wpdb;

		// First delete associated meta data for revisions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$wpdb->query(
			"DELETE pm FROM $wpdb->postmeta pm
			INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
			WHERE p.post_type = 'revision'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$result = $wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'revision'" );

		return $result;
	}

	/**
	 * Delete post revisions intelligently based on limits.
	 *
	 * @since 1.3.0
	 * @param int $max_age_days Maximum age of revisions to keep in days.
	 * @param int $keep_latest Number of latest revisions to keep per post.
	 * @return int Number of rows deleted.
	 */
	public static function clean_revisions_advanced( $max_age_days = 30, $keep_latest = 5 ) {
		global $wpdb;
		$deleted = 0;

		$max_age_seconds = $max_age_days * DAY_IN_SECONDS;
		$cutoff_date     = gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds );

		// Find parent posts that have more than $keep_latest revisions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$parent_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent FROM $wpdb->posts WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > %d",
				$keep_latest
			)
		);

		if ( empty( $parent_ids ) ) {
			return 0;
		}

		$revisions_to_delete = array();

		foreach ( $parent_ids as $parent_id ) {
			// Get all revisions for this post, sorted by date descending.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$revisions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_date FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'revision' ORDER BY post_date DESC",
					$parent_id
				)
			);

			// Keep the latest X revisions.
			$older_revisions = array_slice( $revisions, $keep_latest );

			foreach ( $older_revisions as $rev ) {
				// Delete if older than cutoff.
				if ( $rev->post_date < $cutoff_date ) {
					$revisions_to_delete[] = $rev->ID;
				}
			}
		}

		if ( ! empty( $revisions_to_delete ) ) {
			// Chunk deletes to avoid massive IN clauses.
			$chunks = array_chunk( $revisions_to_delete, 100 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

				// Delete meta.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->query(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->prepare(
						"DELETE FROM $wpdb->postmeta WHERE post_id IN (" . $placeholders . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						...$chunk
					)
				);

				// Delete posts.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$result = $wpdb->query(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->prepare(
						"DELETE FROM $wpdb->posts WHERE ID IN (" . $placeholders . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
	 * Delete all auto-draft posts.
	 *
	 * @since 1.1.0
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clean_auto_drafts() {
		global $wpdb;

		// Delete associated meta data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$wpdb->query(
			"DELETE pm FROM $wpdb->postmeta pm
			INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
			WHERE p.post_status = 'auto-draft'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$result = $wpdb->query( "DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'" );

		return $result;
	}

	/**
	 * Delete all trashed posts.
	 *
	 * @since 1.1.0
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clean_trashed_posts() {
		global $wpdb;

		// Delete associated meta data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$wpdb->query(
			"DELETE pm FROM $wpdb->postmeta pm
			INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
			WHERE p.post_status = 'trash'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$result = $wpdb->query( "DELETE FROM $wpdb->posts WHERE post_status = 'trash'" );

		return $result;
	}

	/**
	 * Delete all spam comments.
	 *
	 * @since 1.1.0
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clean_spam_comments() {
		global $wpdb;

		// Delete comment meta for spam comments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$wpdb->query(
			"DELETE cm FROM $wpdb->commentmeta cm
			INNER JOIN $wpdb->comments c ON c.comment_ID = cm.comment_id
			WHERE c.comment_approved = 'spam'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$result = $wpdb->query( "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'" );

		return $result;
	}

	/**
	 * Delete all trashed comments.
	 *
	 * @since 1.1.0
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clean_trashed_comments() {
		global $wpdb;

		// Delete comment meta for trashed comments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$wpdb->query(
			"DELETE cm FROM $wpdb->commentmeta cm
			INNER JOIN $wpdb->comments c ON c.comment_ID = cm.comment_id
			WHERE c.comment_approved = 'trash'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$result = $wpdb->query( "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'" );

		return $result;
	}

	/**
	 * Delete all expired transients.
	 *
	 * Removes transient option pairs where the timeout has expired.
	 *
	 * @since 1.1.0
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function clean_expired_transients() {
		global $wpdb;

		$time = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE a, b FROM $wpdb->options a, $wpdb->options b
				WHERE a.option_name LIKE %s
				AND a.option_name NOT LIKE %s
				AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
				AND b.option_value < %d",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$time
			)
		);

		return $result;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL is necessary for efficient bulk cleanup and caching is not required for one-off delete operations.
		$result = $wpdb->query(
			"DELETE pm FROM $wpdb->postmeta pm
			LEFT JOIN $wpdb->posts p ON p.ID = pm.post_id
			WHERE p.ID IS NULL"
		);

		return $result;
	}

	/**
	 * Run all cleanup operations.
	 *
	 * @since 1.1.0
	 * @return array Associative array of cleanup type => rows deleted.
	 */
	public static function clean_all() {
		$results = array(
			'revisions'          => self::clean_revisions(),
			'auto_drafts'        => self::clean_auto_drafts(),
			'trashed_posts'      => self::clean_trashed_posts(),
			'spam_comments'      => self::clean_spam_comments(),
			'trashed_comments'   => self::clean_trashed_comments(),
			'expired_transients' => self::clean_expired_transients(),
			'orphan_postmeta'    => self::clean_orphan_postmeta(),
		);

		return $results;
	}

	/**
	 * Run all automated cleanup operations based on settings.
	 *
	 * @since 1.3.0
	 * @param array $settings Database cleanup settings array.
	 */
	public static function auto_clean( $settings ) {
		$max_age = isset( $settings['dbRevMaxAge'] ) ? (int) $settings['dbRevMaxAge'] : 30;
		$keep    = isset( $settings['dbRevKeepLatest'] ) ? (int) $settings['dbRevKeepLatest'] : 5;

		self::clean_revisions_advanced( $max_age, $keep );
		self::clean_auto_drafts();
		self::clean_trashed_posts();
		self::clean_spam_comments();
		self::clean_trashed_comments();
		self::clean_expired_transients();
		self::clean_orphan_postmeta();
	}

	/**
	 * Get counts for all cleanup types.
	 *
	 * Returns the number of items that can be cleaned for each type.
	 *
	 * @since 1.1.0
	 * @return array Associative array of cleanup type => count.
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
}
