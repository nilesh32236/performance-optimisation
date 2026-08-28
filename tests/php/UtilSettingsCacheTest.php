<?php
/**
 * Tests for Util::get_settings blog-keyed memoization (P2 memo).
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests that settings cache is blog-keyed and invalidated correctly.
 */
class UtilSettingsCacheTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Helper to install get_option stub with blog-specific values.
	 *
	 * @param array $by_blog Blog ID => settings array.
	 * @return array Counter for get_option calls.
	 */
	private function install_blog_aware_stubs( array $by_blog ): array {
		$calls = array( 'get_option' => 0 );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( &$calls, $by_blog ) {
				if ( 'wppo_settings' === $name ) {
					++$calls['get_option'];
					$bid = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
					return $by_blog[ $bid ] ?? $fallback;
				}
				return $fallback;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		return $calls;
	}

	/**
	 * Test that get_settings caches per-request (second call does not hit get_option).
	 */
	public function test_get_settings_caches_per_request(): void {
		$get_calls = 0;
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( &$get_calls ) {
				if ( 'wppo_settings' === $name ) {
					++$get_calls;
					return array( 'file_optimisation' => array( 'minifyJS' => true ) );
				}
				return $fallback;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Util::clear_settings_cache();

		$s1 = Util::get_settings();
		$s2 = Util::get_settings();
		$this->assertSame( $s1, $s2 );
		$this->assertSame( 1, $get_calls, 'Second call must be cached' );
	}

	/**
	 * Test that cache is blog-keyed: switching blog yields different settings.
	 */
	public function test_get_settings_is_blog_keyed(): void {
		$blog_id = 1;
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( &$blog_id ) {
				if ( 'wppo_settings' === $name ) {
					return array( 'blog' => $blog_id );
				}
				return $fallback;
			}
		);
		Functions\when( 'get_current_blog_id' )->alias(
			static function () use ( &$blog_id ) {
				return $blog_id;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		Util::clear_settings_cache();

		$blog_id = 1;
		$s1 = Util::get_settings();
		$this->assertSame( 1, $s1['blog'] );

		$blog_id = 2;
		$s2 = Util::get_settings();
		$this->assertSame( 2, $s2['blog'], 'Blog 2 must return its own settings' );

		// Going back to blog 1 should return cached 1, not re-fetch (unless cleared).
		$blog_id = 1;
		$s3 = Util::get_settings();
		$this->assertSame( 1, $s3['blog'] );
	}

	/**
	 * Test that clear_settings_cache with blog_id clears only that blog.
	 */
	public function test_clear_settings_cache_per_blog(): void {
		$blog_id = 1;
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( &$blog_id ) {
				return array( 'blog' => $blog_id );
			}
		);
		Functions\when( 'get_current_blog_id' )->alias(
			static function () use ( &$blog_id ) {
				return $blog_id;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );

		Util::clear_settings_cache();
		$blog_id = 1;
		Util::get_settings();
		$blog_id = 2;
		Util::get_settings();

		// Clear only blog 1.
		Util::clear_settings_cache( 1 );

		// Blog 2 should still be cached (no extra get_option).
		$call_count = 0;
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( &$call_count ) {
				++$call_count;
				return array( 'blog' => 99 );
			}
		);
		$blog_id = 2;
		$s = Util::get_settings();
		$this->assertSame( 2, $s['blog'], 'Blog 2 should still be cached after clearing blog 1' );
		$this->assertSame( 0, $call_count, 'Blog 2 must not trigger get_option after per-blog clear of blog1' );

		// Blog 1 should refetch.
		$blog_id = 1;
		Util::get_settings();
		$this->assertSame( 1, $call_count, 'Blog 1 must trigger get_option after clear' );
	}

	/**
	 * Test that on_settings_update updates the blog-keyed cache.
	 */
	public function test_on_settings_update_refreshes_cache(): void {
		Functions\when( 'get_option' )->justReturn( array( 'old' => true ) );
		Functions\when( 'get_current_blog_id' )->justReturn( 5 );
		Functions\when( 'add_action' )->justReturn( true );
		Util::clear_settings_cache();
		Util::get_settings();

		Util::on_settings_update( array(), array( 'new' => true ) );
		$s = Util::get_settings();
		$this->assertSame( array( 'new' => true ), $s );
	}

	/**
	 * Test that clear_settings_cache with null clears all.
	 */
	public function test_clear_settings_cache_all(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'a' => 1 ) );
		Functions\when( 'add_action' )->justReturn( true );
		Util::get_settings();
		Util::clear_settings_cache();
		$call_count = 0;
		Functions\when( 'get_option' )->alias(
			function () use ( &$call_count ) {
				++$call_count;
				return array( 'b' => 2 );
			}
		);
		Util::get_settings();
		$this->assertSame( 1, $call_count );
	}
}
