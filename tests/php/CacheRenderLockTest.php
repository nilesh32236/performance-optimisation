<?php
/**
 * Tests for the pre-render single-flier lock (issue #1345 follow-up).
 *
 * Covers try_acquire_html_render_lock() win/lose/fail-open paths and the
 * loser-skips-save contract between process_buffer_for_cache() and
 * stash_cache(): a lock loser serves dynamic unprocessed output and must
 * never persist it over the winner's optimized file, while fail-open
 * owners (guard off / empty path) still save normally.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Pre-render single-flier lock tests.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheRenderLockTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory transient store backing the get/set/delete stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Install in-memory transient stubs plus the cacheability gates.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		$this->transients = array();
		$store            = &$this->transients;
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				$k = (string) $key;
				return array_key_exists( $k, $store ) ? $store[ $k ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ (string) $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				unset( $store[ (string) $key ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Util::set_settings_cache( array() );
		unset( $_SERVER['QUERY_STRING'] );
		$_GET = array();
	}

	/**
	 * Build a Cache instance without running its constructor.
	 *
	 * @param array $options Options to seed.
	 * @return Cache
	 */
	private function make_cache( array $options = array() ): Cache {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$prop  = new \ReflectionProperty( Cache::class, 'options' );
		$prop->setValue( $cache, $options );
		$prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$prop->setValue( $cache, WP_CONTENT_DIR . '/cache/wppo' );
		$prop = new \ReflectionProperty( Cache::class, 'domain' );
		$prop->setValue( $cache, 'example.com' );
		$prop = new \ReflectionProperty( Cache::class, 'request_uri' );
		$prop->setValue( $cache, '/' );
		$prop = new \ReflectionProperty( Cache::class, 'url_path' );
		$prop->setValue( $cache, '' );
		return $cache;
	}

	/**
	 * Reset the once-per-request render flag so winner-path tests always
	 * exercise the real process_buffer_only() pipeline.
	 *
	 * @return void
	 */
	private function reset_buffer_enhanced(): void {
		$prop = new \ReflectionProperty( Cache::class, 'buffer_enhanced' );
		$prop->setValue( null, false );
	}

	/**
	 * Stub the extra WP functions needed by the save path
	 * (save_processed_buffer() -> save_cache_files()).
	 *
	 * @return void
	 */
	private function install_save_path_stubs(): void {
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		// Override the suite-wide trailingslashit passthrough with core
		// behavior: directory containment checks require the slash.
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' ) . '/';
			}
		);
	}

	/**
	 * Build a filesystem spy covering both the Cache-level atomic writes
	 * and the Util::prepare_cache_dir() directory probes.
	 *
	 * @return object Spy with a public $writes counter.
	 */
	private function make_filesystem_spy(): object {
		return new class() {
			/**
			 * Number of put_contents calls recorded.
			 *
			 * @var int
			 */
			public $writes = 0;

			/**
			 * Record a write.
			 *
			 * @param string $path     Path.
			 * @param string $contents Contents.
			 * @param int    $mode     Mode.
			 * @return bool
			 */
			public function put_contents( $path, $contents, $mode = 0644 ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				unset( $path, $contents, $mode );
				++$this->writes;
				return true;
			}

			/**
			 * Record a move.
			 *
			 * @param string $from Source.
			 * @param string $to   Dest.
			 * @param bool   $overwrite Overwrite.
			 * @return bool
			 */
			public function move( $from, $to, $overwrite = true ) {
				unset( $from, $to, $overwrite );
				return true;
			}

			/**
			 * Stub delete.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function delete( $path ) {
				unset( $path );
				return true;
			}

			/**
			 * Stub exists.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function exists( $path ) {
				unset( $path );
				return false;
			}

			/**
			 * Stub is_dir (pretend the cache tree already exists).
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function is_dir( $path ) {
				unset( $path );
				return true;
			}

			/**
			 * Stub mkdir.
			 *
			 * @param string $path  Path.
			 * @param int    $chmod Mode.
			 * @return bool
			 */
			public function mkdir( $path, $chmod = 0755 ) {
				unset( $path, $chmod );
				return true;
			}
		};
	}

	/**
	 * Point a Cache instance at the filesystem spy.
	 *
	 * @param Cache  $cache Cache instance.
	 * @param object $spy   Filesystem spy.
	 * @return void
	 */
	private function seed_cache_filesystem( Cache $cache, object $spy ): void {
		$prop = new \ReflectionProperty( Cache::class, 'filesystem' );
		$prop->setValue( $cache, $spy );
		$prop = new \ReflectionProperty( Cache::class, 'fs_initialized' );
		$prop->setValue( $cache, true );
	}

	/**
	 * Point Util::init_filesystem() at the spy via the global.
	 *
	 * @param object $spy Filesystem spy.
	 * @return mixed Previous $GLOBALS['wp_filesystem'] value (null when unset).
	 */
	private function push_global_filesystem( object $spy ) {
		$previous                 = $GLOBALS['wp_filesystem'] ?? null;
		$GLOBALS['wp_filesystem'] = $spy;
		return $previous;
	}

	/**
	 * Restore the previous global filesystem.
	 *
	 * @param mixed $previous Previous value from push_global_filesystem().
	 * @return void
	 */
	private function pop_global_filesystem( $previous ): void {
		if ( null === $previous ) {
			unset( $GLOBALS['wp_filesystem'] );
		} else {
			$GLOBALS['wp_filesystem'] = $previous;
		}
	}

	/**
	 * Invoke a private Cache method.
	 *
	 * @param Cache  $cache Cache instance.
	 * @param string $name  Method name.
	 * @param array  $args  Arguments.
	 * @return mixed
	 */
	private function invoke_private( Cache $cache, string $name, array $args = array() ) {
		$method = new \ReflectionMethod( $cache, $name );
		return $method->invokeArgs( $cache, $args );
	}

	/**
	 * Read a private Cache property.
	 *
	 * @param Cache  $cache Cache instance.
	 * @param string $name  Property name.
	 * @return mixed
	 */
	private function get_prop( Cache $cache, string $name ) {
		$prop = new \ReflectionProperty( Cache::class, $name );
		return $prop->getValue( $cache );
	}

	/**
	 * Winner acquires the render lock and release frees it.
	 */
	public function test_try_acquire_win_tracks_and_releases(): void {
		$this->install_stubs();
		$cache     = $this->make_cache();
		$file_path = $this->invoke_private( $cache, 'get_cache_file_path', array( 'html', '' ) );
		$this->assertNotSame( '', $file_path );

		$owner = $this->invoke_private( $cache, 'try_acquire_html_render_lock', array( $file_path ) );
		$this->assertIsString( $owner );
		$this->assertNotSame( '', $owner );

		$locks = $this->get_prop( $cache, 'html_render_locks' );
		$this->assertNotEmpty( $locks );

		$this->invoke_private( $cache, 'release_html_render_lock', array( $file_path ) );
		$this->assertSame( array(), $this->get_prop( $cache, 'html_render_locks' ) );
		// Owner-checked release removed the transient lock.
		$lock_key = $this->invoke_private( $cache, 'html_render_lock_key', array( $file_path ) );
		$this->assertArrayNotHasKey( $lock_key, $this->transients );
	}

	/**
	 * Observable contention loses: try_acquire returns false and tracks nothing.
	 */
	public function test_try_acquire_lose_returns_false_on_contention(): void {
		$this->install_stubs();
		$cache     = $this->make_cache();
		$file_path = $this->invoke_private( $cache, 'get_cache_file_path', array( 'html', '' ) );
		$lock_key  = $this->invoke_private( $cache, 'html_render_lock_key', array( $file_path ) );
		$this->assertNotSame( '', $lock_key );

		// Another worker observably holds the lock.
		$this->transients[ $lock_key ] = 'other-owner';

		$result = $this->invoke_private( $cache, 'try_acquire_html_render_lock', array( $file_path ) );
		$this->assertFalse( $result );
		$this->assertSame( array(), $this->get_prop( $cache, 'html_render_locks' ) );
	}

	/**
	 * Guard-off fail-open: renders with an owner token, tracks no lock.
	 */
	public function test_try_acquire_fail_open_when_guard_off(): void {
		$this->install_stubs();
		Util::set_settings_cache(
			array(
				'cache_settings' => array(
					'stampedeGuard' => false,
				),
			)
		);
		$cache     = $this->make_cache();
		$file_path = $this->invoke_private( $cache, 'get_cache_file_path', array( 'html', '' ) );

		// Even with the lock held, guard-off renders (fail-open owner).
		$lock_key                      = $this->invoke_private( $cache, 'html_render_lock_key', array( $file_path ) );
		$this->transients[ $lock_key ] = 'other-owner';

		$owner = $this->invoke_private( $cache, 'try_acquire_html_render_lock', array( $file_path ) );
		$this->assertIsString( $owner );
		$this->assertNotSame( '', $owner );
		$this->assertSame( array(), $this->get_prop( $cache, 'html_render_locks' ) );
		$this->assertSame( array(), $this->get_prop( $cache, 'html_render_skipped' ) );
	}

	/**
	 * Empty path fail-open: renders with an owner token.
	 */
	public function test_try_acquire_fail_open_on_empty_path(): void {
		$this->install_stubs();
		$cache = $this->make_cache();
		$owner = $this->invoke_private( $cache, 'try_acquire_html_render_lock', array( '' ) );
		$this->assertIsString( $owner );
		$this->assertNotSame( '', $owner );
	}

	/**
	 * Loser serves dynamic unprocessed and records the skip.
	 */
	public function test_process_buffer_loser_serves_dynamic_and_records_skip(): void {
		$this->install_stubs();
		$cache     = $this->make_cache();
		$file_path = $this->invoke_private( $cache, 'get_cache_file_path', array( 'html', '' ) );
		$lock_key  = $this->invoke_private( $cache, 'html_render_lock_key', array( $file_path ) );

		$this->transients[ $lock_key ] = 'other-owner';

		$result = $cache->process_buffer_for_cache( '<p>raw</p>', '<p>raw</p>' );
		$this->assertSame( '<p>raw</p>', $result );

		$skipped = $this->get_prop( $cache, 'html_render_skipped' );
		$this->assertArrayHasKey( $lock_key, $skipped );
	}

	/**
	 * Loser stash_cache skips persistence and consumes the skip marker.
	 *
	 * Seeds the skipped map exactly as process_buffer_for_cache() does on
	 * lock loss, then verifies stash_cache() returns without touching the
	 * filesystem (write spy records zero writes) and clears the marker, so
	 * unprocessed HTML can never poison the static cache.
	 */
	public function test_loser_stash_cache_skips_save(): void {
		$this->install_stubs();
		$cache = $this->make_cache();

		$writes     = 0;
		$write_mock = new class( $writes ) {
			/**
			 * Write counter reference.
			 *
			 * @var int
			 */
			public $writes = 0;

			/**
			 * Constructor.
			 *
			 * @param int $counter Ignored; writes tracked on the instance.
			 */
			public function __construct( &$counter ) {
				unset( $counter );
			}

			/**
			 * Record a write.
			 *
			 * @param string $path     Path.
			 * @param string $contents Contents.
			 * @param int    $mode     Mode.
			 * @return bool
			 */
			public function put_contents( $path, $contents, $mode = 0644 ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
				unset( $path, $contents, $mode );
				++$this->writes;
				return true;
			}

			/**
			 * Record a move.
			 *
			 * @param string $from Source.
			 * @param string $to   Dest.
			 * @param bool   $overwrite Overwrite.
			 * @return bool
			 */
			public function move( $from, $to, $overwrite = true ) {
				unset( $from, $to, $overwrite );
				return true;
			}

			/**
			 * Stub delete.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function delete( $path ) {
				unset( $path );
				return true;
			}

			/**
			 * Stub exists.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function exists( $path ) {
				unset( $path );
				return false;
			}
		};

		$prop = new \ReflectionProperty( Cache::class, 'filesystem' );
		$prop->setValue( $cache, $write_mock );
		$prop = new \ReflectionProperty( Cache::class, 'fs_initialized' );
		$prop->setValue( $cache, true );

		$file_path = $this->invoke_private( $cache, 'get_cache_file_path', array( 'html', '' ) );
		$lock_key  = $this->invoke_private( $cache, 'html_render_lock_key', array( $file_path ) );
		$this->assertNotSame( '', $lock_key );

		$prop = new \ReflectionProperty( Cache::class, 'html_render_skipped' );
		$prop->setValue( $cache, array( $lock_key => true ) );

		$cache->stash_cache( '<p>unprocessed</p>' );

		$this->assertSame( 0, $write_mock->writes );
		$this->assertSame( array(), $this->get_prop( $cache, 'html_render_skipped' ) );
	}

	/**
	 * Render failure records the skip so stash_cache() never persists
	 * unprocessed output over the winner's optimized file.
	 *
	 * Forces process_buffer_only() to throw via an Image_Optimisation
	 * subclass (no parent-constructor side effects, first pipeline step
	 * throws), with a working save path underneath so the test fails if
	 * the skip marker is missing (writes would be > 0).
	 */
	public function test_render_exception_records_skip_and_stash_skips_save(): void {
		$this->install_stubs();
		$this->install_save_path_stubs();
		$this->reset_buffer_enhanced();
		$cache = $this->make_cache();

		$spy = $this->make_filesystem_spy();
		$this->seed_cache_filesystem( $cache, $spy );
		$fs_backup = $this->push_global_filesystem( $spy );

		$failing_images = new class() extends Image_Optimisation {
			/**
			 * Constructor without side effects (skips hook registration).
			 */
			public function __construct() {
			}

			/**
			 * Fail the render deterministically.
			 *
			 * @param mixed $buffer Buffer (ignored).
			 * @return void
			 * @throws \RuntimeException Always.
			 */
			public function maybe_serve_next_gen_images( $buffer ) {
				unset( $buffer );
				throw new \RuntimeException( 'wppo test render failure' );
			}
		};
		$cache->set_image_optimisation( $failing_images );

		$file_path = $this->invoke_private( $cache, 'get_cache_file_path', array( 'html', '' ) );
		$lock_key  = $this->invoke_private( $cache, 'html_render_lock_key', array( $file_path ) );
		$this->assertNotSame( '', $lock_key );

		try {
			$result = $cache->process_buffer_for_cache( '<p>raw</p>', '<p>raw</p>' );
			$this->assertSame( '<p>raw</p>', $result );

			// Exception path records the skip and frees the flier slot.
			$skipped = $this->get_prop( $cache, 'html_render_skipped' );
			$this->assertArrayHasKey( $lock_key, $skipped );
			$this->assertSame( array(), $this->get_prop( $cache, 'html_render_locks' ) );

			$cache->stash_cache( '<p>unprocessed</p>' );
		} finally {
			$this->pop_global_filesystem( $fs_backup );
		}

		$this->assertSame( 0, $spy->writes );
		$this->assertSame( array(), $this->get_prop( $cache, 'html_render_skipped' ) );
	}

	/**
	 * Winner stash_cache() persists once and releases the render lock.
	 */
	public function test_winner_stash_cache_saves_and_releases(): void {
		$this->install_stubs();
		$this->install_save_path_stubs();
		$this->reset_buffer_enhanced();
		$cache = $this->make_cache();

		$spy = $this->make_filesystem_spy();
		$this->seed_cache_filesystem( $cache, $spy );
		$fs_backup = $this->push_global_filesystem( $spy );

		$file_path = $this->invoke_private( $cache, 'get_cache_file_path', array( 'html', '' ) );
		$this->assertNotSame( '', $file_path );
		$lock_key = $this->invoke_private( $cache, 'html_render_lock_key', array( $file_path ) );
		$this->assertNotSame( '', $lock_key );

		try {
			$owner = $this->invoke_private( $cache, 'try_acquire_html_render_lock', array( $file_path ) );
			$this->assertIsString( $owner );
			$this->assertNotSame( '', $owner );

			$cache->stash_cache( '<p>winner</p>' );
		} finally {
			$this->pop_global_filesystem( $fs_backup );
		}

		// HTML + gzip variants written via tmp+rename; fail-open owners
		// are never tracked, so only the winner path asserts the release.
		$this->assertGreaterThan( 0, $spy->writes );
		$this->assertSame( array(), $this->get_prop( $cache, 'html_render_locks' ) );
		$this->assertArrayNotHasKey( $lock_key, $this->transients );
	}
}
