<?php
/**
 * Tests for Cache class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * Tests for Cache class.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that constructor sets domain from server variables.
	 */
	public function test_constructor_sets_domain_from_server(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/test-page/';
		Functions\stubs(
			array(
				'wp_normalize_path',
				'wp_parse_url',
				'sanitize_text_field',
				'wp_unslash',
				'get_option',
				'trailingslashit',
				'idn_to_ascii',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );

		$cache = new Cache();
		$this->assertNotNull( $cache );
	}

	/**
	 * Test that constructor rejects invalid domain names.
	 */
	public function test_constructor_rejects_invalid_domain(): void {
		$_SERVER['HTTP_HOST']   = 'invalid..domain';
		$_SERVER['REQUEST_URI'] = '/';
		Functions\stubs(
			array(
				'wp_normalize_path',
				'wp_parse_url',
				'sanitize_text_field',
				'wp_unslash',
				'get_option',
				'trailingslashit',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );

		$cache = new Cache();
		$this->assertNotNull( $cache );
	}

	/**
	 * Test that clear_cache static method exists.
	 */
	public function test_clear_cache_static_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'clear_cache' ) );
	}

	/**
	 * Test that flush_runtime method exists.
	 */
	public function test_flush_runtime_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'flush_runtime' ) );
	}

	/**
	 * Test that flush_group method exists.
	 */
	public function test_flush_group_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'flush_group' ) );
	}

	/**
	 * Test that get_cache_size method exists.
	 */
	public function test_get_cache_size_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'get_cache_size' ) );
	}

	/**
	 * Test that rewrite_cdn_urls method exists.
	 */
	public function test_rewrite_cdn_urls_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'rewrite_cdn_urls' ) );
	}

	/**
	 * Test that process_buffer_for_cache method exists.
	 */
	public function test_process_buffer_for_cache_method_exists(): void {
		$this->assertTrue( method_exists( Cache::class, 'process_buffer_for_cache' ) );
	}
}
