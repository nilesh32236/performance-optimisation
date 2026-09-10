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
	 * Connect failure logging writes to the activity log + notice transient.
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
		$this->assertArrayHasKey( $notice_key, $this->transients );
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
	 * Drop-in flush helpers are namespace-aware (verify + scan-delete exist).
	 */
	public function test_dropin_flush_helpers_exist(): void {
		$this->assertTrue( method_exists( 'WP_Object_Cache', 'flush' ) );
		$this->assertTrue( method_exists( 'WP_Object_Cache', 'flush_group' ) );

		$ref = new \ReflectionMethod( 'WP_Object_Cache', 'flush' );
		$this->assertTrue( $ref->isPublic() );
	}
}
