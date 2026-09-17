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
	 */
	public function test_unique_helper_legacy_dedup_returns_zero(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\expect( 'as_enqueue_async_action' )->never();
		$this->assertSame( 0, Util::enqueue_unique_async_action( 'wppo_crawler_warm', array( 'https://example.test/' ), 'performance_optimisation' ) );
	}

	/**
	 * Legacy scheduler path: no duplicate present, falls back to the
	 * 3-argument enqueue and returns its ID.
	 */
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
	}

	/**
	 * Legacy single-action path: falls back to the 4-argument schedule call.
	 */
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
		$result = Util::enqueue_unique_async_action( 'wppo_used_css_generate', array( 'post_id' => 5 ), 'performance_optimisation' );
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
}
