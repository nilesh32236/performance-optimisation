<?php
/**
 * Tests for Redis resilience hardening (issue #1022).
 *
 * Covers serializer-safe defaults (no fatal without igbinary), flush
 * verification hygiene, and in-app failure logging (activity log + notice
 * transient) on the fully-booted path.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Redis resilience tests.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheRedisResilienceTest extends \PHPUnit\Framework\TestCase {
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
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( strip_tags( $value ) ) : $value; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );

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
			 * Recorded inserts.
			 *
			 * @var array
			 */
			public $inserts = array();

			/**
			 * Record an insert.
			 *
			 * @param string $table Table.
			 * @param array  $data Data.
			 * @param array  $format Format.
			 * @return int
			 */
			public function insert( $table, $data, $format = null ) {
				unset( $format );
				$this->inserts[] = array(
					'table' => $table,
					'data'  => $data,
				);
				return 1;
			}
		};
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		require_once WPPO_PLUGIN_PATH . 'includes/redis-connect-helper.php';
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = $this->original_wpdb;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Serializer resolver never fatals and falls back to PHP without igbinary.
	 */
	public function test_serializer_resolver_falls_back_without_igbinary(): void {
		$this->assertTrue( function_exists( 'wppo_resolve_redis_serializer' ) );

		$resolved = wppo_resolve_redis_serializer();

		$this->assertIsArray( $resolved );
		$this->assertArrayHasKey( 'serializer', $resolved );
		$this->assertArrayHasKey( 'name', $resolved );
		$this->assertContains( $resolved['name'], array( 'igbinary', 'msgpack', 'php' ) );

		// Without the igbinary extension loaded, the resolver must not
		// select igbinary: any other outcome would risk mis-serialization.
		if ( ! extension_loaded( 'igbinary' ) && ! function_exists( 'igbinary_serialize' ) ) {
			$this->assertNotSame( 'igbinary', $resolved['name'] );
		}
	}

	/**
	 * Applying options never throws, even when the client rejects the serializer.
	 */
	public function test_apply_redis_options_never_throws(): void {
		$client = new class() {
			/**
			 * Throw on igbinary, accept everything else.
			 *
			 * @param int   $option Option.
			 * @param mixed $value Value.
			 * @return bool
			 * @throws \RuntimeException When the igbinary serializer is requested.
			 */
			public function set_option( $option, $value ) {
				if ( defined( '\Redis::SERIALIZER_IGBINARY' ) && \Redis::SERIALIZER_IGBINARY === $value ) {
					throw new \RuntimeException( 'igbinary unavailable' );
				}
				return true;
			}

			/**
			 * Proxy camelCase to snake_case for the helper under test.
			 *
			 * @param string $name Method name.
			 * @param array  $args Arguments.
			 * @return mixed
			 * @throws \RuntimeException When the igbinary serializer is requested.
			 */
			public function __call( $name, $args ) {
				if ( 'setOption' === $name ) {
					return $this->set_option( $args[0], $args[1] );
				}
				throw new \RuntimeException( 'unknown method' );
			}
		};

		// Must not throw: failures degrade to uncached, never fatal.
		wppo_apply_redis_options( $client, array( 'compression' => 'zstd' ) );
		$this->assertTrue( true );
	}

	/**
	 * Option application is an explicit no-op without the Redis extension.
	 *
	 * Guards on class_exists()/defined() must decide — never try/catch
	 * control flow — so a recording client must see zero setOption calls
	 * when the extension is absent.
	 */
	public function test_apply_redis_options_noop_without_extension(): void {
		if ( class_exists( 'Redis' ) ) {
			$this->markTestSkipped( 'Redis extension is present; guard path not exercisable.' );
		}

		$client = new class() {
			/**
			 * Call counter.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * Record setOption calls.
			 *
			 * @param mixed $option Option.
			 * @param mixed $value Value.
			 * @return bool
			 */
			public function setOption( $option, $value ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,Universal.NamingConventions.NoReservedKeywordParameterNames -- phpredis signature.
				unset( $option, $value );
				++$this->calls;
				return true;
			}
		};

		wppo_apply_redis_options( $client, array( 'compression' => 'zstd' ) );

		$this->assertSame( 0, $client->calls, 'Without the Redis class the helper must return early without touching the client.' );
	}

	/**
	 * A rejected serializer falls back to the PHP serializer (deterministic).
	 *
	 * The fake throws on the first setOption() regardless of the resolved
	 * value; the helper must then retry with SERIALIZER_PHP instead of
	 * letting the failure escape.
	 */
	public function test_apply_redis_options_falls_back_to_php_on_throw(): void {
		if ( ! defined( '\Redis::SERIALIZER_PHP' ) || ! defined( '\Redis::OPT_SERIALIZER' ) ) {
			$this->markTestSkipped( 'phpredis option constants are unavailable.' );
		}

		$client = new class() {
			/**
			 * Values received by setOption, in order.
			 *
			 * @var array
			 */
			public $values = array();

			/**
			 * Throw on first call, accept the retry.
			 *
			 * @param mixed $option Option.
			 * @param mixed $value Value.
			 * @return bool
			 * @throws \RuntimeException On the first call.
			 */
			public function setOption( $option, $value ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,Universal.NamingConventions.NoReservedKeywordParameterNames -- phpredis signature.
				unset( $option );
				$this->values[] = $value;
				if ( 1 === count( $this->values ) ) {
					throw new \RuntimeException( 'serializer unavailable' );
				}
				return true;
			}
		};

		wppo_apply_redis_options( $client, array() );

		$this->assertNotEmpty( $client->values );
		$this->assertSame(
			\Redis::SERIALIZER_PHP,
			end( $client->values ),
			'After a serializer rejection the helper must retry with the PHP serializer.'
		);
	}

	/**
	 * Non-object clients are ignored without throwing.
	 */
	public function test_apply_redis_options_ignores_non_clients(): void {
		wppo_apply_redis_options( null, array() );
		wppo_apply_redis_options( 'not-a-client', array( 'compression' => 'lz4' ) );
		$this->assertTrue( true );
	}

	/**
	 * Connect failure logging writes to the activity log + last-failure transient.
	 *
	 * Plain failures must NOT arm the circuit-notice transient (only the
	 * breaker trip path arms it); the latest failure is surfaced via
	 * get_status()['last_failure'] instead.
	 */
	public function test_log_redis_failure_writes_activity_log_and_transient(): void {
		$manager = new Object_Cache();
		$manager->log_redis_failure( 'conn_fail', 'Could not connect to Redis.' );

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$logged = implode( "\n", array_column( array_column( $GLOBALS['wpdb']->inserts, 'data' ), 'activity' ) );
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertStringContainsString( 'conn_fail', $logged );

		$failure_key = Util::transient_key( Object_Cache::LAST_FAILURE_TRANSIENT );
		$this->assertArrayHasKey( $failure_key, $this->transients );
		$this->assertSame( 'conn_fail', $this->transients[ $failure_key ]['code'] );

		$notice_key = Util::transient_key( Object_Cache::CIRCUIT_NOTICE_TRANSIENT );
		$this->assertArrayNotHasKey( $notice_key, $this->transients, 'Plain failures must not clobber the trip notice payload/TTL.' );

		$status = $manager->get_status();
		$this->assertArrayHasKey( 'last_failure', $status );
		$this->assertIsArray( $status['last_failure'] );
		$this->assertSame( 'conn_fail', $status['last_failure']['code'] );
	}

	/**
	 * Flush is fail-open without Redis: no fatal, bool returned.
	 *
	 * NOTE: wp_cache_flush() is defined by the real drop-in at bootstrap
	 * (before Patchwork boots), so Brain Monkey cannot stub it — the same
	 * constraint documented in ObjectCacheCircuitBreakerTest. This asserts
	 * the fail-open contract instead of forcing a backend failure.
	 */
	public function test_flush_is_fail_open_without_redis(): void {
		$manager = new Object_Cache();
		$result  = $manager->flush();

		$this->assertIsBool( $result );
	}

	/**
	 * Serializer support report always includes the PHP fallback.
	 */
	public function test_serializer_support_reports_php_fallback(): void {
		$manager = new Object_Cache();
		$support = $manager->get_serializer_support();

		$this->assertTrue( $support['php'] );
		$this->assertContains( $support['active'], array( 'igbinary', 'msgpack', 'php' ) );
		if ( ! extension_loaded( 'igbinary' ) && ! function_exists( 'igbinary_serialize' ) ) {
			$this->assertFalse( $support['igbinary'] );
		}
	}

	/**
	 * Fresh manager carries no flush error.
	 */
	public function test_last_flush_error_defaults_to_null(): void {
		$manager = new Object_Cache();
		$this->assertNull( $manager->get_last_flush_error() );
	}

	/**
	 * Drop-in flush helpers are namespace-aware (verify + scan-delete exist).
	 */
	public function test_dropin_flush_helpers_exist(): void {
		$this->assertTrue( method_exists( 'WP_Object_Cache', 'flush' ) );
		$this->assertTrue( method_exists( 'WP_Object_Cache', 'flush_group' ) );

		$ref = new \ReflectionMethod( 'WP_Object_Cache', 'flush' );
		$this->assertTrue( $ref->isPublic() );

		$this->assertTrue( ( new \ReflectionClass( 'WP_Object_Cache' ) )->hasMethod( 'verify_prefix_flushed' ) );
		$this->assertTrue( ( new \ReflectionClass( 'WP_Object_Cache' ) )->hasMethod( 'scan_pattern_is_empty' ) );
	}

	/**
	 * Invoke a private drop-in verify helper with a stubbed redis client.
	 *
	 * @param object $redis Stub client exposing scan().
	 * @return bool verify_prefix_flushed() result.
	 */
	private function invoke_dropin_verify( $redis ): bool {
		$instance = ( new \ReflectionClass( 'WP_Object_Cache' ) )->newInstanceWithoutConstructor();

		$prop = new \ReflectionProperty( 'WP_Object_Cache', 'redis' );
		$prop->setAccessible( true );
		$prop->setValue( $instance, $redis );

		$connected = new \ReflectionProperty( 'WP_Object_Cache', 'redis_connected' );
		$connected->setAccessible( true );
		$connected->setValue( $instance, true );

		$ref = new \ReflectionMethod( 'WP_Object_Cache', 'verify_prefix_flushed' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( $instance, 'wp_:*' );
	}

	/**
	 * Verifier reports dirty when keys survive the sweep.
	 */
	public function test_dropin_verify_reports_stale_keys(): void {
		$stub = new class() {
			/**
			 * Always report one surviving key.
			 *
			 * @param mixed  $cursor Cursor (by reference).
			 * @param string $pattern Pattern.
			 * @param int    $count Count.
			 * @return array
			 */
			public function scan( &$cursor, $pattern, $count = 100 ) {
				unset( $pattern, $count );
				$cursor = 0;
				return array( 'wp_:stale' );
			}
		};

		$this->assertFalse( $this->invoke_dropin_verify( $stub ) );
	}

	/**
	 * Verifier walks past the first page: stale keys on page two fail.
	 */
	public function test_dropin_verify_walks_full_keyspace(): void {
		$stub = new class() {
			/**
			 * Page counter.
			 *
			 * @var int
			 */
			public $pages = 0;

			/**
			 * Empty first page (cursor continues), stale second page.
			 *
			 * @param mixed  $cursor Cursor (by reference).
			 * @param string $pattern Pattern.
			 * @param int    $count Count.
			 * @return array
			 */
			public function scan( &$cursor, $pattern, $count = 100 ) {
				unset( $pattern, $count );
				++$this->pages;
				if ( 1 === $this->pages ) {
					$cursor = 1;
					return array();
				}
				$cursor = 0;
				return array( 'wp_:late-stale' );
			}
		};

		$this->assertFalse( $this->invoke_dropin_verify( $stub ) );
		$this->assertGreaterThan( 1, $stub->pages, 'Verifier must read beyond the first SCAN page.' );
	}

	/**
	 * Verifier is fail-open: scan errors and throws report clean.
	 */
	public function test_dropin_verify_fail_open_on_scan_error(): void {
		$false_stub = new class() {
			/**
			 * Report scan failure.
			 *
			 * @param mixed  $cursor Cursor (by reference).
			 * @param string $pattern Pattern.
			 * @param int    $count Count.
			 * @return bool
			 */
			public function scan( &$cursor, $pattern, $count = 100 ) {
				unset( $pattern, $count );
				$cursor = 0;
				return false;
			}
		};
		$this->assertTrue( $this->invoke_dropin_verify( $false_stub ) );

		$throw_stub = new class() {
			/**
			 * Throw on scan.
			 *
			 * @param mixed  $cursor Cursor (by reference).
			 * @param string $pattern Pattern.
			 * @param int    $count Count.
			 * @throws \RuntimeException Always.
			 */
			public function scan( &$cursor, $pattern, $count = 100 ) {
				unset( $cursor, $pattern, $count );
				throw new \RuntimeException( 'transient blip' );
			}
		};
		$this->assertTrue( $this->invoke_dropin_verify( $throw_stub ) );
	}
}
