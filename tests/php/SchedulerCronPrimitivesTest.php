<?php
/**
 * Regression tests for ARCH-012 cron scheduling primitives (issue #1550).
 *
 * Pins that the WP-Cron choke points on `Scheduler` (`next_scheduled()`,
 * `schedule_recurring_event()`, `schedule_single_event()`) preserve the
 * exact hooks/args/recurrences/timings the `Cron` call sites used inline,
 * that the recurring matrix schedules each job once with no duplicates on
 * re-run, that the sitemap fan-out 500-cap is intact through the delegate,
 * and that the Action Scheduler non-unique path (`Pagespeed::queue_scan()`)
 * is preserved untouched.
 *
 * Brain Monkey isolation notes (ARCH-006 precedent): the API-missing test
 * runs in a separate process because cron-function declarations persist per
 * process; every other test re-stubs the cron functions it touches.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Scheduler;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Scheduler WP-Cron primitive tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SchedulerCronPrimitivesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Recurring schedules recorded by the wp_schedule_event() stub.
	 *
	 * @var array<string, string>
	 */
	private array $recurring = array();

	/**
	 * Single events recorded by the wp_schedule_single_event() stub.
	 *
	 * @var array<int, array{0: int, 1: string, 2: array}>
	 */
	private array $singles = array();

	/**
	 * Hooks recorded by the wp_clear_scheduled_hook() stub.
	 *
	 * @var string[]
	 */
	private array $cleared = array();

	/**
	 * Install option + cron stubs backed by the in-memory stores.
	 *
	 * @return void
	 */
	private function install_cron_stubs(): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) {
				$this->recurring[ (string) $hook ] = (string) $recurrence;
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = array() ) {
				$this->singles[] = array( (int) $timestamp, (string) $hook, (array) $args );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->cleared[] = (string) $hook;
				return 0;
			}
		);
		Functions\when( 'wp_rand' )->justReturn( 100 );
		Object_Cache::reset_circuit_memo_for_tests();
	}

	/**
	 * Settings array with every feature that schedules a recurring cron enabled.
	 *
	 * @return array<string, mixed>
	 */
	private function all_features_enabled_settings(): array {
		return array(
			'preload_settings'  => array(
				'enablePreloadCache' => true,
			),
			'llms_txt'          => array(
				'enabled' => true,
			),
			'file_optimisation' => array(
				'removeUnusedCSS' => true,
			),
		);
	}

	/**
	 * Build a Cron instance without invoking the constructor.
	 *
	 * @return Cron
	 */
	private function make_cron(): Cron {
		$reflection = new \ReflectionClass( Cron::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * The next_scheduled() probe forwards hook and args, returning the timestamp.
	 */
	public function test_next_scheduled_forwards_hook_and_args(): void {
		$seen = array();
		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( $hook, $args = array() ) use ( &$seen ) {
				$seen = array( $hook, $args );
				return 1720000000;
			}
		);

		$this->assertSame( 1720000000, Scheduler::next_scheduled( 'wppo_page_cron_batch', array( 'x' ) ) );
		$this->assertSame( array( 'wppo_page_cron_batch', array( 'x' ) ), $seen );
	}

	/**
	 * The next_scheduled() probe returns false when nothing is scheduled.
	 */
	public function test_next_scheduled_returns_false_when_unscheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$this->assertFalse( Scheduler::next_scheduled( 'wppo_page_cron_batch' ) );
	}

	/**
	 * The schedule_recurring_event() wrapper schedules with identical hook, timestamp, and recurrence.
	 */
	public function test_schedule_recurring_event_schedules_when_not_scheduled(): void {
		$seen = array();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $timestamp, $recurrence, $hook ) use ( &$seen ) {
				$seen = array( $timestamp, $recurrence, $hook );
				return true;
			}
		);

		$at = time();
		$this->assertTrue( Scheduler::schedule_recurring_event( 'wppo_img_conversion', $at, 'hourly' ) );
		$this->assertSame( array( $at, 'hourly', 'wppo_img_conversion' ), $seen );
	}

	/**
	 * The schedule_recurring_event() wrapper is a no-op returning true when already scheduled.
	 */
	public function test_schedule_recurring_event_skips_when_already_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 1720000000 );
		$calls = 0;
		Functions\when( 'wp_schedule_event' )->alias(
			static function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);

		$this->assertTrue( Scheduler::schedule_recurring_event( 'wppo_img_conversion', time(), 'hourly' ) );
		$this->assertSame( 0, $calls );
	}

	/**
	 * The schedule_recurring_event() wrapper returns false when the schedule call fails.
	 */
	public function test_schedule_recurring_event_returns_false_on_failure(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( false );

		$this->assertFalse( Scheduler::schedule_recurring_event( 'wppo_img_conversion', time(), 'hourly' ) );
	}

	/**
	 * The schedule_single_event() wrapper forwards timestamp, hook, and args unchanged.
	 */
	public function test_schedule_single_event_forwards_timestamp_hook_args(): void {
		$seen = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $timestamp, $hook, $args = array() ) use ( &$seen ) {
				$seen = array( $timestamp, $hook, $args );
				return true;
			}
		);

		$at = time() + 60;
		$this->assertTrue( Scheduler::schedule_single_event( $at, 'wppo_page_cron_batch', array( 'k' ) ) );
		$this->assertSame( array( $at, 'wppo_page_cron_batch', array( 'k' ) ), $seen );
	}

	/**
	 * The schedule_single_event() wrapper returns false when the schedule call fails.
	 */
	public function test_schedule_single_event_returns_false_on_failure(): void {
		Functions\when( 'wp_schedule_single_event' )->justReturn( false );

		$this->assertFalse( Scheduler::schedule_single_event( time() + 60, 'wppo_page_cron_batch' ) );
	}

	/**
	 * All three primitives fail open when the WP-Cron API is unavailable.
	 *
	 * Runs in a separate process where no cron functions exist (Brain Monkey
	 * declarations persist per process, so absence can only be observed in a
	 * fresh process).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_primitives_fail_open_when_api_missing(): void {
		$this->assertFalse( function_exists( 'wp_next_scheduled' ) );
		$this->assertFalse( function_exists( 'wp_schedule_event' ) );
		$this->assertFalse( function_exists( 'wp_schedule_single_event' ) );
		$this->assertFalse( Scheduler::next_scheduled( 'wppo_page_cron_batch' ) );
		$this->assertFalse( Scheduler::schedule_recurring_event( 'wppo_img_conversion', time(), 'hourly' ) );
		$this->assertFalse( Scheduler::schedule_single_event( time() + 60, 'wppo_page_cron_batch' ) );
	}

	/**
	 * The matrix schedules every recurring job once with identical recurrences.
	 */
	public function test_schedule_cron_jobs_schedules_each_job_once(): void {
		$this->install_cron_stubs();
		$this->options['wppo_settings'] = $this->all_features_enabled_settings();

		$cron = $this->make_cron();
		$cron->schedule_cron_jobs();

		$expected = array(
			'wppo_page_cron_hook'        => 'every_5_hours',
			'wppo_img_conversion'        => 'hourly',
			'wppo_database_cleanup_cron' => 'daily',
			'wppo_web_vitals_rescan'     => 'daily',
			'wppo_llms_txt_daily'        => 'daily',
			'wppo_used_css_cron'         => 'every_5_hours',
			'wppo_ccss_regeneration'     => 'daily',
		);
		$this->assertSame( $expected, $this->recurring );
		// Healthy circuit: the recovery probe is cleared, not scheduled.
		$this->assertArrayNotHasKey( 'wppo_object_cache_probe', $this->recurring );
		$this->assertContains( 'wppo_object_cache_probe', $this->cleared );
	}

	/**
	 * Re-running the matrix schedules nothing twice (next-scheduled guard intact).
	 */
	public function test_schedule_cron_jobs_no_duplicates_on_rerun(): void {
		$this->install_cron_stubs();
		$this->options['wppo_settings'] = $this->all_features_enabled_settings();

		// Back the probe with the recorded set so the second run observes the first.
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				return isset( $this->recurring[ (string) $hook ] ) ? time() : false;
			}
		);
		$count = 0;
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) use ( &$count ) {
				++$count;
				$this->recurring[ (string) $hook ] = (string) $recurrence;
				return true;
			}
		);

		$cron = $this->make_cron();
		$cron->schedule_cron_jobs();
		$cron->schedule_cron_jobs();

		$this->assertSame( 7, $count );
		$this->assertCount( 7, $this->recurring );
	}

	/**
	 * Disabling preload clears leftover preload events instead of scheduling.
	 */
	public function test_schedule_cron_jobs_clears_preload_hooks_when_disabled(): void {
		$this->install_cron_stubs();
		$this->options['wppo_settings'] = array(
			'preload_settings' => array(
				'enablePreloadCache' => false,
			),
		);

		$cron = $this->make_cron();
		$cron->schedule_cron_jobs();

		foreach ( array( 'wppo_page_cron_hook', 'wppo_page_cron_batch', 'wppo_generate_static_page', 'wppo_generate_static_url' ) as $hook ) {
			$this->assertContains( $hook, $this->cleared );
		}
		$this->assertArrayNotHasKey( 'wppo_page_cron_hook', $this->recurring );
	}

	/**
	 * Sitemap fan-out still caps at 500 single events through the delegate.
	 */
	public function test_sitemap_fan_out_cap_intact_through_delegate(): void {
		$this->install_cron_stubs();

		$locs = '';
		for ( $i = 1; $i <= 600; $i++ ) {
			$locs .= '<url><loc>http://example.com/page-' . $i . '/</loc></url>';
		}
		$body = '<?xml version="1.0"?><urlset>' . $locs . '</urlset>';

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test-only URL parsing parity.
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => 'ok' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);

		$cron       = $this->make_cron();
		$reflection = new \ReflectionMethod( Cron::class, 'schedule_sitemap_url_jobs' );
		$reflection->invoke( $cron, array() );

		$this->assertCount( 500, $this->singles );
		foreach ( $this->singles as $event ) {
			$this->assertSame( 'wppo_generate_static_url', $event[1] );
		}
	}

	/**
	 * The Web Vitals rescan still uses the non-unique Pagespeed path (hook preserved).
	 *
	 * Pins that ARCH-012 did not reroute the intentionally-duplicated AS call
	 * through Scheduler unique-enqueue: every enqueue lands on the Pagespeed
	 * hook with both strategies.
	 */
	public function test_web_vitals_rescan_keeps_non_unique_pagespeed_path(): void {
		$this->install_cron_stubs();
		$this->options['wppo_settings'] = array(
			'performance_audit' => array(
				'auto_rescan' => 'daily',
			),
		);
		Scheduler::reset_action_scheduler_unique_cache();

		$hooks = array();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$hooks ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$hooks[] = (string) $hook;
				return 7;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$cron = $this->make_cron();
		$cron->web_vitals_rescan_cron();

		$this->assertSame( array( 'wppo_pagespeed_scan', 'wppo_pagespeed_scan' ), $hooks );
		$this->assertArrayHasKey( 'wppo_web_vitals_last_rescan', $this->options );
	}
}
