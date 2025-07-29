<?php
/**
 * Database optimization for Performance Optimisation.
 *
 * @package PerformanceOptimise
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc\Lib;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Database
 *
 * @package PerformanceOptimise\Inc\Lib
 */
class Database {

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 * @since 2.0.0
	 */
	private array $options;

	/**
	 * Database constructor.
	 *
	 * @param array<string, mixed> $options The plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Register hooks for database optimization.
	 */
	public function register_hooks(): void {
		add_action( 'wppo_database_cleanup', array( $this, 'run_cleanup' ) );
	}

	/**
	 * Run the database cleanup.
	 */
	public function run_cleanup(): void {
		if ( ! empty( $this->options['database']['revisions'] ) ) {
			$this->delete_revisions();
		}
		if ( ! empty( $this->options['database']['spam_comments'] ) ) {
			$this->delete_spam_comments();
		}
		if ( ! empty( $this->options['database']['transients'] ) ) {
			$this->delete_transients();
		}
	}

	/**
	 * Delete post revisions.
	 */
	private function delete_revisions(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'revision'" );
	}

	/**
	 * Delete spam comments.
	 */
	private function delete_spam_comments(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'" );
	}

	/**
	 * Delete transients.
	 */
	private function delete_transients(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_%'" );
	}
}
