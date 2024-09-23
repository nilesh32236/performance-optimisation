<?php

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Cron
 *
 * This class handles scheduling, managing, and processing cron jobs related to static page generation.
 */
class Cron {

	/**
	 * Constructor function.
	 *
	 * Registers WordPress actions and filters for cron jobs.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'schedule_all_pages_cron_jobs' ) );
		add_action( 'qtpo_page_cron_hook', array( $this, 'qtpo_page_cron_callback' ) );
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_interval' ) );

		add_action( 'qtpo_generate_static_page', array( $this, 'process_page' ), 10, 1 );
	}

	/**
	 * Add a custom cron interval.
	 *
	 * Adds a custom cron schedule that runs every 5 hours.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified schedules with 'every_5_hours' added.
	 */
	public function add_custom_cron_interval( $schedules ): array {
		$schedules['every_5_hours'] = array(
			'interval' => 5 * 60 * 60,
			'display'  => __( 'Every 5 Hours' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the main cron job that triggers the processing of all pages.
	 *
	 * Schedules the `qtpo_page_cron_hook` to run every 5 hours if it's not already scheduled.
	 *
	 * @return void
	 */
	public function schedule_all_pages_cron_jobs(): void {
		if ( ! wp_next_scheduled( 'qtpo_page_cron_hook' ) ) {
			wp_schedule_event( time(), 'every_5_hours', 'qtpo_page_cron_hook' );
		}
	}

	/**
	 * Callback for the main cron job.
	 *
	 * Triggers the scheduling of individual page processing jobs.
	 *
	 * @return void
	 */
	public function qtpo_page_cron_callback(): void {
		$this->schedule_page_cron_jobs();
	}

	/**
	 * Schedule individual cron jobs for each page.
	 *
	 * Schedules a single event for each page to generate a static version.
	 *
	 * @return void
	 */
	private function schedule_page_cron_jobs(): void {
		$pages = $this->get_all_pages();

		if ( empty( $pages ) ) {
			return;
		}

		foreach ( $pages as $page_id ) {
			if ( ! wp_next_scheduled( 'qtpo_generate_static_page', array( $page_id ) ) ) {
				wp_schedule_single_event( time() + rand( 0, 3600 ), 'qtpo_generate_static_page', array( $page_id ) );
			}
		}
	}

	/**
	 * Clear scheduled cron jobs.
	 *
	 * Unschedules all page processing cron jobs and clears the main hook.
	 *
	 * @return void
	 */
	public static function clear_cron_jobs(): void {
		$pages = self::get_all_pages();

		if ( empty( $pages ) ) {
			return;
		}

		foreach ( $pages as $page_id ) {
			$hook      = 'qtpo_generate_static_page_' . $page_id;
			$timestamp = wp_next_scheduled( $hook );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook, array( $page_id ) );
			}
		}

		wp_clear_scheduled_hook( 'qtpo_page_cron_hook' );
	}

	/**
	 * Process a specific page by generating its static version.
	 *
	 * This method will be triggered by the cron job to mark the page as processed and load it.
	 *
	 * @param int $page_id The ID of the page to process.
	 * @return void
	 */
	public function process_page( $page_id ): void {
		if ( $page_id ) {
			$this->mark_page_as_processed( $page_id );
			$this->load_page( $page_id );
		}
	}

	/**
	 * Get a list of all pages to process.
	 *
	 * Retrieves all public pages and posts and ensures the front page is included.
	 *
	 * @return array List of page IDs to process.
	 */
	private static function get_all_pages(): array {
		$front_page_id = get_option( 'page_on_front' );

		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$excluded_post_types = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' );
		$post_types          = array_diff( $post_types, $excluded_post_types );

		$post_types = array_unique( array_merge( array_values( $post_types ), array( 'page', 'post' ) ) );

		// Get all posts of these types
		$posts = get_posts(
			array(
				'post_type'   => $post_types,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		// Add the front page ID at the beginning if it's not already included
		if ( $front_page_id && ! in_array( $front_page_id, $posts, true ) ) {
			array_unshift( $posts, $front_page_id );
		}

		return $posts;
	}

	/**
	 * Load a specific page.
	 *
	 * This method fetches the page via `wp_remote_get` to generate the static page.
	 *
	 * @param int $page_id The ID of the page to load.
	 * @return void
	 */
	private function load_page( $page_id ): void {
		$permalink = get_permalink( $page_id );
		if ( $permalink ) {
			wp_remote_get( $permalink, array( 'timeout' => 30 ) );
		}
	}

	/**
	 * Mark a page as processed by clearing any previously generated cache files.
	 *
	 * Deletes both the `.html` and `.gz` cached versions of the page, if they exist.
	 *
	 * @param int $page_id The ID of the page to mark as processed.
	 * @return void
	 */
	private function mark_page_as_processed( $page_id ): void {
		$permalink  = get_permalink( $page_id );
		$url_path   = trim( wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
		$site_url   = get_option( 'siteurl' );
		$parsed_url = wp_parse_url( $site_url );
		$domain     = sanitize_text_field( $parsed_url['host'] . ( $parsed_url['port'] ?? '' ) );

		$cache_dir = WP_CONTENT_DIR . "/cache/qtpo/{$domain}/{$url_path}";

		if ( Util::init_filesystem() ) {
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
}
