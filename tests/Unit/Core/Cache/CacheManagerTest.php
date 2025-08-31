<?php
/**
 * CacheManager Unit Tests
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Tests\Unit\Core\Cache;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Cache\CacheManager;
use PerformanceOptimisation\Core\Config\ConfigManager;
use PerformanceOptimisation\Interfaces\CacheInterface;
use PerformanceOptimisation\Exceptions\CacheException;

/**
 * Test cases for the CacheManager class
 *
 * @since 1.1.0
 */
class CacheManagerTest extends TestCase {

	/**
	 * CacheManager instance
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache_manager;

	/**
	 * Mock config manager
	 *
	 * @var ConfigManager
	 */
	private ConfigManager $config;

	/**
	 * Set up test environment
	 *
	 * @since 1.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress functions
		if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
			function wp_using_ext_object_cache() {
				return false;
			}
		}

		$this->config        = new ConfigManager();
		$this->cache_manager = new CacheManager( $this->config );
	}

	/**
	 * Test registering a cache provider
	 *
	 * @since 1.1.0
	 */
	public function test_register_provider(): void {
		$mock_provider = $this->createMock( CacheInterface::class );
		$this->cache_manager->register_provider( 'test', $mock_provider );

		$provider = $this->cache_manager->get_provider( 'test' );
		$this->assertSame( $mock_provider, $provider );
	}

	/**
	 * Test getting non-existent provider throws exception
	 *
	 * @since 1.1.0
	 */
	public function test_get_non_existent_provider_throws_exception(): void {
		$this->expectException( CacheException::class );
		$this->cache_manager->get_provider( 'non_existent' );
	}

	/**
	 * Test setting default provider
	 *
	 * @since 1.1.0
	 */
	public function test_set_default_provider(): void {
		$mock_provider = $this->createMock( CacheInterface::class );
		$this->cache_manager->register_provider( 'test', $mock_provider );
		$this->cache_manager->set_default_provider( 'test' );

		$default_provider = $this->cache_manager->get_provider();
		$this->assertSame( $mock_provider, $default_provider );
	}

	/**
	 * Test setting non-existent default provider throws exception
	 *
	 * @since 1.1.0
	 */
	public function test_set_non_existent_default_provider_throws_exception(): void {
		$this->expectException( CacheException::class );
		$this->cache_manager->set_default_provider( 'non_existent' );
	}

	/**
	 * Test cache operations
	 *
	 * @since 1.1.0
	 */
	public function test_cache_operations(): void {
		$mock_provider = $this->createMock( CacheInterface::class );

		// Set up mock expectations
		$mock_provider->expects( $this->once() )
			->method( 'set' )
			->with( 'test_key', 'test_value', 3600 )
			->willReturn( true );

		$mock_provider->expects( $this->once() )
			->method( 'get' )
			->with( 'test_key', null )
			->willReturn( 'test_value' );

		$mock_provider->expects( $this->once() )
			->method( 'has' )
			->with( 'test_key' )
			->willReturn( true );

		$mock_provider->expects( $this->once() )
			->method( 'delete' )
			->with( 'test_key' )
			->willReturn( true );

		$this->cache_manager->register_provider( 'test', $mock_provider );

		// Test operations
		$this->assertTrue( $this->cache_manager->set( 'test_key', 'test_value', 3600, 'test' ) );
		$this->assertEquals( 'test_value', $this->cache_manager->get( 'test_key', null, 'test' ) );
		$this->assertTrue( $this->cache_manager->has( 'test_key', 'test' ) );
		$this->assertTrue( $this->cache_manager->delete( 'test_key', 'test' ) );
	}

	/**
	 * Test cache flush
	 *
	 * @since 1.1.0
	 */
	public function test_cache_flush(): void {
		$mock_provider = $this->createMock( CacheInterface::class );
		$mock_provider->expects( $this->once() )
			->method( 'flush' )
			->willReturn( true );

		$this->cache_manager->register_provider( 'test', $mock_provider );
		$this->assertTrue( $this->cache_manager->flush( 'test' ) );
	}

	/**
	 * Test cache warming
	 *
	 * @since 1.1.0
	 */
	public function test_cache_warming(): void {
		$mock_provider = $this->createMock( CacheInterface::class );
		$mock_provider->expects( $this->once() )
			->method( 'set_multiple' )
			->with(
				array(
					'key1' => 'value1',
					'key2' => 'value2',
				),
				3600
			)
			->willReturn( true );

		$this->cache_manager->register_provider( 'test', $mock_provider );
		$data = array(
			'key1' => 'value1',
			'key2' => 'value2',
		);
		$this->assertTrue( $this->cache_manager->warm( $data, 3600, 'test' ) );
	}

	/**
	 * Test getting available providers
	 *
	 * @since 1.1.0
	 */
	public function test_get_available_providers(): void {
		$providers = $this->cache_manager->get_available_providers();
		$this->assertIsArray( $providers );
		$this->assertContains( 'file', $providers );
	}

	/**
	 * Test cache statistics
	 *
	 * @since 1.1.0
	 */
	public function test_get_stats(): void {
		$stats = $this->cache_manager->get_stats();
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'global', $stats );
		$this->assertArrayHasKey( 'providers', $stats );
	}

	/**
	 * Test is enabled method
	 *
	 * @since 1.1.0
	 */
	public function test_is_enabled(): void {
		$this->assertIsBool( $this->cache_manager->is_enabled() );
	}
}
