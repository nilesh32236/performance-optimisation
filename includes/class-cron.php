<?php

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Cron {

	public function __construct() {
		add_action( 'qtpo_generate_static_page_cron', array( $this, 'process_next_page' ) );
	}

	// Schedule the cron job
	public static function schedule_cron_job() {
		if ( ! wp_next_scheduled( 'qtpo_generate_static_page_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'qtpo_generate_static_page_cron' );
		}
	}

	// Clear the cron job on plugin deactivation
	public static function clear_cron_job() {
		$timestamp = wp_next_scheduled( 'qtpo_generate_static_page_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'qtpo_generate_static_page_cron' );
		}
	}

	// Process the next page in the queue
	public function process_next_page() {
		// Get the next page to process
		$next_page_id = $this->get_next_page_id();

		if ( $next_page_id ) {
			// Load the page to trigger static HTML generation
			$this->load_page( $next_page_id );

			// Mark this page as processed (or remove it from the queue)
			$this->mark_page_as_processed( $next_page_id );
		} else {
			// If no pages left to process, clear the cron job
			self::clear_cron_job();
		}
	}

	// Get the next page ID to process
	private function get_next_page_id() {
		// Logic to get the next page ID from the database or custom queue
		// For simplicity, let's assume pages are ordered by post ID

		global $wpdb;
		$page_id = $wpdb->get_var(
			"
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type IN ('page', 'post') 
            AND post_status = 'publish' 
            ORDER BY ID ASC LIMIT 1
        "
		);

		return $page_id;
	}

	// Load the page to trigger static HTML generation
	private function load_page( $page_id ) {
		// Get the permalink for the page
		$permalink = get_permalink( $page_id );

		// Load the page (triggering the generation of static HTML in Main class)
		if ( $permalink ) {
			wp_remote_get( $permalink, array( 'timeout' => 10 ) );
		}
	}

	// Mark the page as processed (e.g., delete static HTML file before generating new one)
	private function mark_page_as_processed( $page_id ) {
		// You can track processed pages using a custom field, or by another mechanism
		// Example: Delete old static HTML file

		$permalink = get_permalink( $page_id );
		$url_path  = trim( wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
		$domain    = sanitize_text_field( $_SERVER['HTTP_HOST'] );
		$cache_dir = WP_CONTENT_DIR . "/cache/qtpo/{$domain}/{$url_path}";

		if ( $this->init_filesystem() ) {
			global $wp_filesystem;
			$file_path      = "{$cache_dir}/index.html";
			$gzip_file_path = "{$file_path}.gz";

			if ( $wp_filesystem->exists( $file_path ) ) {
				$wp_filesystem->delete( $file_path );
			}

			if ( $wp_filesystem->exists( $gzip_file_path ) ) {
				$wp_filesystem->delete( $gzip_file_path );
			}
		}
	}

	// Initialize the WordPress filesystem
	private function init_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return WP_Filesystem();
	}
}
