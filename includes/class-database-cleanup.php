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
		$wpdb->query(
			"DELETE pm FROM $wpdb->postmeta pm
			INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
			WHERE p.post_type = 'revision'"
		);

		$result = $wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'revision'" );

		return $result;
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
		$wpdb->query(
			"DELETE pm FROM $wpdb->postmeta pm
			INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
			WHERE p.post_status = 'auto-draft'"
		);

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
		$wpdb->query(
			"DELETE pm FROM $wpdb->postmeta pm
			INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
			WHERE p.post_status = 'trash'"
		);

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
		$wpdb->query(
			"DELETE cm FROM $wpdb->commentmeta cm
			INNER JOIN $wpdb->comments c ON c.comment_ID = cm.comment_id
			WHERE c.comment_approved = 'spam'"
		);

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
		$wpdb->query(
			"DELETE cm FROM $wpdb->commentmeta cm
			INNER JOIN $wpdb->comments c ON c.comment_ID = cm.comment_id
			WHERE c.comment_approved = 'trash'"
		);

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
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'" ),
			'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'" ),
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'" ),
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'" ),
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'trash'" ),
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
			'orphan_postmeta'    => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM $wpdb->postmeta pm
				LEFT JOIN $wpdb->posts p ON p.ID = pm.post_id
				WHERE p.ID IS NULL"
			),
		);
	}
}
