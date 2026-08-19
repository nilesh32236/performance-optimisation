<?php
/**
 * Tests for the WP 6.9+ salted cache functions in the object cache drop-in.
 *
 * @package PerformanceOptimise\Tests
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Tests for the salted cache functions in templates/object-cache.php.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Object cache stub instance.
	 *
	 * @var object
	 */
	private $stub;

	/**
	 * Whether the drop-in template has been loaded.
	 *
	 * @var bool
	 */
	private static $dropin_loaded = false;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		if ( ! self::$dropin_loaded ) {
			require_once WPPO_PLUGIN_PATH . 'templates/object-cache.php';
			self::$dropin_loaded = true;
		}

		$this->stub = new class() {
			/**
			 * In-memory store keyed by "group:key".
			 *
			 * @var array
			 */
			public $cache = array();

			/**
			 * Mimics WP_Object_Cache::get().
			 *
			 * @param int|string $key   Cache key.
			 * @param string     $group Cache group.
			 * @param bool       $force Whether to force from Redis.
			 * @param bool|null  $found Whether the value was found.
			 * @return mixed|false
			 */
			public function get( $key, $group = 'default', $force = false, &$found = null ) {
				$local_key = $group . ':' . $key;
				if ( array_key_exists( $local_key, $this->cache ) ) {
					$found = true;
					return $this->cache[ $local_key ];
				}
				$found = false;
				return false;
			}

			/**
			 * Mimics WP_Object_Cache::set().
			 *
			 * @param int|string $key    Cache key.
			 * @param mixed      $data   Cache data.
			 * @param string     $group  Cache group.
			 * @param int        $expire Expiration in seconds.
			 * @return bool True on success.
			 */
			public function set( $key, $data, $group = 'default', $expire = 0 ) {
				$this->cache[ $group . ':' . $key ] = $data;
				return true;
			}

			/**
			 * Mimics WP_Object_Cache::get_multiple().
			 *
			 * @param string[] $keys  Array of cache keys.
			 * @param string   $group Cache group.
			 * @param bool     $force Whether to force from Redis.
			 * @return array
			 */
			public function get_multiple( $keys, $group = 'default', $force = false ) {
				$values = array();
				foreach ( $keys as $key ) {
					$found          = false;
					$values[ $key ] = $this->get( $key, $group, $force, $found );
				}
				return $values;
			}

			/**
			 * Mimics WP_Object_Cache::set_multiple().
			 *
			 * @param array  $data   Array of keys and values.
			 * @param string $group  Cache group.
			 * @param int    $expire Expiration in seconds.
			 * @return array
			 */
			public function set_multiple( $data, $group = 'default', $expire = 0 ) {
				$results = array();
				foreach ( $data as $key => $value ) {
					$this->set( $key, $value, $group, $expire );
					$results[ $key ] = true;
				}
				return $results;
			}

			/**
			 * Mimics WP_Object_Cache::delete().
			 *
			 * @param int|string $key   Cache key.
			 * @param string     $group Cache group.
			 * @return bool True on success.
			 */
			public function delete( $key, $group = 'default' ) {
				unset( $this->cache[ $group . ':' . $key ] );
				return true;
			}
		};
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_object_cache'] = $this->stub;
	}

	/**
	 * Test that set then get with the same salt round-trips.
	 */
	public function test_set_then_get_with_same_salt_round_trips(): void {
		$this->assertTrue( wp_cache_set_salted( 'query-key', array( 'id' => 5 ), 'post-queries', '2026-01-01' ) );
		$this->assertSame( array( 'id' => 5 ), wp_cache_get_salted( 'query-key', 'post-queries', '2026-01-01' ) );
	}

	/**
	 * Test that get with a different salt returns false.
	 */
	public function test_get_with_different_salt_returns_false(): void {
		wp_cache_set_salted( 'query-key', 'cached-value', 'post-queries', '2026-01-01' );
		$this->assertFalse( wp_cache_get_salted( 'query-key', 'post-queries', '2026-01-02' ) );
	}

	/**
	 * Test that get returns false when nothing is cached.
	 */
	public function test_get_returns_false_when_not_cached(): void {
		$this->assertFalse( wp_cache_get_salted( 'missing-key', 'post-queries', '2026-01-01' ) );
	}

	/**
	 * Test that rewriting with a new salt overwrites the same stable key.
	 *
	 * Proves the wrapper-at-stable-key format keeps Redis memory bounded:
	 * only a single underlying key exists after two writes with different salts.
	 */
	public function test_rewrite_with_new_salt_overwrites_same_key(): void {
		wp_cache_set_salted( 'query-key', 'v1', 'post-queries', '2026-01-01' );
		wp_cache_set_salted( 'query-key', 'v2', 'post-queries', '2026-01-02' );

		$this->assertCount( 1, $this->stub->cache );
		$this->assertSame( 'v2', wp_cache_get_salted( 'query-key', 'post-queries', '2026-01-02' ) );
		$this->assertFalse( wp_cache_get_salted( 'query-key', 'post-queries', '2026-01-01' ) );
	}

	/**
	 * Test that the stored wrapper format matches core's cache-compat.php.
	 */
	public function test_stored_wrapper_format_matches_core(): void {
		wp_cache_set_salted( 'query-key', 'cached-value', 'post-queries', '2026-01-01' );

		$this->assertSame(
			array(
				'data' => 'cached-value',
				'salt' => '2026-01-01',
			),
			$this->stub->cache['post-queries:query-key']
		);
	}

	/**
	 * Test that a non-salted wp_cache_delete() invalidates a salted entry.
	 */
	public function test_unsalted_delete_invalidates_salted_entry(): void {
		wp_cache_set_salted( 'query-key', 'cached-value', 'post-queries', '2026-01-01' );
		$this->assertTrue( wp_cache_delete( 'query-key', 'post-queries' ) );
		$this->assertFalse( wp_cache_get_salted( 'query-key', 'post-queries', '2026-01-01' ) );
	}

	/**
	 * Test that set_multiple_salted then get_multiple_salted round-trips and
	 * remaps results to the original cache keys.
	 */
	public function test_set_and_get_multiple_salted_remap_results(): void {
		$set_results = wp_cache_set_multiple_salted(
			array(
				'query-a' => 1,
				'query-b' => 2,
			),
			'post-queries',
			'2026-01-01'
		);
		$this->assertSame(
			array(
				'query-a' => true,
				'query-b' => true,
			),
			$set_results
		);

		$get_results = wp_cache_get_multiple_salted(
			array( 'query-a', 'query-b', 'query-missing' ),
			'post-queries',
			'2026-01-01'
		);
		$this->assertSame(
			array(
				'query-a'       => 1,
				'query-b'       => 2,
				'query-missing' => false,
			),
			$get_results
		);
	}

	/**
	 * Test that get_multiple_salted with a stale salt returns false for every key.
	 */
	public function test_get_multiple_salted_with_stale_salt_returns_false(): void {
		wp_cache_set_multiple_salted(
			array(
				'query-a' => 1,
				'query-b' => 2,
			),
			'post-queries',
			'2026-01-01'
		);

		$this->assertSame(
			array(
				'query-a' => false,
				'query-b' => false,
			),
			wp_cache_get_multiple_salted( array( 'query-a', 'query-b' ), 'post-queries', '2026-01-02' )
		);
	}

	/**
	 * Test that array salts normalize identically on both the setter and getter.
	 */
	public function test_array_salts_normalize_identically(): void {
		$salt = array(
			'posts' => '2026-01-01',
			'terms' => '2026-01-02',
		);

		$this->assertTrue( wp_cache_set_salted( 'query-key', 'cached-value', 'post-queries', $salt ) );
		$this->assertSame(
			'cached-value',
			wp_cache_get_salted(
				'query-key',
				'post-queries',
				array(
					'posts' => '2026-01-01',
					'terms' => '2026-01-02',
				)
			)
		);
	}

	/**
	 * Test that wp_cache_supports() claims every feature the drop-in implements.
	 *
	 * The list mirrors core's wp_cache_supports() minus flush_runtime. add_multiple is
	 * served by core's cache-compat.php fallback (loops wp_cache_add() per key), so it is
	 * genuinely supported by this drop-in.
	 */
	public function test_wp_cache_supports_claims_implemented_features(): void {
		$this->assertTrue( wp_cache_supports( 'add_multiple' ) );
		$this->assertTrue( wp_cache_supports( 'set_multiple' ) );
		$this->assertTrue( wp_cache_supports( 'get_multiple' ) );
		$this->assertTrue( wp_cache_supports( 'delete_multiple' ) );
		$this->assertTrue( wp_cache_supports( 'flush_group' ) );
	}

	/**
	 * Test that wp_cache_supports() does NOT claim flush_runtime.
	 *
	 * The drop-in has no runtime-only flush, so claiming it would make core's compat
	 * wp_cache_flush_runtime() delegate to a full persistent Redis flush instead of a
	 * cheap in-memory-only invalidation.
	 */
	public function test_wp_cache_supports_does_not_claim_flush_runtime(): void {
		$this->assertFalse( wp_cache_supports( 'flush_runtime' ) );
	}

	/**
	 * Test that wp_cache_supports() returns false for unknown features.
	 */
	public function test_wp_cache_supports_returns_false_for_unknown_features(): void {
		$this->assertFalse( wp_cache_supports( 'not_a_real_feature' ) );
	}

	/**
	 * Test that a differently ordered array salt produces a different normalized salt.
	 */
	public function test_array_salt_order_changes_normalized_salt(): void {
		$salt = array(
			'posts' => '2026-01-01',
			'terms' => '2026-01-02',
		);

		wp_cache_set_salted( 'query-key', 'cached-value', 'post-queries', $salt );
		$this->assertFalse(
			wp_cache_get_salted(
				'query-key',
				'post-queries',
				array(
					'terms' => '2026-01-02',
					'posts' => '2026-01-01',
				)
			)
		);
	}

	/**
	 * Write a Redis config fixture into WP_CONTENT_DIR and return its path.
	 *
	 * @return string Absolute path to the freshly written config file.
	 */
	private function create_config_fixture(): string {
		$config_file = WP_CONTENT_DIR . '/wppo-redis-config.php';
		$config_dir  = dirname( $config_file );

		if ( ! is_dir( $config_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory.
			mkdir( $config_dir, 0777, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.
		file_put_contents(
			$config_file,
			"<?php\nreturn array(\n\t'host' => '127.0.0.1',\n\t'port' => 6379,\n\t'mode' => 'standalone',\n);\n"
		);

		return $config_file;
	}

	/**
	 * Regression test for the WP_PLUGIN_DIR boot-order fatal (issue #612).
	 *
	 * WP 6.x/7.x boots the object cache (and therefore constructs this drop-in)
	 * BEFORE wp_plugin_directory_constants() defines WP_PLUGIN_DIR, so referencing
	 * the constant directly used to raise a fatal Error that took the whole site
	 * down on every request. Two scenarios are exercised against a real config
	 * file so connect_redis() proceeds past its early return:
	 *
	 * 1. Helper missing: the derived path does not exist, so the drop-in must
	 *    degrade to the in-memory cache (redis_connected = false) without fataling.
	 * 2. Helper present: the drop-in derives the plugins directory from
	 *    WP_CONTENT_DIR, locates the helper there, loads it, and connects.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_connect_redis_no_fatal_when_wp_plugin_dir_undefined(): void {
		$this->assertFalse( defined( 'WP_PLUGIN_DIR' ), 'Test precondition: WP_PLUGIN_DIR must be undefined at object cache boot.' );

		$config_file = $this->create_config_fixture();
		// phpcs:ignore WordPress.PHP.IniSet -- Suppress expected helper-missing error_log output in the test.
		$old_error_log = ini_set( 'error_log', '/dev/null' );

		$created_dirs = array();
		// Track directories created for the config fixture.
		$config_dir = dirname( $config_file );
		if ( ! is_dir( $config_dir ) ) {
			$created_dirs[] = $config_dir;
		}
		try {
			// Scenario 1: no helper file at the derived path -> graceful in-memory fallback.
			$instance = ( new \ReflectionClass( 'WP_Object_Cache' ) )->newInstanceWithoutConstructor();

			$method = new \ReflectionMethod( 'WP_Object_Cache', 'connect_redis' );
			$method->setAccessible( true );
			$method->invoke( $instance );

			$prop = new \ReflectionProperty( 'WP_Object_Cache', 'redis_connected' );
			$prop->setAccessible( true );

			$this->assertFalse( $prop->getValue( $instance ), 'Missing helper must degrade to the in-memory cache, not fatal.' );

			// Scenario 2: helper found via the WP_CONTENT_DIR-derived plugins dir.
			// A stand-in helper is used so no real Redis connection is attempted and
			// no WP_Error (absent from this test env) is constructed.
			$helper_dest = WP_CONTENT_DIR . '/plugins/performance-optimisation/includes/redis-connect-helper.php';
			$helper_dir  = dirname( $helper_dest );
			$helper_base = WP_CONTENT_DIR . '/plugins/performance-optimisation';
			$plugins_dir = WP_CONTENT_DIR . '/plugins';
			// Track every directory that will be created for the helper fixture.
			foreach ( array( $plugins_dir, $helper_base, $helper_dir ) as $dir ) {
				if ( ! is_dir( $dir ) ) {
					$created_dirs[] = $dir;
				}
			}
			if ( ! is_dir( $helper_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory.
				mkdir( $helper_dir, 0777, true );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.
			file_put_contents(
				$helper_dest,
				"<?php\nif ( ! function_exists( 'wppo_redis_connect' ) ) {\n\tfunction wppo_redis_connect( \$config ) {\n\t\treturn new \\stdClass();\n\t}\n}\nif ( ! function_exists( 'wppo_apply_redis_options' ) ) {\n\tfunction wppo_apply_redis_options( \$redis, \$config ) {}\n}\n"
			);

			\Brain\Monkey\Functions\when( 'is_wp_error' )->justReturn( false );

			$instance2 = ( new \ReflectionClass( 'WP_Object_Cache' ) )->newInstanceWithoutConstructor();
			$method->invoke( $instance2 );

			$this->assertTrue( function_exists( 'wppo_redis_connect' ), 'Helper must be loaded from the WP_CONTENT_DIR-derived plugins directory.' );
			$this->assertTrue( $prop->getValue( $instance2 ), 'Drop-in must connect once the helper is resolved.' );
		} finally {
			if ( false !== $old_error_log ) {
				// phpcs:ignore WordPress.PHP.IniSet -- Restore the error_log ini value after the test.
				ini_set( 'error_log', $old_error_log );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $config_file );
			if ( isset( $helper_dest ) && file_exists( $helper_dest ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $helper_dest );
			}
			// Remove directories in reverse creation order.
			foreach ( array_reverse( $created_dirs ) as $dir ) {
				if ( is_dir( $dir ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture cleanup.
					@rmdir( $dir );
				}
			}
		}
	}
}
