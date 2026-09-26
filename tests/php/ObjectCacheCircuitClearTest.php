<?php
/**
 * Regression: `Object_Cache::clear_circuit_state()` must delete stale state
 * files even when `WP_Filesystem` is unavailable.
 *
 * An independent adversarial review of #1664 found that PR would drop the
 * `@unlink()` fallback in favour of skipping the delete when
 * `Util::init_filesystem()` returns false. That is a reliability regression, and
 * the causal chain is short:
 *
 *   `clear_circuit_state()` deletes `get_parked_path()`;
 *   `get_parked_path()` existing **forces `$state['open'] = true`**
 *   (`get_circuit_state()`, `includes/Cache/class-object-cache.php`).
 *
 * So if the parked drop-in survives, the circuit breaker re-opens on the very
 * next state read — after a successful Redis probe, after `enable()`, on
 * uninstall and on deactivate. That is precisely the state the method exists to
 * clear.
 *
 * #1664 is not being merged, so master is currently correct. This test exists to
 * keep it correct: `clear_circuit_state()` had **zero** coverage before, which is
 * how the review's mutation of the `@unlink()` fallback passed a full green suite.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests that clear_circuit_state() always removes the state files.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheCircuitClearTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Files created by a test, removed on teardown.
	 *
	 * @var array
	 */
	private $created = array();

	/**
	 * Remove any state files the test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->created as $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		$this->created = array();
		parent::tearDown();
	}

	/**
	 * Install the option/transient surface the method touches.
	 *
	 * @return void
	 */
	private function install_option_stubs(): void {
		Functions\stubs(
			array(
				'delete_option',
				'delete_transient',
				'get_option',
				'update_option',
				'wp_normalize_path',
				'WP_Filesystem',
			)
		);
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		// Default: no filesystem, so the @unlink fallback is the live path.
		Functions\when( 'WP_Filesystem' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
	}

	/**
	 * Build an Object_Cache without running the constructor.
	 *
	 * The constructor reaches for apply_filters() and WP_Filesystem file
	 * includes that the unit fixture does not provide; the only property this
	 * test needs is the drop-in path the state paths are derived from.
	 *
	 * @return Object_Cache
	 */
	private function make_cache(): Object_Cache {
		$reflection = new ReflectionClass( Object_Cache::class );
		$cache      = $reflection->newInstanceWithoutConstructor();
		$path       = new ReflectionProperty( Object_Cache::class, 'dropin_path' );
		$path->setValue( $cache, wp_normalize_path( WP_CONTENT_DIR . '/object-cache.php' ) );
		return $cache;
	}

	/**
	 * The parked state-file path for an instance.
	 *
	 * @param Object_Cache $cache Instance.
	 * @return string
	 */
	private function parked_path( Object_Cache $cache ): string {
		$method = new ReflectionMethod( Object_Cache::class, 'get_parked_path' );
		return (string) $method->invoke( $cache );
	}

	/**
	 * The disabled-state path for an instance.
	 *
	 * @param Object_Cache $cache Instance.
	 * @return string
	 */
	private function disabled_state_path( Object_Cache $cache ): string {
		$method = new ReflectionMethod( Object_Cache::class, 'get_disabled_state_path' );
		return (string) $method->invoke( $cache );
	}

	/**
	 * Create a state file and remember it for teardown.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function touch_state_file( string $path ): string {
		$this->assertNotSame(
			'',
			$path,
			'the state path must resolve, or this test would pass vacuously'
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture must create a REAL file; the code under test deletes real files.
		file_put_contents( $path, "test\n" );
		$this->created[] = $path;
		$this->assertFileExists( $path );
		return $path;
	}

	/**
	 * The parked drop-in is removed even with no WP_Filesystem available.
	 *
	 * This is the regression: with the `@unlink()` fallback gone, the parked file
	 * survives and the breaker re-opens on the next read.
	 *
	 * @return void
	 */
	public function test_parked_file_is_removed_without_wp_filesystem(): void {
		$this->install_option_stubs();

		// The bootstrap stubs WP_Filesystem() to false, so
		// Filesystem::init_filesystem() returns false here for real — this is
		// the fallback branch, not a stubbed one.
		$this->assertFalse( Util::init_filesystem(), 'this test only means anything on the no-filesystem branch' );

		$cache  = $this->make_cache();
		$parked = $this->touch_state_file( $this->parked_path( $cache ) );

		$cache->clear_circuit_state();

		$this->assertFileDoesNotExist(
			$parked,
			'clear_circuit_state() must delete the parked drop-in even when WP_Filesystem is unavailable; a surviving parked file forces the breaker open again on the next read'
		);
	}

	/**
	 * The disabled-state file is removed on the same no-filesystem path.
	 *
	 * @return void
	 */
	public function test_disabled_state_file_is_removed_without_wp_filesystem(): void {
		$this->install_option_stubs();
		$this->assertFalse( Util::init_filesystem() );

		$cache = $this->make_cache();
		$state = $this->touch_state_file( $this->disabled_state_path( $cache ) );

		$cache->clear_circuit_state();

		$this->assertFileDoesNotExist( $state );
	}

	/**
	 * A present filesystem is preferred, and the unlink fallback is not used.
	 *
	 * Guards the opposite direction: the fallback must not become the only path.
	 *
	 * @return void
	 */
	public function test_wp_filesystem_is_preferred_when_available(): void {
		$this->install_option_stubs();

		$fs = new class() {
			/**
			 * Paths this fake filesystem was asked to delete.
			 *
			 * @var array
			 */
			public $deleted = array();

			/**
			 * Record a delete.
			 *
			 * @param string $path File path.
			 * @return bool
			 */
			public function delete( $path ) {
				$this->deleted[] = $path;
				return true;
			}
		};

		Functions\when( 'WP_Filesystem' )->justReturn( true );
		// Filesystem::init_filesystem() reads this global after WP_Filesystem() passes.
		$GLOBALS['wp_filesystem'] = $fs;

		$cache  = $this->make_cache();
		$parked = $this->touch_state_file( $this->parked_path( $cache ) );

		$cache->clear_circuit_state();

		$this->assertContains(
			$parked,
			$fs->deleted,
			'a real WP_Filesystem must be used when available'
		);
		$this->assertFileExists( $parked, 'the filesystem path must not fall back to unlink' );
		unset( $GLOBALS['wp_filesystem'] );
	}
}
