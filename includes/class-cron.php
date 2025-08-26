<?php
/**
 * Cron Class for scheduling and managing cron jobs in the PerformanceOptimise plugin.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimisation\Services\CacheService;
use PerformanceOptimisation\Services\ImageService;
use PerformanceOptimisation\Services\SettingsService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron
 *
 * @since 1.0.0
 */
class Cron {

	const PAGE_CRON_HOOK = 'wppo_page_cron_hook';
	const IMG_CRON_HOOK = 'wppo_img_conversation';
	const GENERATE_PAGE_HOOK = 'wppo_generate_static_page';

	private CacheService $cacheService;
	private ImageService $imageService;
	private SettingsService $settingsService;

	public function __construct(
		CacheService $cacheService,
		ImageService $imageService,
		SettingsService $settingsService
	) {
		$this->cacheService    = $cacheService;
		$this->imageService    = $imageService;
		$this->settingsService = $settingsService;

		add_action( 'init', [ $this, 'schedule_cron_jobs' ] );
		add_filter( 'cron_schedules', [ $this, 'add_custom_cron_interval' ] );
		add_action( self::PAGE_CRON_HOOK, [ $this, 'run_page_preloading_tasks' ] );
		add_action( self::IMG_CRON_HOOK, [ $this, 'run_image_conversion_tasks' ] );
		add_action( self::GENERATE_PAGE_HOOK, [ $this, 'process_single_page_for_preloading' ], 10, 1 );
	}

	public function add_custom_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['every_5_hours'] ) ) {
			$schedules['every_5_hours'] = [
				'interval' => 5 * HOUR_IN_SECONDS,
				'display'  => esc_html__( 'Every 5 Hours (Performance Optimise)', 'performance-optimisation' ),
			];
		}
		return $schedules;
	}

	public function schedule_cron_jobs(): void {
		$settings = $this->settingsService->get_settings();

		if ( ! empty( $settings['preload_settings']['enablePreloadCache'] ) && ! wp_next_scheduled( self::PAGE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_5_hours', self::PAGE_CRON_HOOK );
		} elseif ( empty( $settings['preload_settings']['enablePreloadCache'] ) && wp_next_scheduled( self::PAGE_CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::PAGE_CRON_HOOK );
		}

		if ( ! empty( $settings['image_optimisation']['convertImg'] ) && ! wp_next_scheduled( self::IMG_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::IMG_CRON_HOOK );
		} elseif ( empty( $settings['image_optimisation']['convertImg'] ) && wp_next_scheduled( self::IMG_CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::IMG_CRON_HOOK );
		}
	}

	public function run_page_preloading_tasks(): void {
		$this->cacheService->warmUpCache();
	}

	public function process_single_page_for_preloading( int $page_id ): void {
		$permalink = get_permalink( $page_id );
		if ( ! $permalink || is_wp_error( $permalink ) ) {
			return;
		}

		$this->cacheService->invalidateCache( (string) $page_id );

		wp_remote_get(
			$permalink,
			[
				'timeout'   => 15,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			]
		);
	}

	public function run_image_conversion_tasks(): void {
		$pending_images = $this->imageService->get_pending_images( 10 );
		foreach ( $pending_images as $image ) {
			$this->imageService->convert_image( $image['path'], $image['format'] );
		}
	}
}
