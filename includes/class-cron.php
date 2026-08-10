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
			add_action( 'wppo_generate_static_url', array( $this, 'process_url' ), 10, 1 );

			add_action( 'wppo_database_cleanup_cron', array( $this, 'database_cleanup_cron' ) );

			add_action( 'wppo_web_vitals_rescan', array( $this, 'web_vitals_rescan_cron' ) );

			add_action( 'wppo_used_css_cron', array( $this, 'used_css_cron' ) );
			add_action( 'wppo_ccss_regeneration', array( $this, 'ccss_regeneration_cron' ) );
		}

		/**
		 * Trigger the cache preload directly (not via cron hook).
		 *
		 * Wraps the private schedule_page_cron_jobs() for WP-CLI invocation.
		 *
		 * @since 2.14.0
		 */
		public static function trigger_preload(): void {
			$instance = new self();
			$instance->schedule_page_cron_jobs();
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

			if ( ! wp_next_scheduled( 'wppo_web_vitals_rescan' ) ) {
				wp_schedule_event( time(), 'daily', 'wppo_web_vitals_rescan' );
			}

			$options = get_option( 'wppo_settings', array() );
			if ( ! empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
				if ( ! wp_next_scheduled( 'wppo_used_css_cron' ) ) {
					wp_schedule_event( time(), 'every_5_hours', 'wppo_used_css_cron' );
				}
			}

			if ( ! wp_next_scheduled( 'wppo_ccss_regeneration' ) ) {
				wp_schedule_event( time(), 'daily', 'wppo_ccss_regeneration' );
			}
		}

		/**
		 * Callback for CCSS daily regeneration cron.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public function ccss_regeneration_cron() {
			$options = get_option( 'wppo_settings', array() );
			if ( ! empty( $options['file_optimisation']['criticalCSS'] ) ) {
				Critical_CSS::regenerate_all();
			}
		}

		/**
		 * Callback for the daily Web Vitals auto-rescan cron.
		 *
		 * Queues PageSpeed scans for the home URL and any configured high-value
		 * URLs on both mobile and desktop strategies, gated by the
		 * performance_audit.auto_rescan setting ('daily' or 'weekly'). Weekly mode
		 * throttles itself by checking the last-run timestamp.
		 *
		 * @return void
		 * @since 2.14.0
		 */
		public function web_vitals_rescan_cron(): void {
			$options = get_option( 'wppo_settings', array() );
			$audit   = isset( $options['performance_audit'] ) && is_array( $options['performance_audit'] ) ? $options['performance_audit'] : array();

			$frequency = isset( $audit['auto_rescan'] ) ? sanitize_text_field( $audit['auto_rescan'] ) : '';
			if ( ! in_array( $frequency, array( 'daily', 'weekly' ), true ) ) {
				return;
			}

			if ( 'weekly' === $frequency ) {
				$last_run = (int) get_option( 'wppo_web_vitals_last_rescan', 0 );
				if ( ( time() - $last_run ) < WEEK_IN_SECONDS ) {
					return;
				}
			}

			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return;
			}

			$urls = array( home_url( '/' ) );

			if ( ! empty( $audit['high_value_urls'] ) && is_array( $audit['high_value_urls'] ) ) {
				foreach ( $audit['high_value_urls'] as $high_url ) {
					$clean = esc_url_raw( (string) $high_url );
					if ( ! empty( $clean ) && ! in_array( $clean, $urls, true ) ) {
						$urls[] = $clean;
					}
				}
			}

			$all_queued = true;

			foreach ( $urls as $scan_url ) {
				foreach ( array( 'mobile', 'desktop' ) as $scan_strategy ) {
					// queue_scan() returns 0 when Action Scheduler cannot create the job.
					if ( 0 === Pagespeed::queue_scan( $scan_url, $scan_strategy ) ) {
						$all_queued = false;
					}
				}
			}

			// Only record a completed run when everything queued successfully, so a
			// failed enqueue does not block retries for the full weekly window.
			if ( $all_queued ) {
				update_option( 'wppo_web_vitals_last_rescan', time(), false );
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
			if ( get_transient( Util::transient_key( 'wppo_preload_cron_lock' ) ) ) {
				return;
			}
			set_transient( Util::transient_key( 'wppo_preload_cron_lock' ), 1, 20 * MINUTE_IN_SECONDS );

			try {
				// Persist iteration offset across runs.
				$paged_offset = (int) get_option( 'wppo_preload_cron_offset', 0 );

				$post_types = get_post_types( array( 'public' => true ), 'names' );
				$post_types = array_unique( array_merge( array_values( array_diff( $post_types, array( 'attachment' ) ) ), array( 'page', 'post' ) ) );

				$args = array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
					'posts_per_page' => 200, // Process pages in batches to prevent OOM.
					'paged'          => max( 1, (int) ceil( ( $paged_offset + 1 ) / 200 ) ),
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				);

				$query_batch_posts = get_posts( $args );

				if ( empty( $query_batch_posts ) ) {
					// Reset offset on completion.
					delete_option( 'wppo_preload_cron_offset' );
					return; // Lock released in finally.
				}

				$options      = get_option( 'wppo_settings', array() );
				$preload      = $options['preload_settings'] ?? array();
				$exclude_urls = Util::process_urls( $preload['excludePreloadCache'] ?? array() );

				// Sitemap-aware preload: once per cycle (offset 0), warm URLs that
				// live outside standard post queries (custom endpoints, archives).
				if ( 0 === $paged_offset && ! empty( $preload['preloadSitemap'] ) ) {
					$this->schedule_sitemap_url_jobs( $exclude_urls );
				}

				foreach ( $query_batch_posts as $page_id ) {
					$page_url = get_permalink( $page_id );

					if ( Util::is_url_excluded( $page_url, $exclude_urls ) ) {
						continue;
					}

					if ( ! wp_next_scheduled( 'wppo_generate_static_page', array( $page_id ) ) ) {
						wp_schedule_single_event( time() + wp_rand( 0, 1800 ), 'wppo_generate_static_page', array( $page_id ) );
					}
				}

				// Update iteration offset for the next batch.
				update_option( 'wppo_preload_cron_offset', $paged_offset + 200, false );

				// Schedule next batch if needed.
				if ( ! wp_next_scheduled( 'wppo_page_cron_batch' ) ) {
					wp_schedule_single_event( time() + 60, 'wppo_page_cron_batch' );
				}
			} finally {
				delete_transient( Util::transient_key( 'wppo_preload_cron_lock' ) );
			}
		}

		/**
		 * Schedule single cron events for sitemap-discovered URLs.
		 *
		 * Skips URLs that match configured exclusion rules and URLs already
		 * scheduled, and caps the number of events to avoid flooding cron.
		 *
		 * @since 2.17.0
		 * @param array $exclude_urls Exclusion rules (processed URLs/patterns).
		 * @return void
		 */
		private function schedule_sitemap_url_jobs( array $exclude_urls ): void {
			$sitemap_urls = $this->get_sitemap_urls( 500 );

			foreach ( $sitemap_urls as $url ) {
				if ( Util::is_url_excluded( $url, $exclude_urls ) ) {
					continue;
				}

				if ( ! wp_next_scheduled( 'wppo_generate_static_url', array( $url ) ) ) {
					wp_schedule_single_event( time() + wp_rand( 0, 1800 ), 'wppo_generate_static_url', array( $url ) );
				}
			}
		}

		/**
		 * Callback for used-CSS background regeneration cron.
		 *
		 * @return void
		 * @since 1.9.0
		 */
		public function used_css_cron() {
			if ( get_transient( Util::transient_key( 'wppo_used_css_lock' ) ) ) {
				return;
			}
			set_transient( Util::transient_key( 'wppo_used_css_lock' ), 1, 20 * MINUTE_IN_SECONDS );

			try {
				$options = get_option( 'wppo_settings', array() );
				if ( empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
					return;
				}

				$used_css = new Used_CSS( $options );
				$used_css->regenerate_all();
			} finally {
				delete_transient( Util::transient_key( 'wppo_used_css_lock' ) );
			}
		}

		/**
		 * Clear all scheduled cron jobs.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function clear_cron_jobs(): void {
			wp_unschedule_hook( 'wppo_generate_static_page' );
			wp_unschedule_hook( 'wppo_generate_static_url' );
			wp_clear_scheduled_hook( 'wppo_page_cron_hook' );
			wp_clear_scheduled_hook( 'wppo_page_cron_batch' );
			delete_option( 'wppo_preload_cron_offset' );
			delete_transient( Util::transient_key( 'wppo_preload_cron_lock' ) );
			wp_clear_scheduled_hook( 'wppo_used_css_cron' );
			delete_transient( Util::transient_key( 'wppo_used_css_lock' ) );
			wp_clear_scheduled_hook( 'wppo_ccss_regeneration' );
			wp_clear_scheduled_hook( 'wppo_generate_ccss' );
			wp_clear_scheduled_hook( 'wppo_web_vitals_rescan' );
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
		 * Generate a static cache file for an arbitrary URL.
		 *
		 * Used by sitemap-aware preloading for pages that are not tied to a post
		 * ID (custom endpoints, third-party archives). Reuses the same remote
		 * GET approach as {@see load_page()}.
		 *
		 * @since 2.17.0
		 * @param string $url The URL to preload.
		 * @return void
		 */
		public function process_url( $url ): void {
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				return;
			}

			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				return;
			}

			$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
			if ( is_wp_error( $response ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO preload failed for URL ' . $url . ': ' . sanitize_text_field( str_replace( ABSPATH, '', $response->get_error_message() ) ) );
				}
			} elseif ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO preload failed: HTTP status ' . (int) wp_remote_retrieve_response_code( $response ) . ' for ' . $url );
				}
			}
		}

		/**
		 * Discover preloadable URLs from the site's sitemap.
		 *
		 * Prefers the core `wp-sitemap.xml` (WP 5.5+). Follows the sitemap index
		 * to child sitemaps and collects up to the given cap of `<loc>` URLs that
		 * belong to this site. Falls back to an empty list when the request fails
		 * or the sitemap is unavailable, so preloading never breaks.
		 *
		 * @since 2.17.0
		 * @param int $cap Maximum number of URLs to return.
		 * @return string[] List of absolute sitemap URLs.
		 */
		private function get_sitemap_urls( int $cap = 500 ): array {
			$urls       = array();
			$urls_count = 0;
			$home_host  = wp_parse_url( home_url(), PHP_URL_HOST );
			$to_fetch   = array( home_url( '/wp-sitemap.xml' ) );
			$fetched    = array();

			// Bound the whole discovery pass so a slow sitemap index cannot hold the
			// cron request (or the follow-up preload batch) for minutes on end.
			$deadline = microtime( true ) + 15;

			while ( ! empty( $to_fetch ) && $urls_count < $cap ) {
				$current = array_shift( $to_fetch );

				if ( isset( $fetched[ $current ] ) ) {
					continue;
				}
				$fetched[ $current ] = true;

				if ( microtime( true ) >= $deadline ) {
					break;
				}

				$response = wp_remote_get( $current, array( 'timeout' => 5 ) );
				if ( is_wp_error( $response ) ) {
					continue;
				}

				$body = wp_remote_retrieve_body( $response );
				if ( '' === $body ) {
					continue;
				}

				$is_index = ( false !== strpos( $body, '<sitemapindex' ) );

				if ( ! preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $body, $matches ) ) {
					continue;
				}

				$to_fetch_count = count( $to_fetch );

				foreach ( $matches[1] as $loc ) {
					$loc = esc_url_raw( trim( $loc ) );
					if ( '' === $loc ) {
						continue;
					}

					$loc_host = wp_parse_url( $loc, PHP_URL_HOST );
					if ( $loc_host && $loc_host !== $home_host ) {
						continue;
					}

					if ( $is_index ) {
						if ( ! isset( $fetched[ $loc ] ) && $to_fetch_count < 50 ) {
							$to_fetch[] = $loc;
							++$to_fetch_count;
						}
						continue;
					}

					$urls[] = $loc;
					++$urls_count;
					if ( $urls_count >= $cap ) {
						break 2;
					}
				}
			}

			return $urls;
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
				$clean_err = sanitize_text_field( str_replace( ABSPATH, '', $response->get_error_message() ) );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO preload failed for page ' . (int) $page_id . ': ' . $clean_err );
				}
			} elseif ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO preload failed: HTTP status ' . (int) wp_remote_retrieve_response_code( $response ) );
				}
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
			if ( get_transient( Util::transient_key( 'wppo_img_convert_lock' ) ) ) {
				return;
			}
			set_transient( Util::transient_key( 'wppo_img_convert_lock' ), true, 5 * MINUTE_IN_SECONDS );

			try {
				$options       = get_option( 'wppo_settings', array() );
				$img_converter = new Img_Converter( $options );

				$img_info = Img_Converter::get_img_info();

				$conversion_format = $options['image_optimisation']['conversionFormat'] ?? 'webp';

				$batch_size = $options['image_optimisation']['batch'] ?? 50;

				$normalized_abspath = trailingslashit( wp_normalize_path( ABSPATH ) );

				$formats_to_process = array();
				if ( 'both' === $conversion_format ) {
					$formats_to_process = array( 'avif', 'webp' );
				} elseif ( in_array( $conversion_format, array( 'avif', 'webp' ), true ) ) {
					$formats_to_process[] = $conversion_format;
				}

				foreach ( $formats_to_process as $format ) {
					$images = $img_info['pending'][ $format ] ?? array();

					if ( empty( $images ) ) {
						continue;
					}

					$counter = 0;
					foreach ( $images as $img ) {
						if ( $counter >= $batch_size ) {
							break;
						}

						++$counter;

						$source_path = wp_normalize_path( ABSPATH . $img );
						$resolved    = realpath( $source_path );
						if ( false === $resolved || 0 !== strpos( wp_normalize_path( $resolved ), $normalized_abspath ) ) {
							continue;
						}

						$img_converter->convert_image( $source_path, $format );
					}
				}
			} finally {
				delete_transient( Util::transient_key( 'wppo_img_convert_lock' ) );
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
				if ( get_transient( Util::transient_key( 'wppo_db_cleanup_lock' ) ) ) {
					return;
				}
				set_transient( Util::transient_key( 'wppo_db_cleanup_lock' ), 1, 5 * MINUTE_IN_SECONDS );
				try {
					Database_Cleanup::auto_clean( $settings );
				} finally {
					delete_transient( Util::transient_key( 'wppo_db_cleanup_lock' ) );
				}
				update_option( 'wppo_last_db_cleanup', $now, false );
			}
		}
	}
}
