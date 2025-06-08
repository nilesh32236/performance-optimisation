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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron
 *
 * This class handles scheduling, managing, and processing cron jobs related to static page generation and image optimization.
 *
 * @since 1.0.0
 */
class Cron {

	/**
	 * Hook name for the main page preloading cron.
	 *
	 * @var string
	 */
	const PAGE_CRON_HOOK = 'wppo_page_cron_hook';

	/**
	 * Hook name for the image conversion cron.
	 *
	 * @var string
	 */
	const IMG_CRON_HOOK = 'wppo_img_conversation';

	/**
	 * Hook name for individual page generation.
	 *
	 * @var string
	 */
	const GENERATE_PAGE_HOOK = 'wppo_generate_static_page';

	/**
	 * Plugin options.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Constructor function.
	 *
	 * Registers WordPress actions and filters for cron jobs.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->options = get_option( 'wppo_settings', array() );

		add_action( 'init', array( $this, 'schedule_cron_jobs' ) );
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_interval' ) );

		add_action( self::PAGE_CRON_HOOK, array( $this, 'run_page_preloading_tasks' ) );
		add_action( self::IMG_CRON_HOOK, array( $this, 'run_image_conversion_tasks' ) );
		add_action( self::GENERATE_PAGE_HOOK, array( $this, 'process_single_page_for_preloading' ), 10, 1 );
	}

	/**
	 * Add a custom cron interval.
	 *
	 * Adds a custom cron schedule that runs every 5 hours.
	 *
	 * @since 1.0.0
	 * @param array<string,array<string,mixed>> $schedules Existing cron schedules.
	 * @return array<string,array<string,mixed>> Modified schedules.
	 */
	public function add_custom_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['every_5_hours'] ) ) {
			$schedules['every_5_hours'] = array(
				'interval' => 5 * HOUR_IN_SECONDS,
				'display'  => esc_html__( 'Every 5 Hours (Performance Optimise)', 'performance-optimisation' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the main cron jobs if enabled in settings.
	 *
	 * @since 1.0.0
	 */
	public function schedule_cron_jobs(): void {
		$enable_cron = $this->options['preload_settings']['enableCronJobs'] ?? true;

		if ( ! $enable_cron ) {
			self::clear_all_plugin_cron_jobs();
			return;
		}

		if ( ! empty( $this->options['preload_settings']['enablePreloadCache'] ) && ! wp_next_scheduled( self::PAGE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_5_hours', self::PAGE_CRON_HOOK );
		} elseif ( empty( $this->options['preload_settings']['enablePreloadCache'] ) && wp_next_scheduled( self::PAGE_CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::PAGE_CRON_HOOK ); // Unschedule if feature disabled.
		}

		if ( ! empty( $this->options['image_optimisation']['convertImg'] ) && ! wp_next_scheduled( self::IMG_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::IMG_CRON_HOOK );
		} elseif ( empty( $this->options['image_optimisation']['convertImg'] ) && wp_next_scheduled( self::IMG_CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::IMG_CRON_HOOK ); // Unschedule if feature disabled.
		}
	}

	/**
	 * Callback for the main page preloading cron job.
	 * Triggers the scheduling of individual page processing jobs.
	 *
	 * @since 1.0.0
	 */
	public function run_page_preloading_tasks(): void {
		if ( empty( $this->options['preload_settings']['enablePreloadCache'] ) ) {
			return;
		}
		$this->schedule_individual_page_cache_generation();
	}

	/**
	 * Schedule individual cron jobs for each page to generate its static cache.
	 *
	 * @since 1.0.0
	 */
	private function schedule_individual_page_cache_generation(): void {
		$page_ids = self::get_all_cacheable_post_ids();

		if ( empty( $page_ids ) ) {
			return;
		}

		$exclude_urls_patterns = array();
		if ( ! empty( $this->options['preload_settings']['excludePreloadCache'] ) ) {
			$exclude_urls_patterns = Util::process_urls( (string) $this->options['preload_settings']['excludePreloadCache'] );
		}

		$delay_interval = 5;
		$max_delay      = HOUR_IN_SECONDS / 2;

		foreach ( $page_ids as $page_id ) {
			$page_url = get_permalink( $page_id );
			if ( ! $page_url || is_wp_error( $page_url ) ) {
				continue;
			}
			$page_url = rtrim( $page_url, '/' );

			$should_exclude = false;
			foreach ( $exclude_urls_patterns as $pattern ) {
				$pattern = rtrim( $pattern, '/' );
				if ( 0 !== strpos( $pattern, 'http' ) ) {
					$pattern = home_url( $pattern ); // Assume relative to home_url.
					$pattern = rtrim( $pattern, '/' );
				}

				if ( str_ends_with( $pattern, '(.*)' ) ) {
					$base_pattern = rtrim( str_replace( '(.*)', '', $pattern ), '/' );
					if ( 0 === strpos( $page_url, $base_pattern ) ) {
						$should_exclude = true;
						break;
					}
				} elseif ( $page_url === $pattern ) {
					$should_exclude = true;
					break;
				}
			}

			if ( $should_exclude ) {
				continue;
			}

			if ( ! wp_next_scheduled( self::GENERATE_PAGE_HOOK, array( $page_id ) ) ) {
				wp_schedule_single_event( time() + $delay_interval, self::GENERATE_PAGE_HOOK, array( $page_id ) );
				$delay_interval += wp_rand( 5, 15 );
				if ( $delay_interval > $max_delay ) {
					$delay_interval = $max_delay;
				}
			}
		}
	}

	/**
	 * Clear all scheduled cron jobs related to this plugin.
	 *
	 * @since 1.0.0
	 */
	public static function clear_all_plugin_cron_jobs(): void {
		wp_clear_scheduled_hook( self::PAGE_CRON_HOOK );
		wp_clear_scheduled_hook( self::IMG_CRON_HOOK );

		// phpcs:ignore WordPress.PHP.DiscouragedFunctions.CronControl_get_cron_array
		$crons = _get_cron_array();
		if ( empty( $crons ) ) {
			return;
		}

		foreach ( $crons as $timestamp => $cron_hooks ) {
			if ( isset( $cron_hooks[ self::GENERATE_PAGE_HOOK ] ) ) {
				foreach ( $cron_hooks[ self::GENERATE_PAGE_HOOK ] as $hook_details ) {
					wp_unschedule_event( $timestamp, self::GENERATE_PAGE_HOOK, $hook_details['args'] );
				}
			}
		}
	}

	/**
	 * Process a specific page by invalidating its cache and triggering a visit to regenerate it.
	 *
	 * @since 1.0.0
	 * @param int $page_id The ID of the page to process.
	 */
	public function process_single_page_for_preloading( int $page_id ): void {
		if ( ! $page_id ) {
			return;
		}

		$permalink = get_permalink( $page_id );
		if ( ! $permalink || is_wp_error( $permalink ) ) {
			return;
		}
		$url_path = trim( wp_parse_url( $permalink, PHP_URL_PATH ), '/' );

		$cache_manager = new Cache();
		Cache::clear_cache( $url_path );

		wp_remote_get(
			$permalink,
			array(
				'timeout'   => 15,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // Handle self-signed certs in local dev.
			)
		);
	}

	/**
	 * Get a list of all public, cacheable post IDs.
	 *
	 * @since 1.0.0
	 * @return array<int> List of post IDs.
	 */
	private static function get_all_cacheable_post_ids(): array {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		$excluded_post_types = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'user_request' );
		$post_types          = array_diff( $post_types, $excluded_post_types );
		$post_types = array_unique( array_merge( array_values( $post_types ), array( 'page', 'post' ) ) );

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$post_ids = get_posts( $query_args );

		$front_page_id = get_option( 'page_on_front' );
		if ( $front_page_id && 'page' === get_post_type( (int) $front_page_id ) && ! in_array( (int) $front_page_id, $post_ids, true ) ) {
			array_unshift( $post_ids, (int) $front_page_id );
		}
		$posts_page_id = get_option( 'page_for_posts' );
		if ( $posts_page_id && 'page' === get_post_type( (int) $posts_page_id ) && ! in_array( (int) $posts_page_id, $post_ids, true ) ) {
			$post_ids[] = (int) $posts_page_id;
		}

		return array_unique( array_map( 'intval', $post_ids ) );
	}


	/**
	 * Callback for the image conversion cron.
	 * Processes pending images in batches.
	 *
	 * @since 1.0.0
	 */
	public function run_image_conversion_tasks(): void {
		if ( empty( $this->options['image_optimisation']['convertImg'] ) ) {
			return;
		}

		if ( ! class_exists( 'PerformanceOptimise\Inc\Img_Converter' ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/class-img-converter.php';
		}
		$img_converter = new Img_Converter( $this->options );
		$img_info      = get_option( 'wppo_img_info', array() );

		$conversion_format = $this->options['image_optimisation']['conversionFormat'] ?? 'webp'; // Default to webp.
		$batch_size        = isset( $this->options['image_optimisation']['batch'] ) ? absint( $this->options['image_optimisation']['batch'] ) : 50;
		if ( $batch_size <= 0 ) {
			$batch_size = 50;
		}

		$processed_count = 0;

		if ( in_array( $conversion_format, array( 'avif', 'both' ), true ) ) {
			$avif_pending_images = $img_info['pending']['avif'] ?? array();
			if ( ! empty( $avif_pending_images ) ) {
				foreach ( $avif_pending_images as $image_relative_path ) {
					if ( $processed_count >= $batch_size ) {
						break;
					}
					$full_image_path = wp_normalize_path( ABSPATH . ltrim( $image_relative_path, '/' ) );
					$img_converter->convert_image( $full_image_path, 'avif' );
					++$processed_count;
				}
			}
		}

		if ( $processed_count < $batch_size && in_array( $conversion_format, array( 'webp', 'both' ), true ) ) {
			$webp_pending_images = $img_info['pending']['webp'] ?? array();
			if ( ! empty( $webp_pending_images ) ) {
				foreach ( $webp_pending_images as $image_relative_path ) {
					if ( $processed_count >= $batch_size ) {
						break;
					}
					$full_image_path = wp_normalize_path( ABSPATH . ltrim( $image_relative_path, '/' ) );
					$img_converter->convert_image( $full_image_path, 'webp' ); // Default format for convert_image is webp.
					++$processed_count;
				}
			}
		}

		$img_info_after_batch = get_option( 'wppo_img_info', array() ); // Re-fetch.
		$has_more_pending     = ( ! empty( $img_info_after_batch['pending']['avif'] ) && in_array( $conversion_format, array( 'avif', 'both' ), true ) ) ||
								( ! empty( $img_info_after_batch['pending']['webp'] ) && in_array( $conversion_format, array( 'webp', 'both' ), true ) );

		if ( $has_more_pending && ! wp_next_scheduled( self::IMG_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::IMG_CRON_HOOK );
		} elseif ( ! $has_more_pending && ! wp_next_scheduled( self::IMG_CRON_HOOK ) && ! empty( $this->options['image_optimisation']['convertImg'] ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::IMG_CRON_HOOK );
		}
	}
}
