<?php
/**
 * Tests for the bounded Redis connect timeout (issue #1423).
 *
 * Covers wppo_redis_connect_timeout(): legacy default, config-supplied
 * values, the 300ms ceiling even when filters supply larger values, the
 * 50ms floor, and fail-open on invalid input. Also pins the drop-in
 * set() fail-open path (throwing client degrades to memory) and the
 * foreign drop-in ownership guard.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;

/**
 * Bounded connect timeout tests.
 *
 * @package PerformanceOptimise\Tests
 */
class RedisConnectTimeoutClampTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();

		if ( ! function_exists( 'wppo_redis_connect_timeout' ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/redis-connect-helper.php';
		}

		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 1 );
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Legacy default (no timeout key) clamps the 0.5s fallback to 0.3s.
	 */
	public function test_default_clamps_to_ceiling(): void {
		$this->assertSame( 0.3, wppo_redis_connect_timeout( array() ) );
	}

	/**
	 * Filter-supplied large timeouts clamp to 300ms.
	 */
	public function test_large_config_timeout_clamps_to_300ms(): void {
		$this->assertSame( 0.3, wppo_redis_connect_timeout( array( 'timeout' => 5 ) ) );
		$this->assertSame( 0.3, wppo_redis_connect_timeout( array( 'timeout' => '5' ) ) );
	}

	/**
	 * Filter hook values above the ceiling are clamped too.
	 */
	public function test_filter_supplied_large_timeout_clamps(): void {
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'wppo_object_cache_connect_timeout' === $hook ) {
					return 5.0;
				}
				return $value;
			}
		);

		$this->assertSame( 0.3, wppo_redis_connect_timeout( array( 'timeout' => 0.2 ) ) );
	}

	/**
	 * Tiny values clamp up to the 50ms floor; in-range values pass through.
	 */
	public function test_floor_and_passthrough(): void {
		$this->assertSame( 0.05, wppo_redis_connect_timeout( array( 'timeout' => 0.01 ) ) );
		$this->assertSame( 0.2, wppo_redis_connect_timeout( array( 'timeout' => 0.2 ) ) );
	}

	/**
	 * Invalid config input fail-opens to the 300ms ceiling.
	 */
	public function test_invalid_input_fail_opens(): void {
		$this->assertSame( 0.3, wppo_redis_connect_timeout( array( 'timeout' => 'fast' ) ) );
		$this->assertSame( 0.3, wppo_redis_connect_timeout( null ) );
	}

	/**
	 * A throwing Redis client in set() degrades to the memory store.
	 */
	public function test_dropin_set_fail_open_to_memory(): void {
		$throwing = new class() {
			/**
			 * Always throw.
			 *
			 * @param string $name Method name.
			 * @param array  $args Arguments.
			 * @throws \RuntimeException Always.
			 */
			public function __call( $name, $args ) {
				throw new \RuntimeException( 'Redis is down' );
			}
		};

		$instance = ( new \ReflectionClass( 'WP_Object_Cache' ) )->newInstanceWithoutConstructor();
		$ref      = new \ReflectionProperty( 'WP_Object_Cache', 'redis' );
		$ref->setValue( $instance, $throwing );
		$connected = new \ReflectionProperty( 'WP_Object_Cache', 'redis_connected' );
		$connected->setValue( $instance, true );

		$this->assertTrue( $instance->set( 'wppo-clamp-key', 'value', 'default', 0 ) );
		$found = null;
		$this->assertSame( 'value', $instance->get( 'wppo-clamp-key', 'default', false, $found ) );
		$this->assertTrue( $found );
	}

	/**
	 * Foreign drop-in content is never treated as ours.
	 */
	public function test_foreign_dropin_content_is_not_ours(): void {
		$this->assertFalse(
			\PerformanceOptimise\Inc\Object_Cache::is_own_dropin_content( "<?php // Foreign cache drop-in by Someone Else\n" )
		);
	}
}
