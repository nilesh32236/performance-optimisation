<?php
/**
 * Tests for the object-cache circuit breaker (issue #906).
 *
 * Covers the early-boot file counter in templates/object-cache.php
 * (windowing, threshold filter, foreign-drop-in safety), the plugin-side
 * API in Object_Cache (status fields, auto-disable, probe recovery,
 * multisite transient prefixing), the admin notice re-arm behaviour, the
 * REST config-merge for manual recovery, and the cron probe scheduling.
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test fixtures are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Admin_Notices;
use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Rest;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Scriptable Object_Cache subclass replacing the Redis network edge.
 *
 * The probe_recovery() orchestration (ping, enable, clear, log) runs for
 * real; only the network calls are scripted, because a live Redis server
 * cannot be assumed in unit tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Scriptable_Object_Cache extends Object_Cache {

	/**
	 * Scripted ping() result.
	 *
	 * @var bool|\WP_Error
	 */
	public static $ping_result = true;

	/**
	 * Scripted enable() result.
	 *
	 * @var bool|\WP_Error
	 */
	public static $enable_result = true;

	/**
	 * Config arrays received by enable(), in call order.
	 *
	 * @var array<int, array>
	 */
	public static $enable_calls = array();

	/**
	 * Number of ping() calls.
	 *
	 * @var int
	 */
	public static $ping_calls = 0;

	/**
	 * Reset scripted state between tests.
	 *
	 * @return void
	 */
	public static function reset_script(): void {
		self::$ping_result   = true;
		self::$enable_result = true;
		self::$enable_calls  = array();
		self::$ping_calls    = 0;
	}

	/**
	 * Scripted ping.
	 *
	 * @param array $config Connection configuration.
	 * @return bool|\WP_Error
	 */
	public function ping( $config = array() ) {
		++self::$ping_calls;
		return self::$ping_result;
	}

	/**
	 * Scripted enable (records the config it would restore with).
	 *
	 * @param array $config Connection configuration.
	 * @return bool|\WP_Error
	 */
	public function enable( $config ) {
		self::$enable_calls[] = $config;
		return self::$enable_result;
	}
}

/**
 * In-memory $wpdb recorder for Log::add() assertions.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Wpdb_Recorder {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Recorded insert payloads.
	 *
	 * @var array<int, array>
	 */
	public $inserts = array();

	/**
	 * Record an insert.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Row data.
	 * @param array  $format Formats.
	 * @return int Always 1 (truthy, like a successful insert).
	 */
	public function insert( $table, $data, $format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->inserts[] = array(
			'table' => $table,
			'data'  => $data,
		);
		return 1;
	}
}

