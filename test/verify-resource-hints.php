<?php
/**
 * Verification script for Resource Hints Functionality
 *
 * Tests the logic in ResourceHintsService.
 */

namespace PerformanceOptimisation\Services {
	class SettingsService {
		private $settings = array();
		public function get_setting( $group, $key, $default = null ) {
			return $this->settings[ $group ][ $key ] ?? $default;
		}
		public function set_mock_setting( $group, $key, $value ) {
			$this->settings[ $group ][ $key ] = $value;
		}
	}
}

namespace {
	// Mock WordPress functions
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp' ); }
	function esc_url( $url ) {
		return $url; }
	function esc_attr( $attr ) {
		return $attr; }
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}

	// Include ResourceHintsService
	require_once __DIR__ . '/includes/Services/ResourceHintsService.php';

	use PerformanceOptimisation\Services\ResourceHintsService;
	use PerformanceOptimisation\Services\SettingsService;

	// Setup
	$settings = new SettingsService();
	$service  = new ResourceHintsService( $settings );

	echo "Starting Resource Hints Verification...\n\n";

	// Test 1: DNS Prefetch
	echo "Test 1: DNS Prefetch\n";
	$settings->set_mock_setting( 'preloading', 'dns_prefetch', array( 'example.com' ) );
	ob_start();
	$reflection = new ReflectionClass( $service );
	$method     = $reflection->getMethod( 'add_dns_prefetch' );
	$method->setAccessible( true );
	$method->invoke( $service );
	$output = ob_get_clean();

	if ( strpos( $output, '<link rel="dns-prefetch" href="//example.com">' ) !== false ) {
		echo "[PASS] DNS Prefetch tag generated correctly\n";
	} else {
		echo "[FAIL] DNS Prefetch tag generation failed. Got: $output\n";
	}

	// Test 2: Preconnect
	echo "\nTest 2: Preconnect\n";
	$settings->set_mock_setting( 'preloading', 'preconnect', array( 'https://cdn.example.com' ) );
	ob_start();
	$method = $reflection->getMethod( 'add_preconnect' );
	$method->setAccessible( true );
	$method->invoke( $service );
	$output = ob_get_clean();

	if ( strpos( $output, '<link rel="preconnect" href="https://cdn.example.com" crossorigin>' ) !== false ) {
		echo "[PASS] Preconnect tag generated correctly\n";
	} else {
		echo "[FAIL] Preconnect tag generation failed. Got: $output\n";
	}

	// Test 3: Preload Images
	echo "\nTest 3: Preload Images\n";
	$settings->set_mock_setting( 'preloading', 'preload_images', array( 'https://example.com/image.jpg' ) );
	ob_start();
	$method = $reflection->getMethod( 'add_preload_images' );
	$method->setAccessible( true );
	$method->invoke( $service );
	$output = ob_get_clean();

	if ( strpos( $output, '<link rel="preload" href="https://example.com/image.jpg" as="image">' ) !== false ) {
		echo "[PASS] Preload Image tag generated correctly\n";
	} else {
		echo "[FAIL] Preload Image tag generation failed. Got: $output\n";
	}

	echo "\nVerification Completed.\n";
}
