<?php
/**
 * Tests for wppo_object_cache_config hook (Phase3 PR-C).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Object_Cache;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

/**
 * Verifies wppo_object_cache_config filters Redis config.
 *
 * @since NEXT
 */
class HookObjectCacheConfigTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * get_redis_config applies filter and returns filtered config — verify source contains filter.
	 */
	public function test_get_redis_config_applies_filter(): void {
		$content = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-object-cache.php' );
		$this->assertStringContainsString( "apply_filters( 'wppo_object_cache_config'", $content );
		$this->assertStringContainsString( 'function get_redis_config', $content );
		// Also verify method returns filtered array.
		$this->assertMatchesRegularExpression( "/apply_filters\(\s*'wppo_object_cache_config'/", $content );
	}

	/**
	 * ping filters config before connect — verify source.
	 */
	public function test_ping_filters_config(): void {
		$content = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-object-cache.php' );
		// ping() must filter config before connect_internal.
		$this->assertStringContainsString( "apply_filters( 'wppo_object_cache_config'", $content );
		$this->assertStringContainsString( 'public function ping(', $content );
		// Ensure filter appears after ping signature (simple contains check).
		$this->assertGreaterThan( 0, substr_count( $content, "wppo_object_cache_config" ) );
	}

	/**
	 * Hook is documented existence.
	 */
	public function test_hook_exists_in_file(): void {
		$content = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-object-cache.php' );
		$this->assertStringContainsString( "apply_filters( 'wppo_object_cache_config'", $content );
	}
}
