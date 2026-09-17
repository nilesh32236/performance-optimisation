<?php
/**
 * Tests for Action Scheduler 4.x unique-args adoption and the opt-in
 * failed-action purge (issue #1310).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Database_Cleanup;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Tests for unique scheduling helpers and the failed-action purge gate.
 *
 * @package PerformanceOptimise\Tests
 */
class ActionSchedulerUniquePurge1310Test extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The new settings key defaults to off in the runtime defaults.
	 */
	public function test_default_settings_include_purge_key_off(): void {
		$defaults = Util::get_default_settings();
		$this->assertArrayHasKey( 'purgeFailedActions', $defaults['database_cleanup'] );
		$this->assertFalse( $defaults['database_cleanup']['purgeFailedActions'] );
	}

	/**
	 * String 'false' from an import must not sanitize to truthy (fail-safe off).
	 */
	public function test_purge_key_sanitizes_string_false_to_bool(): void {
		Functions\stubs( array( 'sanitize_text_field', 'sanitize_textarea_field', 'esc_url_raw' ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		$sanitized = Util::sanitize_settings_recursively(
			array(
				'purgeFailedActions' => 'false',
			)
		);
		$this->assertFalse( $sanitized['purgeFailedActions'] );
	}

	/**
	 * The purge is disabled by default: empty settings, no filter.
	 */
	public function test_failed_purge_disabled_by_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertFalse( Database_Cleanup::is_failed_action_purge_enabled() );
		$this->assertSame( 0, Database_Cleanup::purge_failed_actions() );
	}

	/**
	 * The purge enables via the additive settings key.
	 *
	 * The store is unavailable in this process, so the purge itself still
	 * returns 0 (fail-open) while the gate reports enabled.
	 */
	public function test_failed_purge_enabled_via_setting(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'database_cleanup' => array(
					'purgeFailedActions' => true,
				),
			)
		);
		$this->assertTrue( Database_Cleanup::is_failed_action_purge_enabled() );
		$this->assertSame( 0, Database_Cleanup::purge_failed_actions() );
	}

	/**
	 * The purge enables via the filter even when the setting is off.
	 */
	public function test_failed_purge_enabled_via_filter(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Filters\expectApplied( 'wppo_purge_failed_actions' )->once()->with( false )->andReturn( true );
		$this->assertTrue( Database_Cleanup::is_failed_action_purge_enabled() );
	}

	/**
	 * Legacy scheduler path: deduped jobs return 0 without enqueueing.
	 *
	 * Runs in a separate process so the legacy scheduler stubs do not leak
	 * into later test files (Brain Monkey declarations persist per process).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unique_helper_legacy_dedup_returns_zero(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\expect( 'as_enqueue_async_action' )->never();
		$this->assertSame( 0, Util::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' ) );
	}

	/**
	 * Legacy scheduler path: no duplicate present, falls back to the
	 * 3-argument enqueue and returns its ID.
	 *
	 * Runs in a separate process so the legacy scheduler stubs do not leak
	 * into later test files (Brain Monkey declarations persist per process).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unique_helper_legacy_enqueue_fallback(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$calls = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( $hook, $args = array(), $group = '' ) use ( &$calls ) {
				$calls[] = array( $hook, $args, $group );
				return 42;
			}
		);
		$this->assertSame( 42, Util::enqueue_unique_async_action( 'wppo_used_css_generate', array( 'post_id' => 7 ), 'performance_optimisation' ) );
		$this->assertCount( 1, $calls );
		// Legacy fallback passes exactly hook+args+group (no $unique flag).
		$this->assertCount( 3, $calls[0] );
		// Hook, args, and group are forwarded verbatim.
		$this->assertSame( 'wppo_used_css_generate', $calls[0][0] );
		$this->assertSame( array( 'post_id' => 7 ), $calls[0][1] );
		$this->assertSame( 'performance_optimisation', $calls[0][2] );
	}

	/**
	 * Legacy single-action path: falls back to the 4-argument schedule call.
	 *
	 * Runs in a separate process so the legacy scheduler stubs do not leak
	 * into later test files (Brain Monkey declarations persist per process).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unique_single_helper_legacy_schedule_fallback(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		$calls = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $timestamp, $hook, $args = array(), $group = '' ) use ( &$calls ) {
				$calls[] = array( $timestamp, $hook, $args, $group );
				return 43;
			}
		);
		$this->assertSame( 43, Util::schedule_unique_single_action( time() + 60, 'wppo_generate_ccss', array( array( 'template_hash' => 'abc' ) ), 'wppo-ccss' ) );
		$this->assertCount( 1, $calls );
		$this->assertCount( 4, $calls[0] );
		// Timestamp, hook, args, and group are forwarded verbatim (no $unique flag).
		$this->assertIsInt( $calls[0][0] );
		$this->assertSame( 'wppo_generate_ccss', $calls[0][1] );
		$this->assertSame( array( array( 'template_hash' => 'abc' ) ), $calls[0][2] );
		$this->assertSame( 'wppo-ccss', $calls[0][3] );
	}

	/**
	 * AS 4.x path: the atomic $unique flag is passed and a 0 return (racing
	 * duplicate) is surfaced without consulting the legacy guard.
	 *
	 * Runs in a separate process with real 4.x-shaped global functions so
	 * reflection detects unique support.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unique_helper_atomic_path_passes_unique_flag(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			eval( 'function as_enqueue_async_action( $hook, $args = array(), $group = "", $unique = false, $priority = 10 ) { $GLOBALS["wppo_1310_calls"][] = func_get_args(); return $unique ? 0 : 99; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only 4.x-shaped scheduler stub in an isolated process.
		}
		$GLOBALS['wppo_1310_calls'] = array();
		$result                     = Util::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' );
		$this->assertSame( 0, $result );
		$this->assertCount( 1, $GLOBALS['wppo_1310_calls'] );
		$this->assertTrue( $GLOBALS['wppo_1310_calls'][0][3] );
	}

	/**
	 * AS 4.x single-action path: the atomic $unique flag is passed as the
	 * 5th argument and timestamp/hook/args/group are forwarded verbatim.
	 *
	 * Runs in a separate process with real 4.x-shaped global functions so
	 * reflection detects unique support.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unique_single_helper_atomic_path_passes_unique_flag(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			eval( 'function as_enqueue_async_action( $hook, $args = array(), $group = "", $unique = false, $priority = 10 ) { return 99; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only 4.x-shaped scheduler stub in an isolated process.
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			eval( 'function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = "", $unique = false, $priority = 10 ) { $GLOBALS["wppo_1310_single_calls"][] = func_get_args(); return $unique ? 77 : 78; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only 4.x-shaped scheduler stub in an isolated process.
		}
		$GLOBALS['wppo_1310_single_calls'] = array();
		$timestamp                         = time() + 60;
		$result                            = Util::schedule_unique_single_action( $timestamp, 'wppo_generate_ccss', array( array( 'template_hash' => 'abc' ) ), 'wppo-ccss' );
		$this->assertSame( 77, $result );
		$this->assertCount( 1, $GLOBALS['wppo_1310_single_calls'] );
		$call = $GLOBALS['wppo_1310_single_calls'][0];
		$this->assertSame( $timestamp, $call[0] );
		$this->assertSame( 'wppo_generate_ccss', $call[1] );
		$this->assertSame( array( array( 'template_hash' => 'abc' ) ), $call[2] );
		$this->assertSame( 'wppo-ccss', $call[3] );
		$this->assertTrue( $call[4] );
	}

	/**
	 * AS 4.x recurring path: the atomic $unique flag is passed as the 6th
	 * argument and timestamp/interval/hook/args/group are forwarded.
	 *
	 * Runs in a separate process with real 4.x-shaped global functions so
	 * reflection detects unique support.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unique_recurring_helper_atomic_path_passes_unique_flag(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			eval( 'function as_enqueue_async_action( $hook, $args = array(), $group = "", $unique = false, $priority = 10 ) { return 99; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only 4.x-shaped scheduler stub in an isolated process.
		}
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			eval( 'function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = "", $unique = false, $priority = 10 ) { $GLOBALS["wppo_1310_recurring_calls"][] = func_get_args(); return $unique ? 88 : 89; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only 4.x-shaped scheduler stub in an isolated process.
		}
		$GLOBALS['wppo_1310_recurring_calls'] = array();
		$timestamp                            = time() + 120;
		$result                               = Util::schedule_unique_recurring_action( $timestamp, 3600, 'wppo_recurring_hook', array( 'scope' => 'all' ), 'performance_optimisation' );
		$this->assertSame( 88, $result );
		$this->assertCount( 1, $GLOBALS['wppo_1310_recurring_calls'] );
		$call = $GLOBALS['wppo_1310_recurring_calls'][0];
		$this->assertSame( $timestamp, $call[0] );
		$this->assertSame( 3600, $call[1] );
		$this->assertSame( 'wppo_recurring_hook', $call[2] );
		$this->assertSame( array( 'scope' => 'all' ), $call[3] );
		$this->assertSame( 'performance_optimisation', $call[4] );
		$this->assertTrue( $call[5] );
	}

	/**
	 * Without any scheduler loaded every unique helper fails open to 0 and
	 * the probe memo can be reset without error.
	 *
	 * Runs in a separate process where no AS functions exist.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unique_helpers_return_zero_without_scheduler(): void {
		$this->assertFalse( function_exists( 'as_enqueue_async_action' ) );
		$this->assertFalse( Util::supports_action_scheduler_unique() );
		$this->assertSame( 0, Util::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' ) );
		$this->assertSame( 0, Util::schedule_unique_single_action( time() + 60, 'wppo_generate_ccss', array(), 'wppo-ccss' ) );
		$this->assertSame( 0, Util::schedule_unique_recurring_action( time() + 60, 3600, 'wppo_recurring_hook', array(), 'performance_optimisation' ) );
		Util::reset_action_scheduler_unique_cache();
		$this->assertFalse( Util::supports_action_scheduler_unique() );
	}

	/**
	 * A raw string 'false' (e.g. via direct update_option/DB edit bypassing
	 * the sanitizer) must not enable the purge through !empty() truthiness.
	 */
	public function test_failed_purge_read_normalizes_string_false_to_off(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'database_cleanup' => array(
					'purgeFailedActions' => 'false',
				),
			)
		);
		Filters\expectApplied( 'wppo_purge_failed_actions' )->once()->with( false )->andReturn( false );
		$this->assertFalse( Database_Cleanup::is_failed_action_purge_enabled() );
	}

	/**
	 * A lowered/rogue retention filter returning 0 must not make the purge
	 * cutoff "now" (fail-destructive): the lifespan is floored at one day.
	 *
	 * Runs in a separate process with a stub store capturing the query.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_purge_cutoff_floors_zero_retention_to_one_day(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub store in an isolated process; no real AS tables exist in unit tests.
				'abstract class ActionScheduler_Store { const STATUS_FAILED = "failed"; public static $wppo_1310_floor_queries = array(); public static $wppo_1310_floor_batches = array( array( 31 ), array() ); public static function instance() { return new ActionScheduler_1310_Floor_Store(); } public function query_actions( $query = array(), $query_type = "select" ) { self::$wppo_1310_floor_queries[] = $query; return array_shift( self::$wppo_1310_floor_batches ); } public function delete_action( $action_id ) {} }'
				. 'class ActionScheduler_1310_Floor_Store extends ActionScheduler_Store {}'
			);
		}
		if ( ! class_exists( 'ActionScheduler_QueueCleaner' ) ) {
			eval( 'class ActionScheduler_QueueCleaner {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub in an isolated process.
		}
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );
		\Brain\Monkey\Filters\expectApplied( 'wppo_purge_failed_actions' )->once()->with( false )->andReturn( true );
		\Brain\Monkey\Filters\expectApplied( 'action_scheduler_retention_period' )->once()->andReturn( 0 );
		\Brain\Monkey\Filters\expectApplied( 'action_scheduler_retention_period_for_failed' )->once()->andReturn( 0 );

		$deleted = Database_Cleanup::purge_failed_actions( 50, false );

		$this->assertSame( 1, $deleted );
		$this->assertNotEmpty( \ActionScheduler_Store::$wppo_1310_floor_queries );
		$cutoff = \ActionScheduler_Store::$wppo_1310_floor_queries[0]['modified'];
		$this->assertInstanceOf( \DateTime::class, $cutoff );
		$age = time() - $cutoff->getTimestamp();
		$this->assertGreaterThanOrEqual( 86400 - 120, $age );
		$this->assertLessThanOrEqual( 86400 + 120, $age );
	}

	/**
	 * A raised retention filter (e.g. 10 years) is capped at the documented
	 * 3-month bound instead of widening the purge window.
	 *
	 * Runs in a separate process with a stub store capturing the query.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_purge_cutoff_caps_raised_retention_at_three_months(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub store in an isolated process; no real AS tables exist in unit tests.
				'abstract class ActionScheduler_Store { const STATUS_FAILED = "failed"; public static $wppo_1310_cap_queries = array(); public static $wppo_1310_cap_batches = array( array( 41 ), array() ); public static function instance() { return new ActionScheduler_1310_Cap_Store(); } public function query_actions( $query = array(), $query_type = "select" ) { self::$wppo_1310_cap_queries[] = $query; return array_shift( self::$wppo_1310_cap_batches ); } public function delete_action( $action_id ) {} }'
				. 'class ActionScheduler_1310_Cap_Store extends ActionScheduler_Store {}'
			);
		}
		if ( ! class_exists( 'ActionScheduler_QueueCleaner' ) ) {
			eval( 'class ActionScheduler_QueueCleaner {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub in an isolated process.
		}
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );
		\Brain\Monkey\Filters\expectApplied( 'wppo_purge_failed_actions' )->once()->with( false )->andReturn( true );
		\Brain\Monkey\Filters\expectApplied( 'action_scheduler_retention_period' )->once()->andReturn( 10 * 365 * 86400 );
		\Brain\Monkey\Filters\expectApplied( 'action_scheduler_retention_period_for_failed' )->once()->andReturn( 10 * 365 * 86400 );

		$deleted = Database_Cleanup::purge_failed_actions( 50, false );

		$this->assertSame( 1, $deleted );
		$this->assertNotEmpty( \ActionScheduler_Store::$wppo_1310_cap_queries );
		$cutoff = \ActionScheduler_Store::$wppo_1310_cap_queries[0]['modified'];
		$this->assertInstanceOf( \DateTime::class, $cutoff );
		$age = time() - $cutoff->getTimestamp();
		$this->assertGreaterThanOrEqual( 3 * 2678400 - 300, $age );
		$this->assertLessThanOrEqual( 3 * 2678400 + 300, $age );
	}

	/**
	 * The CCSS pending probe sees a concurrent winner in the legacy group,
	 * not just the dedicated group, so the post-race re-check can never
	 * fall through to a double-schedule.
	 *
	 * Runs in a separate process with a group-sensitive lookup stub.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ccss_pending_probe_sees_legacy_group_winner(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			eval( 'function as_next_scheduled_action( $hook, $args = array(), $group = "" ) { if ( "performance_optimisation" === $group ) { return time() + 60; } return false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only group-sensitive lookup stub in an isolated process.
		}
		$method = new \ReflectionMethod( \PerformanceOptimise\Inc\Critical_CSS::class, 'has_pending_ccss_job' );
		$this->assertTrue( $method->invoke( null, 'wppo_generate_ccss', array( array( 'template_hash' => 'abc' ) ) ) );
	}

	/**
	 * The PageSpeed winner re-query returns the pending job ID so
	 * queue_scan() reports the job instead of failure after a lost
	 * unique-race.
	 *
	 * Runs in a separate process with a stub lookup plus store stub.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_pagespeed_winner_requery_returns_pending_id(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			eval( 'abstract class ActionScheduler_Store { const STATUS_PENDING = "pending"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub in an isolated process.
		}
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			eval( 'function as_get_scheduled_actions( $query = array(), $query_type = "select" ) { return array( 123 ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only lookup stub in an isolated process.
		}
		$method = new \ReflectionMethod( \PerformanceOptimise\Inc\Pagespeed::class, 'find_pending_job_id' );
		$args   = array(
			array(
				'url'      => 'https://example.test/',
				'strategy' => 'mobile',
			),
		);
		$this->assertSame( 123, $method->invoke( null, $args ) );
	}

	/**
	 * Scheduler failure (0 with nothing pending) makes regenerate_single()
	 * return 0 without asserting phantom `queued` status.
	 *
	 * Runs in a separate process with a legacy-shaped scheduler stub whose
	 * schedule call returns 0 and whose lookup reports nothing pending.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_regenerate_single_returns_zero_on_scheduler_failure(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			eval( 'function as_enqueue_async_action( $hook, $args = array(), $group = "" ) { return 0; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only legacy-shaped scheduler stub in an isolated process.
		}
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			eval( 'function as_has_scheduled_action( $hook, $args = array(), $group = "" ) { return false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only lookup stub in an isolated process.
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			eval( 'function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = "" ) { return 0; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only failing scheduler stub in an isolated process.
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			eval( 'function as_next_scheduled_action( $hook, $args = array(), $group = "" ) { return false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only lookup stub in an isolated process.
		}
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );
		\Brain\Monkey\Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		\Brain\Monkey\Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );
		\Brain\Monkey\Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/' . ltrim( (string) $path, '/' );
			}
		);
		\Brain\Monkey\Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		$transients = array();
		\Brain\Monkey\Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration = 0 ) use ( &$transients ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$transients[ $key ] = $value;
				return true;
			}
		);
		\Brain\Monkey\Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				return array_key_exists( $key, $transients ) ? $transients[ $key ] : false;
			}
		);

		$result = \PerformanceOptimise\Inc\Critical_CSS::regenerate_single( 'home', array( 'home' => 'Home' ) );

		$this->assertSame( 0, $result );
		foreach ( $transients as $value ) {
			$this->assertNotSame( 'queued', $value );
		}
	}

	/**
	 * A broken legacy guard must not fail the enqueue (fail-open).
	 *
	 * Regression coverage for stale test doubles (or a half-loaded
	 * scheduler) where as_has_scheduled_action() exists but throws: the
	 * helper treats the guard as "not scheduled" and still enqueues.
	 *
	 * Runs in a separate process with real global functions.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unique_helper_survives_throwing_legacy_guard(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			eval( 'function as_enqueue_async_action( $hook, $args = array(), $group = "" ) { $GLOBALS["wppo_1310_legacy_calls"][] = func_get_args(); return 55; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only legacy-shaped scheduler stub in an isolated process.
		}
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			eval( 'function as_has_scheduled_action( $hook, $args = array(), $group = "" ) { throw new \Exception( "stale guard" ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only broken guard stub in an isolated process.
		}
		$GLOBALS['wppo_1310_legacy_calls'] = array();
		$result                            = Util::enqueue_unique_async_action( 'wppo_used_css_generate', array( 'post_id' => 5 ), 'performance_optimisation' );
		$this->assertSame( 55, $result );
		$this->assertCount( 1, $GLOBALS['wppo_1310_legacy_calls'] );
	}

	/**
	 * The opt-in purge deletes only failed actions older than the cutoff, in
	 * batches, and stops when a batch is partial.
	 *
	 * Runs in a separate process with a stub store so no real AS tables exist.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_purge_failed_actions_batches_store_deletes(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub store in an isolated process; no real AS tables exist in unit tests.
				'abstract class ActionScheduler_Store { const STATUS_FAILED = "failed"; public static $wppo_1310_queries = array(); public static $wppo_1310_deleted = array(); public static $wppo_1310_batches = array( array( 11, 12 ), array() ); public static function instance() { return new ActionScheduler_1310_Stub_Store(); } public function query_actions( $query = array(), $query_type = "select" ) { self::$wppo_1310_queries[] = $query; return array_shift( self::$wppo_1310_batches ); } public function delete_action( $action_id ) { self::$wppo_1310_deleted[] = $action_id; } }'
				. 'class ActionScheduler_1310_Stub_Store extends ActionScheduler_Store {}'
			);
		}
		if ( ! class_exists( 'ActionScheduler_QueueCleaner' ) ) {
			eval( 'class ActionScheduler_QueueCleaner {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub in an isolated process.
		}
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );
		\Brain\Monkey\Functions\when( 'update_option' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'wp_kses_post' )->returnArg();
		\Brain\Monkey\Functions\when( '__' )->returnArg();
		\Brain\Monkey\Functions\when( 'delete_transient' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'is_multisite' )->justReturn( false );
		\Brain\Monkey\Filters\expectApplied( 'wppo_purge_failed_actions' )->once()->with( false )->andReturn( true );
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Database table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Last error state.
			 *
			 * @var string
			 */
			public $last_error = '';

			/**
			 * Simulate a failed insert so Log::add() skips option writes.
			 *
			 * @param string $table Table name (unused).
			 * @param array  $data Row data (unused).
			 * @param mixed  $format Format (unused).
			 * @return false
			 */
			public function insert( $table = null, $data = array(), $format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return false;
			}
		};

		$deleted = Database_Cleanup::purge_failed_actions( 50 );

		$this->assertSame( 2, $deleted );
		$this->assertSame( array( 11, 12 ), \ActionScheduler_Store::$wppo_1310_deleted );
		$this->assertNotEmpty( \ActionScheduler_Store::$wppo_1310_queries );
		foreach ( \ActionScheduler_Store::$wppo_1310_queries as $query ) {
			$this->assertSame( 'failed', $query['status'] );
			$this->assertSame( '<=', $query['modified_compare'] );
			$this->assertLessThanOrEqual( 100, $query['per_page'] );
		}
	}

	/**
	 * When every delete_action() throws, the purge stops after the first
	 * zero-progress iteration instead of re-querying the same batch for
	 * all 10 iterations.
	 *
	 * Runs in a separate process with a stub store whose delete always
	 * throws and whose query always returns the same IDs.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_purge_failed_actions_breaks_on_zero_progress(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub store in an isolated process; no real AS tables exist in unit tests.
				'abstract class ActionScheduler_Store { const STATUS_FAILED = "failed"; public static $wppo_1310_zp_queries = 0; public static function instance() { return new ActionScheduler_1310_ZeroProgress_Store(); } public function query_actions( $query = array(), $query_type = "select" ) { ++self::$wppo_1310_zp_queries; return array( 21, 22 ); } public function delete_action( $action_id ) { throw new \Exception( "locked row" ); } }'
				. 'class ActionScheduler_1310_ZeroProgress_Store extends ActionScheduler_Store {}'
			);
		}
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );
		\Brain\Monkey\Filters\expectApplied( 'wppo_purge_failed_actions' )->once()->with( false )->andReturn( true );

		$deleted = Database_Cleanup::purge_failed_actions( 50 );

		$this->assertSame( 0, $deleted );
		$this->assertSame( 1, \ActionScheduler_Store::$wppo_1310_zp_queries );
	}
}
