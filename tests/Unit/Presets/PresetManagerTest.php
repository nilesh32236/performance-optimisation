<?php
/**
 * Tests for PresetManager class
 *
 * @package PerformanceOptimisation\Tests\Unit\Presets
 */

namespace PerformanceOptimisation\Tests\Unit\Presets;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Presets\PresetManager;

/**
 * Test case for PresetManager class.
 */
class PresetManagerTest extends TestCase {

	/**
	 * PresetManager instance.
	 *
	 * @var PresetManager
	 */
	private PresetManager $manager;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mockWordPressFunctions();
		$this->manager = new PresetManager();
	}

	/**
	 * Test getting all presets.
	 */
	public function test_get_presets(): void {
		$presets = $this->manager->get_presets();

		$this->assertIsArray( $presets );
		$this->assertArrayHasKey( 'safe', $presets );
		$this->assertArrayHasKey( 'recommended', $presets );
		$this->assertArrayHasKey( 'advanced', $presets );

		// Test preset structure
		foreach ( $presets as $preset ) {
			$this->assertArrayHasKey( 'id', $preset );
			$this->assertArrayHasKey( 'name', $preset );
			$this->assertArrayHasKey( 'description', $preset );
			$this->assertArrayHasKey( 'settings', $preset );
			$this->assertArrayHasKey( 'type', $preset );
		}
	}

	/**
	 * Test getting specific preset.
	 */
	public function test_get_preset(): void {
		$preset = $this->manager->get_preset( 'safe' );

		$this->assertIsArray( $preset );
		$this->assertEquals( 'safe', $preset['id'] );
		$this->assertEquals( 'Safe Mode', $preset['name'] );
		$this->assertArrayHasKey( 'settings', $preset );

		// Test non-existent preset
		$non_existent = $this->manager->get_preset( 'non_existent' );
		$this->assertNull( $non_existent );
	}

	/**
	 * Test getting preset settings.
	 */
	public function test_get_preset_settings(): void {
		$settings = $this->manager->get_preset_settings( 'safe' );

		$this->assertIsArray( $settings );
		$this->assertArrayHasKey( 'cache_settings', $settings );
		$this->assertArrayHasKey( 'image_optimisation', $settings );
		$this->assertArrayHasKey( 'file_optimisation', $settings );

		// Test non-existent preset
		$empty_settings = $this->manager->get_preset_settings( 'non_existent' );
		$this->assertEmpty( $empty_settings );
	}

	/**
	 * Test preset validation.
	 */
	public function test_validate_preset(): void {
		$valid_preset = array(
			'id'          => 'test_preset',
			'name'        => 'Test Preset',
			'description' => 'A test preset',
			'settings'    => array(
				'cache_settings' => array(
					'enablePageCaching' => true,
					'cacheExpiration'   => 3600,
				),
			),
		);

		$validation = $this->manager->validate_preset( $valid_preset );

		$this->assertTrue( $validation['valid'] );
		$this->assertEmpty( $validation['errors'] );
		$this->assertIsArray( $validation['warnings'] );

		// Test invalid preset
		$invalid_preset = array(
			'name' => 'Invalid Preset',
			// Missing required fields
		);

		$validation = $this->manager->validate_preset( $invalid_preset );

		$this->assertFalse( $validation['valid'] );
		$this->assertNotEmpty( $validation['errors'] );
	}

	/**
	 * Test creating custom preset.
	 */
	public function test_create_preset(): void {
		$preset_config = array(
			'id'          => 'custom_test',
			'name'        => 'Custom Test Preset',
			'description' => 'A custom test preset',
			'settings'    => array(
				'cache_settings' => array(
					'enablePageCaching' => true,
					'cacheExpiration'   => 7200,
				),
			),
		);

		$result = $this->manager->create_preset( $preset_config );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'custom_test', $result['preset_id'] );

		// Verify preset was added
		$created_preset = $this->manager->get_preset( 'custom_test' );
		$this->assertNotNull( $created_preset );
		$this->assertEquals( 'custom_test', $created_preset['id'] );
		$this->assertEquals( 'custom', $created_preset['type'] );
	}

	/**
	 * Test creating preset with validation errors.
	 */
	public function test_create_preset_with_errors(): void {
		$invalid_preset = array(
			'name' => 'Invalid Preset',
			// Missing required fields
		);

		$result = $this->manager->create_preset( $invalid_preset );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test updating custom preset.
	 */
	public function test_update_preset(): void {
		// First create a preset
		$preset_config = array(
			'id'          => 'update_test',
			'name'        => 'Update Test Preset',
			'description' => 'A preset for update testing',
			'settings'    => array(
				'cache_settings' => array(
					'enablePageCaching' => true,
				),
			),
		);

		$this->manager->create_preset( $preset_config );

		// Now update it
		$updated_config = array(
			'name'        => 'Updated Test Preset',
			'description' => 'An updated preset',
			'settings'    => array(
				'cache_settings' => array(
					'enablePageCaching' => false,
				),
			),
		);

		$result = $this->manager->update_preset( 'update_test', $updated_config );

		$this->assertTrue( $result['success'] );

		// Verify update
		$updated_preset = $this->manager->get_preset( 'update_test' );
		$this->assertEquals( 'Updated Test Preset', $updated_preset['name'] );
		$this->assertFalse( $updated_preset['settings']['cache_settings']['enablePageCaching'] );
	}

	/**
	 * Test updating non-existent preset.
	 */
	public function test_update_non_existent_preset(): void {
		$result = $this->manager->update_preset( 'non_existent', array() );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'Preset not found', $result['errors'] );
	}

	/**
	 * Test updating default preset (should fail).
	 */
	public function test_update_default_preset(): void {
		$result = $this->manager->update_preset( 'safe', array( 'name' => 'Modified Safe' ) );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'Cannot modify default presets', $result['errors'] );
	}

	/**
	 * Test deleting custom preset.
	 */
	public function test_delete_preset(): void {
		// First create a preset
		$preset_config = array(
			'id'          => 'delete_test',
			'name'        => 'Delete Test Preset',
			'description' => 'A preset for delete testing',
			'settings'    => array(),
		);

		$this->manager->create_preset( $preset_config );

		// Verify it exists
		$this->assertNotNull( $this->manager->get_preset( 'delete_test' ) );

		// Delete it
		$result = $this->manager->delete_preset( 'delete_test' );

		$this->assertTrue( $result['success'] );

		// Verify it's gone
		$this->assertNull( $this->manager->get_preset( 'delete_test' ) );
	}

	/**
	 * Test deleting default preset (should fail).
	 */
	public function test_delete_default_preset(): void {
		$result = $this->manager->delete_preset( 'safe' );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'Cannot delete default presets', $result['errors'] );
	}

	/**
	 * Test exporting preset.
	 */
	public function test_export_preset(): void {
		$result = $this->manager->export_preset( 'safe' );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'json', $result );

		$export_data = $result['data'];
		$this->assertArrayHasKey( 'preset', $export_data );
		$this->assertArrayHasKey( 'metadata', $export_data );

		// Verify JSON is valid
		$decoded = json_decode( $result['json'], true );
		$this->assertNotNull( $decoded );
		$this->assertEquals( $export_data, $decoded );
	}

	/**
	 * Test importing preset.
	 */
	public function test_import_preset(): void {
		$import_data = array(
			'preset'   => array(
				'id'          => 'imported_test',
				'name'        => 'Imported Test Preset',
				'description' => 'An imported preset',
				'settings'    => array(
					'cache_settings' => array(
						'enablePageCaching' => true,
					),
				),
			),
			'metadata' => array(
				'exported_at' => '2023-01-01 00:00:00',
			),
		);

		$result = $this->manager->import_preset( $import_data );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'imported_test', $result['preset_id'] );

		// Verify preset was imported
		$imported_preset = $this->manager->get_preset( 'imported_test' );
		$this->assertNotNull( $imported_preset );
		$this->assertEquals( 'Imported Test Preset', $imported_preset['name'] );
	}

	/**
	 * Test importing preset with ID conflict.
	 */
	public function test_import_preset_with_conflict(): void {
		$import_data = array(
			'preset' => array(
				'id'          => 'safe', // Conflicts with existing preset
				'name'        => 'Conflicting Preset',
				'description' => 'A preset with conflicting ID',
				'settings'    => array(),
			),
		);

		$result = $this->manager->import_preset( $import_data );

		$this->assertTrue( $result['success'] );
		$this->assertNotEquals( 'safe', $result['preset_id'] );
		$this->assertStringStartsWith( 'safe_', $result['preset_id'] );
	}

	/**
	 * Mock WordPress functions for testing.
	 */
	private function mockWordPressFunctions(): void {
		if ( ! function_exists( 'get_option' ) ) {
			function get_option( $option, $default = false ) {
				return $default;
			}
		}

		if ( ! function_exists( 'update_option' ) ) {
			function update_option( $option, $value ) {
				return true;
			}
		}

		if ( ! function_exists( 'current_time' ) ) {
			function current_time( $type ) {
				return '2023-01-01 00:00:00';
			}
		}

		if ( ! function_exists( 'get_current_user_id' ) ) {
			function get_current_user_id() {
				return 1;
			}
		}

		if ( ! function_exists( 'get_bloginfo' ) ) {
			function get_bloginfo( $show ) {
				return '6.2.0';
			}
		}

		if ( ! function_exists( 'wp_json_encode' ) ) {
			function wp_json_encode( $data, $options = 0 ) {
				return json_encode( $data, $options );
			}
		}

		if ( ! defined( 'WPPO_VERSION' ) ) {
			define( 'WPPO_VERSION', '1.0.0' );
		}
	}
}
