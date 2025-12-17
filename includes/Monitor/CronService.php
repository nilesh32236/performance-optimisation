<?php
/**
 * Cron Service for Performance Optimisation.
 *
 * Handles scheduled performance monitoring tasks.
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PerformanceOptimisation\Monitor;

/**
 * Class CronService
 *
 * Manages WP-Cron hooks for scheduled performance checks.
 *
 * @since 2.0.0
 */
class CronService {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'wppo_performance_check';

	/**
	 * Default schedule interval (every 6 hours).
	 */
	const DEFAULT_INTERVAL = 'wppo_six_hours';

	/**
	 * PageSpeed service instance.
	 *
	 * @var PageSpeedService
	 */
	private PageSpeedService $pagespeed_service;

	/**
	 * Asset analyzer instance.
	 *
	 * @var AssetAnalyzer
	 */
	private AssetAnalyzer $asset_analyzer;

	/**
	 * Metrics storage instance.
	 *
	 * @var MetricsStorage
	 */
	private MetricsStorage $metrics_storage;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pagespeed_service = new PageSpeedService();
		$this->asset_analyzer    = new AssetAnalyzer();
		$this->metrics_storage   = new MetricsStorage();
	}

	/**
	 * Initialize the cron service.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_performance_check' ) );

		// Create database table on init.
		$this->metrics_storage->create_table();
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( array $schedules ): array {
		$schedules['wppo_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours', 'performance-optimisation' ),
		);

		$schedules['wppo_twelve_hours'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 12 Hours', 'performance-optimisation' ),
		);

		$schedules['wppo_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Daily', 'performance-optimisation' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the performance check cron job.
	 *
	 * @param string $recurrence Cron recurrence (e.g., 'wppo_six_hours').
	 *
	 * @return bool Whether the event was scheduled.
	 */
	public function schedule( string $recurrence = '' ): bool {
		if ( empty( $recurrence ) ) {
			$recurrence = self::DEFAULT_INTERVAL;
		}

		// Clear any existing schedule.
		$this->unschedule();

		$scheduled = wp_schedule_event( time(), $recurrence, self::CRON_HOOK );

		return false !== $scheduled;
	}

	/**
	 * Unschedule the performance check cron job.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Check if the cron job is scheduled.
	 *
	 * @return bool Whether the job is scheduled.
	 */
	public function is_scheduled(): bool {
		return false !== wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Get the next scheduled run time.
	 *
	 * @return int|false Timestamp of next run, or false if not scheduled.
	 */
	public function get_next_run() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Run the performance check.
	 *
	 * @return array Results of the check.
	 */
	public function run_performance_check(): array {
		$results = array();
		$url     = home_url( '/' );

		// Check for both mobile and desktop.
		foreach ( array( 'mobile', 'desktop' ) as $device ) {
			$strategy = ( 'mobile' === $device ) ? 'mobile' : 'desktop';

			// Get PageSpeed data.
			$pagespeed_data = $this->pagespeed_service->get_pagespeed_data(
				$url,
				$strategy,
				false
			);

			// Get asset data.
			$asset_data = $this->asset_analyzer->analyze( $url, false );

			// Prepare metrics for storage.
			$metrics = array(
				'url'                  => $url,
				'device'               => $device,
				'performance_score'    => $pagespeed_data['scores']['performance'] ?? null,
				'accessibility_score'  => $pagespeed_data['scores']['accessibility'] ?? null,
				'best_practices_score' => $pagespeed_data['scores']['best_practices'] ?? null,
				'seo_score'            => $pagespeed_data['scores']['seo'] ?? null,
				'fcp_ms'               => $this->extract_ms( $pagespeed_data, 'fcp' ),
				'lcp_ms'               => $this->extract_ms( $pagespeed_data, 'lcp' ),
				'cls_value'            => $pagespeed_data['metrics']['cls']['value'] ?? null,
				'tbt_ms'               => $this->extract_ms( $pagespeed_data, 'tbt' ),
				'speed_index_ms'       => $this->extract_ms( $pagespeed_data, 'si' ),
				'total_assets'         => $asset_data['summary']['total_assets'] ?? null,
				'total_size'           => $asset_data['summary']['total_size'] ?? null,
			);

			// Store the metrics.
			$insert_id = $this->metrics_storage->store( $metrics );

			$results[ $device ] = array(
				'success'   => false !== $insert_id,
				'insert_id' => $insert_id,
				'scores'    => $pagespeed_data['scores'] ?? array(),
			);
		}

		// Store last run time.
		update_option( 'wppo_last_performance_check', time() );

		return $results;
	}

	/**
	 * Extract milliseconds value from PageSpeed data.
	 *
	 * @param array  $data  PageSpeed data.
	 * @param string $metric Metric key.
	 *
	 * @return int|null Milliseconds value or null.
	 */
	private function extract_ms( array $data, string $metric ): ?int {
		if ( ! isset( $data['metrics'][ $metric ]['value'] ) ) {
			return null;
		}

		$value = $data['metrics'][ $metric ]['value'];

		// If already numeric, return as int.
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		// Parse string format (e.g., "2.4 s" or "180 ms").
		$value = strtolower( (string) $value );

		if ( strpos( $value, 'ms' ) !== false ) {
			return (int) str_replace( 'ms', '', $value );
		}

		if ( strpos( $value, 's' ) !== false ) {
			return (int) ( floatval( str_replace( 's', '', $value ) ) * 1000 );
		}

		return null;
	}

	/**
	 * Get the monitoring status.
	 *
	 * @return array Monitoring status info.
	 */
	public function get_status(): array {
		$next_run = $this->get_next_run();
		$last_run = get_option( 'wppo_last_performance_check', 0 );

		return array(
			'is_scheduled' => $this->is_scheduled(),
			'next_run'     => $next_run ? gmdate( 'Y-m-d H:i:s', (int) $next_run ) : null,
			'last_run'     => $last_run ? gmdate( 'Y-m-d H:i:s', (int) $last_run ) : null,
			'records'      => $this->get_record_count(),
		);
	}

	/**
	 * Get total record count.
	 *
	 * @return int Number of stored records.
	 */
	private function get_record_count(): int {
		global $wpdb;
		$table_name = $this->metrics_storage->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		return (int) $count;
	}
}
