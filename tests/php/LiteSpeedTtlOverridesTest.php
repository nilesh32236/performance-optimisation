<?php
/**
 * Tests for LS-903 N10-T2 per-post-type TTL overrides (Tier-2).
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Generic.Commenting.DocComment.Missing

use PerformanceOptimise\Inc\LiteSpeed_Integration;
use Brain\Monkey\Functions;

/**
 * Tier-2 per-type override tests — LS layer only, file-cache stays global.
 *
 * @since NEXT
 */
class LiteSpeedTtlOverridesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	protected function tearDown(): void {
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	public function test_per_type_override_post(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 24,
					'ttlOverrides' => array( 'post' => 6 ),
				),
			)
		);
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/hello-world/', 123 );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $ttl );
	}

	public function test_per_type_override_page(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 24,
					'ttlOverrides' => array( 'page' => 1 ),
				),
			)
		);
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/about/', 55 );
		$this->assertSame( 1 * HOUR_IN_SECONDS, $ttl );
	}

	public function test_per_type_override_product(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 24,
					'ttlOverrides' => array( 'product' => 48 ),
				),
			)
		);
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/product/awesome/', 99 );
		$this->assertSame( 48 * HOUR_IN_SECONDS, $ttl );
	}

	public function test_fallback_to_global_when_no_override(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 12,
					'ttlOverrides' => array( 'post' => 6 ),
				),
			)
		);
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/about/', 55 );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $ttl );
	}

	public function test_fallback_to_global_when_empty_overrides(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 6,
					'ttlOverrides' => array(),
				),
			)
		);
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/hello/', 10 );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $ttl );
	}

	public function test_non_singular_fallback_to_global(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 24,
					'ttlOverrides' => array( 'post' => 1 ),
				),
			)
		);
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/hello/', 123 );
		$this->assertSame( 24 * HOUR_IN_SECONDS, $ttl );
	}

	public function test_non_singular_null_post_id_global(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 24,
					'ttlOverrides' => array( 'post' => 1 ),
				),
			)
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com/' );
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/', null );
		$this->assertSame( 24 * HOUR_IN_SECONDS, $ttl );
	}

	public function test_override_zero_maps_to_week(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 24,
					'ttlOverrides' => array( 'post' => 0 ),
				),
			)
		);
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/hello/', 1 );
		$this->assertSame( WEEK_IN_SECONDS, $ttl );
	}

	public function test_unknown_post_type_fallback(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array(
					'cacheLife'    => 48,
					'ttlOverrides' => array( 'post' => 1 ),
				),
			)
		);
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl( '/file.jpg', 200 );
		$this->assertSame( 48 * HOUR_IN_SECONDS, $ttl );
	}
}
