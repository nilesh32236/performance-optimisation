<?php
/**
 * Tests for Object_Cache::flush_scoped() multisite scoping (issue #1186).
 *
 * Covers: single-site delegation to flush(), multisite forcing of the
 * `object_cache_allow_flush_all` opt-out filter to the blog-prefix path
 * (added then removed), and fail-closed refusal while a foreign
 * object-cache.php drop-in is active.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test doubles are co-located by convention.
 * @phpcs:disable WordPress.Files.FileName -- Declares a minimal WP_Error stand-in required for instanceof checks in error-path tests.
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Util;

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in for error-path tests.
	 *
	 * Mirrors the core API surface used by the plugin: code and message.
	 */
	class WP_Error {

		/**
		 * Error codes mapped to messages.
		 *
		 * @var array
		 */
		public $errors = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Optional error data (ignored).
		 */
		public function __construct( $code = '', $message = '', $data = null ) {
			unset( $data );
			if ( '' !== $code ) {
				$this->errors[ (string) $code ] = (string) $message;
			}
		}

		/**
		 * First error code, or empty string.
		 *
		 * @return string
		 */
		public function get_error_code() {
			$keys = array_keys( $this->errors );
			return array() === $keys ? '' : (string) $keys[0];
		}

		/**
		 * First error message, or empty string.
		 *
		 * @return string
		 */
		public function get_error_message() {
			$values = array_values( $this->errors );
			return array() === $values ? '' : (string) $values[0];
		}
	}
}

/**
 * Tests for the multisite-scoped object-cache flush.
 */
class ObjectCacheFlushScopedTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Scripted is_multisite() result.
	 *
	 * @var bool
	 */
	public $multisite = false;

	/**
	 * Drop-in path override served via the wppo_object_cache_dropin_path filter.
	 *
	 * @var string|null
	 */
	public $dropin_override = null;

	/**
	 * In-memory option store.
	 *
	 * @var array
	 */
	public $options = array();

	/**
	 * In-memory transient store.
	 *
	 * @var array
	 */
	public $transients = array();

	/**
	 * Recorded add_filter() calls as [hook, priority] pairs.
	 *
	 * @var array
	 */
	public $added_filters = array();

	/**
	 * Callbacks registered via add_filter(), keyed by hook.
	 *
	 * @var array
	 */
	public $added_callbacks = array();

	/**
	 * Value of object_cache_allow_flush_all seen inside the stub flush.
	 *
	 * @var bool|null
	 */
	public $allow_flush_all_seen = null;

	/**
	 * Stubbed current blog ID.
	 *
	 * @var int
	 */
	public $blog_id = 1;

	/**
	 * What the stub cache flush() should return.
	 *
	 * @var bool
	 */
	public $flush_return = true;

	/**
	 * Whether the stub cache flush() should throw.
	 *
	 * @var bool
	 */
	public $flush_throw = false;

	/**
	 * Recorded remove_filter() calls as [hook, priority] pairs.
	 *
	 * @var array
	 */
	public $removed_filters = array();

	/**
	 * Count of wp_cache_flush() delegations observed on the stub cache.
	 *
	 * @var int
	 */
	public $cache_flush_calls = 0;

	/**
	 * Previous global object cache stub to restore in tearDown().
	 *
	 * @var mixed
	 */
	private $original_cache = null;

	/**
	 * Previous global $wpdb to restore in tearDown().
	 *
	 * @var mixed
	 */
	private $original_wpdb = null;

	/**
	 * Temp directory created for drop-in fixtures.
	 *
	 * @var string|null
	 */
	private $temp_dir = null;

	/**
	 * Whether WP_CONTENT_DIR was created by this test.
	 *
	 * @var bool
	 */
	private $content_dir_created = false;

	/**
	 * Set up Brain Monkey, WP stubs, and the recording cache stub.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Util::reset_runtime_caches();
		$this->register_common_function_stubs();

		$test = $this;

		Functions\when( 'is_multisite' )->alias(
			function () use ( $test ) {
				return $test->multisite;
			}
		);
		Functions\when( 'get_current_blog_id' )->alias(
			function () use ( $test ) {
				return $test->blog_id;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null ) use ( $test ) {
				if ( 'wppo_object_cache_dropin_path' === $hook && null !== $test->dropin_override ) {
					return $test->dropin_override;
				}
				if ( 'object_cache_allow_flush_all' === $hook && ! empty( $test->added_callbacks[ $hook ] ) ) {
					foreach ( $test->added_callbacks[ $hook ] as $cb ) {
						$value = call_user_func( $cb, $value );
					}
				}
				return $value;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback = null, $priority = 10, $args = 1 ) use ( $test ) {
				unset( $args );
				$test->added_filters[] = array( $hook, $priority );
				if ( null !== $callback ) {
					$test->added_callbacks[ $hook ][] = $callback;
				}
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( $hook, $callback = null, $priority = 10 ) use ( $test ) {
				unset( $callback );
				$test->removed_filters[] = array( $hook, $priority );
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
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
		Functions\when( 'wp_kses_post' )->returnArg();

		// Recording $wpdb so Log::add() (via log_redis_failure()) stays
		// in-process without a database.
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
			 * @param string $table Table name.
			 * @param array  $data  Row data.
			 * @param mixed  $format Format (ignored).
			 * @return int
			 */
			public function insert( $table, $data, $format = null ) {
				unset( $format );
				$this->inserts[] = array( $table, $data );
				return 1;
			}
		};

		// Recording object cache so the real wp_cache_flush() (defined by
		// templates/object-cache.php at bootstrap) delegates visibly.
		$this->original_cache       = $GLOBALS['wp_object_cache'] ?? null;
		$this->cache_flush_calls    = 0;
		$GLOBALS['wp_object_cache'] = new class( $test ) {
			/**
			 * Owning test for call counting.
			 *
			 * @var ObjectCacheFlushScopedTest
			 */
			private $test;

			/**
			 * Constructor.
			 *
			 * @param ObjectCacheFlushScopedTest $test Owning test.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * Record the flush, route through the scope filter, and succeed.
			 *
			 * @return bool
			 * @throws \Exception When flush_throw is set on the owning test.
			 */
			public function flush() {
				++$this->test->cache_flush_calls;
				if ( $this->test->flush_throw ) {
					throw new \Exception( 'Foreign cache threw.' );
				}
				// Route through the opt-out filter like the real drop-in
				// so tests prove the blog-prefix path was actually forced.
				$this->test->allow_flush_all_seen = apply_filters( 'object_cache_allow_flush_all', true );
				return $this->test->flush_return;
			}
		};
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Restore globals, remove fixtures, and tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = $this->original_wpdb;
		if ( null === $this->original_cache ) {
			unset( $GLOBALS['wp_object_cache'] );
		} else {
			$GLOBALS['wp_object_cache'] = $this->original_cache;
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

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

		$this->multisite            = false;
		$this->dropin_override      = null;
		$this->options              = array();
		$this->transients           = array();
		$this->added_filters        = array();
		$this->added_callbacks      = array();
		$this->allow_flush_all_seen = null;
		$this->blog_id              = 1;
		$this->flush_return         = true;
		$this->flush_throw          = false;
		$this->removed_filters      = array();
		$this->cache_flush_calls    = 0;

		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Create an isolated directory inside WP_CONTENT_DIR for drop-in fixtures.
	 *
	 * Must live inside WP_CONTENT_DIR: the wppo_object_cache_dropin_path
	 * filter is containment-validated, so outside paths are ignored.
	 *
	 * @return string Directory path.
	 */
	private function make_temp_dir(): string {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( WP_CONTENT_DIR, 0777, true );
			$this->content_dir_created = true;
		}
		$dir = WP_CONTENT_DIR . '/wppo-flush-scoped-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $dir, 0777, true );
		$this->temp_dir = $dir;
		return $dir;
	}

	/**
	 * Single-site installs delegate straight to flush() without scoping filters.
	 */
	public function test_single_site_delegates_to_flush(): void {
		$this->multisite = false;

		$manager = new Object_Cache();

		$this->assertTrue( $manager->flush_scoped() );
		$this->assertSame( 1, $this->cache_flush_calls, 'Single-site flush_scoped() must delegate to wp_cache_flush() exactly once.' );
		$this->assertSame( array(), $this->added_filters, 'Single-site flush must not force the scope filter.' );
		$this->assertSame( array(), $this->removed_filters, 'Single-site flush must not touch the scope filter.' );
		$this->assertNull( $manager->get_last_flush_error() );
	}

	/**
	 * Multisite forces the blog-prefix path and releases the filter afterwards.
	 */
	public function test_multisite_scoped_flush_forces_filter_and_succeeds(): void {
		$this->multisite = true;
		$this->blog_id   = 3;

		$manager = new Object_Cache();

		$this->assertTrue( $manager->flush_scoped() );
		$this->assertSame( 1, $this->cache_flush_calls, 'Multisite flush_scoped() must flush exactly once.' );
		$this->assertContains(
			array( 'object_cache_allow_flush_all', PHP_INT_MAX ),
			$this->added_filters,
			'Multisite flush must force object_cache_allow_flush_all to false.'
		);
		$this->assertContains(
			array( 'object_cache_allow_flush_all', PHP_INT_MAX ),
			$this->removed_filters,
			'Multisite flush must release the forced scope filter afterwards.'
		);
		// Prove the recorded callback actually forces the blog-prefix path.
		$this->assertNotEmpty( $this->added_callbacks['object_cache_allow_flush_all'] );
		foreach ( $this->added_callbacks['object_cache_allow_flush_all'] as $cb ) {
			$this->assertFalse( call_user_func( $cb, true ), 'Scope filter must force object_cache_allow_flush_all to false.' );
		}
		$this->assertFalse( $this->allow_flush_all_seen, 'Stub flush must observe the forced false scope filter.' );
		$this->assertNull( $manager->get_last_flush_error() );
	}

	/**
	 * Flush failure still releases the forced scope filter.
	 */
	public function test_multisite_flush_failure_releases_filter(): void {
		$this->multisite    = true;
		$this->blog_id      = 3;
		$this->flush_return = false;

		$manager = new Object_Cache();

		$this->assertFalse( $manager->flush_scoped() );
		$this->assertSame( 1, $this->cache_flush_calls );
		$this->assertContains(
			array( 'object_cache_allow_flush_all', PHP_INT_MAX ),
			$this->added_filters
		);
		$this->assertContains(
			array( 'object_cache_allow_flush_all', PHP_INT_MAX ),
			$this->removed_filters,
			'Failed flush must still release the forced scope filter.'
		);
		$this->assertInstanceOf( \WP_Error::class, $manager->get_last_flush_error() );
	}

	/**
	 * A throwing cache backend is converted to false + last_flush_error.
	 */
	public function test_multisite_flush_exception_returns_false(): void {
		$this->multisite   = true;
		$this->blog_id     = 3;
		$this->flush_throw = true;

		$manager = new Object_Cache();

		$this->assertFalse( $manager->flush_scoped() );
		$this->assertContains(
			array( 'object_cache_allow_flush_all', PHP_INT_MAX ),
			$this->removed_filters,
			'Throwing flush must still release the forced scope filter.'
		);
		$error = $manager->get_last_flush_error();
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'flush_exception', $error->get_error_code() );
	}

	/**
	 * Unknown multisite state falls back to single-site flush().
	 */
	public function test_unknown_multisite_state_falls_back_to_flush(): void {
		Functions\when( 'is_multisite' )->alias(
			static function () {
				throw new \Exception( 'Boot too early.' );
			}
		);

		$manager = new Object_Cache();

		$this->assertTrue( $manager->flush_scoped() );
		$this->assertSame( 1, $this->cache_flush_calls, 'Unknown multisite state must delegate to flush().' );
		$this->assertSame( array(), $this->added_filters, 'Fallback flush must not force the scope filter.' );
	}

	/**
	 * Multisite refuses to flush while a foreign drop-in is active.
	 */
	public function test_multisite_foreign_dropin_refuses_flush(): void {
		$this->multisite = true;

		$dir          = $this->make_temp_dir();
		$foreign_path = $dir . '/object-cache.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $foreign_path, "<?php\n// Some other plugin's drop-in.\n" );
		$this->dropin_override = $foreign_path;

		$manager = new Object_Cache();

		$this->assertFalse( $manager->flush_scoped(), 'Foreign drop-in must fail the scoped flush closed.' );
		$this->assertSame( 0, $this->cache_flush_calls, 'Foreign drop-in must never reach wp_cache_flush().' );

		$error = $manager->get_last_flush_error();
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'flush_foreign_dropin', $error->get_error_code() );
	}
}
