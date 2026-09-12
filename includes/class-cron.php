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

		/**
		 * Maximum number of child sitemaps to fetch from an index.
		 *
		 * @since 2.0.0
		 */
		private const TO_FETCH_LIMIT = 50;

		/**
		 * Canonical list of WP-Cron hooks this plugin schedules.
		 *
		 * Single source of truth for deactivation cleanup (audit #888 finding 9):
		 * {@see schedule_cron_jobs()} and the single-event schedulers may only
		 * schedule hooks from this list, and {@see clear_cron_jobs()} /
		 * {@see Deactivate::unschedule_crons()} unschedule exactly this list (plus
		 * the legacy `wppo_img_conversation` misspelling kept for BC).
		 *
		 * Store ownership notes: `wppo_litespeed_crawler_batch` and
		 * `wppo_crawler_warm` are dual-scheduled (WP-Cron single events here AND
		 * Action Scheduler in the crawler bridge), so they are cleaned in both
		 * paths — here and in Deactivate::unschedule_action_scheduler_jobs().
		 * `wppo_generate_ccss` is Action Scheduler-first (as_enqueue_async_action
		 * in Critical_CSS) with a WP-Cron single-event fallback when the AS
		 * enqueue fails, so it is listed here too and cleaned in both paths.
		 *
		 * @since 2.0.0
		 * @var string[]
		 */
		public const SCHEDULED_HOOKS = array(
			'wppo_page_cron_hook',       // Recurring preload dispatcher (every_5_hours).
			'wppo_page_cron_batch',      // Single-event batch continuation.
			'wppo_generate_static_page', // Single-event per-post preload.
			'wppo_generate_static_url',  // Single-event per-URL preload (sitemap).
			'wppo_img_conversion',       // Hourly image conversion dispatcher.
			'wppo_database_cleanup_cron', // Daily DB cleanup.
			'wppo_web_vitals_rescan',    // Daily Web Vitals auto-rescan.
			'wppo_llms_txt_daily',       // Daily llms.txt regeneration.
			'wppo_used_css_cron',        // Recurring used-CSS regeneration (every_5_hours).
			'wppo_ccss_regeneration',    // Daily critical-CSS regeneration.
			'wppo_rum_flush',            // Single-event RUM queue flush (scheduled by RUM::queue()).
			'wppo_run_upgrades',         // Single-event upgrade routine (scheduled by Activate).
			'wppo_litespeed_crawler_batch', // Dual-scheduled: WP-Cron here + AS in the crawler bridge.
			'wppo_crawler_warm',         // Dual-scheduled: WP-Cron here + AS in the crawler bridge.
			'wppo_generate_ccss',        // Dual-scheduled: AS-first + WP-Cron fallback single event.
			'wppo_object_cache_probe',   // Recurring object-cache recovery probe (only while the circuit is open).
		);

		/**
		 * Action Scheduler hooks this plugin schedules via as_enqueue_async_action().
		 *
		 * Companion to SCHEDULED_HOOKS for store ownership: these live in the AS
		 * custom tables, not WP-Cron, so deactivation cleanup must use
		 * as_unschedule_all_actions() (see Deactivate::unschedule_action_scheduler_jobs()).
		 * Any future as_enqueue_async_action() site must be added here.
		 *
		 * @since 2.0.0
		 * @var string[]
		 */
		public const AS_HOOKS = array(
			'wppo_convert_image_background', // Image conversion (Img_Converter / REST).
			'wppo_pagespeed_scan',           // PageSpeed scans (Pagespeed).
			'wppo_used_css_generate',        // Used-CSS generation (Main / Used_CSS).
			'wppo_generate_ccss',            // Critical CSS generation (Critical_CSS — AS-only).
			'wppo_litespeed_crawler_batch',  // Dual-scheduled with SCHEDULED_HOOKS.
			'wppo_crawler_warm',             // Dual-scheduled with SCHEDULED_HOOKS.
			'wppo_google_fonts_download',    // Google Fonts CSS/font download (Google_Fonts — out-of-band).
		);

		/**
		 * Constructor.
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
			add_action( 'wppo_litespeed_crawler_batch', array( $this, 'litespeed_crawler_batch' ), 10, 1 );
			add_action( 'wppo_crawler_warm', array( $this, 'crawler_warm_single' ), 10, 1 );

			add_action( 'wppo_database_cleanup_cron', array( $this, 'database_cleanup_cron' ) );

			add_action( 'wppo_web_vitals_rescan', array( $this, 'web_vitals_rescan_cron' ) );

			add_action( 'wppo_llms_txt_daily', array( $this, 'llms_txt_cron' ) );

			add_action( 'wppo_used_css_cron', array( $this, 'used_css_cron' ) );
			add_action( 'wppo_ccss_regeneration', array( $this, 'ccss_regeneration_cron' ) );
			add_action( 'wppo_rum_flush', array( 'PerformanceOptimise\Inc\RUM', 'flush_queue' ) );
			add_action( 'wppo_object_cache_probe', array( $this, 'object_cache_probe_cron' ) );
		}

		/**
		 * Trigger the cache preload directly (not via cron hook).
		 *
		 * Wraps the private schedule_page_cron_jobs() for WP-CLI invocation.
		 *
		 * @since 2.0.0
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

			/**
			 * Filter the object-cache recovery-probe interval in seconds.
			 *
			 * @since 2.0.0
			 * @param int $interval Seconds between recovery probes while the circuit is open. Default HOUR_IN_SECONDS, minimum 300.
			 */
			$probe_interval                       = max( 300, (int) apply_filters( 'wppo_object_cache_probe_interval', HOUR_IN_SECONDS ) );
			$schedules['wppo_object_cache_probe'] = array(
				'interval' => $probe_interval,
				'display'  => __( 'Object Cache Recovery Probe', 'performance-optimisation' ),
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
			$options = Util::get_settings();

			// The preload toggle is the source of truth: when it is off, clear any
			// leftover per-page preload events instead of warming the cache anyway.
			if ( ! empty( $options['preload_settings']['enablePreloadCache'] ) ) {
				if ( ! wp_next_scheduled( 'wppo_page_cron_hook' ) ) {
					wp_schedule_event( time(), 'every_5_hours', 'wppo_page_cron_hook' );
				}
			} else {
				wp_clear_scheduled_hook( 'wppo_page_cron_hook' );
				wp_clear_scheduled_hook( 'wppo_page_cron_batch' );
				wp_clear_scheduled_hook( 'wppo_generate_static_page' );
				wp_clear_scheduled_hook( 'wppo_generate_static_url' );
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

			if ( ! empty( $options['llms_txt']['enabled'] ) ) {
				if ( ! wp_next_scheduled( 'wppo_llms_txt_daily' ) ) {
					wp_schedule_event( time(), 'daily', 'wppo_llms_txt_daily' );
				}
			} else {
				wp_clear_scheduled_hook( 'wppo_llms_txt_daily' );
			}

			if ( ! empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
				if ( ! wp_next_scheduled( 'wppo_used_css_cron' ) ) {
					wp_schedule_event( time(), 'every_5_hours', 'wppo_used_css_cron' );
				}
			}

			if ( ! wp_next_scheduled( 'wppo_ccss_regeneration' ) ) {
				wp_schedule_event( time(), 'daily', 'wppo_ccss_regeneration' );
			}

			// Object-cache recovery probe: scheduled only while the circuit
			// is open (drop-in parked after repeated Redis failures) and
			// cleared as soon as it closes, so healthy sites carry no extra
			// cron event.
			if ( $this->is_object_cache_circuit_open() ) {
				if ( ! wp_next_scheduled( 'wppo_object_cache_probe' ) ) {
					wp_schedule_event( time(), 'wppo_object_cache_probe', 'wppo_object_cache_probe' );
				}
			} else {
				wp_clear_scheduled_hook( 'wppo_object_cache_probe' );
			}
		}

		/**
		 * Whether the object-cache circuit breaker is currently open.
		 *
		 * Small wrapper so schedule_cron_jobs() (which runs on every init)
		 * degrades gracefully when the Object_Cache class is unavailable.
		 *
		 * @since 2.0.0
		 * @return bool True when the circuit is open.
		 */
		private function is_object_cache_circuit_open(): bool {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Object_Cache' ) ) {
				return false;
			}

			try {
				$manager = new Object_Cache();
				$state   = $manager->get_circuit_state();
				return ! empty( $state['open'] );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Callback for the object-cache recovery probe cron.
		 *
		 * Runs only while the circuit is open. Guarded by a 5-minute
		 * transient lock (blog-prefixed via Util::transient_key(), matching
		 * the existing lock pattern) so overlapping workers cannot ping in
		 * parallel. On success Object_Cache::probe_recovery() restores the
		 * drop-in and clears this schedule.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function object_cache_probe_cron(): void {
			if ( get_transient( Util::transient_key( 'wppo_object_cache_probe_lock' ) ) ) {
				return;
			}
			set_transient( Util::transient_key( 'wppo_object_cache_probe_lock' ), 1, 5 * MINUTE_IN_SECONDS );

			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Object_Cache' ) ) {
					return;
				}

				$manager = new Object_Cache();
				$manager->probe_recovery();
			} finally {
				delete_transient( Util::transient_key( 'wppo_object_cache_probe_lock' ) );
			}
		}

		/**
		 * Callback for LLMs.txt daily regeneration cron.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function llms_txt_cron(): void {
			$options = Util::get_settings();
			if ( empty( $options['llms_txt']['enabled'] ) ) {
				return;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\Llms' ) ) {
				Llms::generate();
			}
		}

		/**
		 * Callback for CCSS daily regeneration cron.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public function ccss_regeneration_cron() {
			$options = Util::get_settings();
			if ( ! empty( $options['file_optimisation']['criticalCSS'] ) ) {
				// Suspended while deferJS/delayJS is active: generated
				// variants could not be used (issue #1090). Belt-and-braces
				// alongside Critical_CSS::regenerate_all()'s own guard.
				if ( method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'is_deferral_suspended_by_js' ) && Critical_CSS::is_deferral_suspended_by_js() ) {
					return;
				}
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
		 * @since 2.0.0
		 */
		public function web_vitals_rescan_cron(): void {
			$options = Util::get_settings();
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

			$urls = array( Util::cached_home_url( '/' ) );

			if ( ! empty( $audit['high_value_urls'] ) && is_array( $audit['high_value_urls'] ) ) {
				foreach ( $audit['high_value_urls'] as $high_url ) {
					$clean = esc_url_raw( (string) $high_url );
					if ( ! empty( $clean ) && ! in_array( $clean, $urls, true ) ) {
						$urls[] = $clean;
					}
				}
			}

			$all_queued   = true;
			$newly_queued = 0;

			foreach ( $urls as $scan_url ) {
				foreach ( array( 'mobile', 'desktop' ) as $scan_strategy ) {
					// queue_scan() already deduplicates internally via
					// as_has_scheduled_action() + as_get_scheduled_actions()
					// (returning the existing job ID), so no outer pre-check
					// here — it would only double the Action Scheduler store
					// queries per URL x strategy. A non-zero return means
					// newly queued or already pending; 0 means enqueue failed
					// or deduped without a retrievable job ID.
					$job_id = Pagespeed::queue_scan( $scan_url, $scan_strategy );
					if ( 0 === $job_id ) {
						$all_queued = false;
					} else {
						++$newly_queued;
					}
				}
			}

			// Only record a completed run when at least one job is queued or
			// already pending and no enqueue failed. A 0 return means the
			// job could neither be queued nor confirmed pending, so the
			// timestamp must not advance — otherwise the rescan is marked
			// completed without scans actually being scheduled.
			if ( $all_queued && $newly_queued > 0 ) {
				update_option( 'wppo_web_vitals_last_rescan', time(), false );
			}
		}

		/**
		 * Triggers scheduling of the next batch of per-page static-generation jobs.
		 *
		 * @since 1.0.0
		 */
		public function wppo_page_cron_callback(): void {
			$options = Util::get_settings();
			if ( empty( $options['preload_settings']['enablePreloadCache'] ) ) {
				return;
			}
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
				// Cursor-based pagination: store last processed ID instead of OFFSET.
				global $wpdb;
				$last_id = (int) get_option( 'wppo_preload_cron_last_id', 0 );

				// One-time migration: old offset option is OFFSET-based and not convertible to ID cursor.
				// Convert offset to cursor via a single offset query (one-time cost) instead of restarting at 0
				// which would re-queue the first 200 IDs. Gated behind a one-time option to avoid duplicate warm-ups.
				$old_offset = (int) get_option( 'wppo_preload_cron_offset', 0 );
				if ( 0 === $last_id && 0 !== $old_offset && ! get_option( 'wppo_preload_cron_migrated', false ) ) {
					// Try to map old OFFSET to an ID cursor to resume without duplicating work.
					$post_types_for_migration = get_post_types( array( 'public' => true ), 'names' );
					$post_types_for_migration = array_unique( array_merge( array_values( array_diff( $post_types_for_migration, array( 'attachment' ) ) ), array( 'page', 'post' ) ) );
					if ( ! empty( $post_types_for_migration ) ) {
						$mig_placeholders = implode( ',', array_fill( 0, count( $post_types_for_migration ), '%s' ) );
						$mig_args         = array_values( $post_types_for_migration );
						$mig_args[]       = $old_offset;
						// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $mig_placeholders is count-derived.
						$mapped_id = $wpdb->get_var(
							// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $mig_placeholders is count-derived.
							$wpdb->prepare(
								"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($mig_placeholders) AND post_status = 'publish' ORDER BY ID ASC LIMIT 1 OFFSET %d",
								...$mig_args
							)
						);
						// phpcs:enable
						if ( null !== $mapped_id && '' !== $mapped_id ) {
							$last_id = (int) $mapped_id;
							update_option( 'wppo_preload_cron_last_id', $last_id, false );
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								error_log( 'WPPO: migrated preload cursor offset ' . $old_offset . ' -> ID ' . $last_id );
							}
						} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							error_log( 'WPPO: preload cursor migration reset offset ' . $old_offset . ' to 0 (no mapping found)' );
						}
					}
					update_option( 'wppo_preload_cron_migrated', 1, false );
					delete_option( 'wppo_preload_cron_offset' );
				} elseif ( 0 === $last_id && 0 !== $old_offset ) {
					delete_option( 'wppo_preload_cron_offset' );
				}

				$post_types = get_post_types( array( 'public' => true ), 'names' );
				$post_types = array_unique( array_merge( array_values( array_diff( $post_types, array( 'attachment' ) ) ), array( 'page', 'post' ) ) );

				if ( empty( $post_types ) ) {
					delete_option( 'wppo_preload_cron_last_id' );
					delete_option( 'wppo_preload_cron_offset' );
					return;
				}

				$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
				// Build args explicitly to avoid spread-variadic PHPCS concerns; $placeholders is count-derived only.
				$prepare_args   = array_values( $post_types );
				$prepare_args[] = $last_id;
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is count-derived.
				$query_batch_posts = $wpdb->get_col(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is count-derived.
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT 200",
						...$prepare_args
					)
				);
				// phpcs:enable

				if ( empty( $query_batch_posts ) ) {
					// Reset cursor on completion.
					delete_option( 'wppo_preload_cron_last_id' );
					delete_option( 'wppo_preload_cron_offset' );
					return; // Lock released in finally.
				}

				$options      = Util::get_settings();
				$preload      = $options['preload_settings'] ?? array();
				$exclude_urls = Util::process_urls( $preload['excludePreloadCache'] ?? array() );

				// Sitemap-aware preload: once per cycle (cursor 0), warm URLs that
				// live outside standard post queries (custom endpoints, archives).
				if ( 0 === $last_id && ! empty( $preload['preloadSitemap'] ) ) {
					$this->schedule_sitemap_url_jobs( $exclude_urls );
				}

				// Prime object-cache entries for the whole batch so the
				// per-ID get_permalink() calls below resolve from memory
				// instead of issuing one DB/cache round-trip each (audit
				// #874 finding 1). _prime_post_caches() is WP 6.1+; the
				// static memo in Util covers older cores and repeat lookups.
				if ( function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( array_map( 'intval', $query_batch_posts ), false, false );
				}

				// Snapshot the cron array once per batch so the per-ID check
				// below is an in-memory lookup instead of up to 200 full
				// cron-array scans (one wp_next_scheduled() per ID).
				$scheduled_pages = $this->get_scheduled_args_set( 'wppo_generate_static_page' );

				foreach ( $query_batch_posts as $page_id ) {
					$page_url = Util::memoized_permalink( (int) $page_id );

					// Unresolvable IDs (deleted mid-batch, filtered post types)
					// would otherwise consume a cron slot for nothing — mirror
					// the crawler's empty-permalink guard.
					if ( '' === $page_url ) {
						continue;
					}

					if ( Util::is_url_excluded( $page_url, $exclude_urls ) ) {
						continue;
					}

					if ( $this->is_hook_arg_scheduled( 'wppo_generate_static_page', array( $page_id ), $scheduled_pages ) ) {
						continue;
					}
					wp_schedule_single_event( time() + wp_rand( 0, 1800 ), 'wppo_generate_static_page', array( $page_id ) );
					if ( is_array( $scheduled_pages ) ) {
						$scheduled_pages[ wp_json_encode( array( $page_id ) ) ] = true;
					}
				}

				// Update cursor for the next batch.
				$max_id = (int) end( $query_batch_posts );
				if ( $max_id > $last_id ) {
					update_option( 'wppo_preload_cron_last_id', $max_id, false );
				}

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
		 * @since 2.0.0
		 * @param array $exclude_urls Exclusion rules (processed URLs/patterns).
		 * @return void
		 */
		private function schedule_sitemap_url_jobs( array $exclude_urls ): void {
			$sitemap_urls = $this->get_sitemap_urls( 500 );

			// Hoist the safe-mode toggle + Woo path list out of the per-URL
			// loop: get_woo_excluded_paths() resolves wc_get_page_id +
			// get_permalink per call, so resolve once per batch.
			$woo_safe  = true;
			$woo_paths = array();
			try {
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_safe_mode_enabled' ) ) {
					$woo_safe = Util::is_woo_safe_mode_enabled();
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'get_woo_excluded_paths' ) ) {
					$woo_paths = (array) Util::get_woo_excluded_paths();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// Snapshot scheduled URL args once so the per-URL check below is
			// an in-memory lookup instead of up to 500 cron-array scans.
			$scheduled_urls = $this->get_scheduled_args_set( 'wppo_generate_static_url' );

			foreach ( $sitemap_urls as $url ) {
				if ( Util::is_url_excluded( $url, $exclude_urls ) ) {
					continue;
				}

				// WooCommerce dynamic routes (issue #962): never preload cart /
				// checkout / account, custom Woo slugs, or Store API routes.
				if ( $this->is_woo_excluded_url( $url, $woo_safe, $woo_paths ) ) {
					continue;
				}

				// Editor/admin previews (issue #1097): never preload wp-admin or
				// builder/core preview URLs.
				if ( $this->is_editor_preview_url( $url ) ) {
					continue;
				}

				if ( $this->is_hook_arg_scheduled( 'wppo_generate_static_url', array( $url ), $scheduled_urls ) ) {
					continue;
				}
				wp_schedule_single_event( time() + wp_rand( 0, 1800 ), 'wppo_generate_static_url', array( $url ) );
				if ( is_array( $scheduled_urls ) ) {
					$scheduled_urls[ wp_json_encode( array( $url ) ) ] = true;
				}
			}
		}

		/**
		 * Whether a preload URL targets a WooCommerce dynamic route.
		 *
		 * Gated on `wooSafeMode` (absent = enabled); Store API routes are
		 * always skipped, mirroring Cache::is_woo_excluded(). Fail-open:
		 * detection failure skips the URL (never preload dynamic content).
		 *
		 * @since 2.0.0
		 * @param string        $url       Absolute URL.
		 * @param bool|null     $woo_safe  Optional pre-resolved safe-mode flag (hoisted by batch callers).
		 * @param string[]|null $woo_paths Optional pre-resolved Woo excluded paths (hoisted by batch callers).
		 * @return bool True when the URL must not be preloaded.
		 */
		private function is_woo_excluded_url( string $url, ?bool $woo_safe = null, ?array $woo_paths = null ): bool {
			try {
				$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
				$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
				// Plain-permalink Store API (?rest_route=/wc/store/...) is never
				// preloaded — unconditional on safe mode.
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_request' ) ) {
					if ( Util::is_woo_store_api_request( $path, $query, '' ) || Util::is_woo_store_api_request( $path, '', $this->get_rest_route_param( $url, $query ) ) ) {
						return true;
					}
				} elseif ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_path' ) && ( Util::is_woo_store_api_path( $path ) || Util::is_woo_store_api_path( $this->get_rest_route_param( $url, $query ) ) ) ) {
					return true;
				} elseif ( (bool) preg_match( '#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i', '/' . ltrim( $path, '/' ) ) || (bool) preg_match( '#rest_route=[^&]*(?:wc/store|wcstore)#i', rawurldecode( $query ) ) ) {
					return true;
				}
				if ( ! method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_safe_mode_enabled' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_dynamic_path' ) ) {
					// Fail-safe on mixed-version deploys: absent helper means
					// exclude (never preload potentially dynamic content).
					return true;
				}
				if ( null === $woo_safe ) {
					$woo_safe = Util::is_woo_safe_mode_enabled();
				}
				if ( ! $woo_safe ) {
					// Safe mode off: only the unconditional Store API skip applies.
					return false;
				}
				if ( null !== $woo_paths ) {
					$path_norm = strtolower( trim( $path, '/' ) );
					foreach ( $woo_paths as $excluded ) {
						$candidate = strtolower( trim( (string) $excluded, '/' ) );
						if ( '' === $candidate ) {
							continue;
						}
						if ( (bool) preg_match( '#/(?:' . preg_quote( $candidate, '#' ) . ')(/|$)#i', '/' . $path_norm ) ) {
							return true;
						}
					}
					return Util::is_woo_store_api_path( $path );
				}
				return Util::is_woo_dynamic_path( $path );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
			return false;
		}

		/**
		 * Whether a preload URL targets an admin or editor-preview context.
		 *
		 * Wraps `Util::is_editor_preview_url()` so wp-admin and builder/core
		 * preview URLs are never warmed (issue #1097). Fail-open: detection
		 * failure skips the URL (never preload dynamic content).
		 *
		 * @since NEXT
		 * @param string $url Absolute URL.
		 * @return bool True when the URL must not be preloaded.
		 */
		private function is_editor_preview_url( string $url ): bool {
			try {
				if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_editor_preview_url' ) ) {
					return Util::is_editor_preview_url( $url );
				}
				// Mixed-version fallback: inline the greppable preview signals.
				$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
				$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
				if ( (bool) preg_match( '#(^|/)(?:wp-admin|wp-login\.php|admin-ajax\.php)(/|$)#i', '/' . ltrim( $path, '/' ) ) ) {
					return true;
				}
				if ( '' !== $query && (bool) preg_match( '/(?:^|&)(?:elementor-preview|et_fb|et_pb_preview|vc_action|vc_editable|bricks|preview|preview_id|customize_changeset_uuid|customizer)(?:=|&|$)/i', $query ) ) {
					return true;
				}
				$params = array();
				if ( '' !== $query ) {
					parse_str( $query, $params );
				}
				foreach ( array( 'elementor-preview', 'et_fb', 'et_pb_preview', 'vc_action', 'vc_editable', 'bricks', 'preview', 'preview_id', 'customize_changeset_uuid', 'customizer' ) as $key ) {
					if ( isset( $params[ $key ] ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Snapshot scheduled args for a cron hook into an in-memory set.
		 *
		 * Reads the full cron array once via `_get_cron_array()` and indexes
		 * entries for `$hook` by JSON-encoded args. Returns null when the
		 * cron API is unavailable so callers fall back to wp_next_scheduled().
		 *
		 * @since 2.0.0
		 * @param string $hook Cron hook name.
		 * @return array<string, bool>|null Args set, or null on fallback.
		 */
		private function get_scheduled_args_set( string $hook ): ?array {
			try {
				if ( ! function_exists( '_get_cron_array' ) ) {
					return null;
				}
				$crons = _get_cron_array();
				if ( ! is_array( $crons ) || empty( $crons ) ) {
					return array();
				}
				$set = array();
				foreach ( $crons as $timestamp => $hooks ) {
					if ( ! is_array( $hooks ) || ! isset( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
						continue;
					}
					foreach ( $hooks[ $hook ] as $entry ) {
						$args = isset( $entry['args'] ) ? $entry['args'] : null;
						if ( function_exists( 'wp_json_encode' ) ) {
							$set[ wp_json_encode( $args ) ] = true;
						} else {
							$set[ (string) wp_json_encode( $args ) ] = true;
						}
					}
				}
				return $set;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Whether a hook+args event is already scheduled.
		 *
		 * Uses the in-memory snapshot when available; falls back to
		 * wp_next_scheduled() otherwise.
		 *
		 * @since 2.0.0
		 * @param string                   $hook Hook name.
		 * @param array                    $args Event args.
		 * @param array<string, bool>|null $scheduled In-memory snapshot (null = fallback).
		 * @return bool True when already scheduled.
		 */
		private function is_hook_arg_scheduled( string $hook, array $args, ?array $scheduled ): bool {
			if ( is_array( $scheduled ) ) {
				try {
					$key = function_exists( 'wp_json_encode' ) ? wp_json_encode( $args ) : (string) json_encode( $args ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback when wp_json_encode() unavailable.
					return isset( $scheduled[ (string) $key ] );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'wp_next_scheduled' ) ) {
				try {
					return (bool) wp_next_scheduled( $hook, $args );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return false;
		}

		/**
		 * Extract the `rest_route` query value from a preload URL.
		 *
		 * @since 2.0.0
		 * @param string $url   Absolute URL.
		 * @param string $query Pre-parsed query string.
		 * @return string The `rest_route` value or ''.
		 */
		private function get_rest_route_param( string $url, string $query ): string {
			if ( '' === $query ) {
				$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			}
			if ( '' === $query ) {
				return '';
			}
			parse_str( $query, $params );
			if ( isset( $params['rest_route'] ) && is_string( $params['rest_route'] ) ) {
				return $params['rest_route'];
			}
			return '';
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
				$options = Util::get_settings();
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
		 * Derives the unschedule list from {@see SCHEDULED_HOOKS} (single source
		 * of truth shared with schedule_cron_jobs()) so no plugin cron leaks on
		 * deactivate (audit #888 finding 9). `wp_unschedule_hook()` removes every
		 * event for a hook (recurring + single), unlike the single-event
		 * wp_unschedule_event() used previously in Deactivate.
		 *
		 * @return void
		 * @since 1.0.0
		 * @since 2.0.0 Derived from Cron::SCHEDULED_HOOKS; added wppo_img_conversion and wppo_database_cleanup_cron.
		 */
		public static function clear_cron_jobs(): void {
			// Canonical plugin hooks (recurring + single events).
			foreach ( self::SCHEDULED_HOOKS as $hook ) {
				wp_unschedule_hook( $hook );
			}

			// Legacy misspelled image-conversion hook kept for BC with older installs.
			wp_unschedule_hook( 'wppo_img_conversation' );

			// Preload bookkeeping options + locks.
			delete_option( 'wppo_preload_cron_offset' );
			delete_option( 'wppo_preload_cron_last_id' );
			delete_option( 'wppo_preload_cron_migrated' );
			delete_transient( Util::transient_key( 'wppo_preload_cron_lock' ) );
			delete_transient( Util::transient_key( 'wppo_used_css_lock' ) );
			delete_transient( Util::transient_key( 'wppo_object_cache_probe_lock' ) );
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
				// Defensive Woo skip (issue #962): never warm dynamic pages.
				// Defensive editor skip (issue #1097): never warm previews.
				try {
					if ( function_exists( 'get_permalink' ) ) {
						$permalink = get_permalink( $page_id );
						if ( is_string( $permalink ) && '' !== $permalink && ( $this->is_woo_excluded_url( $permalink ) || $this->is_editor_preview_url( $permalink ) ) ) {
							$this->mark_page_as_processed( $page_id );
							return;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
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
		 * @since 2.0.0
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
			if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
				return;
			}
			// Same-host re-check at the sink: a scheduled URL must never turn
			// into an off-host server-side GET.
			if ( function_exists( 'wp_parse_url' ) && function_exists( 'home_url' ) ) {
				$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
				if ( ( wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host ) {
					return;
				}
			}

			// Defensive Woo skip (issue #962): never warm dynamic routes.
			// Defensive editor skip (issue #1097): never warm previews.
			if ( $this->is_woo_excluded_url( $url ) || $this->is_editor_preview_url( $url ) ) {
				return;
			}

			// P4 lane: use crawler when on LiteSpeed.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) && class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::is_litespeed() ) {
				LiteSpeed_Crawler::crawl_single( $url );
				return;
			}

			// The same-host check above is the SSRF control. Do NOT also set
			// reject_unsafe_urls: WP's validator rejects every private,
			// loopback and non-dotted host, so cache warming would silently
			// stop on localhost/staging installs and on any site whose own
			// hostname resolves to a private address.
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 30,
				)
			);
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
		 * @since 2.0.0
		 * @param int $cap Maximum number of URLs to return.
		 * @return string[] List of absolute sitemap URLs.
		 */
		public function get_sitemap_urls( int $cap = 500 ): array {
			$urls       = array();
			$urls_count = 0;
			$home_host  = wp_parse_url( Util::cached_home_url(), PHP_URL_HOST );
			$to_fetch   = array( Util::cached_home_url( '/wp-sitemap.xml' ) );
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

				if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
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
						if ( isset( $fetched[ $loc ] ) || $to_fetch_count >= self::TO_FETCH_LIMIT ) {
							continue;
						}

						$to_fetch[] = $loc;
						++$to_fetch_count;
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
			// P4 lane: use LiteSpeed crawler variant matrix when available.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) && class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::is_litespeed() ) {
				LiteSpeed_Crawler::crawl_single( $permalink );
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
		 * Crawl batch via curl_multi variant matrix (P4).
		 *
		 * The event argument is either a batch ID string — the deferral stores
		 * the URL list in a transient so WP-Cron args stay small (audit #888
		 * finding 6) — or, for legacy/fallback events, the URL array itself.
		 *
		 * @since 2.0.0 Accepts batch-ID arguments and cleans up the transient.
		 * @param string|string[] $arg Batch ID or URL list.
		 * @return void
		 */
		public function litespeed_crawler_batch( $arg ): void {
			$urls = self::peek_crawler_batch_arg( $arg );
			if ( empty( $urls ) ) {
				// Unknown/missing batch ID — clean up any stale payload.
				if ( is_string( $arg ) && '' !== $arg ) {
					delete_transient( Util::transient_key( 'wppo_crawler_batch_' . $arg ) );
				}
				return;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {
				LiteSpeed_Crawler::crawl_batch( $urls );
			}

			// Delete only after the crawl ran so an error mid-crawl can be
			// retried by re-scheduling; the payload TTL remains the orphan
			// backstop (Part 2 review round 2).
			if ( is_string( $arg ) && '' !== $arg ) {
				delete_transient( Util::transient_key( 'wppo_crawler_batch_' . $arg ) );
			}
		}

		/**
		 * Resolve a deferred crawler batch argument into its URL list without
		 * deleting the payload.
		 *
		 * Batch-ID arguments are looked up in the blog-prefixed
		 * `wppo_crawler_batch_{id}` transient. URL-array arguments (legacy/
		 * fallback events) pass through unchanged. Deletion is the caller's
		 * responsibility (after a successful crawl).
		 *
		 * @param mixed $arg Batch ID or URL list.
		 * @return string[] URLs to crawl.
		 * @since 2.0.0
		 */
		private static function peek_crawler_batch_arg( $arg ): array {
			if ( is_string( $arg ) && '' !== $arg ) {
				$urls = get_transient( Util::transient_key( 'wppo_crawler_batch_' . $arg ) );
				return is_array( $urls ) ? array_values( array_filter( array_map( 'strval', $urls ) ) ) : array();
			}

			return is_array( $arg ) ? array_values( array_filter( array_map( 'strval', $arg ) ) ) : array();
		}

		/**
		 * Warm a single URL via crawler (post-publish lane).
		 *
		 * @since 2.0.0
		 * @param string $url URL.
		 * @return void
		 */
		public function crawler_warm_single( $url ): void {
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				return;
			}
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				return;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {
				LiteSpeed_Crawler::crawl_single( $url );
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
				$br_file_path   = "{$file_path}.br";

				if ( $wp_filesystem->exists( $file_path ) ) {
					$wp_filesystem->delete( $file_path );
				}

				if ( $wp_filesystem->exists( $gzip_file_path ) ) {
					$wp_filesystem->delete( $gzip_file_path );
				}

				if ( $wp_filesystem->exists( $br_file_path ) ) {
					$wp_filesystem->delete( $br_file_path );
				}

				// Remove logged-in role-variant copies (index-{hash}.html
				// plus compressed variants), mirroring
				// Cache::delete_role_variant_files().
				if ( $wp_filesystem->is_dir( $cache_dir ) ) {
					$files = $wp_filesystem->dirlist( $cache_dir );
					if ( is_array( $files ) ) {
						foreach ( $files as $file ) {
							if ( ! isset( $file['name'] ) || ! preg_match( '/^index-[a-f0-9]{12}\.html(\.gz|\.br)?$/', $file['name'] ) ) {
								continue;
							}
							$wp_filesystem->delete( trailingslashit( $cache_dir ) . $file['name'] );
						}
					}
				}
			}

			if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				Cache::bump_stats_cache();
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
				$options       = Util::get_settings();
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

				// Discover library images that predate plugin activation (or ran while
				// conversion was disabled) so they enter the queue instead of relying
				// on lazy frontend discovery. Bounded per run; newest attachments first.
				if ( ! empty( $formats_to_process ) ) {
					Img_Converter::queue_unconverted_library_images(
						$formats_to_process,
						(int) apply_filters( 'wppo_cron_discovery_limit', 50 )
					);

					// Re-read so this run also processes newly discovered items.
					$img_info = Img_Converter::get_img_info();
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
			$options  = Util::get_settings();
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
					// Record the run timestamp while still holding the lock
					// so two overlapping workers cannot both pass the
					// should_run check and double-execute the cleanup.
					update_option( 'wppo_last_db_cleanup', $now, false );
				} finally {
					delete_transient( Util::transient_key( 'wppo_db_cleanup_lock' ) );
				}
			}
		}
	}
}
