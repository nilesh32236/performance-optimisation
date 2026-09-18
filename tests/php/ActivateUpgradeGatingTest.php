<?php
/**
 * Tests for the version-gated one-shot upgrade routines (issue #1464).
 *
 * Fresh installs must allocate zero `*_migrated` rows, steady-state runs
 * must be zero-write no-ops, unparseable stored versions must fail open
 * (skip the shim, still roll the version forward), and genuine upgrades
 * must run the one-shot backfills exactly once.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Activate;
use Brain\Monkey\Functions;

/**
 * Version-gating tests for Activate::maybe_run_upgrades().
 *
 * @package PerformanceOptimise\Tests
 */
class ActivateUpgradeGatingTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Original global $wpdb before it is swapped for the test fake.
	 *
	 * @var object
	 */
	private $original_wpdb;

	/**
	 * Recorded update_option() writes (each entry is array{key, value}).
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private $writes = array();

	/**
	 * Number of wp_set_option_autoload() invocations (autoload backfills).
	 *
	 * @var int
	 */
	private $autoload_calls = 0;

	/**
	 * Number of Log::add() activity rows written via the $wpdb fake.
	 *
	 * @var int
	 */
	private $log_calls = 0;

	/**
	 * Set up BrainMonkey, an in-memory option store, and a fake $wpdb.
	 */
	protected function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		parent::setUp();

		$this->writes         = array();
		$this->autoload_calls = 0;
		$this->log_calls      = 0;

		$test = $this;

		global $wpdb;
		$this->original_wpdb = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb                = new class( $test ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			/**
			 * Owning test case (for counting inserts).
			 *
			 * @var ActivateUpgradeGatingTest
			 */
			private $test;

			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Constructor.
			 *
			 * @param ActivateUpgradeGatingTest $test Owning test case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * Record an insert into the activity log table.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Data to insert.
			 * @param array  $format Format array.
			 * @return int
			 */
			public function insert( $table, $data, $format = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				$this->test->count_log_call();
				return 1;
			}
		};

		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_set_option_autoload' )->alias(
			function ( $option, $autoload ) use ( $test ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Mirrors the WP signature.
				$test->count_autoload_call();
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( $test ) {
				$test->record_write( $key, $value );
				return true;
			}
		);
	}

	/**
	 * Restore the original $wpdb and tear down BrainMonkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		global $wpdb;
		$wpdb = $this->original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tearDown();
	}

	/**
	 * Record an update_option() write.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Option value.
	 * @return void
	 */
	public function record_write( $key, $value ): void {
		$this->writes[] = array( $key, $value );
	}

	/**
	 * Count an autoload-backfill invocation.
	 *
	 * @return void
	 */
	public function count_autoload_call(): void {
		++$this->autoload_calls;
	}

	/**
	 * Count an activity-log insert.
	 *
	 * @return void
	 */
	public function count_log_call(): void {
		++$this->log_calls;
	}

	/**
	 * Stub get_option() from an in-memory map.
	 *
	 * @param array<string, mixed> $options Option fixtures.
	 * @return void
	 */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) use ( $options ) {
				return array_key_exists( $key, $options ) ? $options[ $key ] : $default_value;
			}
		);
	}

	/**
	 * Assert that no migration-marker row was written.
	 */
	private function assert_no_migrated_rows(): void {
		foreach ( $this->writes as $entry ) {
			$this->assertStringNotContainsString( '_migrated', (string) $entry[0], 'must not allocate migration-marker rows' );
		}
	}

	/**
	 * Fresh install: only the version row is recorded, zero migrated rows.
	 */
	public function test_fresh_install_allocates_only_version_row(): void {
		$this->stub_options( array() );

		Activate::maybe_run_upgrades( true );

		$this->assertSame( array( array( 'wppo_version', WPPO_VERSION ) ), $this->writes );
		$this->assert_no_migrated_rows();
		$this->assertSame( 0, $this->autoload_calls );
	}

	/**
	 * Steady state (stored version is current): zero reads beyond the
	 * version check, zero writes, backfills skipped.
	 */
	public function test_current_version_is_a_noop(): void {
		$this->stub_options( array( 'wppo_version' => WPPO_VERSION ) );

		Activate::maybe_run_upgrades( false );

		$this->assertSame( array(), $this->writes );
		$this->assert_no_migrated_rows();
		$this->assertSame( 0, $this->autoload_calls );
	}

	/**
	 * Fail-open: an empty stored version skips the shim but still rolls
	 * the version forward instead of re-running writes every request.
	 */
	public function test_empty_version_fails_open(): void {
		$this->stub_options( array( 'wppo_version' => '' ) );

		Activate::maybe_run_upgrades( false );

		$this->assertSame( array( array( 'wppo_version', WPPO_VERSION ) ), $this->writes );
		$this->assert_no_migrated_rows();
		$this->assertSame( 0, $this->autoload_calls );
	}

	/**
	 * Fail-open: a garbage stored version is treated as already-migrated.
	 */
	public function test_garbage_version_fails_open(): void {
		$this->stub_options( array( 'wppo_version' => 'not-a-version!!' ) );

		Activate::maybe_run_upgrades( false );

		$this->assertSame( array( array( 'wppo_version', WPPO_VERSION ) ), $this->writes );
		$this->assert_no_migrated_rows();
		$this->assertSame( 0, $this->autoload_calls );
	}

	/**
	 * Upgrade at/above the legacy floor: backfills run once, the version
	 * rolls forward, and no legacy flush (or flush log) happens.
	 */
	public function test_upgrade_at_floor_runs_backfills_and_rolls_version(): void {
		$this->stub_options( array( 'wppo_version' => '1.8.1' ) );

		Activate::maybe_run_upgrades( false );

		// The three one-time autoload backfills ran exactly once.
		$this->assertSame( 3, $this->autoload_calls );
		$this->assert_no_migrated_rows();

		// The version rolled forward without a flush log entry.
		$this->assertContains( array( 'wppo_version', WPPO_VERSION ), $this->writes );
		$this->assertSame( 0, $this->log_calls );
	}

	/**
	 * Second run after an upgrade is a steady-state no-op (one-shot).
	 */
	public function test_second_run_after_upgrade_is_a_noop(): void {
		$this->stub_options( array( 'wppo_version' => '1.8.1' ) );

		Activate::maybe_run_upgrades( false );

		$this->assertSame( 3, $this->autoload_calls );

		// Simulate the rolled-forward version on the next request.
		$this->writes         = array();
		$this->autoload_calls = 0;
		$this->stub_options( array( 'wppo_version' => WPPO_VERSION ) );

		Activate::maybe_run_upgrades( false );

		$this->assertSame( array(), $this->writes );
		$this->assertSame( 0, $this->autoload_calls );
	}
}
