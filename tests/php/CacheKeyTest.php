<?php
/**
 * Regression tests for the REF-001 Cache_Key boundary extraction (issue #1496).
 *
 * Pins byte-identical key output for single-site + multisite (blogs 1/2)
 * including switch_to_blog semantics, plus facade-proxy equivalence
 * (Util::x === Cache_Key::x) for all four extracted helpers.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Cache_Key;
use PerformanceOptimise\Inc\Util;

/**
 * Cache_Key boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheKeyTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Single-site: keys pass through unchanged.
	 */
	public function test_single_site_passthrough(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		$this->assertSame( 'wppo_foo', Cache_Key::transient_key( 'wppo_foo' ) );
		$this->assertSame( 'wppo_foo', Cache_Key::option_key( 'wppo_foo' ) );
		$this->assertSame( 'wppo_foo_stale', Cache_Key::stampede_stale_key( 'wppo_foo' ) );
	}

	/**
	 * Multisite: blog-ID prefix per blog.
	 */
	public function test_multisite_blog_prefix(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$this->assertSame( '1_wppo_foo', Cache_Key::transient_key( 'wppo_foo' ) );
		$this->assertSame( '1_wppo_foo', Cache_Key::option_key( 'wppo_foo' ) );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$this->assertSame( '2_wppo_foo', Cache_Key::transient_key( 'wppo_foo' ) );
		$this->assertSame( '2_wppo_foo', Cache_Key::option_key( 'wppo_foo' ) );
	}

	/**
	 * Missing is_multisite() (early boot): fail-open passthrough.
	 */
	public function test_missing_is_multisite_passthrough(): void {
		// Do not stub is_multisite: Brain Monkey leaves it undefined here
		// only if no other test in this process defined it — the bootstrap
		// never pre-registers it, and this test reconfigures per-case.
		// Guard explicitly: when the function cannot be called, keys pass through.
		if ( function_exists( 'is_multisite' ) ) {
			$this->markTestSkipped( 'is_multisite already declared in this process; covered by fail-open vectors below.' );
		}
		$this->assertSame( 'wppo_foo', Cache_Key::transient_key( 'wppo_foo' ) );
		$this->assertSame( 'wppo_foo', Cache_Key::option_key( 'wppo_foo' ) );
	}

	/**
	 * Multisite check throwing fails open to the bare key.
	 */
	public function test_multisite_throwable_fail_open(): void {
		Functions\when( 'is_multisite' )->alias(
			static function () {
				throw new \RuntimeException( 'ms check down' );
			}
		);
		$this->assertSame( 'wppo_foo', Cache_Key::transient_key( 'wppo_foo' ) );
		$this->assertSame( 'wppo_foo', Cache_Key::option_key( 'wppo_foo' ) );
		$this->assertSame( 'wppo_foo_stale', Cache_Key::stampede_stale_key( 'wppo_foo' ) );
	}

	/**
	 * Stale key: no double blog prefix on multisite, bare keys qualified.
	 */
	public function test_stale_key_no_double_prefix(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );

		$prefixed = Cache_Key::transient_key( 'wppo_audit_abc' );
		$this->assertSame( '3_wppo_audit_abc', $prefixed );
		$this->assertSame( '3_wppo_audit_abc_stale', Cache_Key::stampede_stale_key( $prefixed ) );
		$this->assertSame( '3_wppo_audit_xyz_stale', Cache_Key::stampede_stale_key( 'wppo_audit_xyz' ) );
	}

	/**
	 * Blog-switch correctness: prefix follows the current blog.
	 */
	public function test_switch_to_blog_prefix_follows_current_blog(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$current_blog = 1;
		$ref          = &$current_blog;
		Functions\when( 'get_current_blog_id' )->alias(
			static function () use ( &$ref ) {
				return $ref;
			}
		);

		$this->assertSame( '1_wppo_k', Cache_Key::transient_key( 'wppo_k' ) );
		// Simulate switch_to_blog( 2 ).
		$current_blog = 2;
		$this->assertSame( '2_wppo_k', Cache_Key::transient_key( 'wppo_k' ) );
		$this->assertSame( '2_wppo_k', Cache_Key::option_key( 'wppo_k' ) );
		$this->assertSame( '2_wppo_k_stale', Cache_Key::stampede_stale_key( 'wppo_k' ) );
		// And back.
		$current_blog = 1;
		$this->assertSame( '1_wppo_k_stale', Cache_Key::stampede_stale_key( 'wppo_k' ) );
	}

	/**
	 * Salt vectors: scalar values stringified, false/non-scalar map to '0'.
	 */
	public function test_cache_salt_vectors(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = false ) {
				$map = array(
					'wppo_salt_str'   => 'abc123',
					'wppo_salt_int'   => 42,
					'wppo_salt_false' => false,
					'wppo_salt_arr'   => array( 'x' ),
				);
				return array_key_exists( (string) $option, $map ) ? $map[ (string) $option ] : $fallback;
			}
		);

		$this->assertSame( 'abc123', Cache_Key::cache_salt( 'wppo_salt_str' ) );
		$this->assertSame( '42', Cache_Key::cache_salt( 'wppo_salt_int' ) );
		$this->assertSame( '0', Cache_Key::cache_salt( 'wppo_salt_false' ) );
		$this->assertSame( '0', Cache_Key::cache_salt( 'wppo_salt_arr' ) );
		$this->assertSame( '0', Cache_Key::cache_salt( 'wppo_salt_missing' ) );
	}

	/**
	 * Facade proxies: Util::x === Cache_Key::x in every mode.
	 */
	public function test_util_proxies_match_boundary(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return 'salt-v';
			}
		);

		foreach ( array( false, true ) as $multisite ) {
			Functions\when( 'is_multisite' )->justReturn( $multisite );
			foreach ( array( 1, 2 ) as $blog_id ) {
				Functions\when( 'get_current_blog_id' )->justReturn( $blog_id );
				$this->assertSame( Cache_Key::transient_key( 'wppo_k' ), Util::transient_key( 'wppo_k' ) );
				$this->assertSame( Cache_Key::option_key( 'wppo_k' ), Util::option_key( 'wppo_k' ) );
				$this->assertSame( Cache_Key::stampede_stale_key( 'wppo_k' ), Util::stampede_stale_key( 'wppo_k' ) );
				$this->assertSame(
					Cache_Key::stampede_stale_key( Cache_Key::transient_key( 'wppo_k' ) ),
					Util::stampede_stale_key( Util::transient_key( 'wppo_k' ) )
				);
			}
		}
		$this->assertSame( Cache_Key::cache_salt( 'wppo_s' ), Util::cache_salt( 'wppo_s' ) );
	}
}
