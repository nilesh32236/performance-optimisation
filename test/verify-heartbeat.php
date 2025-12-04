<?php
/**
 * Verification script for Heartbeat Control.
 *
 * Usage: php verify-heartbeat.php
 */

namespace PerformanceOptimisation\Services {
	class SettingsService {
		private $settings;

		public function __construct( $settings ) {
			$this->settings = $settings;
		}

		public function get_setting( $group ) {
			return $this->settings[ $group ] ?? array();
		}
	}
}

namespace {
	use PerformanceOptimisation\Services\HeartbeatService;
	use PerformanceOptimisation\Services\SettingsService;

	// Define constants
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/srv/http/awm/' );
	}
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
	}
	if ( ! defined( 'WPPO_PLUGIN_DIR' ) ) {
		define( 'WPPO_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins/performance-optimisation/' );
	}

	// Mock WordPress functions
	function add_filter( $hook, $callback ) {
		global $filters;
		$filters[ $hook ]   = $filters[ $hook ] ?? array();
		$filters[ $hook ][] = $callback;
	}

	function is_admin() {
		return true; // Simulate admin
	}

	function get_current_screen() {
		$screen                  = new stdClass();
		$screen->base            = 'dashboard';
		$screen->is_block_editor = function () {
			return false;
		};
		return $screen;
	}

	function wp_deregister_script( $handle ) {
		echo "[MOCK] Deregistered script: $handle\n";
	}

	// Include the service
	require_once WPPO_PLUGIN_DIR . 'includes/Services/HeartbeatService.php';

	echo "Starting Heartbeat Control Verification...\n\n";

	// Test Case 1: Heartbeat Disabled
	echo "Test 1: Heartbeat Disabled (Global)\n";
	$settings_disabled = array(
		'heartbeat_control' => array(
			'enabled' => false,
		),
	);
	$service           = new HeartbeatService( new SettingsService( $settings_disabled ) );
	$service->init();
	global $filters;
	if ( empty( $filters['heartbeat_settings'] ) ) {
		echo "[PASS] Hook not registered when disabled.\n";
	} else {
		echo "[FAIL] Hook registered when disabled.\n";
	}

	// Test Case 2: Heartbeat Enabled, Dashboard 60s
	echo "\nTest 2: Heartbeat Enabled (Dashboard 60s)\n";
	$settings_enabled = array(
		'heartbeat_control' => array(
			'enabled'   => true,
			'locations' => array(
				'dashboard' => 60,
			),
		),
	);
	$service          = new HeartbeatService( new SettingsService( $settings_enabled ) );
	$service->init(); // Registers hook

	// Simulate hook execution
	$callback          = $filters['heartbeat_settings'][0];
	$modified_settings = $service->configure_heartbeat( array() );

	if ( isset( $modified_settings['interval'] ) && $modified_settings['interval'] === 60 ) {
		echo "[PASS] Interval set to 60s for dashboard.\n";
	} else {
		echo '[FAIL] Interval not set correctly. Got: ' . print_r( $modified_settings, true ) . "\n";
	}

	// Test Case 3: Heartbeat Disabled for Dashboard (0s)
	echo "\nTest 3: Heartbeat Disabled for Dashboard (0s)\n";
	$settings_zero = array(
		'heartbeat_control' => array(
			'enabled'   => true,
			'locations' => array(
				'dashboard' => 0,
			),
		),
	);
	$service       = new HeartbeatService( new SettingsService( $settings_zero ) );
	// We expect wp_deregister_script to be called
	ob_start();
	$service->configure_heartbeat( array() );
	$output = ob_get_clean();

	if ( strpos( $output, 'Deregistered script: heartbeat' ) !== false ) {
		echo "[PASS] Script deregistered for 0s interval.\n";
	} else {
		echo "[FAIL] Script not deregistered.\n";
	}

	echo "\nVerification Completed.\n";
}
