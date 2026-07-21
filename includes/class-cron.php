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

if ( ! class_exists( 'PerformanceOptimise\Inc\Cron' ) ) {
	/**
	 * Class Cron
	 *
	 * This class handles scheduling, managing, and processing cron jobs related to static page generation.
	 *
	 * @since 1.0.0
	 */
	class Cron {

		/**
		 * Register WordPress actions and filters used to schedule and run the plugin's cron jobs.
		 *
		 * Hooks registered:
		 * - init → schedule_cron_jobs
		 * - wppo_page_cron_hook, wppo_page_cron_batch → wppo_page_cron_callback
		 * - wppo_img_conversion → img_convert_cron
		 * - cron_schedules (filter) → add_custom_cron_interval
		 * - wppo_generate_static_page → process_page (priority 10, 1 arg)
		 * - wppo_database_cleanup_cron → database_cleanup_cron
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_action( 'init', array( $this, 'schedule_cron_jobs' ) );
			add_action( 'wppo_page_cron_hook', array( $this, 'wppo_page_cron_callback' ) );
			add_action( 'wppo_page_cron_batch', array( $this, 'wppo_page_cron_callback' ) );
			add_action( 'wppo_img_conversion', array( $this, 'img_convert_cron' ) );
			add_filter( 'cron_schedules', array( $this, 'add_custom_cron_interval' ) );

			add_action( 'wppo_generate_static_page', array( $this, 'process_page' ), 10, 1 );

			add_action( 'wppo_database_cleanup_cron', array( $this, 'database_cleanup_cron' ) );
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

			if ( ! wp_next_scheduled( 'wppo_img_conversion' ) ) {
				wp_schedule_event( time(), 'hourly', 'wppo_img_conversion' );
			}

			if ( ! wp_next_scheduled( 'wppo_database_cleanup_cron' ) ) {
				wp_schedule_event( time(), 'daily', 'wppo_database_cleanup_cron' );
			}
		}

		/**
		 * Triggers scheduling of the next batch of per-page static-generation jobs.
		 *
		 * @since 1.0.0
		 */
		public function wppo_page_cron_callback(): void {
			$this->schedule_page_cron_jobs();
		}

		/**
		 * Schedules per-page static-generation cron events in paged batches.
		 *
		 * Reads a persisted batch offset, queries published public post types (200 IDs),
		 * skips pages that match configured exclude patterns, and schedules a single
		 * 'wppo_generate_static_page' event for each remaining page with a randomized
		 * delay up to 1800 seconds. Updates the batch offset transient and enqueues a
		 * follow-up 'wppo_page_cron_batch' single event if not already scheduled.
		 *
		 * @since 1.0.0
		 */
		private function schedule_page_cron_jobs(): void {
			// Transient-based lock to prevent concurrent workers from duplicating or skipping work.
			if ( get_transient( 'wppo_preload_cron_lock' ) ) {
				return;
			}
			set_transient( 'wppo_preload_cron_lock', 1, 5 * MINUTE_IN_SECONDS );

			// Persist iteration offset across runs.
			$paged_offset = (int) get_option( 'wppo_preload_cron_offset', 0 );

			$post_types = get_post_types( array( 'public' => true ), 'names' );
			$post_types = array_unique( array_merge( array_values( array_diff( $post_types, array( 'attachment' ) ) ), array( 'page', 'post' ) ) );

			$args = array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'posts_per_page' => 200, // Process pages in batches to prevent OOM.
				'offset'         => $paged_offset,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			);

			$query_batch_posts = get_posts( $args );

			if ( empty( $query_batch_posts ) ) {
				// Reset offset and release lock on completion.
				delete_option( 'wppo_preload_cron_offset' );
				delete_transient( 'wppo_preload_cron_lock' );
				return;
			}

			$options      = get_option( 'wppo_settings', array() );
			$preload      = $options['preload_settings'] ?? array();
			$exclude_urls = Util::process_urls( $preload['excludePreloadCache'] ?? array() );

			foreach ( $query_batch_posts as $page_id ) {
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
					wp_schedule_single_event( time() + \wp_rand( 0, 1800 ), 'wppo_generate_static_page', array( $page_id ) );
				}
			}

			// Update iteration offset for the next batch.
			update_option( 'wppo_preload_cron_offset', $paged_offset + 200, false );

			// Schedule next batch if needed.
			if ( ! wp_next_scheduled( 'wppo_page_cron_batch' ) ) {
				wp_schedule_single_event( time() + 60, 'wppo_page_cron_batch' );
			}

			// Release lock so the next scheduled batch event can run.
			delete_transient( 'wppo_preload_cron_lock' );
		}

		/**
		 * Clear scheduled cron jobs.
		 *
		 * Unschedules all page processing cron jobs and clears the main hook.
		 *
		 * @since 1.0.0
		 */
		public static function clear_cron_jobs(): void {
			wp_unschedule_hook( 'wppo_generate_static_page' );
			wp_clear_scheduled_hook( 'wppo_page_cron_hook' );
			wp_clear_scheduled_hook( 'wppo_page_cron_batch' );
			delete_option( 'wppo_preload_cron_offset' );
			delete_transient( 'wppo_preload_cron_lock' );
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
		 * Load a specific page.
		 *
		 * This method fetches the page via `wp_remote_get` to generate the static page.
		 *
		 * @param int $page_id The ID of the page to load.
		 * @since 1.0.0
		 */
		private function load_page( $page_id ): void {
			$permalink = get_permalink( $page_id );
			if ( ! $permalink ) {
				return;
			}
			$response = wp_remote_get( $permalink, array( 'timeout' => 30 ) );
			if ( is_wp_error( $response ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO preload failed for page ID: ' . $page_id . ' - ' . $response->get_error_message() );
			} elseif ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WPPO preload returned HTTP ' . wp_remote_retrieve_response_code( $response ) . ' for page ID: ' . $page_id );
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
			$permalink = get_permalink( $page_id );
			if ( ! $permalink ) {
				return;
			}
			$url_path   = trim( wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
			$site_url   = site_url();
			$parsed_url = wp_parse_url( $site_url );
			$domain     = sanitize_text_field( $parsed_url['host'] . ( isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '' ) );

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
			if ( get_transient( 'wppo_img_convert_lock' ) ) {
				return;
			}
			set_transient( 'wppo_img_convert_lock', true, 5 * MINUTE_IN_SECONDS );

			try {
				$options       = get_option( 'wppo_settings', array() );
				$img_converter = new Img_Converter( $options );

				$img_info = Img_Converter::get_img_info();

				$conversion_format = $options['image_optimisation']['conversionFormat'] ?? 'webp';

				$batch_size = $options['image_optimisation']['batch'] ?? 50;

				$normalized_abspath = trailingslashit( wp_normalize_path( ABSPATH ) );

				if ( in_array( $conversion_format, array( 'avif', 'both' ), true ) ) {
					$images = $img_info['pending']['avif'] ?? array();

					$counter = 0;
					if ( ! empty( $images ) ) {
						foreach ( $images as $img ) {
							++$counter;

							if ( $counter <= $batch_size ) {
								$source_path = wp_normalize_path( ABSPATH . $img );
								$resolved    = realpath( $source_path );
								if ( false === $resolved || 0 !== strpos( wp_normalize_path( $resolved ), $normalized_abspath ) ) {
									continue;
								}
								$img_converter->convert_image( $source_path, 'avif' );
							}
						}
					}
				}

				if ( in_array( $conversion_format, array( 'webp', 'both' ), true ) ) {
					$images = $img_info['pending']['webp'] ?? array();

					$counter = 0;
					if ( ! empty( $images ) ) {
						foreach ( $images as $img ) {
							++$counter;

							if ( $counter <= $batch_size ) {
								$source_path = wp_normalize_path( ABSPATH . $img );
								$resolved    = realpath( $source_path );
								if ( false === $resolved || 0 !== strpos( wp_normalize_path( $resolved ), $normalized_abspath ) ) {
									continue;
								}
								$img_converter->convert_image( $source_path );
							}
						}
					}
				}
			} finally {
				delete_transient( 'wppo_img_convert_lock' );
			}
		}

		/**
		 * Callback for database automatic cleanup cron.
		 *
		 * Checks the user settings and runs cleanup if the schedule matches.
		 *
		 * @return void
		 * @since 1.3.0
		 */
		public function database_cleanup_cron() {
			$options  = get_option( 'wppo_settings', array() );
			$settings = $options['database_cleanup'] ?? array();

			$schedule = $settings['dbSchedule'] ?? 'none';
			if ( 'none' === $schedule ) {
				return;
			}

			$last_run = (int) get_option( 'wppo_last_db_cleanup', 0 );
			$now      = time();

			$should_run = false;

			switch ( $schedule ) {
				case 'daily':
					$should_run = ( $now - $last_run > DAY_IN_SECONDS - HOUR_IN_SECONDS );
					break;
				case 'weekly':
					$should_run = ( $now - $last_run > WEEK_IN_SECONDS - HOUR_IN_SECONDS );
					break;
				case 'monthly':
					$should_run = ( $now - $last_run > 30 * DAY_IN_SECONDS - HOUR_IN_SECONDS );
					break;
			}

			if ( $should_run ) {
				// Use transient-based lock as primary mechanism (works without persistent object cache).
				if ( get_transient( 'wppo_db_cleanup_lock' ) ) {
					return;
				}
				set_transient( 'wppo_db_cleanup_lock', 1, 5 * MINUTE_IN_SECONDS );
				Database_Cleanup::auto_clean( $settings );
				update_option( 'wppo_last_db_cleanup', $now, false );
			}
		}
	}
}
