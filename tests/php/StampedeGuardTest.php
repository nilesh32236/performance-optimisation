<?php
/**
 * Tests for the stampede guard primitive on Util (issue #1101 follow-up).
 *
 * Covers the shared coalescing helper: single rebuild on contention with
 * loser-serves-stale, rebuild-failure-serves-stale, owner-checked release,
 * guard-disabled passthrough, lock-failure fail-open, stale-key namespacing
 * (no double blog prefix on multisite), and the non-persistent-cache
 * best-effort lock path.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Util;

/**
 * Stampede guard tests.
 *
 * @package PerformanceOptimise\Tests
 */
class StampedeGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory transient store backing the get/set/delete stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Install in-memory transient stubs.
	 *
	 * @return void
	 */
	private function install_transient_stubs(): void {
		$this->transients = array();
		$store            = &$this->transients;
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				$k = (string) $key;
				return array_key_exists( $k, $store ) ? $store[ $k ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ (string) $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				unset( $store[ (string) $key ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = false ) {
				if ( 'wppo_transient_index' === $option ) {
					return array();
				}
				return $fallback;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Force the best-effort transient lock path so tests are deterministic
		// regardless of the object-cache drop-in loaded by the bootstrap.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Util::set_settings_cache( array() );
	}

	/**
	 * Loser serves the stale copy when another worker holds the lock.
	 */
	public function test_loser_serves_stale_on_contention(): void {
		$this->install_transient_stubs();
		$key                            = 'wppo_stampede_test_a';
		$stale_key                      = Util::stampede_stale_key( $key );
		$this->transients[ $stale_key ] = 'stale-value';
		// Simulate a held lock under the derived lock key.
		$lock_key                      = Util::transient_key( 'wppo_stampede_' . md5( $key ) );
		$this->transients[ $lock_key ] = 'other-owner';
		$rebuild_calls                 = 0;
		$result                        = Util::get_with_stampede_lock(
			$key,
			static function () use ( &$rebuild_calls ) {
				++$rebuild_calls;
				return 'fresh-value';
			},
			array(
				'retries'        => 1,
				'retry_delay_us' => 0,
			)
		);
		$this->assertSame( 'stale-value', $result );
		// Loser must not rebuild when stale is available (cold-start direct
		// rebuild only runs when no stale copy exists).
		$this->assertSame( 0, $rebuild_calls );
	}

	/**
	 * Winner rebuilds once and both fresh + stale copies are written.
	 */
	public function test_winner_rebuilds_and_primes_stale(): void {
		$this->install_transient_stubs();
		$key    = 'wppo_stampede_test_b';
		$calls  = 0;
		$result = Util::get_with_stampede_lock(
			$key,
			static function () use ( &$calls ) {
				++$calls;
				return 'fresh-b';
			},
			array(
				'retries'        => 1,
				'retry_delay_us' => 0,
			)
		);
		$this->assertSame( 'fresh-b', $result );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'fresh-b', $this->transients[ $key ] );
		$this->assertSame( 'fresh-b', $this->transients[ Util::stampede_stale_key( $key ) ] );
	}

	/**
	 * Rebuild failure serves stale instead of caching zeros/false.
	 */
	public function test_rebuild_failure_serves_stale(): void {
		$this->install_transient_stubs();
		$key                            = 'wppo_stampede_test_c';
		$stale_key                      = Util::stampede_stale_key( $key );
		$this->transients[ $stale_key ] = 'stale-c';
		$result                         = Util::get_with_stampede_lock(
			$key,
			static function () {
				return false;
			},
			array(
				'retries'        => 0,
				'retry_delay_us' => 0,
			)
		);
		$this->assertSame( 'stale-c', $result );
	}

	/**
	 * Lock release is owner-checked.
	 */
	public function test_owner_checked_release(): void {
		$this->install_transient_stubs();
		$lock_key = Util::transient_key( 'wppo_stampede_owner_check' );
		$this->assertTrue( Util::acquire_stampede_lock( $lock_key, 'owner-a', 5 ) );
		// Wrong owner must not release.
		Util::release_stampede_lock( $lock_key, 'owner-b' );
		$this->assertSame( 'owner-a', $this->transients[ $lock_key ] );
		// Right owner releases.
		Util::release_stampede_lock( $lock_key, 'owner-a' );
		$this->assertArrayNotHasKey( $lock_key, $this->transients );
	}

	/**
	 * Guard-disabled path rebuilds directly without coalescing.
	 */
	public function test_guard_disabled_passthrough(): void {
		$this->install_transient_stubs();
		Util::set_settings_cache(
			array(
				'cache_settings' => array(
					'stampedeGuard' => false,
				),
			)
		);
		$key      = 'wppo_stampede_test_d';
		$lock_key = Util::transient_key( 'wppo_stampede_' . md5( $key ) );
		// Even with the lock held, disabled guard rebuilds directly.
		$this->transients[ $lock_key ] = 'other-owner';
		$calls                         = 0;
		$result                        = Util::get_with_stampede_lock(
			$key,
			static function () use ( &$calls ) {
				++$calls;
				return 'direct-d';
			},
			array(
				'retries'        => 1,
				'retry_delay_us' => 0,
			)
		);
		$this->assertSame( 'direct-d', $result );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Lock throwables fail open (never fatal, stale or dynamic served).
	 */
	public function test_lock_throwable_fail_open(): void {
		$this->install_transient_stubs();
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				if ( str_contains( (string) $key, 'wppo_stampede_' ) ) {
					throw new \RuntimeException( 'redis down' );
				}
				return false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function () {
				throw new \RuntimeException( 'redis down' );
			}
		);
		$calls  = 0;
		$result = Util::get_with_stampede_lock(
			'wppo_stampede_test_e',
			static function () use ( &$calls ) {
				++$calls;
				return 'dynamic-e';
			},
			array(
				'retries'        => 1,
				'retry_delay_us' => 0,
			)
		);
		$this->assertSame( 'dynamic-e', $result );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Stale key avoids a double blog prefix on multisite.
	 */
	public function test_stale_key_has_no_double_prefix_on_multisite(): void {
		$this->install_transient_stubs();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );
		$prefixed = Util::transient_key( 'wppo_audit_abc' );
		$this->assertSame( '3_wppo_audit_abc', $prefixed );
		$this->assertSame( '3_wppo_audit_abc_stale', Util::stampede_stale_key( $prefixed ) );
		// Bare keys stay isolated via transient_key().
		$this->assertSame( '3_wppo_audit_xyz_stale', Util::stampede_stale_key( 'wppo_audit_xyz' ) );
	}

	/**
	 * Without a persistent object cache the lock is best-effort via transients.
	 */
	public function test_acquire_uses_transient_fallback_without_ext_cache(): void {
		$this->install_transient_stubs();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		$lock_key = Util::transient_key( 'wppo_stampede_noext' );
		$this->assertTrue( Util::acquire_stampede_lock( $lock_key, 'owner-1', 5 ) );
		// Second contender observes the transient and loses (best-effort).
		$this->assertFalse( Util::acquire_stampede_lock( $lock_key, 'owner-2', 5 ) );
	}
}
