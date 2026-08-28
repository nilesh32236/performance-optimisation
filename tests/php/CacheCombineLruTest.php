<?php
/**
 * Regression tests for P2 combine_css LRU (SRC_STAT_CACHE_LIMIT 500).
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * Tests for Cache src_stat LRU.
 */
class CacheCombineLruTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Cache instance without constructor.
	 *
	 * @return Cache
	 */
	private function make_cache(): Cache {
		return ( new ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Test that get_cached_src_stat caches and returns same result.
	 */
	public function test_get_cached_src_stat_caches(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'wppo-stat-' );
		file_put_contents( $tmp, str_repeat( 'a', 100 ) );

		$cache = $this->make_cache();
		$ref   = new ReflectionMethod( Cache::class, 'get_cached_src_stat' );
		$ref->setAccessible( true );

		$result1 = $ref->invoke( $cache, $tmp );
		$this->assertTrue( $result1['readable'] );
		$this->assertSame( 100, $result1['size'] );

		// Delete file and call again — should still return cached 100 (not re-stat).
		unlink( $tmp );
		$result2 = $ref->invoke( $cache, $tmp );
		$this->assertSame( 100, $result2['size'], 'Second call must be cached even after file deleted' );
	}

	/**
	 * Test that cache respects FIFO limit 500 (evicts oldest).
	 */
	public function test_src_stat_cache_evicts_fifo_when_full(): void {
		$cache = $this->make_cache();
		$ref   = new ReflectionMethod( Cache::class, 'get_cached_src_stat' );
		$ref->setAccessible( true );
		$prop = new ReflectionProperty( Cache::class, 'src_stat_cache' );
		$prop->setAccessible( true );

		// Fill to limit.
		$dir = sys_get_temp_dir() . '/wppo-lru-' . uniqid();
		mkdir( $dir );
		$files = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$f = $dir . '/file-' . $i . '.css';
			file_put_contents( $f, 'a' );
			$files[] = $f;
			$ref->invoke( $cache, $f );
		}
		$this->assertCount( 500, $prop->getValue( $cache ) );

		// Add 501st should evict oldest (file-0).
		$f501 = $dir . '/file-500.css';
		file_put_contents( $f501, 'b' );
		$ref->invoke( $cache, $f501 );

		$current = $prop->getValue( $cache );
		$this->assertCount( 500, $current );
		$this->assertArrayNotHasKey( $files[0], $current, 'Oldest entry should be evicted FIFO' );
		$this->assertArrayHasKey( $f501, $current );

		// Cleanup.
		foreach ( array_merge( $files, array( $f501 ) ) as $f ) {
			if ( file_exists( $f ) ) {
				unlink( $f );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Test should_skip_combine_for_inline_budget uses LRU and handles unreadable gracefully.
	 */
	public function test_should_skip_combine_uses_lru_and_handles_unreadable(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $p ) {
				return str_replace( '\\', '/', $p );
			}
		);

		global $wp_styles;
		$wp_styles = new stdClass();
		$wp_styles->registered = array(
			'style-a' => (object) array( 'src' => 'http://example.com/wp-content/themes/t/a.css', 'args' => 'all' ),
		);
		$wp_styles->queue = array( 'style-a' );

		// Mock Util::get_local_path to return unreadable path.
		// Instead we can directly set a non-existent path via wp_parse_url alias and ABSPATH.
		// For this test, we want should_skip to return false when unreadable.
		$cache = $this->make_cache();
		$options_prop = new ReflectionProperty( Cache::class, 'options' );
		$options_prop->setAccessible( true );
		$options_prop->setValue( $cache, array( 'file_optimisation' => array() ) );

		$ref = new ReflectionMethod( Cache::class, 'should_skip_combine_for_inline_budget' );
		$ref->setAccessible( true );

		// With unreadable file (path that doesn't exist), should return false (do not skip).
		$GLOBALS['wp_version'] = '6.9';
		$result = $ref->invoke( $cache, array( 'style-a' ) );
		$this->assertFalse( $result, 'Unreadable style should not cause skip (return false)' );
	}
}
