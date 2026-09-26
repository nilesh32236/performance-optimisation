<?php
/**
 * Tests for the Redis outage fail-open bypass (issue #1233).
 *
 * Covers the class-layer wppo_redis_outage_fallback() helper: a dead Redis
 * arms an in-request bypass plus a persistent wppo_settings status flag so
 * repeat calls short-circuit (single reconnect attempt max per request),
 * get_status() reports the degraded state, and ping()/re-enable clear the
 * flag on recovery.
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Fake Redis client for recovery-path tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Fake_Redis_Client {

	/**
	 * Successful ping.
	 *
	 * @return bool
	 */
	public function ping() {
		return true;
	}

	/**
	 * No-op close.
	 *
	 * @return void
	 */
	public function close() {
	}

	/**
	 * Minimal INFO payload.
	 *
	 * @param string|null $section Section name.
	 * @return array
	 */
	public function info( $section = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'redis_version'     => '7.0.0',
			'uptime_in_seconds' => 10,
			'connected_clients' => 1,
			'used_memory_human' => '1M',
			'db0'               => 'keys=1,expires=0,avg_ttl=0',
		);
	}
}

/**
 * Outage fallback tests.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheOutageFallbackTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * In-memory transients store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Counted wppo_redis_connect() invocations.
	 *
	 * @var int
	 */
	private int $connect_calls = 0;

	/**
	 * Scripted connection outcome: 'error' or 'success'.
	 *
	 * @var string
	 */
	private string $connect_result = 'error';

	/**
	 * Original $wpdb, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();

		Util::reset_runtime_caches();
		Object_Cache::reset_outage_bypass();

		$test = $this;

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( $test ) {
				return array_key_exists( $name, $test->options ) ? $test->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( $test ) {
				$test->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( $test ) {
				unset( $test->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) use ( $test ) {
				return array_key_exists( $name, $test->transients ) ? $test->transients[ $name ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) use ( $test ) {
				$test->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) use ( $test ) {
				unset( $test->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );

		// Script the network edge: count every real connection attempt so the
		// single-reconnect-attempt budget is assertable. Stubbed before the
		// helper file loads, so ensure_redis_helper() sees it as available
		// and never loads the real implementation.
		Functions\when( 'wppo_redis_connect' )->alias(
			function ( $config ) use ( $test ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature must match wppo_redis_connect().
				++$test->connect_calls;
				if ( 'success' === $test->connect_result ) {
					return new WPPO_Fake_Redis_Client();
				}
				return new \WP_Error( 'connection_refused', 'Connection refused.' );
			}
		);

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->original_wpdb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']     = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Record an insert.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Row data.
			 * @param array  $format Formats.
			 * @return int
			 */
			public function insert( $table, $data, $format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return 1;
			}
		};
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = $this->original_wpdb;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		Object_Cache::reset_outage_bypass();
		\Brain\Monkey\tearDown();
		if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Failure arms the bypass + status flag; repeats short-circuit.
	 */
	public function test_fallback_arms_bypass_and_flag_on_failure(): void {
		$manager = new Object_Cache();

		$first = $manager->wppo_redis_outage_fallback( array() );

		$this->assertInstanceOf( \WP_Error::class, $first );
		$this->assertSame( 1, $this->connect_calls, 'First failure must cost exactly one connection attempt.' );
		$this->assertTrue( Object_Cache::is_outage_bypassed() );
		$this->assertTrue( $manager->is_outage_flagged(), 'Persistent outage flag must be armed.' );

		$second = $manager->wppo_redis_outage_fallback( array() );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 1, $this->connect_calls, 'Repeat calls in the same request must short-circuit without another reconnect.' );
		$this->assertSame( $first->get_error_code(), $second->get_error_code() );
	}

	/**
	 * Status reports the degraded state without another reconnect.
	 */
	public function test_status_reports_bypassed_without_reconnect(): void {
		$manager = new Object_Cache();
		$manager->wppo_redis_outage_fallback( array() );
		$this->assertSame( 1, $this->connect_calls );

		$status = $manager->get_status();

		$this->assertArrayHasKey( 'bypassed', $status );
		$this->assertTrue( $status['bypassed'] );
		$this->assertFalse( $status['redis_reachable'] );
		$this->assertSame( 1, $this->connect_calls, 'Status on the bypass path must not pay another connection timeout.' );
	}

	/**
	 * Successful ping clears both the in-request bypass and the flag.
	 */
	public function test_ping_success_clears_bypass_and_flag(): void {
		$manager = new Object_Cache();
		$manager->wppo_redis_outage_fallback( array() );
		$this->assertTrue( $manager->is_outage_flagged() );

		$this->connect_result = 'success';
		$result               = $manager->ping( array() );

		$this->assertTrue( $result );
		$this->assertFalse( Object_Cache::is_outage_bypassed() );
		$this->assertFalse( $manager->is_outage_flagged(), 'Recovery must clear the persistent flag.' );

		// Normal caching resumes: the next fallback connects fresh.
		$calls_before = $this->connect_calls;
		$connection   = $manager->wppo_redis_outage_fallback( array() );
		$this->assertNotInstanceOf( \WP_Error::class, $connection );
		$this->assertSame( $calls_before + 1, $this->connect_calls );
	}

	/**
	 * Ping failure while bypassed forces one real attempt and re-arms.
	 */
	public function test_ping_failure_forces_attempt_and_rearms(): void {
		$manager = new Object_Cache();
		$manager->wppo_redis_outage_fallback( array() );
		$this->assertSame( 1, $this->connect_calls );

		$result = $manager->ping( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 2, $this->connect_calls, 'Ping is the recovery probe and must always attempt once.' );
		$this->assertTrue( Object_Cache::is_outage_bypassed() );
		$this->assertTrue( $manager->is_outage_flagged() );
	}

	/**
	 * Schema allowlists the additive outage flag key.
	 */
	public function test_schema_allowlists_outage_flag(): void {
		$schema = Util::get_settings_schema();
		$this->assertArrayHasKey( 'object_cache', $schema );
		$this->assertArrayHasKey( 'outage_bypassed', $schema['object_cache'] );
	}

	/**
	 * Migration backfills the additive flag without touching other keys.
	 */
	public function test_migration_backfills_outage_flag(): void {
		$this->options['wppo_settings'] = array(
			'object_cache' => array( 'host' => '127.0.0.1' ),
		);

		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setValue( $main, array( 'object_cache' => array( 'host' => '127.0.0.1' ) ) );

		$main->maybe_migrate_object_cache_outage_flag();

		$stored = $this->options['wppo_settings'];
		$this->assertArrayHasKey( 'outage_bypassed', $stored['object_cache'] );
		$this->assertFalse( $stored['object_cache']['outage_bypassed'] );
		$this->assertSame( '127.0.0.1', $stored['object_cache']['host'], 'Existing keys must be preserved verbatim.' );

		// Idempotent: a second run writes nothing new.
		$main->maybe_migrate_object_cache_outage_flag();
		$this->assertFalse( $this->options['wppo_settings']['object_cache']['outage_bypassed'] );
	}
}
