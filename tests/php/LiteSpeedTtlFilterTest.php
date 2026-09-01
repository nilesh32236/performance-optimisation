<?php
/**
 * Tests for LS-903 N10-T1 Per-page TTL filter (Tier 1).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\LiteSpeed_Integration;
use Brain\Monkey\Functions;

/**
 * Tier-1 filter-only TTL tests — LS layer only, file-cache stays global.
 *
 * @since NEXT
 */
class LiteSpeedTtlFilterTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset static caches after each test.
	 */
	protected function tearDown(): void {
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	/**
	 * Test default TTL unchanged when no filter is registered.
	 */
	public function test_default_ttl_unchanged_when_no_filter(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 24 ) ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_SERVER['REQUEST_URI'] = '/about/';
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl();
		$this->assertSame( 86400, $ttl );
	}

	/**
	 * Test wppo_cache_ttl filter can override per-URI.
	 */
	public function test_wppo_cache_ttl_override_per_uri(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 24 ) ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) {
				if ( 'wppo_cache_ttl' === $tag ) {
					$uri = $args[0] ?? '';
					if ( '/shop/' === $uri ) {
						return 600;
					}
					return $value;
				}
				return $value;
			}
		);
		LiteSpeed_Integration::reset_cache();
		$ttl_shop = LiteSpeed_Integration::get_litespeed_ttl( '/shop/', null );
		$this->assertSame( 600, $ttl_shop );
		LiteSpeed_Integration::reset_cache();
		$ttl_other = LiteSpeed_Integration::get_litespeed_ttl( '/about/', null );
		$this->assertSame( 86400, $ttl_other );
	}

	/**
	 * Test wppo_cache_ttl filter receives post_id (url_to_postid / global post fallback).
	 */
	public function test_wppo_cache_ttl_receives_post_id(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 6 ) ) );
		Functions\when( 'url_to_postid' )->justReturn( 42 );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) {
				if ( 'wppo_cache_ttl' === $tag ) {
					$post_id = $args[1] ?? null;
					if ( 42 === $post_id ) {
						return 300;
					}
					return $value;
				}
				return $value;
			}
		);
		$_SERVER['REQUEST_URI'] = '/product/awesome/';
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl();
		$this->assertSame( 300, $ttl );
	}

	/**
	 * Test wppo_litespeed_ttl third arg context contains uri, post_type, post_id.
	 */
	public function test_wppo_litespeed_ttl_third_arg_context(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 12 ) ) );
		Functions\when( 'home_url' )->justReturn( 'http://example.com/about/' );
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'get_post_type' )->justReturn( false );
		$captured_context = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) use ( &$captured_context ) {
				if ( 'wppo_litespeed_ttl' === $tag ) {
					$captured_context = $args[1] ?? null;
					if ( is_array( $captured_context ) && '/special/' === ( $captured_context['uri'] ?? '' ) ) {
						return 123;
					}
					return $value;
				}
				if ( 'wppo_cache_ttl' === $tag ) {
					return $value;
				}
				return $value;
			}
		);
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/special/', 99 );
		$this->assertSame( 123, $ttl );
		$this->assertIsArray( $captured_context );
		$this->assertSame( '/special/', $captured_context['uri'] );
		$this->assertSame( 99, $captured_context['post_id'] );
	}

	/**
	 * Test fallback via global post when url_to_postid not resolvable.
	 */
	public function test_fallback_via_global_post(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 1 ) ) );
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'home_url' )->justReturn( 'http://example.com/fallback/' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$GLOBALS['post'] = (object) array( 'ID' => 55 );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) {
				if ( 'wppo_cache_ttl' === $tag ) {
					$post_id = $args[1] ?? null;
					if ( 55 === $post_id ) {
						return 777;
					}
					return $value;
				}
				return $value;
			}
		);
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/fallback/', null );
		$this->assertSame( 777, $ttl );
	}

	/**
	 * Test when post_id unresolvable, null is passed to wppo_cache_ttl.
	 */
	public function test_null_post_id_when_unresolvable(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 1 ) ) );
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'home_url' )->justReturn( 'http://example.com/none/' );
		$captured_post_id = 'not_set';
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) use ( &$captured_post_id ) {
				if ( 'wppo_cache_ttl' === $tag ) {
					$captured_post_id = array_key_exists( 1, $args ) ? $args[1] : 'missing';
					return $value;
				}
				return $value;
			}
		);
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/none/', null );
		$this->assertNull( $captured_post_id );
		$this->assertSame( 3600, $ttl );
	}
}
