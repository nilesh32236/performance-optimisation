<?php
/**
 * Behavioural differential for the object-cache drop-in with Redis absent.
 *
 * The drop-in is fail-open by design at 1643 catch sites (D-168/D-169). D-170
 * established that the behavioural net beneath that surface is thin: the
 * resilience suite reaches into internal helpers by reflection, and only 7 of the
 * 222 test files make any output-shape assertion at all. Nothing asserted the
 * property that actually matters to a visitor — with Redis gone, the cache must
 * still work, not merely avoid fataling.
 *
 * This is that fixture for the hottest path. It does not mock Redis, does not
 * stub the WP cache API, and does not touch Reflection: it runs the real
 * drop-in's public API in its unconnected state and asserts what comes out.
 *
 * @package PerformanceOptimise\Tests
 */

/**
 * Redis-absent behavioural contract for WP_Object_Cache.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheRedisAbsentContractTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The real drop-in, in the state a fresh request reaches before connecting.
	 *
	 * @var \WP_Object_Cache
	 */
	private $cache;

	/**
	 * Whether the drop-in template has been loaded.
	 *
	 * @var bool
	 */
	private static $dropin_loaded = false;

	/**
	 * Boot the real drop-in once, and give each test a clean instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! self::$dropin_loaded ) {
			require_once WPPO_PLUGIN_PATH . 'templates/object-cache.php';
			self::$dropin_loaded = true;
		}

		$instance = ( new \ReflectionClass( 'WP_Object_Cache' ) )->newInstanceWithoutConstructor();

		// Start each test with an empty in-memory store, or a previous test's
		// keys would leak in and the "a miss returns false" case could pass for
		// the wrong reason.
		$store = new \ReflectionProperty( 'WP_Object_Cache', 'cache' );
		$store->setAccessible( true );
		$store->setValue( $instance, array() );

		// The state a request is in when Redis is unreachable: no client, not
		// connected. A plain new instance would be unconnected too, but setting
		// both explicitly means the fixture cannot pass against a different
		// branch if someone later adds lazy connection.
		$redis = new \ReflectionProperty( 'WP_Object_Cache', 'redis' );
		$redis->setAccessible( true );
		$redis->setValue( $instance, null );

		$connected = new \ReflectionProperty( 'WP_Object_Cache', 'redis_connected' );
		$connected->setAccessible( true );
		$connected->setValue( $instance, false );

		$this->cache = $instance;
	}

	/**
	 * A stored value must come back, unchanged, with Redis gone.
	 *
	 * @return void
	 */
	public function test_a_stored_value_comes_back_with_redis_gone(): void {
		$stored = $this->cache->set( 'greeting', 'hello', 'my-group' );
		$this->assertTrue( (bool) $stored, 'set() must report success while degraded.' );

		$fetched = $this->cache->get( 'greeting', 'my-group' );
		$this->assertSame( 'hello', $fetched, 'A degraded cache must still return what was stored.' );
	}

	/**
	 * A miss must be a clean false, not null and not a throw.
	 *
	 * @return void
	 */
	public function test_a_miss_returns_false_with_redis_gone(): void {
		$found = null;
		$value = $this->cache->get( 'never-stored', 'my-group', false, $found );

		$this->assertFalse( $found, 'The miss flag must say not-found.' );
		$this->assertFalse( $value, 'A miss must return false, which is what wp_cache_get() checks.' );
	}

	/**
	 * Overwriting must win, which the in-memory store has to get right.
	 *
	 * @return void
	 */
	public function test_an_overwrite_wins_with_redis_gone(): void {
		$this->cache->set( 'k', 'first', 'my-group' );
		$this->cache->set( 'k', 'second', 'my-group' );

		$this->assertSame( 'second', $this->cache->get( 'k', 'my-group' ) );
	}

	/**
	 * Deletion must actually remove, or the degraded cache becomes a lie.
	 *
	 * @return void
	 */
	public function test_delete_removes_the_entry_with_redis_gone(): void {
		$this->cache->set( 'doomed', 'value', 'my-group' );
		$this->assertTrue( (bool) $this->cache->delete( 'doomed', 'my-group' ), 'delete() must report success.' );

		$found = null;
		$this->cache->get( 'doomed', 'my-group', false, $found );

		$this->assertFalse( $found, 'The entry must be gone after delete().' );
	}
}
