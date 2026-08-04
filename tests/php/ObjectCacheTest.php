<?php
/**
 * Tests for the WP 6.9+ salted cache functions in the object cache drop-in.
 *
 * @package PerformanceOptimise\Tests
 */

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
}
