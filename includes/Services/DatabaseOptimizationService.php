<?php
/**
 * Database Optimization Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.1.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DatabaseOptimizationService
 */
class DatabaseOptimizationService {

	/**
	 * Logger instance.
	 *
	 * @var LoggingUtil
	 */
	private LoggingUtil $logger;

	/**
	 * Settings service.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Settings service.
	 * @param LoggingUtil     $logger   Logger instance.
	 */
	public function __construct( SettingsService $settings, LoggingUtil $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Run all enabled cleanup tasks.
	 *
	 * @return array Results of the cleanup.
	 */
	public function run_cleanup(): array {
		$results = array();
		$settings = $this->settings->get_setting( 'database', 'cleanup', array() );

		if ( ! empty( $settings['revisions'] ) ) {
			$results['revisions'] = $this->cleanup_revisions();
		}

		if ( ! empty( $settings['spam'] ) ) {
			$results['spam'] = $this->cleanup_spam();
		}

		if ( ! empty( $settings['trash'] ) ) {
			$results['trash'] = $this->cleanup_trash();
		}

		if ( ! empty( $settings['optimize_tables'] ) ) {
			$results['optimize_tables'] = $this->optimize_tables();
		}

		return $results;
	}

	/**
	 * Clean up post revisions.
	 *
	 * @return int Number of revisions deleted.
	 */
	public function cleanup_revisions(): int {
		global $wpdb;
		
		// Keep last 5 revisions per post to be safe
		$query = "
			DELETE a,b,c
			FROM {$wpdb->posts} a
			LEFT JOIN {$wpdb->term_relationships} b ON (a.ID = b.object_id)
			LEFT JOIN {$wpdb->postmeta} c ON (a.ID = c.post_id)
			LEFT JOIN {$wpdb->posts} p ON (a.post_parent = p.ID)
			WHERE a.post_type = 'revision'
			AND p.post_status = 'publish'
			AND a.ID NOT IN (
				SELECT ID FROM (
					SELECT ID FROM {$wpdb->posts} as posts_temp
					WHERE post_type = 'revision'
					AND post_parent = a.post_parent
					ORDER BY ID DESC
					LIMIT 5
				) as subquery
			)
		";

		$deleted = $wpdb->query( $query );
		$this->logger->info( "Cleaned up $deleted revisions" );
		
		return (int) $deleted;
	}

	/**
	 * Clean up spam comments.
	 *
	 * @return int Number of spam comments deleted.
	 */
	public function cleanup_spam(): int {
		global $wpdb;

		$query = "
			DELETE FROM {$wpdb->comments}
			WHERE comment_approved = 'spam'
		";

		$deleted = $wpdb->query( $query );
		
		// Also clean up comment meta
		$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})" );

		$this->logger->info( "Cleaned up $deleted spam comments" );

		return (int) $deleted;
	}

	/**
	 * Clean up trashed posts and comments.
	 *
	 * @return int Number of items deleted.
	 */
	public function cleanup_trash(): int {
		global $wpdb;
		$count = 0;

		// Trashed posts (older than 30 days usually, but we force clean)
		$query_posts = "
			DELETE a,b,c
			FROM {$wpdb->posts} a
			LEFT JOIN {$wpdb->term_relationships} b ON (a.ID = b.object_id)
			LEFT JOIN {$wpdb->postmeta} c ON (a.ID = c.post_id)
			WHERE a.post_status = 'trash'
		";
		$count += (int) $wpdb->query( $query_posts );

		// Trashed comments
		$query_comments = "
			DELETE FROM {$wpdb->comments}
			WHERE comment_approved = 'trash'
		";
		$count += (int) $wpdb->query( $query_comments );

		$this->logger->info( "Cleaned up $count trashed items" );

		return $count;
	}

	/**
	 * Optimize database tables.
	 *
	 * @return int Number of tables optimized.
	 */
	public function optimize_tables(): int {
		global $wpdb;

		$tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
		$count = 0;

		foreach ( $tables as $table ) {
			$table_name = $table[0];
			$wpdb->query( "OPTIMIZE TABLE $table_name" );
			$count++;
		}

		$this->logger->info( "Optimized $count database tables" );

		return $count;
	}
}
