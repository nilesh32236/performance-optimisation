<?php
/**
 * ConfigManager Unit Tests
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Config\ConfigManager;
use PerformanceOptimisation\Exceptions\ConfigurationException;

/**
 * Test cases for the ConfigManager class
 *
 * @since 1.1.0
 */
class ConfigManagerTest extends TestCase {

	/**
	 * ConfigManager instance
	 *
	 * @var ConfigManager
	 */
	private ConfigManager $config_manager;

	/**
	 * Set up test environment
	 *
	 * @since 1.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config_manager = new ConfigManager();
	}

	/**
	 * Test getting default configuration values
	 *
	 * @since 1.1.0
	 */
	public function test_get_default_values(): void {
		$defaults = $this->config_manager->get_defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'caching', $defaults );
		$this->assertArrayHasKey( 'minification', $defaults );
		$this->assertArrayHasKey( 'images', $defaults );
	}

	/**
	 * Test getting configuration value with dot notation
	 *
	 * @since 1.1.0
	 */
	public function test_get_with_dot_notation(): void {
		$value = $this->config_manager->get( 'caching.page_cache_enabled' );
		$this->assertIsBool( $value );
		$this->assertFalse( $value ); // Default should be false
	}

	/**
	 * Test setting configuration value with dot notation
	 *
	 * @since 1.1.0
	 */
	public function test_set_with_dot_notation(): void {
		$this->config_manager->set( 'caching.page_cache_enabled', true );
		$value = $this->config_manager->get( 'caching.page_cache_enabled' );
		$this->assertTrue( $value );
	}

	/**
	 * Test has method with dot notation
	 *
	 * @since 1.1.0
	 */
	public function test_has_with_dot_notation(): void {
		$this->assertTrue( $this->config_manager->has( 'caching.page_cache_enabled' ) );
		$this->assertFalse( $this->config_manager->has( 'non_existent.key' ) );
	}

	/**
	 * Test remove method with dot notation
	 *
	 * @since 1.1.0
	 */
	public function test_remove_with_dot_notation(): void {
		$this->config_manager->set( 'test.key', 'value' );
		$this->assertTrue( $this->config_manager->has( 'test.key' ) );

		$this->config_manager->remove( 'test.key' );
		$this->assertFalse( $this->config_manager->has( 'test.key' ) );
	}

	/**
	 * Test getting all configuration values
	 *
	 * @since 1.1.0
	 */
	public function test_get_all(): void {
		$all_config = $this->config_manager->all();
		$this->assertIsArray( $all_config );
		$this->assertArrayHasKey( 'caching', $all_config );
	}

	/**
	 * Test reset to defaults
	 *
	 * @since 1.1.0
	 */
	public function test_reset(): void {
		$this->config_manager->set( 'caching.page_cache_enabled', true );
		$this->assertTrue( $this->config_manager->get( 'caching.page_cache_enabled' ) );

		$this->config_manager->reset();
		$this->assertFalse( $this->config_manager->get( 'caching.page_cache_enabled' ) );
	}

	/**
	 * Test validation with valid caching config
	 *
	 * @since 1.1.0
	 */
	public function test_validate_valid_caching_config(): void {
		$config = array(
			'caching' => array(
				'page_cache_enabled' => true,
				'cache_ttl'          => 1800,
			),
		);

		$validated = $this->config_manager->validate( $config );
		$this->assertIsArray( $validated );
		$this->assertTrue( $validated['caching']['page_cache_enabled'] );
		$this->assertEquals( 1800, $validated['caching']['cache_ttl'] );
	}

	/**
	 * Test validation with invalid cache TTL
	 *
	 * @since 1.1.0
	 */
	public function test_validate_invalid_cache_ttl(): void {
		$config = array(
			'caching' => array(
				'cache_ttl' => 30, // Too low
			),
		);

		$this->expectException( ConfigurationException::class );
		$this->config_manager->validate( $config );
	}

	/**
	 * Test validation with valid image config
	 *
	 * @since 1.1.0
	 */
	public function test_validate_valid_image_config(): void {
		$config = array(
			'images' => array(
				'compression_quality' => 90,
				'max_image_width'     => 1920,
			),
		);

		$validated = $this->config_manager->validate( $config );
		$this->assertEquals( 90, $validated['images']['compression_quality'] );
		$this->assertEquals( 1920, $validated['images']['max_image_width'] );
	}

	/**
	 * Test validation with invalid compression quality
	 *
	 * @since 1.1.0
	 */
	public function test_validate_invalid_compression_quality(): void {
		$config = array(
			'images' => array(
				'compression_quality' => 150, // Too high
			),
		);

		$this->expectException( ConfigurationException::class );
		$this->config_manager->validate( $config );
	}
}
