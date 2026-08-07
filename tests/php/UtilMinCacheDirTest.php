<?php
/**
 * Tests for Util min-cache directory helpers.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Util::min_cache_base_dir() / Util::min_cache_dir() /
 * Util::min_cache_url().
 *
 * @package PerformanceOptimise\Tests
 */
class UtilMinCacheDirTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 *
	 * @before
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'content_url' )->alias(
			static function ( $path ) {
				return 'http://example.com/wp-content/' . ltrim( (string) $path, '/' );
			}
		);
	}

	/**
	 * Test that min_cache_base_dir returns the shared root.
	 */
	public function test_min_cache_base_dir_is_shared_root(): void {
		$this->assertSame( '/tmp/wordpress/wp-content/cache/wppo/min', Util::min_cache_base_dir() );
	}

	/**
	 * Test that min_cache_dir is blog-scoped.
	 */
	public function test_min_cache_dir_is_blog_scoped(): void {
		$this->assertSame( '/tmp/wordpress/wp-content/cache/wppo/min/1', Util::min_cache_dir() );
	}

	/**
	 * Test that min_cache_dir appends the requested subdirectory.
	 */
	public function test_min_cache_dir_with_subdir(): void {
		$this->assertSame( '/tmp/wordpress/wp-content/cache/wppo/min/1/css', Util::min_cache_dir( 'css' ) );
		$this->assertSame( '/tmp/wordpress/wp-content/cache/wppo/min/1/js', Util::min_cache_dir( 'js' ) );
	}

	/**
	 * Test that min_cache_dir reflects the current blog id.
	 */
	public function test_min_cache_dir_uses_current_blog_id(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$this->assertSame( '/tmp/wordpress/wp-content/cache/wppo/min/2', Util::min_cache_dir() );
	}

	/**
	 * Test that min_cache_url resolves a blog-scoped content URL.
	 */
	public function test_min_cache_url_is_blog_scoped(): void {
		$this->assertSame(
			'http://example.com/wp-content/cache/wppo/min/1/css/abc123.css',
			Util::min_cache_url( 'css', 'abc123.css' )
		);
	}
}
