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

		add_filter( 'heartbeat_settings', array( $this, 'configure_heartbeat' ) );
	}

	/**
	 * Configure Heartbeat settings.
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array Modified settings.
	 */
	public function configure_heartbeat( array $settings ): array {
		$control_settings = $this->settings_service->get_setting( 'heartbeat_control' );
		$location         = $this->get_current_location();
		
		// Get frequency for current location
		$frequency = 0; // Default to disable if not set
		if ( isset( $control_settings['locations'][ $location ] ) ) {
			$frequency = (int) $control_settings['locations'][ $location ];
		}

		// 0 means disable
		if ( $frequency === 0 ) {
			// To disable, we can't just return empty array, we need to stop the script
			// But a cleaner way is to set interval to 60 (max) or use wp_deregister_script if we really want to kill it.
			// However, 'heartbeat_settings' filter is for the JS client.
			// If we want to disable, we should probably dequeue the script.
			// But let's stick to modifying interval first.
			// Actually, returning empty array doesn't disable it.
			// Let's handle disabling via script dequeue in a separate hook if needed.
			// For now, let's assume 0 means "suspend" which isn't natively supported by this filter alone easily without client side manipulation.
			// Standard practice: if 0, we can try to set a very long interval or use 'heartbeat_nopriv_send' / 'heartbeat_received' to block.
			
			// Better approach for "Disable": Dequeue the script.
			if ( $location !== 'other' ) { // Don't disable blindly everywhere
				wp_deregister_script( 'heartbeat' );
				return $settings;
			}
		}

		// Valid intervals: 15, 30, 60, 120, etc.
		// WP default is 15 for post edit, 60 for others.
		// We can force it.
		$settings['interval'] = $frequency;

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
