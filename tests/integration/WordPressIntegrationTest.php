<?php
/**
 * WordPress Integration Tests
 *
 * @package PerformanceOptimisation\Tests\Integration
 */

namespace PerformanceOptimisation\Tests\Integration;

use WP_UnitTestCase;

class WordPressIntegrationTest extends WP_UnitTestCase {

	public function test_plugin_activation(): void {
		// Test plugin can be activated without errors
		$this->assertTrue(is_plugin_active('performance-optimisation/performance-optimisation.php'));
	}

	public function test_database_tables_created(): void {
		global $wpdb;
		
		// Check if custom tables exist (if any)
		$tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}wppo_%'");
		$this->assertIsArray($tables);
	}

	public function test_options_created(): void {
		// Test that plugin options are created
		$settings = get_option('wppo_settings');
		$this->assertIsArray($settings);
	}

	public function test_hooks_registered(): void {
		// Test that WordPress hooks are properly registered
		$this->assertGreaterThan(0, has_action('init', 'wppo_init'));
		$this->assertGreaterThan(0, has_action('wp_enqueue_scripts'));
	}

	public function test_rest_api_endpoints(): void {
		// Test REST API endpoints are registered
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey('/performance-optimisation/v1/cache/clear', $routes);
		$this->assertArrayHasKey('/performance-optimisation/v1/images/optimize', $routes);
	}

	public function test_admin_menu_added(): void {
		// Test admin menu is added
		global $menu, $submenu;
		
		set_current_screen('dashboard');
		wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
		
		do_action('admin_menu');
		
		$found = false;
		foreach ($menu as $item) {
			if (strpos($item[2], 'performance-optimisation') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found);
	}

	public function test_cache_directory_creation(): void {
		// Test cache directories are created
		$upload_dir = wp_upload_dir();
		$cache_dir = $upload_dir['basedir'] . '/wppo-cache';
		
		// Trigger cache directory creation
		do_action('wppo_create_cache_dirs');
		
		$this->assertTrue(is_dir($cache_dir));
	}

	public function test_settings_validation(): void {
		// Test settings validation works
		$invalid_settings = [
			'cache' => ['enabled' => 'invalid_boolean'],
			'minification' => ['quality' => 150], // Invalid range
		];
		
		$result = apply_filters('wppo_validate_settings', $invalid_settings);
		$this->assertNotEquals($invalid_settings, $result);
	}
}
