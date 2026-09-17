<?php
/**
 * Tests for Object_Cache drop-in path containment (audit #888 finding 22).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Object_Cache;
use Brain\Monkey\Functions;

/**
 * Tests that the wppo_object_cache_dropin_path filter cannot move the
 * drop-in outside wp-content.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheDropinPathTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Read the resolved dropin_path property.
	 *
	 * @param Object_Cache $cache Instance.
	 * @return string
	 */
	private function get_dropin_path( Object_Cache $cache ): string {
		$ref = new \ReflectionProperty( Object_Cache::class, 'dropin_path' );
		$ref->setAccessible( true );
		return (string) $ref->getValue( $cache );
	}

	/**
	 * Test that the default (unfiltered) path is the canonical drop-in.
	 */
	public function test_default_path_is_canonical(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$cache = new Object_Cache();

		$this->assertSame(
			wp_normalize_path( WP_CONTENT_DIR . '/object-cache.php' ),
			$this->get_dropin_path( $cache )
		);
	}

	/**
	 * Test that a filtered path outside wp-content is rejected.
	 */
	public function test_filter_path_outside_content_dir_is_rejected(): void {
		Functions\when( 'apply_filters' )->justReturn( '/tmp/evil/object-cache.php' );

		$cache = new Object_Cache();

		$this->assertSame(
			wp_normalize_path( WP_CONTENT_DIR . '/object-cache.php' ),
			$this->get_dropin_path( $cache )
		);
	}

	/**
	 * Test that a filtered path containing traversal is rejected.
	 */
	public function test_filter_path_with_traversal_is_rejected(): void {
		Functions\when( 'apply_filters' )->justReturn(
			WP_CONTENT_DIR . '/../evil/object-cache.php'
		);

		$cache = new Object_Cache();

		$this->assertSame(
			wp_normalize_path( WP_CONTENT_DIR . '/object-cache.php' ),
			$this->get_dropin_path( $cache )
		);
	}

	/**
	 * Test that a filtered path inside wp-content is accepted.
	 */
	public function test_filter_path_inside_content_dir_is_accepted(): void {
		Functions\when( 'apply_filters' )->justReturn(
			WP_CONTENT_DIR . '/subdir/object-cache.php'
		);

		$cache = new Object_Cache();

		$this->assertSame(
			wp_normalize_path( WP_CONTENT_DIR . '/subdir/object-cache.php' ),
			$this->get_dropin_path( $cache )
		);
	}
}
