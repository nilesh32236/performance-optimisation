<?php
/**
 * Regression tests for the REF-014 Scheduler boundary extraction (issue #1524).
 *
 * Pins byte-identical behavior for the scheduler responsibility cluster moved
 * from `Util` to `PerformanceOptimise\Inc\Scheduler`: Action Scheduler
 * unique-job dedup vectors (absent/legacy/atomic/cross-group), stampede-lock
 * acquire/release/owner/TTL vectors, guard toggle + TTL clamp vectors, plus
 * facade-proxy equivalence (`Util::x === Scheduler::x`) for every moved
 * public method. Also pins that the cache-read paths left in `Util`
 * (`get_with_stampede_lock()`) still coalesce through the moved verbs.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Scheduler;
use PerformanceOptimise\Inc\Util;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Scheduler boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SchedulerBoundaryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory transient store backing the get/set/delete stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Expiry captured by the set_transient stub, keyed by transient name.
	 *
	 * @var array<string, int>
	 */
	private array $transient_expiry = array();

	/**
	 * Install in-memory transient stubs.
	 *
	 * @return void
	 */
	private function install_transient_stubs(): void {
		$this->transients       = array();
		$this->transient_expiry = array();
		$store                  = &$this->transients;
		$expiry                 = &$this->transient_expiry;
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				$k = (string) $key;
				return array_key_exists( $k, $store ) ? $store[ $k ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration = 0 ) use ( &$store, &$expiry ) {
				$store[ (string) $key ]  = $value;
				$expiry[ (string) $key ] = (int) $expiration;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$store, &$expiry ) {
				unset( $store[ (string) $key ], $expiry[ (string) $key ] );
				return true;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Force the best-effort transient lock path so tests are deterministic
		// regardless of the object-cache drop-in loaded by the bootstrap.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Util::set_settings_cache( array() );
	}

	/**
	 * Without any scheduler loaded every unique helper fails open to 0, the
	 * support probe is false, and both reset seams clear the memo.
	 *
	 * Util:: proxies must agree with the Scheduler owner on every vector.
	 *
	 * Runs in a separate process where no AS functions exist.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_absent_scheduler_vectors_match_proxy(): void {
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ) );
		$this->assertFalse( Scheduler::supports_action_scheduler_unique() );
		$this->assertFalse( Util::supports_action_scheduler_unique() );
		$this->assertSame( 0, Scheduler::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' ) );
		$this->assertSame( 0, Util::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' ) );
		$this->assertSame( 0, Scheduler::schedule_unique_single_action( time() + 60, 'wppo_generate_ccss', array(), 'wppo-ccss' ) );
		$this->assertSame( 0, Util::schedule_unique_single_action( time() + 60, 'wppo_generate_ccss', array(), 'wppo-ccss' ) );
		Scheduler::reset_action_scheduler_unique_cache();
		Util::reset_action_scheduler_unique_cache();
		$this->assertFalse( Scheduler::supports_action_scheduler_unique() );
		$this->assertFalse( Util::supports_action_scheduler_unique() );
	}

	/**
	 * AS 4.x atomic path: owner and proxy pass the same `$unique` flag,
	 * forward identical arguments, and surface the same action ID.
	 *
	 * Runs in a separate process with real 4.x-shaped global functions so
	 * reflection detects unique support.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_atomic_enqueue_proxy_equivalence(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			eval( 'function as_enqueue_async_action( $hook, $args = array(), $group = "", $unique = false, $priority = 10 ) { $GLOBALS["wppo_sched_calls"][] = func_get_args(); return $unique ? 61 : 62; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only 4.x-shaped scheduler stub in an isolated process.
		}
		$GLOBALS['wppo_sched_calls'] = array();
		$owner_result                = Scheduler::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' );
		$proxy_result                = Util::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' );
		$this->assertSame( 61, $owner_result );
		$this->assertSame( $owner_result, $proxy_result );
		$this->assertCount( 2, $GLOBALS['wppo_sched_calls'] );
		$this->assertSame( $GLOBALS['wppo_sched_calls'][0], $GLOBALS['wppo_sched_calls'][1] );
		$this->assertTrue( $GLOBALS['wppo_sched_calls'][0][3] );
	}

	/**
	 * Legacy scheduler path: dedup returns 0 and the fallback forwards
	 * hook+args+group identically through owner and proxy; the single-action
	 * fallback behaves the same.
	 *
	 * Runs in a separate process so the legacy stubs do not leak into later
	 * test files (Brain Monkey declarations persist per process).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_legacy_vectors_proxy_equivalence(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$enqueue_calls = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( $hook, $args = array(), $group = '' ) use ( &$enqueue_calls ) {
				$enqueue_calls[] = array( $hook, $args, $group );
				return 42;
			}
		);
		$this->assertSame( 42, Scheduler::enqueue_unique_async_action( 'wppo_used_css_generate', array( 'post_id' => 7 ), 'performance_optimisation' ) );
		$this->assertSame( 42, Util::enqueue_unique_async_action( 'wppo_used_css_generate', array( 'post_id' => 7 ), 'performance_optimisation' ) );
		$this->assertCount( 2, $enqueue_calls );
		$this->assertSame( $enqueue_calls[0], $enqueue_calls[1] );
		$this->assertCount( 3, $enqueue_calls[0] );

		$schedule_calls = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args = array(), $group = '' ) use ( &$schedule_calls ) {
				$schedule_calls[] = array( $timestamp, $hook, $args, $group );
				return 43;
			}
		);
		$timestamp = time() + 60;
		$this->assertSame( 43, Scheduler::schedule_unique_single_action( $timestamp, 'wppo_generate_ccss', array( array( 'template_hash' => 'abc' ) ), 'wppo-ccss' ) );
		$this->assertSame( 43, Util::schedule_unique_single_action( $timestamp, 'wppo_generate_ccss', array( array( 'template_hash' => 'abc' ) ), 'wppo-ccss' ) );
		$this->assertCount( 2, $schedule_calls );
		$this->assertSame( $schedule_calls[0], $schedule_calls[1] );

		// Deduped jobs return 0 through both entry points without enqueueing.
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		$before = count( $enqueue_calls );
		$this->assertSame( 0, Scheduler::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' ) );
		$this->assertSame( 0, Util::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' ) );
		$this->assertCount( $before, $enqueue_calls );
	}

	/**
	 * Cross-group dedupe: a pending job in an extra (legacy) group blocks
	 * the primary insert on the AS 4.x atomic path through both entry
	 * points, so no insert runs.
	 *
	 * Runs in a separate process with real 4.x-shaped global functions so
	 * reflection detects unique support.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cross_group_dedup_proxy_equivalence(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			eval( 'function as_enqueue_async_action( $hook, $args = array(), $group = "", $unique = false, $priority = 10 ) { $GLOBALS["wppo_sched_xgroup_calls"][] = func_get_args(); return 99; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only 4.x-shaped scheduler stub in an isolated process.
		}
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			eval( 'function as_has_scheduled_action( $hook, $args = array(), $group = "" ) { return "performance_optimisation" === $group; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only group-sensitive guard stub in an isolated process.
		}
		$GLOBALS['wppo_sched_xgroup_calls'] = array();
		$args                               = array( array( 'template_hash' => 'abc' ) );
		$this->assertSame( 0, Scheduler::enqueue_unique_async_action( 'wppo_generate_ccss', $args, 'wppo-ccss', array( 'performance_optimisation' ) ) );
		$this->assertSame( 0, Util::enqueue_unique_async_action( 'wppo_generate_ccss', $args, 'wppo-ccss', array( 'performance_optimisation' ) ) );
		$this->assertCount( 0, $GLOBALS['wppo_sched_xgroup_calls'] );
	}

	/**
	 * TTL clamp vectors: below 2 floors, above 5 caps, in-range passes
	 * through — identically through owner and proxy.
	 */
	public function test_lock_ttl_clamp_vectors_match_proxy(): void {
		$this->install_transient_stubs();
		foreach ( array( 0, 1, 2, 3, 5, 6, 99 ) as $raw ) {
			Util::set_settings_cache(
				array(
					'cache_settings' => array(
						'stampedeLockTtl' => $raw,
					),
				)
			);
			$expected = min( 5, max( 2, (int) $raw ) );
			$this->assertSame( $expected, Scheduler::stampede_lock_ttl(), "raw TTL {$raw}" );
			$this->assertSame( $expected, Util::stampede_lock_ttl(), "raw TTL {$raw} via proxy" );
		}
		// A filter override is still clamped after filtering.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'wppo_stampede_lock_ttl' === $hook ? 99 : $value;
			}
		);
		$this->assertSame( 5, Scheduler::stampede_lock_ttl() );
		$this->assertSame( 5, Util::stampede_lock_ttl() );
	}

	/**
	 * Acquire clamps the TTL written to the store; empty keys/owners fail;
	 * release is owner-checked — identically through owner and proxy.
	 */
	public function test_acquire_release_vectors_match_proxy(): void {
		$this->install_transient_stubs();
		$this->assertFalse( Scheduler::acquire_stampede_lock( '', 'owner', 5 ) );
		$this->assertFalse( Util::acquire_stampede_lock( 'some-key', '', 5 ) );

		$lock_key = Util::transient_key( 'wppo_sched_boundary_lock' );
		$this->assertTrue( Scheduler::acquire_stampede_lock( $lock_key, 'owner-a', 1 ) );
		// TTL 1 clamps to 2 in the store write.
		$this->assertSame( 2, $this->transient_expiry[ $lock_key ] );
		// Contender loses while held.
		$this->assertFalse( Util::acquire_stampede_lock( $lock_key, 'owner-b', 5 ) );
		// Wrong owner must not release.
		Util::release_stampede_lock( $lock_key, 'owner-b' );
		$this->assertSame( 'owner-a', $this->transients[ $lock_key ] );
		// Right owner releases through the owner entry point.
		Scheduler::release_stampede_lock( $lock_key, 'owner-a' );
		$this->assertArrayNotHasKey( $lock_key, $this->transients );

		// High TTL clamps to 5 in the store write.
		$this->assertTrue( Util::acquire_stampede_lock( $lock_key, 'owner-c', 99 ) );
		$this->assertSame( 5, $this->transient_expiry[ $lock_key ] );
		Scheduler::release_stampede_lock( $lock_key, 'owner-c' );
		$this->assertArrayNotHasKey( $lock_key, $this->transients );
	}

	/**
	 * Owner tokens are never empty and never repeat across calls.
	 */
	public function test_owner_tokens_unique_and_nonempty(): void {
		$this->install_transient_stubs();
		$seen = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$owner = ( 0 === $i % 2 ) ? Scheduler::generate_stampede_owner() : Util::generate_stampede_owner();
			$this->assertNotSame( '', $owner );
			$this->assertArrayNotHasKey( $owner, $seen );
			$seen[ $owner ] = true;
		}
	}

	/**
	 * Guard toggle: enabled by default, operator opt-out via settings, and
	 * filter override — identically through owner and proxy.
	 */
	public function test_guard_toggle_vectors_match_proxy(): void {
		$this->install_transient_stubs();
		$this->assertTrue( Scheduler::is_stampede_guard_enabled() );
		$this->assertTrue( Util::is_stampede_guard_enabled() );

		Util::set_settings_cache(
			array(
				'cache_settings' => array(
					'stampedeGuard' => false,
				),
			)
		);
		$this->assertFalse( Scheduler::is_stampede_guard_enabled() );
		$this->assertFalse( Util::is_stampede_guard_enabled() );

		// Filter re-enables over an explicit opt-out.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'wppo_stampede_guard_enabled' === $hook ? true : $value;
			}
		);
		$this->assertTrue( Scheduler::is_stampede_guard_enabled() );
		$this->assertTrue( Util::is_stampede_guard_enabled() );
	}

	/**
	 * The cache-read path left in Util still coalesces through the moved
	 * verbs: winner rebuilds once and primes stale; loser serves stale.
	 */
	public function test_get_with_stampede_lock_still_coalesces(): void {
		$this->install_transient_stubs();
		$key                            = 'wppo_sched_boundary_coalesce';
		$stale_key                      = Util::stampede_stale_key( $key );
		$this->transients[ $stale_key ] = 'stale-value';
		$lock_key                       = Util::transient_key( 'wppo_stampede_' . md5( $key ) );
		$this->transients[ $lock_key ]  = 'other-owner';
		$rebuild_calls                  = 0;
		$result                         = Util::get_with_stampede_lock(
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
		$this->assertSame( 0, $rebuild_calls );

		// Lock free: winner rebuilds and primes the stale copy.
		unset( $this->transients[ $lock_key ] );
		$result = Util::get_with_stampede_lock(
			$key,
			static function () {
				return 'fresh-value';
			},
			array(
				'retries'        => 1,
				'retry_delay_us' => 0,
			)
		);
		$this->assertSame( 'fresh-value', $result );
		$this->assertSame( 'fresh-value', $this->transients[ $stale_key ] );
	}
}