/**
 * Circuit-breaker tests.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheCircuitBreakerTest extends \PHPUnit\Framework\TestCase {
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
	 * Whether is_multisite() reports true.
	 *
	 * @var bool
	 */
	private bool $multisite = false;

	/**
	 * Override for the wppo_object_cache_dropin_path filter.
	 *
	 * @var string|null
	 */
	private $dropin_override = null;

	/**
	 * Override for the threshold filter.
	 *
	 * @var int|null
	 */
	private $threshold_override = null;

	/**
	 * Override for the window filter.
	 *
	 * @var int|null
	 */
	private $window_override = null;

	/**
	 * Hooks recorded by the wp_schedule_event() stub.
	 *
	 * @var string[]
	 */
	private array $scheduled = array();

	/**
	 * Hooks recorded by the wp_clear_scheduled_hook() stub.
	 *
	 * @var string[]
	 */
	private array $cleared_hooks = array();

	/**
	 * Filesystem mock backing Util::init_filesystem().
	 *
	 * @var object
	 */
	private $fs;

	/**
	 * Original $wpdb, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Original error_log ini value, restored in tearDown().
	 *
	 * @var string|false
	 */
	private $old_error_log = false;

	/**
	 * Files created under WP_CONTENT_DIR, removed in tearDown().
	 *
	 * @var string[]
	 */
	private array $tracked_files = array();

	/**
	 * Temp drop-in directory, removed in tearDown().
	 *
	 * @var string|null
	 */
	private $temp_dir = null;

	/**
	 * Whether this test created WP_CONTENT_DIR itself.
	 *
	 * @var bool
	 */
	private bool $content_dir_created = false;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();

		// The trait's setUp() is shadowed by this method, so repeat its
		// static-cache resets here. Without clear_settings_cache(),
		// Util::get_settings() would serve a stale (empty) config cached by
		// an earlier test and probe tests would miss stored Redis settings.
		Util::reset_cached_home_urls();
		Util::clear_settings_cache();
		Util::clear_permalink_cache();

		$test = $this;

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( $test ) {
				if ( 'wppo_object_cache_dropin_path' === $hook && null !== $test->dropin_override ) {
					return $test->dropin_override;
				}
				if ( 'wppo_object_cache_circuit_breaker_threshold' === $hook && null !== $test->threshold_override ) {
					return $test->threshold_override;
				}
				if ( 'wppo_object_cache_circuit_breaker_window' === $hook && null !== $test->window_override ) {
					return $test->window_override;
				}
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
		// Realistic sanitize_text_field (core trims + strips tags; the
		// common stub is a plain passthrough, which would hide normalizer
		// bugs in the Redis config merge tests).
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				// Test double mimicking core: strip_tags() stands in for wp_strip_all_tags().
				// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
				return is_string( $value ) ? trim( strip_tags( $value ) ) : $value;
			}
		);
		Functions\when( 'is_multisite' )->alias(
			function () use ( $test ) {
				return $test->multisite;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		// The drop-in prefers wp_strip_all_tags() with a strip_tags()
		// fallback. Other suites may declare the function first, which
		// flips the drop-in onto this branch — stub it either way.
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) use ( $test ) {
				$test->scheduled[] = $hook;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( $test ) {
				$test->cleared_hooks[] = $hook;
				return 0;
			}
		);
		// NOTE: wp_cache_flush() is intentionally NOT stubbed here — the real
		// drop-in defines it at bootstrap (before Patchwork boots), so
		// Brain Monkey cannot redefine it. No path under test calls it:
		// probe tests script enable(), and auto-disable never flushes.
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'date_i18n' )->alias(
			static function ( $format, $timestamp = null ) {
				return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com/wp-admin/' . (string) $path;
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url ) {
				return (string) $url . '&_wpnonce=testnonce';
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null ) {
				if ( is_array( $key ) ) {
					return 'http://example.com/wp-admin/?' . http_build_query( $key );
				}
				return 'http://example.com/wp-admin/?' . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		);

		// Filesystem mock proxying to real file functions so marker checks,
		// renames and state writes behave exactly like production.
		$this->fs = new class() {
			/**
			 * Read a file.
			 *
			 * @param string $path Path.
			 * @return string|false
			 */
			public function get_contents( $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
				$contents = @file_get_contents( $path );
				return $contents;
			}

			/**
			 * Write a file.
			 *
			 * @param string $path Path.
			 * @param string $contents Contents.
			 * @param mixed  $mode Ignored.
			 * @return bool
			 */
			public function put_contents( $path, $contents, $mode = false ) {
				unset( $mode );
				$dir = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged
					@mkdir( $dir, 0777, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged
				return false !== @file_put_contents( $path, $contents );
			}

			/**
			 * Delete a file.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function delete( $path ) {
				if ( ! file_exists( $path ) ) {
					return false;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
				return @unlink( $path );
			}

			/**
			 * Move a file.
			 *
			 * @param string $source Source.
			 * @param string $destination Destination.
			 * @param bool   $overwrite Overwrite.
			 * @return bool
			 */
			public function move( $source, $destination, $overwrite = false ) {
				if ( $overwrite && file_exists( $destination ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $destination );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
				return @rename( $source, $destination );
			}

			/**
			 * Copy a file.
			 *
			 * @param string $source Source.
			 * @param string $destination Destination.
			 * @param bool   $overwrite Overwrite.
			 * @param mixed  $mode Ignored.
			 * @return bool
			 */
			public function copy( $source, $destination, $overwrite = true, $mode = false ) {
				unset( $overwrite, $mode );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy,WordPress.PHP.NoSilencedErrors.Discouraged
				return @copy( $source, $destination );
			}

			/**
			 * Check existence.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function exists( $path ) {
				return file_exists( $path );
			}
		};

		Functions\when( 'WP_Filesystem' )->alias(
			function () use ( $test ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$GLOBALS['wp_filesystem'] = $test->fs;
				return true;
			}
		);

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->original_wpdb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']     = new WPPO_Wpdb_Recorder();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		// Trip paths error_log() exactly once per trip; silence it in tests.
		// phpcs:ignore WordPress.PHP.IniSet -- Silence expected error_log output in tests.
		$this->old_error_log = ini_set( 'error_log', '/dev/null' );

		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( WP_CONTENT_DIR, 0777, true );
			$this->content_dir_created = true;
		}

		WPPO_Scriptable_Object_Cache::reset_script();
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		foreach ( $this->tracked_files as $file ) {
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $file );
			}
		}
		$this->tracked_files = array();

		// Circuit artefacts under WP_CONTENT_DIR are exclusively created by
		// this class (drop-in trips, auto-disable). Remove them
		// unconditionally: a lingering bridge file would read as "circuit
		// open" in later tests (and in unrelated suites sharing the process).
		foreach ( array( 'wppo-redis-disabled.json', 'wppo-redis-failures.json', 'wppo-redis-down.flag', 'object-cache.php.wppo-disabled' ) as $artefact ) {
			$path = WP_CONTENT_DIR . '/' . $artefact;
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $path );
			}
		}

		if ( null !== $this->temp_dir && is_dir( $this->temp_dir ) ) {
			$leftovers = glob( $this->temp_dir . '/*' );
			if ( is_array( $leftovers ) ) {
				foreach ( $leftovers as $leftover ) {
					if ( is_file( $leftover ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
						@unlink( $leftover );
					}
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $this->temp_dir );
			$this->temp_dir = null;
		}

		if ( $this->content_dir_created && is_dir( WP_CONTENT_DIR ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( WP_CONTENT_DIR );
			$this->content_dir_created = false;
		}

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset( $GLOBALS['wp_filesystem'] );
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		if ( false !== $this->old_error_log ) {
			// phpcs:ignore WordPress.PHP.IniSet -- Restore the error_log ini value after the test.
			ini_set( 'error_log', $this->old_error_log );
		}

		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Track a WP_CONTENT_DIR file for tearDown() cleanup.
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	private function track( string $path ): void {
		$this->tracked_files[] = $path;
	}

	/**
	 * Create an isolated directory for drop-in path override tests.
	 *
	 * Must live inside WP_CONTENT_DIR: the wppo_object_cache_dropin_path
	 * filter is containment-validated (audit #888 finding 22), so paths
	 * outside wp-content are rejected and the override would be ignored.
	 *
	 * @return string Directory path.
	 */
	private function make_temp_dir(): string {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( WP_CONTENT_DIR, 0777, true );
			$this->content_dir_created = true;
		}
		$dir = WP_CONTENT_DIR . '/wppo-cb-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $dir, 0777, true );
		$this->temp_dir = $dir;
		return $dir;
	}

	/**
	 * Write a file, tracking it for cleanup.
	 *
	 * @param string $path Absolute path.
	 * @param string $contents Contents.
	 * @return void
	 */
	private function write_tracked( string $path, string $contents ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $contents );
		$this->track( $path );
	}

	/**
	 * Invoke a private WP_Object_Cache drop-in method.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke_dropin( string $method, ...$args ) {
		$instance = ( new \ReflectionClass( 'WP_Object_Cache' ) )->newInstanceWithoutConstructor();
		$ref      = new \ReflectionMethod( 'WP_Object_Cache', $method );
		$ref->setAccessible( true );
		return $ref->invoke( $instance, ...$args );
	}

	/**
	 * Record one drop-in failure (auth/connection class).
	 *
	 * @param string $code Error code.
	 * @param string $reason Reason.
	 * @return void
	 */
	private function dropin_record( string $code, string $reason ): void {
		$this->invoke_dropin( 'record_redis_failure', $code, $reason );
	}

	/**
	 * Read a JSON state file.
	 *
	 * @param string $path Absolute path.
	 * @return array|null
	 */
	private function read_json( string $path ) {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw     = file_get_contents( $path );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Failure threshold trips the breaker and parks our own drop-in.
	 */
	public function test_threshold_filter_controls_trip(): void {
		$this->threshold_override = 3;

		$dropin  = WP_CONTENT_DIR . '/object-cache.php';
		$parked  = $dropin . '.wppo-disabled';
		$bridge  = WP_CONTENT_DIR . '/wppo-redis-disabled.json';
		$counter = WP_CONTENT_DIR . '/wppo-redis-failures.json';
		$this->track( $dropin );
		$this->track( $parked );
		$this->track( $bridge );
		$this->track( $counter );
		$this->track( WP_CONTENT_DIR . '/wppo-redis-down.flag' );

		$this->write_tracked( $dropin, "<?php // Redis Object Cache Drop-in for Performance Optimisation\n" );

		$this->dropin_record( 'auth_fail', 'Redis Auth failed.' );
		$this->dropin_record( 'auth_fail', 'Redis Auth failed.' );
		$this->assertFalse( file_exists( $parked ), 'Below threshold the drop-in must stay in place.' );

		$this->dropin_record( 'auth_fail', 'Redis Auth failed.' );

		$this->assertFalse( file_exists( $dropin ), 'At threshold our own drop-in must be parked.' );
		$this->assertTrue( file_exists( $parked ), 'Parked sibling must exist after the trip.' );

		$state = $this->read_json( $bridge );
		$this->assertIsArray( $state );
		$this->assertSame( 3, $state['failures'] );
		$this->assertSame( 'auth_fail', $state['error_code'] );
		$this->assertGreaterThan( 0, $state['tripped_at'] );
	}

	/**
	 * Failures outside the window restart the counter instead of tripping.
	 */
	public function test_window_expiry_resets_counter(): void {
		$this->threshold_override = 5;

		$counter = WP_CONTENT_DIR . '/wppo-redis-failures.json';
		$parked  = WP_CONTENT_DIR . '/object-cache.php.wppo-disabled';
		$this->track( $counter );
		$this->track( $parked );

		$old = time() - 3600;
		$this->write_tracked(
			$counter,
			(string) wp_json_encode(
				array(
					'count' => 4,
					'first' => $old,
					'last'  => $old,
				)
			)
		);

		$this->dropin_record( 'conn_fail', 'Could not connect to Redis.' );

		$state = $this->read_json( $counter );
		$this->assertIsArray( $state );
		$this->assertSame( 1, $state['count'], 'Stale failures outside the 600s window must reset the counter.' );
		$this->assertFalse( file_exists( $parked ), 'A reset counter must not trip the breaker.' );
	}

	/**
	 * Environment/config error codes never count toward the breaker.
	 */
	public function test_non_circuit_error_codes_are_ignored(): void {
		$counter = WP_CONTENT_DIR . '/wppo-redis-failures.json';
		$this->track( $counter );

		foreach ( array( 'missing_redis', 'missing_cluster', 'missing_sentinel', 'low_nodes', 'redis_version' ) as $code ) {
			$this->dropin_record( $code, 'environment problem' );
		}

		$this->assertFalse( file_exists( $counter ), 'Non-connection error codes must not create a counter.' );
	}

	/**
	 * A foreign drop-in is never renamed, even at threshold.
	 */
	public function test_foreign_dropin_is_never_renamed(): void {
		$this->threshold_override = 1;

		$dropin  = WP_CONTENT_DIR . '/object-cache.php';
		$parked  = $dropin . '.wppo-disabled';
		$bridge  = WP_CONTENT_DIR . '/wppo-redis-disabled.json';
		$counter = WP_CONTENT_DIR . '/wppo-redis-failures.json';
		$this->track( $dropin );
		$this->track( $parked );
		$this->track( $bridge );
		$this->track( $counter );
		$this->track( WP_CONTENT_DIR . '/wppo-redis-down.flag' );

		$foreign = "<?php // Foreign cache drop-in by Someone Else\n";
		$this->write_tracked( $dropin, $foreign );

		$this->dropin_record( 'conn_fail', 'Could not connect to Redis.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( $foreign, file_get_contents( $dropin ), 'Foreign drop-in content must be untouched.' );
		$this->assertFalse( file_exists( $parked ), 'Foreign drop-ins must never be parked.' );
		$this->assertNotNull( $this->read_json( $bridge ), 'The trip bridge is still written so the admin UI can report it.' );
	}

	/**
	 * Legacy-only marker content is never renamed (narrow marker required).
	 *
	 * The short legacy phrase can appear in foreign drop-ins, so the
	 * destructive rename requires the full plugin-specific marker. The
	 * trip bridge is still written so the admin UI can report the outage.
	 */
	public function test_legacy_marker_content_is_never_renamed(): void {
		$this->threshold_override = 1;

		$dropin  = WP_CONTENT_DIR . '/object-cache.php';
		$parked  = $dropin . '.wppo-disabled';
		$bridge  = WP_CONTENT_DIR . '/wppo-redis-disabled.json';
		$counter = WP_CONTENT_DIR . '/wppo-redis-failures.json';
		$this->track( $dropin );
		$this->track( $parked );
		$this->track( $bridge );
		$this->track( $counter );
		$this->track( WP_CONTENT_DIR . '/wppo-redis-down.flag' );

		$legacy = "<?php // Redis Object Cache Drop-in (legacy fork)\n";
		$this->write_tracked( $dropin, $legacy );

		$this->dropin_record( 'conn_fail', 'Could not connect to Redis.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( $legacy, file_get_contents( $dropin ), 'Legacy-marker content must be untouched.' );
		$this->assertFalse( file_exists( $parked ) );
		$this->assertNotNull( $this->read_json( $bridge ) );
	}

	/**
	 * A failed rename trips exactly once: the bridge guards re-trips.
	 */
	public function test_repeat_trip_after_failed_rename_logs_once(): void {
		$this->threshold_override = 1;

		$bridge  = WP_CONTENT_DIR . '/wppo-redis-disabled.json';
		$counter = WP_CONTENT_DIR . '/wppo-redis-failures.json';
		$this->track( $bridge );
		$this->track( $counter );
		$this->track( WP_CONTENT_DIR . '/wppo-redis-down.flag' );

		// No drop-in file on disk: rename is skipped, bridge is written.
		$this->dropin_record( 'conn_fail', 'Could not connect to Redis.' );
		$first = $this->read_json( $bridge );
		$this->assertIsArray( $first );
		$this->assertSame( 1, $first['failures'] );

		$this->dropin_record( 'conn_fail', 'Could not connect to Redis.' );
		$this->dropin_record( 'conn_fail', 'Could not connect to Redis.' );

		$second = $this->read_json( $bridge );
		$this->assertIsArray( $second );
		$this->assertSame( $first['tripped_at'], $second['tripped_at'], 'Re-trips must not rewrite the bridge.' );
		$this->assertSame( 1, $second['failures'], 'Re-trips must not bump the bridged failure count.' );
	}

	/**
	 * Status exposes circuit fields with closed defaults.
	 */
	public function test_status_reports_circuit_fields_when_closed(): void {
		$manager = new Object_Cache();
		$status  = $manager->get_status();

		$this->assertFalse( $status['circuit_open'] );
		$this->assertSame( 0, $status['circuit_tripped_at'] );
		$this->assertSame( '', $status['circuit_reason'] );
		$this->assertSame( 0, $status['failure_count'] );
	}

	/**
	 * Status surfaces an open circuit from the drop-in bridge files.
	 */
	public function test_status_reports_open_circuit_from_bridge(): void {
		$tripped_at = time() - 60;
		$this->write_tracked(
			WP_CONTENT_DIR . '/wppo-redis-disabled.json',
			(string) wp_json_encode(
				array(
					'reason'     => 'Redis Auth failed.',
					'error_code' => 'auth_fail',
					'tripped_at' => $tripped_at,
					'failures'   => 5,
				)
			)
		);

		$manager = new Object_Cache();
		$status  = $manager->get_status();

		$this->assertTrue( $status['circuit_open'] );
		$this->assertSame( $tripped_at, $status['circuit_tripped_at'] );
		$this->assertSame( 'Redis Auth failed.', $status['circuit_reason'] );
		$this->assertSame( 5, $status['failure_count'] );
	}

	/**
	 * Successful probe restores via enable(), clears state and logs recovery.
	 */
	public function test_probe_success_restores_and_logs(): void {
		$tripped_at                                    = time() - 120;
		$this->options[ Object_Cache::CIRCUIT_OPTION ] = array(
			'open'       => true,
			'tripped_at' => $tripped_at,
			'reason'     => 'Redis Auth failed.',
			'error_code' => 'auth_fail',
			'failures'   => 5,
		);
		$this->options['wppo_settings']                = array(
			'object_cache' => array(
				'mode' => 'standalone',
				'host' => '127.0.0.1',
				'port' => 6379,
			),
		);

		$manager = new WPPO_Scriptable_Object_Cache();
		$result  = $manager->probe_recovery();

		$this->assertTrue( $result );
		$this->assertCount( 1, WPPO_Scriptable_Object_Cache::$enable_calls );
		$this->assertSame( '127.0.0.1', WPPO_Scriptable_Object_Cache::$enable_calls[0]['host'], 'Probe must restore with the stored Redis settings.' );
		$this->assertArrayNotHasKey( Object_Cache::CIRCUIT_OPTION, $this->options, 'Circuit option must be cleared after recovery.' );
		$this->assertContains( 'wppo_object_cache_probe', $this->cleared_hooks, 'Probe schedule must be cleared after recovery.' );

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$logged = implode( "\n", array_column( array_column( $GLOBALS['wpdb']->inserts, 'data' ), 'activity' ) );
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertStringContainsStringIgnoringCase( 'recover', $logged );
	}

	/**
	 * Failed probe keeps the circuit open and never calls enable().
	 */
	public function test_probe_failure_keeps_circuit_open(): void {
		WPPO_Scriptable_Object_Cache::$ping_result = new \WP_Error( 'conn_fail', 'Could not connect to Redis.' );

		$this->options[ Object_Cache::CIRCUIT_OPTION ] = array(
			'open'       => true,
			'tripped_at' => time() - 30,
			'reason'     => 'Could not connect to Redis.',
			'error_code' => 'conn_fail',
			'failures'   => 5,
		);

		$manager = new WPPO_Scriptable_Object_Cache();
		$result  = $manager->probe_recovery();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 0, WPPO_Scriptable_Object_Cache::$enable_calls );
		$this->assertArrayHasKey( Object_Cache::CIRCUIT_OPTION, $this->options, 'Circuit option must survive a failed probe.' );
	}

	/**
	 * Closed circuit probes are a no-op without touching Redis.
	 */
	public function test_probe_when_closed_is_noop(): void {
		$manager = new WPPO_Scriptable_Object_Cache();

		$this->assertTrue( $manager->probe_recovery() );
		$this->assertSame( 0, WPPO_Scriptable_Object_Cache::$ping_calls );
	}

	/**
	 * Parked-sibling-only state synthesizes tripped_at from filemtime.
	 *
	 * Without a timestamp the per-trip dismissal could never match and a
	 * dismissed notice would reappear on every page load.
	 */
	public function test_parked_only_state_synthesizes_tripped_at(): void {
		$dir                   = $this->make_temp_dir();
		$this->dropin_override = $dir . '/object-cache.php';
		$parked                = $this->dropin_override . '.wppo-disabled';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $parked, "<?php // parked\n" );

		$manager = new Object_Cache();
		$state   = $manager->get_circuit_state();

		$this->assertTrue( $state['open'] );
		$this->assertGreaterThan( 0, $state['tripped_at'] );
		$this->assertSame( (int) filemtime( $parked ), $state['tripped_at'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime
	}

	/**
	 * Failure count falls back to the transient mirror when files are absent.
	 */
	public function test_failures_fall_back_to_transient_mirror(): void {
		$this->transients[ Util::transient_key( Object_Cache::FAIL_TRANSIENT ) ] = array(
			'count'   => 3,
			'updated' => time(),
		);

		$manager = new Object_Cache();
		$state   = $manager->get_circuit_state();

		$this->assertFalse( $state['open'], 'The mirror alone must not open the circuit.' );
		$this->assertSame( 3, $state['failures'] );
	}

	/**
	 * Auto-disable parks our drop-in, mirrors state and logs.
	 */
	public function test_auto_disable_parks_own_dropin_and_arms_notice(): void {
		$dir                   = $this->make_temp_dir();
		$this->dropin_override = $dir . '/object-cache.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->dropin_override, "<?php // Redis Object Cache Drop-in for Performance Optimisation\n" );

		$manager = new Object_Cache();
		$result  = $manager->auto_disable_circuit( 'Too many failures' );

		$this->assertTrue( $result );
		$this->assertFalse( file_exists( $this->dropin_override ) );
		$this->assertTrue( file_exists( $this->dropin_override . '.wppo-disabled' ) );

		$this->assertArrayHasKey( Object_Cache::CIRCUIT_OPTION, $this->options );
		$circuit = $this->options[ Object_Cache::CIRCUIT_OPTION ];
		$this->assertTrue( $circuit['open'] );
		$this->assertSame( 'Too many failures', $circuit['reason'] );
		$this->assertGreaterThan( 0, $circuit['tripped_at'] );

		$notice_key = Util::transient_key( Object_Cache::CIRCUIT_NOTICE_TRANSIENT );
		$this->assertSame( 'wppo_object_cache_circuit_notice', $notice_key );
		$this->assertArrayHasKey( $notice_key, $this->transients, 'Trip must arm the admin-notice transient.' );

		$mirror_key = Util::transient_key( Object_Cache::FAIL_TRANSIENT );
		$this->assertArrayHasKey( $mirror_key, $this->transients, 'Trip must mirror the failure count transient.' );
		$this->assertArrayHasKey( 'count', $this->transients[ $mirror_key ] );

		$this->assertContains( 'wppo_object_cache_probe', $this->scheduled, 'Trip must arm the recovery probe.' );

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$logged = implode( "\n", array_column( array_column( $GLOBALS['wpdb']->inserts, 'data' ), 'activity' ) );
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertStringContainsStringIgnoringCase( 'circuit breaker tripped', $logged );
	}

	/**
	 * Auto-disable refuses foreign drop-ins.
	 */
	public function test_auto_disable_refuses_foreign_dropin(): void {
		$dir                   = $this->make_temp_dir();
		$this->dropin_override = $dir . '/object-cache.php';
		$foreign               = "<?php // Foreign cache drop-in\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->dropin_override, $foreign );

		$manager = new Object_Cache();
		$result  = $manager->auto_disable_circuit( 'Too many failures' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'foreign_dropin', $result->get_error_code() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( $foreign, file_get_contents( $this->dropin_override ) );
		$this->assertArrayNotHasKey( Object_Cache::CIRCUIT_OPTION, $this->options );
	}

	/**
	 * Circuit transients are blog-prefixed on multisite.
	 */
	public function test_multisite_transient_prefixing(): void {
		$this->multisite = true;
		Functions\when( 'get_current_blog_id' )->justReturn( 7 );

		$this->assertSame( '7_wppo_redis_failures', Util::transient_key( Object_Cache::FAIL_TRANSIENT ) );
		$this->assertSame( '7_wppo_object_cache_circuit_notice', Util::transient_key( Object_Cache::CIRCUIT_NOTICE_TRANSIENT ) );

		$dir                   = $this->make_temp_dir();
		$this->dropin_override = $dir . '/object-cache.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->dropin_override, "<?php // Redis Object Cache Drop-in for Performance Optimisation\n" );

		$manager = new Object_Cache();
		$this->assertTrue( $manager->auto_disable_circuit( 'Too many failures' ) );
		$this->assertArrayHasKey( '7_wppo_object_cache_circuit_notice', $this->transients, 'Notice transient must be blog-prefixed on multisite.' );
	}

	/**
	 * Circuit notice renders with alert semantics, honours dismissal, and re-arms on the next trip.
	 */
	public function test_admin_notice_renders_and_rearms(): void {
		$tripped_at                                    = time() - 90;
		$this->options[ Object_Cache::CIRCUIT_OPTION ] = array(
			'open'       => true,
			'tripped_at' => $tripped_at,
			'reason'     => 'Redis Auth failed.',
			'error_code' => 'auth_fail',
			'failures'   => 5,
		);

		$notices = new Admin_Notices();
		$method  = new \ReflectionMethod( Admin_Notices::class, 'maybe_object_cache_circuit_notice' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $notices );
		$open_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'role="alert"', $open_html );
		$this->assertStringContainsString( 'aria-live="assertive"', $open_html );
		$this->assertStringContainsString( 'Redis Auth failed.', $open_html );
		$this->assertStringContainsString( 'object_cache_circuit', $open_html );

		// Dismissed for this trip: silent.
		$this->options[ Object_Cache::CIRCUIT_DISMISSED_OPTION ] = $tripped_at;
		ob_start();
		$method->invoke( $notices );
		$dismissed_html = (string) ob_get_clean();
		$this->assertSame( '', $dismissed_html );

		// A newer trip re-arms the notice.
		$this->options[ Object_Cache::CIRCUIT_OPTION ]['tripped_at'] = $tripped_at + 100;
		ob_start();
		$method->invoke( $notices );
		$rearmed_html = (string) ob_get_clean();
		$this->assertStringContainsString( 'role="alert"', $rearmed_html );
	}

	/**
	 * Config builder merges stored defaults under explicit request keys.
	 */
	public function test_build_redis_config_merges_stored_defaults(): void {
		$rest   = new Rest();
		$method = new \ReflectionMethod( Rest::class, 'build_redis_config' );
		$method->setAccessible( true );

		$stored = array(
			'mode'     => 'standalone',
			'host'     => '10.0.0.5',
			'port'     => '6380',
			'password' => 's3cret',
			'database' => '2',
			'nodes'    => array( ' 10.0.0.6:6379 ', '', '10.0.0.7:6379' ),
			'use_tls'  => 1,
		);

		$merged = $method->invoke(
			$rest,
			array(
				'action' => 'recover',
				'mode'   => 'sentinel',
			),
			$stored
		);

		$this->assertSame( 'sentinel', $merged['mode'], 'Explicit request keys win.' );
		$this->assertSame( '10.0.0.5', $merged['host'], 'Stored host fills keys the request omits.' );
		$this->assertSame( 6380, $merged['port'], 'Merged values use the same int coercion as requests.' );
		$this->assertSame( 's3cret', $merged['password'] );
		$this->assertSame( 2, $merged['database'] );
		$this->assertSame( array( '10.0.0.6:6379', '10.0.0.7:6379' ), $merged['nodes'], 'Merged nodes use the same normalizer as requests.' );
		$this->assertTrue( $merged['use_tls'] );

		$legacy = $method->invoke( $rest, array( 'action' => 'status' ) );
		$this->assertSame( 'standalone', $legacy['mode'] );
		$this->assertSame( '127.0.0.1', $legacy['host'] );
		$this->assertSame( 6379, $legacy['port'] );
	}

	/**
	 * Probe cron is cleared while the circuit is closed.
	 */
	public function test_cron_clears_probe_when_circuit_closed(): void {
		$cron = ( new \ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();
		$cron->schedule_cron_jobs();

		$this->assertContains( 'wppo_object_cache_probe', $this->cleared_hooks );
		$this->assertNotContains( 'wppo_object_cache_probe', $this->scheduled );
	}

	/**
	 * Probe cron is scheduled while the circuit is open.
	 */
	public function test_cron_schedules_probe_when_circuit_open(): void {
		$this->options[ Object_Cache::CIRCUIT_OPTION ] = array(
			'open'       => true,
			'tripped_at' => time() - 10,
			'reason'     => 'Could not connect to Redis.',
			'error_code' => 'conn_fail',
			'failures'   => 5,
		);

		$cron = ( new \ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();
		$cron->schedule_cron_jobs();

		$this->assertContains( 'wppo_object_cache_probe', $this->scheduled );
	}

	/**
	 * Probe cron releases its lock and keeps a still-open circuit open.
	 */
	public function test_cron_probe_callback_releases_lock(): void {
		// Port 6399 refuses fast even if a Redis server runs on 6379, so
		// this exercises the real still-down path without touching files.
		$this->options['wppo_settings']                = array(
			'object_cache' => array(
				'mode' => 'standalone',
				'host' => '127.0.0.1',
				'port' => 6399,
			),
		);
		$this->options[ Object_Cache::CIRCUIT_OPTION ] = array(
			'open'       => true,
			'tripped_at' => time() - 10,
			'reason'     => 'Could not connect to Redis.',
			'error_code' => 'conn_fail',
			'failures'   => 5,
		);

		$cron = ( new \ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();
		$cron->object_cache_probe_cron();

		$lock = Util::transient_key( 'wppo_object_cache_probe_lock' );
		$this->assertArrayNotHasKey( $lock, $this->transients, 'Probe lock must always be released.' );
		$this->assertArrayHasKey( Object_Cache::CIRCUIT_OPTION, $this->options, 'Still-down Redis must keep the circuit open.' );
	}
}
