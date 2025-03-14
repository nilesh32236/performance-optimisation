<?php
/**
 * Cron Class for scheduling and managing cron jobs in the PerformanceOptimise plugin.
 *
 * This class handles scheduling, managing, and processing cron jobs related to
 * static page generation and image optimization tasks. It includes scheduling
 * the main cron jobs, adding custom cron intervals, scheduling individual page
 * processing jobs, clearing scheduled jobs, and processing image conversions.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Cron
 *
 * This class handles scheduling, managing, and processing cron jobs related to static page generation.
 *
 * @since 1.0.0
 */
class Cron {

	/**
	 * Constructor function.
	 *
	 * Registers WordPress actions and filters for cron jobs.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'schedule_cron_jobs' ) );
		add_action( 'wppo_page_cron_hook', array( $this, 'wppo_page_cron_callback' ) );
		add_action( 'wppo_img_conversation', array( $this, 'img_convert_cron' ) );
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_interval' ) );

		add_action( 'wppo_generate_static_page', array( $this, 'process_page' ), 10, 1 );
	}

	/**
	 * Add a custom cron interval.
	 *
	 * Adds a custom cron schedule that runs every 5 hours.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified schedules with 'every_5_hours' added.
	 *
	 * @since 1.0.0
	 */
	public function add_custom_cron_interval( $schedules ): array {
		$schedules['every_5_hours'] = array(
			'interval' => 5 * 60 * 60,
			'display'  => __( 'Every 5 Hours', 'performance-optimisation' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the main cron job that triggers the processing of all pages.
	 *
	 * Schedules the `wppo_page_cron_hook` to run every 5 hours if it's not already scheduled.
	 *
	 * @since 1.0.0
	 */
	public function schedule_cron_jobs(): void {
		if ( ! wp_next_scheduled( 'wppo_page_cron_hook' ) ) {
			wp_schedule_event( time(), 'every_5_hours', 'wppo_page_cron_hook' );
		}

		if ( ! wp_next_scheduled( 'wppo_img_conversation' ) ) {
			wp_schedule_event( time(), 'hourly', 'wppo_img_conversation' );
		}
	}

	/**
	 * Callback for the main cron job.
	 *
	 * Triggers the scheduling of individual page processing jobs.
	 *
	 * @since 1.0.0
	 */
	public function wppo_page_cron_callback(): void {
		$this->schedule_page_cron_jobs();
	}

	/**
	 * Schedule individual cron jobs for each page.
	 *
	 * Schedules a single event for each page to generate a static version.
	 *
	 * @since 1.0.0
	 */
	private function schedule_page_cron_jobs(): void {
		$pages = $this->get_all_pages();

		if ( empty( $pages ) ) {
			return;
		}

		$options = get_option( 'wppo_settings', array() );

		$exclude_urls = Util::process_urls( $options['preload_settings']['excludePreloadCache'] ?? array() );

		foreach ( $pages as $page_id ) {
			$page_url       = get_permalink( $page_id );
			$should_exclude = false;

			foreach ( $exclude_urls as $exclude_url ) {
				$exclude_url = rtrim( $exclude_url, '/' );

				if ( 0 !== strpos( $exclude_url, 'http' ) ) {
					$exclude_url = home_url( $exclude_url );
				}

				if ( false !== strpos( $exclude_url, '(.*)' ) ) {
					$exclude_prefix = str_replace( '(.*)', '', $exclude_url );

					if ( 0 === strpos( $page_url, $exclude_prefix ) ) {
						$should_exclude = true;
						break;
					}
				}

				if ( $page_url === $exclude_url ) {
					$should_exclude = true;
					break;
				}
			}

			if ( $should_exclude ) {
				continue;
			}

			if ( ! wp_next_scheduled( 'wppo_generate_static_page', array( $page_id ) ) ) {
				wp_schedule_single_event( time() + \wp_rand( 0, 3600 ), 'wppo_generate_static_page', array( $page_id ) );
			}
		}
	}

	/**
	 * Clear scheduled cron jobs.
	 *
	 * Unschedules all page processing cron jobs and clears the main hook.
	 *
	 * @since 1.0.0
	 */
	public static function clear_cron_jobs(): void {
		$pages = self::get_all_pages();

		if ( empty( $pages ) ) {
			return;
		}

		foreach ( $pages as $page_id ) {
			$hook      = 'wppo_generate_static_page_' . $page_id;
			$timestamp = wp_next_scheduled( $hook );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook, array( $page_id ) );
			}
		}

		wp_clear_scheduled_hook( 'wppo_page_cron_hook' );
	}

	/**
	 * Process a specific page by generating its static version.
	 *
	 * This method will be triggered by the cron job to mark the page as processed and load it.
	 *
	 * @param int $page_id The ID of the page to process.
	 * @since 1.0.0
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
	 *
	 * @since 1.0.0
	 */
	private static function get_all_pages(): array {
		$front_page_id = get_option( 'page_on_front' );

		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$excluded_post_types = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' );
		$post_types          = array_diff( $post_types, $excluded_post_types );

		$post_types = array_unique( array_merge( array_values( $post_types ), array( 'page', 'post' ) ) );

		// Get all posts of these types.
		$posts = get_posts(
			array(
				'post_type'   => $post_types,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		// Add the front page ID at the beginning if it's not already included.
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 */
	private function mark_page_as_processed( $page_id ): void {
		$permalink  = get_permalink( $page_id );
		$url_path   = trim( wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
		$site_url   = get_option( 'siteurl' );
		$parsed_url = wp_parse_url( $site_url );
		$domain     = sanitize_text_field( $parsed_url['host'] . ( $parsed_url['port'] ?? '' ) );

		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . "/cache/wppo/{$domain}/{$url_path}" );

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

	/**
	 * Convert images to optimized formats.
	 *
	 * Processes pending images and converts them to `webp` and/or `avif` formats
	 * based on the plugin settings. Handles images in batches to optimize performance.
	 *
	 * @since 1.0.0
	 */
	public function img_convert_cron() {
		$options       = get_option( 'wppo_settings', array() );
		$img_converter = new Img_Converter( $options );

		$img_info = get_option( 'wppo_img_info', array() );

		$conversation_format = $options['image_optimisation']['conversionFormat'] ?? 'webp';

		$batch_size = $options['image_optimisation']['batch'] ?? 50;

		if ( in_array( $conversation_format, array( 'avif', 'both' ), true ) ) {
			$images = $img_info['pending']['avif'] ?? array();

			$counter = 0;
			if ( ! empty( $images ) ) {
				foreach ( $images as $img ) {
					++$counter;

					if ( $counter <= $batch_size ) {
						$img_converter->convert_image( wp_normalize_path( ABSPATH . $img ), 'avif' );
					}
				}
			}
		}

		if ( in_array( $conversation_format, array( 'webp', 'both' ), true ) ) {
			$images = $img_info['pending']['webp'] ?? array();

			$counter = 0;
			if ( ! empty( $images ) ) {
				foreach ( $images as $img ) {
					++$counter;

					if ( $counter <= $batch_size ) {
						$img_converter->convert_image( wp_normalize_path( ABSPATH . $img ) );
					}
				}
			}
		}
	}
}
