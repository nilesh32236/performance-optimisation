<?php
/**
 * Heartbeat Control Service.
 *
 * @package PerformanceOptimisation\Services
 * @since 2.1.0
 */

namespace PerformanceOptimisation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HeartbeatService
 */
class HeartbeatService {

	/**
	 * Settings service.
	 *
	 * @var SettingsService
	 */
	private $settings_service;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings_service Settings service.
	 */
	public function __construct( SettingsService $settings_service ) {
		$this->settings_service = $settings_service;
	}

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		$settings = $this->settings_service->get_setting( 'heartbeat_control' );

		if ( empty( $settings ) || empty( $settings['enabled'] ) ) {
			return;
		}

		// Use init hook with priority 1 to dequeue heartbeat script early if needed
		add_action( 'init', array( $this, 'maybe_disable_heartbeat' ), 1 );
		add_filter( 'heartbeat_settings', array( $this, 'configure_heartbeat' ) );
	}

	/**
	 * Disable heartbeat script if frequency is set to 0 for current location.
	 * This runs early in the init hook to properly dequeue the script.
	 *
	 * @return void
	 */
	public function maybe_disable_heartbeat(): void {
		$control_settings = $this->settings_service->get_setting( 'heartbeat_control' );
		$location         = $this->get_current_location();

		// Get frequency for current location
		$frequency = 0; // Default to disable if not set
		if ( isset( $control_settings['locations'][ $location ] ) ) {
			$frequency = (int) $control_settings['locations'][ $location ];
		}

		// If frequency is 0, disable heartbeat for this location
		if ( $frequency === 0 && $location !== 'other' ) {
			// Dequeue heartbeat script early in init hook
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_heartbeat' ), 100 );
			add_action( 'admin_enqueue_scripts', array( $this, 'dequeue_heartbeat' ), 100 );
		}
	}

	/**
	 * Dequeue heartbeat script.
	 *
	 * @return void
	 */
	public function dequeue_heartbeat(): void {
		wp_dequeue_script( 'heartbeat' );
	}

	/**
	 * Configure Heartbeat settings (interval).
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array Modified settings.
	 */
	public function configure_heartbeat( array $settings ): array {
		$control_settings = $this->settings_service->get_setting( 'heartbeat_control' );
		$location         = $this->get_current_location();

		// Get frequency for current location
		$frequency = 60; // Default to 60 seconds if not set
		if ( isset( $control_settings['locations'][ $location ] ) ) {
			$frequency = (int) $control_settings['locations'][ $location ];
		}

		// Only modify interval if not disabled (0 is handled by maybe_disable_heartbeat)
		if ( $frequency > 0 ) {
			// Valid intervals: 15, 30, 60, 120, etc.
			$settings['interval'] = $frequency;
		}

		return $settings;
	}

	/**
	 * Get current location context.
	 *
	 * @return string 'dashboard', 'post_edit', 'frontend', or 'other'.
	 */
	private function get_current_location(): string {
		if ( is_admin() ) {
			$screen = get_current_screen();
			if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
				return 'post_edit';
			}

			if ( $screen && 'post' === $screen->base ) {
				return 'post_edit';
			}

			return 'dashboard';
		}

		return 'frontend';
	}
}
