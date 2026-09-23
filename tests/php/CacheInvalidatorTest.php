<?php
/**
 * Regression tests for the ARCH-007 Cache_Invalidator extraction (issue #1540).
 *
 * Proves the invalidation/purge cluster moved from `Cache` to
 * `PerformanceOptimise\Inc\Cache_Invalidator` without behavior change:
 * single/dynamic/woo invalidation file effects, clear_cache single vs all,
 * traversal-guard refusal, fallback serve/redirect/limits, LiteSpeed swap
 * purge, `wppo_before/after_cache_clear` firing order/count, and multisite
 * blog isolation.
 *
 * Instance state stays on `Cache` (Option A); an in-memory filesystem double
 * with real PHP methods keeps the `method_exists()` guards green while no
 * real disk is touched.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Cache_Invalidator;
use PerformanceOptimise\Inc\Loader_Map;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test doubles are co-located by convention.
// phpcs:disable WordPress.Files.FileName -- Fixture class names cannot derive the *Test.php file name.

/**
 * In-memory WP_Filesystem double for the invalidator suite.
 *
 * A concrete anonymous class (not a Mockery mock): SUT guards call
 * `method_exists( $fs, ... )`, which returns false for Mockery `__call`
 * magic and would skip every snapshot/restore path. Real methods keep those
 * guards green while the in-memory tree keeps the disk untouched.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class WPPO_Invalidator_Fake_FS {

	/**
	 * Files: normalized path => bytes.
	 *
	 * @var array<string,string>
	 */
	public $files = array();

	/**
	 * Directories: normalized path => true.
	 *
	 * @var array<string,bool>
	 */
	public $dirs = array();

	/**
	 * Deleted paths in call order (files and directories).
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * Normalize a path like wp_normalize_path().
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function norm( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = (string) preg_replace( '|(?<=.)/+|', '/', $path );
		return $path;
	}

	/**
	 * Seed a directory and its parents.
	 *
	 * @param string $path Directory path.
	 * @return void
	 */
	public function seed_dir( $path ) {
		$dir = rtrim( $this->norm( $path ), '/' );
		while ( '' !== $dir && ! isset( $this->dirs[ $dir ] ) ) {
			$this->dirs[ $dir ] = true;
			$pos                = strrpos( $dir, '/' );
			$dir                = false === $pos ? '' : substr( $dir, 0, $pos );
		}
	}

	/**
	 * Seed a file and its parent directories.
	 *
	 * @param string $path     File path.
	 * @param string $contents File bytes.
	 * @return void
	 */
	public function seed_file( $path, $contents = 'x' ) {
		$path                 = $this->norm( $path );
		$this->files[ $path ] = (string) $contents;
		$pos                  = strrpos( $path, '/' );
		if ( false !== $pos ) {
			$this->seed_dir( substr( $path, 0, $pos ) );
		}
	}

	/**
	 * Whether a path exists.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		$path = $this->norm( $path );
		return isset( $this->files[ $path ] ) || isset( $this->dirs[ $path ] );
	}

	/**
	 * Whether a path is a directory.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_dir( $path ) {
		return isset( $this->dirs[ $this->norm( $path ) ] );
	}

	/**
	 * Create a directory tree.
	 *
	 * @param string $path  Path.
	 * @param mixed  $chmod Ignored.
	 * @return bool
	 */
	public function mkdir( $path, $chmod = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->seed_dir( $path );
		return true;
	}

	/**
	 * Delete a file or (recursively) a directory.
	 *
	 * @param string $path      Path.
	 * @param bool   $recursive Delete directory contents.
	 * @param mixed  $type      Ignored.
	 * @return bool
	 */
	public function delete( $path, $recursive = false, $type = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$path = $this->norm( $path );
		if ( isset( $this->files[ $path ] ) ) {
			unset( $this->files[ $path ] );
			$this->deleted[] = $path;
			return true;
		}
		$prefix = rtrim( $path, '/' ) . '/';
		$found  = false;
		foreach ( array_keys( $this->files ) as $file ) {
			if ( 0 === strpos( $file, $prefix ) ) {
				unset( $this->files[ $file ] );
				$this->deleted[] = $file;
				$found           = true;
			}
		}
		if ( $recursive ) {
			foreach ( array_keys( $this->dirs ) as $dir ) {
				if ( $dir === $path || 0 === strpos( $dir, $prefix ) ) {
					unset( $this->dirs[ $dir ] );
					$this->deleted[] = $dir;
					$found           = true;
				}
			}
			$this->deleted[] = $path;
			return true;
		}
		return $found;
	}

	/**
	 * List immediate children of a directory.
	 *
	 * @param string $path Directory path.
	 * @return array<string,array{name:string,type:string}> Entries keyed by basename.
	 */
	public function dirlist( $path ) {
		$path   = rtrim( $this->norm( $path ), '/' );
		$prefix = $path . '/';
		$out    = array();
		foreach ( $this->dirs as $dir => $ignored ) {
			if ( 0 === strpos( $dir, $prefix ) && false === strpos( substr( $dir, strlen( $prefix ) ), '/' ) ) {
				$name         = substr( $dir, strlen( $prefix ) );
				$out[ $name ] = array(
					'name' => $name,
					'type' => 'd',
				);
			}
		}
		foreach ( $this->files as $file => $ignored ) {
			if ( 0 === strpos( $file, $prefix ) && false === strpos( substr( $file, strlen( $prefix ) ), '/' ) ) {
				$name         = substr( $file, strlen( $prefix ) );
				$out[ $name ] = array(
					'name' => $name,
					'type' => 'f',
				);
			}
		}
		return $out;
	}

	/**
	 * Read a file.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		$path = $this->norm( $path );
		return isset( $this->files[ $path ] ) ? $this->files[ $path ] : false;
	}

	/**
	 * Write a file.
	 *
	 * @param string $path     Path.
	 * @param string $contents Bytes.
	 * @param mixed  $mode     Ignored.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $mode = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->seed_file( $path, $contents );
		return true;
	}

	/**
	 * Size of a file.
	 *
	 * @param string $path Path.
	 * @return int|false
	 */
	public function size( $path ) {
		$path = $this->norm( $path );
		return isset( $this->files[ $path ] ) ? strlen( $this->files[ $path ] ) : false;
	}

	/**
	 * Copy a file.
	 *
	 * @param string $src       Source path.
	 * @param string $dest      Destination path.
	 * @param bool   $overwrite Overwrite flag.
	 * @return bool
	 */
	public function copy( $src, $dest, $overwrite = false ) {
		$src  = $this->norm( $src );
		$dest = $this->norm( $dest );
		if ( ! isset( $this->files[ $src ] ) ) {
			return false;
		}
		if ( isset( $this->files[ $dest ] ) && ! $overwrite ) {
			return false;
		}
		$this->seed_file( $dest, $this->files[ $src ] );
		return true;
	}

	/**
	 * Move a file.
	 *
	 * @param string $src       Source path.
	 * @param string $dest      Destination path.
	 * @param bool   $overwrite Overwrite flag.
	 * @return bool
	 */
	public function move( $src, $dest, $overwrite = false ) {
		if ( ! $this->copy( $src, $dest, $overwrite ) ) {
			return false;
		}
		$this->delete( $src );
		return true;
	}
}

/**
 * In-memory $wpdb recorder for activity-log assertions.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class WPPO_Invalidator_Wpdb_Recorder {

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
 * ARCH-007 Cache_Invalidator regression tests.
 *
 * Each test runs in a separate PHP process (ARCH-006 precedent): Brain
 * Monkey mocked-function definitions and the `purge_fallback_limits()` /
 * traversal-probe static memos persist for the whole suite-process lifetime,
 * so an in-process run would leak stubs into later files that branch on
 * `function_exists()`. Isolation keeps this file order-independent.
 *
 * @package PerformanceOptimise\Tests
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class CacheInvalidatorTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		setUp as protected wppoSetUp;
		tearDown as protected wppoTearDown;
	}

	/**
	 * Per-test apply_filters() overrides: tag => value or callable.
	 *
	 * @var array<string,mixed>
	 */
	private array $filter_map = array();

	/**
	 * Captured do_action() calls: list of [hook, args].
	 *
	 * @var array<int,array{0:string,1:array}>
	 */
	private array $actions = array();

	/**
	 * Captured delete_transient() keys.
	 *
	 * @var string[]
	 */
	private array $deleted_transients = array();

	/**
	 * Captured update_option() calls: list of [option, value].
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	private array $updated_options = array();

	/**
	 * Permalink fixtures: post ID => URL.
	 *
	 * @var array<int,string>
	 */
	private array $permalinks = array();

	/**
	 * Fake filesystem for this test.
	 *
	 * @var WPPO_Invalidator_Fake_FS|null
	 */
	private ?WPPO_Invalidator_Fake_FS $fake_fs = null;

	/**
	 * Previous $wpdb instance state.
	 *
	 * @var array{had:bool,instance:mixed}
	 */
	private array $wpdb_state = array(
		'had'      => false,
		'instance' => null,
	);

	/**
	 * Superglobal backup.
	 *
	 * @var array<string,mixed>
	 */
	private array $server_backup = array();

	/**
	 * Fresh stubs per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wppoSetUp();
		$this->filter_map         = array();
		$this->actions            = array();
		$this->deleted_transients = array();
		$this->updated_options    = array();
		$this->permalinks         = array();

		$test = $this;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $test ) {
				return $test->filter_value( $tag, $value );
			}
		);
		Functions\when( 'do_action' )->alias(
			static function ( $hook, ...$args ) use ( $test ) {
				$test->record_action( $hook, $args );
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array();
				}
				if ( 'show_on_front' === $name ) {
					return 'posts';
				}
				if ( 'page_for_posts' === $name ) {
					return 0;
				}
				if ( 'wppo_cache_last_cleared' === $name ) {
					return 0;
				}
				return $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $option, $value ) {
				$this->updated_options[] = array( $option, $value );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				$this->deleted_transients[] = $key;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'site_url' )->justReturn( 'http://example.com/' );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'wp_make_link_relative' )->alias(
			static function ( $url ) {
				$path = wp_parse_url( (string) $url, PHP_URL_PATH );
				return is_string( $path ) ? $path : '';
			}
		);
		Functions\when( 'get_permalink' )->alias(
			function ( $post_id ) {
				$id = (int) $post_id;
				if ( isset( $this->permalinks[ $id ] ) ) {
					return $this->permalinks[ $id ];
				}
				return 'http://example.com/?p=' . $id;
			}
		);
		Functions\when( 'get_post_type' )->justReturn( false );
		Functions\when( 'get_post_type_archive_link' )->justReturn( '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wp_get_object_terms' )->justReturn( array() );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_rand' )->justReturn( 0 );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );
		// The shared bootstrap stubs trailingslashit() as returnArg (no slash
		// appended). Containment checks funnel through
		// is_path_contained(trailingslashit($dir)), whose validator needs the
		// trailing slash, so restore the real behavior here (ARCH-006 precedent).
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' ) . '/';
			}
		);

		$this->fake_fs                = new WPPO_Invalidator_Fake_FS();
		$GLOBALS['wp_filesystem']     = $this->fake_fs;
		$this->wpdb_state['had']      = isset( $GLOBALS['wpdb'] );
		$this->wpdb_state['instance'] = $this->wpdb_state['had'] ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb']              = new WPPO_Invalidator_Wpdb_Recorder();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup.
		$this->server_backup['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup.
		$this->server_backup['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup.
		$this->server_backup['SERVER_SOFTWARE'] = $_SERVER['SERVER_SOFTWARE'] ?? null;
		$_SERVER['HTTP_HOST']                   = 'example.com';
		$_SERVER['REQUEST_URI']                 = '/';
		unset( $_SERVER['SERVER_SOFTWARE'] );

		Util::reset_runtime_caches();
	}

	/**
	 * Restore state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_filesystem'] );
		if ( $this->wpdb_state['had'] ) {
			$GLOBALS['wpdb'] = $this->wpdb_state['instance'];
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		foreach ( array( 'HTTP_HOST', 'REQUEST_URI', 'SERVER_SOFTWARE' ) as $key ) {
			if ( null === $this->server_backup[ $key ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $this->server_backup[ $key ];
			}
		}
		$this->fake_fs = null;
		$this->wppoTearDown();
	}

	/**
	 * Resolve a stubbed filter value (override map, else passthrough).
	 *
	 * @param string $tag   Filter tag.
	 * @param mixed  $value Incoming value.
	 * @return mixed Filtered value.
	 */
	public function filter_value( $tag, $value = null ) {
		if ( array_key_exists( $tag, $this->filter_map ) ) {
			$override = $this->filter_map[ $tag ];
			if ( $override instanceof \Closure ) {
				return $override( $value );
			}
			return $override;
		}
		return $value;
	}

	/**
	 * Record a do_action() call.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Hook arguments.
	 * @return void
	 */
	public function record_action( $hook, $args ): void {
		$this->actions[] = array( $hook, $args );
	}

	/**
	 * Build a Cache instance wired to the fake filesystem.
	 *
	 * @param string $domain Cache domain.
	 * @return Cache
	 */
	private function make_cache( string $domain = 'example.com' ): Cache {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		$root = WP_CONTENT_DIR . '/cache/wppo-invalidator-test';
		$prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$prop->setValue( $cache, $root );
		$prop = new \ReflectionProperty( Cache::class, 'domain' );
		$prop->setValue( $cache, $domain );
		$prop = new \ReflectionProperty( Cache::class, 'cache_root_url' );
		$prop->setValue( $cache, 'http://example.com/wp-content/cache/wppo-invalidator-test' );
		$prop = new \ReflectionProperty( Cache::class, 'options' );
		$prop->setValue( $cache, array() );
		$prop = new \ReflectionProperty( Cache::class, 'request_uri' );
		$prop->setValue( $cache, '/' );
		$prop = new \ReflectionProperty( Cache::class, 'url_path' );
		$prop->setValue( $cache, '' );
		$prop = new \ReflectionProperty( Cache::class, 'path_rejected' );
		$prop->setValue( $cache, false );
		$prop = new \ReflectionProperty( Cache::class, 'filesystem' );
		$prop->setValue( $cache, $this->fake_fs );
		$prop = new \ReflectionProperty( Cache::class, 'fs_initialized' );
		$prop->setValue( $cache, true );

		return $cache;
	}

	/**
	 * Cache root used by make_cache().
	 *
	 * @return string
	 */
	private function root(): string {
		return WP_CONTENT_DIR . '/cache/wppo-invalidator-test';
	}

	/**
	 * Seed a full page entry (html + gzip + brotli + marker + sidecars).
	 *
	 * @param string $domain Domain directory.
	 * @param string $slug   URL slug ('' for homepage).
	 * @return void
	 */
	private function seed_page( string $domain, string $slug ): void {
		$base = $this->root() . '/' . $domain . ( '' === $slug ? '' : '/' . $slug );
		$this->fake_fs->seed_file( $base . '/index.html', '<html>' . $slug . '</html>' );
		$this->fake_fs->seed_file( $base . '/index.html.gz', 'gz' );
		$this->fake_fs->seed_file( $base . '/index.html.br', 'br' );
		$this->fake_fs->seed_file( $base . '/.wppo-no-cache', '123' );
		$this->fake_fs->seed_file( $base . '/index-abcdef123456.html', 'role' );
		$this->fake_fs->seed_file( $base . '/index.css', '.a{color:red}' );
		$this->fake_fs->seed_file( $base . '/used-css.css', '.b{color:blue}' );
	}

	/**
	 * Action names fired, in order.
	 *
	 * @return string[]
	 */
	private function fired_hooks(): array {
		return array_map(
			static function ( $entry ) {
				return $entry[0];
			},
			$this->actions
		);
	}

	/**
	 * Single-page purge deletes html variants, marker, role variants, and sidecars.
	 *
	 * @return void
	 */
	public function test_invalidate_single_deletes_page_artifacts(): void {
		$this->seed_page( 'example.com', 'about' );
		$this->seed_page( 'example.com', 'contact' );
		$this->permalinks[42] = 'http://example.com/about/';

		$cache = $this->make_cache();
		$cache->invalidate_single_static_html( 42 );

		$root  = $this->root() . '/example.com/about';
		$other = $this->root() . '/example.com/contact';
		foreach ( array( 'index.html', 'index.html.gz', 'index.html.br', '.wppo-no-cache', 'index-abcdef123456.html', 'index.css', 'used-css.css' ) as $leaf ) {
			$this->assertFalse( $this->fake_fs->exists( $root . '/' . $leaf ), "Expected deleted: {$leaf}" );
		}
		foreach ( array( 'index.html', 'index.css', 'used-css.css' ) as $leaf ) {
			$this->assertTrue( $this->fake_fs->exists( $other . '/' . $leaf ), "Must survive: {$leaf}" );
		}
		$this->assertContains( 'wppo_cache_stats', $this->deleted_transients );
	}

	/**
	 * Single-page purge refuses hostile permalinks without touching the tree.
	 *
	 * @return void
	 */
	public function test_invalidate_single_traversal_refused(): void {
		$this->seed_page( 'example.com', 'about' );
		$this->permalinks[43] = 'http://example.com/../../etc/';

		$cache = $this->make_cache();
		$cache->invalidate_single_static_html( 43 );
		$cache->invalidate_single_static_html( 0 );

		$this->assertTrue( $this->fake_fs->exists( $this->root() . '/example.com/about/index.html' ) );
		$this->assertSame( array(), $this->fake_fs->deleted );
	}

	/**
	 * Dynamic purge clears the post plus home and schedules regeneration.
	 *
	 * @return void
	 */
	public function test_invalidate_dynamic_purges_post_and_home(): void {
		$scheduled = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $timestamp, $hook, $args = array() ) use ( &$scheduled ) {
				$scheduled[] = array( $hook, $args );
				return true;
			}
		);
		$this->seed_page( 'example.com', 'hello' );
		$this->seed_page( 'example.com', '' );
		$this->seed_page( 'example.com', 'contact' );
		$this->permalinks[7] = 'http://example.com/hello/';

		$cache = $this->make_cache();
		$cache->invalidate_dynamic_static_html( 7 );

		$this->assertFalse( $this->fake_fs->exists( $this->root() . '/example.com/hello/index.html' ) );
		$this->assertFalse( $this->fake_fs->exists( $this->root() . '/example.com/index.html' ) );
		$this->assertTrue( $this->fake_fs->exists( $this->root() . '/example.com/contact/index.html' ) );
		$this->assertSame( array( array( 'wppo_generate_static_page', array( 7 ) ) ), $scheduled );
		$this->assertContains( 'wppo_cache_stats', $this->deleted_transients );
	}

	/**
	 * Woo purge clears the product and shop paths only, honoring the filter.
	 *
	 * @return void
	 */
	public function test_invalidate_woo_product_purges_self_and_shop(): void {
		$captured                                       = array();
		$this->filter_map['wppo_woo_invalidation_urls'] = static function ( $urls ) use ( &$captured ) {
			$captured = $urls;
			return $urls;
		};
		Functions\when( 'wc_get_page_id' )->alias(
			static function ( $page ) {
				return 'shop' === $page ? 10 : 0;
			}
		);
		$this->seed_page( 'example.com', 'product/hoodie' );
		$this->seed_page( 'example.com', 'shop' );
		$this->seed_page( 'example.com', '' );
		$this->permalinks[99] = 'http://example.com/product/hoodie/';
		$this->permalinks[10] = 'http://example.com/shop/';

		$cache = $this->make_cache();
		$cache->invalidate_woo_object( 99, 'product' );

		$this->assertFalse( $this->fake_fs->exists( $this->root() . '/example.com/product/hoodie/index.html' ) );
		$this->assertFalse( $this->fake_fs->exists( $this->root() . '/example.com/shop/index.html' ) );
		$this->assertTrue( $this->fake_fs->exists( $this->root() . '/example.com/index.html' ) );
		$this->assertContains( '/product/hoodie/', $captured );
		$this->assertContains( '/shop/', $captured );
	}

	/**
	 * Static single-page clear deletes the page set and fires ordered actions.
	 *
	 * @return void
	 */
	public function test_clear_cache_single_path(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/about/';
		$root                   = WP_CONTENT_DIR . '/cache/wppo/example.com/about';
		$this->fake_fs->seed_file( $root . '/index.html', '<html>about</html>' );
		$this->fake_fs->seed_file( $root . '/index.html.gz', 'gz' );
		$this->fake_fs->seed_file( $root . '/.wppo-no-cache', '1' );
		$this->fake_fs->seed_file( $root . '/index.css', '.a{}' );
		$this->fake_fs->seed_file( $root . '/used-css.css', '.b{}' );
		$other = WP_CONTENT_DIR . '/cache/wppo/example.com/contact/index.html';
		$this->fake_fs->seed_file( $other, '<html>contact</html>' );

		$this->assertTrue( Cache::clear_cache( '/about/' ) );

		foreach ( array( 'index.html', 'index.html.gz', '.wppo-no-cache', 'index.css', 'used-css.css' ) as $leaf ) {
			$this->assertFalse( $this->fake_fs->exists( $root . '/' . $leaf ), "Expected deleted: {$leaf}" );
		}
		$this->assertTrue( $this->fake_fs->exists( $other ) );
		$this->assertSame( array( 'wppo_before_cache_clear', 'wppo_after_cache_clear' ), $this->fired_hooks() );
		$this->assertSame( 'single_page', $this->actions[0][1][0] );
		$this->assertSame( '/about/', $this->actions[0][1][1] );
		$this->assertSame( 'single_page', $this->actions[1][1][0] );
		$updated = array_column( $this->updated_options, 0 );
		$this->assertContains( 'wppo_cache_last_cleared_time', $updated );
	}

	/**
	 * Hostile single-page clears are refused: no deletes, no after-action.
	 *
	 * @return void
	 */
	public function test_clear_cache_hostile_refused(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/';
		$other                  = WP_CONTENT_DIR . '/cache/wppo/example.com/contact/index.html';
		$this->fake_fs->seed_file( $other, '<html>contact</html>' );

		$this->assertFalse( Cache::clear_cache( '../../etc/passwd' ) );
		$this->assertFalse( Cache::clear_cache( 'https://evil.com/about/' ) );

		$this->assertTrue( $this->fake_fs->exists( $other ) );
		$this->assertSame( array(), $this->fake_fs->deleted );
		$this->assertSame( array( 'wppo_before_cache_clear', 'wppo_before_cache_clear' ), $this->fired_hooks() );
	}

	/**
	 * Full clear wipes the domain tree and fires the all-type action pair.
	 *
	 * @return void
	 */
	public function test_clear_cache_all(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/';
		$domain                 = WP_CONTENT_DIR . '/cache/wppo/example.com';
		$this->fake_fs->seed_file( $domain . '/about/index.html', '<html>a</html>' );
		$this->fake_fs->seed_file( $domain . '/index.html', '<html>home</html>' );
		$min = WP_CONTENT_DIR . '/cache/wppo/min/1/css/index-abc123.css';
		$this->fake_fs->seed_file( $min, '.a{}' );

		$this->assertTrue( Cache::clear_cache() );

		$this->assertFalse( $this->fake_fs->exists( $domain . '/about/index.html' ) );
		$this->assertFalse( $this->fake_fs->exists( $domain . '/index.html' ) );
		$this->assertFalse( $this->fake_fs->exists( $min ) );
		$this->assertSame( array( 'wppo_before_cache_clear', 'wppo_after_cache_clear' ), $this->fired_hooks() );
		$this->assertSame( 'all', $this->actions[0][1][0] );
		$this->assertSame( 'all', $this->actions[1][1][0] );
	}

	/**
	 * Fallback retention + 302 serve + redirect contract + limits clamp.
	 *
	 * @return void
	 */
	public function test_purge_fallback_retain_serve_and_limits(): void {
		if ( ! defined( 'WPPO_PURGE_FALLBACK_NO_EXIT' ) ) {
			define( 'WPPO_PURGE_FALLBACK_NO_EXIT', true );
		}
		$this->filter_map['wppo_purge_fallback_enabled'] = true;
		$this->filter_map['wppo_purge_fallback_limits']  = array(
			'max_files'       => 1000,
			'max_depth'       => 100,
			'max_bytes'       => -5,
			'max_dirs'        => 0,
			'max_total_bytes' => 999999999,
		);

		$cache = $this->make_cache();
		$dir   = $this->root() . '/example.com/css';
		$base  = $dir . '/index.css';
		$this->fake_fs->seed_file( $base, '.kept{color:red}' );

		$invalidator = new Cache_Invalidator( $cache );
		$this->assertTrue( $invalidator->delete_cache_files( $base ) );
		$fallback = $dir . '/fallback.css';
		$this->assertTrue( $this->fake_fs->exists( $fallback ) );
		$this->assertSame( '.kept{color:red}', $this->fake_fs->get_contents( $fallback ) );

		$response = $invalidator->get_purge_fallback_response( $base );
		$this->assertTrue( $response['served'] );
		$this->assertSame( 302, $response['status'] );
		$this->assertSame( $fallback, $response['fallback_path'] );
		$this->assertStringContainsString( 'fallback.css', $response['location'] );

		$built = Cache_Invalidator::build_purge_fallback_headers( $response['location'], $response['status'] );
		$this->assertSame( 302, $built['status'] );
		$this->assertContains( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', $built['headers'] );
		$this->assertTrue( $invalidator->serve_purge_fallback_response( $response ) );

		$custom = Cache_Invalidator::purge_fallback_redirect_status();
		$this->assertSame( 302, $custom );

		$limits = Cache_Invalidator::purge_fallback_limits();
		$this->assertSame( 200, $limits['max_files'] );
		$this->assertSame( 20, $limits['max_depth'] );
		$this->assertSame( 512 * 1024, $limits['max_bytes'] );
		$this->assertSame( 200, $limits['max_dirs'] );
		$this->assertSame( 8 * 1024 * 1024, $limits['max_total_bytes'] );
	}

	/**
	 * Swap-dir purge deletes inside the allowlisted root only.
	 *
	 * @return void
	 */
	public function test_delete_swap_dir_files_contained(): void {
		$staging = sys_get_temp_dir() . '/wppo-swap-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-only fixture tree.
		mkdir( $staging . '/nested', 0777, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture.
		file_put_contents( $staging . '/a.tmp', 'a' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture.
		file_put_contents( $staging . '/nested/b.tmp', 'b' );
		$outside = sys_get_temp_dir() . '/wppo-swap-outside-' . uniqid() . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only fixture.
		file_put_contents( $outside, 'keep' );

		try {
			Cache_Invalidator::delete_swap_dir_files( $staging );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file -- Test-only assertion.
			$this->assertFalse( is_file( $staging . '/a.tmp' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file -- Test-only assertion.
			$this->assertFalse( is_file( $staging . '/nested/b.tmp' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file -- Test-only assertion.
			$this->assertTrue( is_file( $outside ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Test-only assertion.
			$this->assertTrue( is_dir( $staging ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file -- Test-only cleanup guard.
			if ( is_file( $staging . '/a.tmp' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup.
				unlink( $staging . '/a.tmp' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file -- Test-only cleanup guard.
			if ( is_file( $staging . '/nested/b.tmp' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup.
				unlink( $staging . '/nested/b.tmp' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Test-only cleanup guard.
			if ( is_dir( $staging . '/nested' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only cleanup.
				rmdir( $staging . '/nested' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Test-only cleanup guard.
			if ( is_dir( $staging ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only cleanup.
				rmdir( $staging );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file -- Test-only cleanup guard.
			if ( is_file( $outside ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup.
				unlink( $outside );
			}
		}
	}

	/**
	 * Multisite: blog-keyed transients and domain-isolated purges.
	 *
	 * @return void
	 */
	public function test_multisite_blog_isolation(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );

		$this->assertSame( '2_wppo_cache_stats', Util::transient_key( 'wppo_cache_stats' ) );

		$this->seed_page( 'site-a.example.com', 'about' );
		$this->seed_page( 'site-b.example.com', 'about' );
		$this->permalinks[42] = 'http://site-a.example.com/about/';

		$cache_a = $this->make_cache( 'site-a.example.com' );
		$cache_a->invalidate_single_static_html( 42 );

		$this->assertFalse( $this->fake_fs->exists( $this->root() . '/site-a.example.com/about/index.html' ) );
		$this->assertTrue( $this->fake_fs->exists( $this->root() . '/site-b.example.com/about/index.html' ) );
		$this->assertContains( '2_wppo_cache_stats', $this->deleted_transients );
	}

	/**
	 * Facade contract: proxies, guards, loader, and visibility are intact.
	 *
	 * @return void
	 */
	public function test_facade_contract_intact(): void {
		$this->assertTrue( method_exists( Cache::class, 'clear_cache' ) );
		$this->assertTrue( method_exists( Cache::class, 'invalidate_dynamic_static_html' ) );
		$this->assertTrue( method_exists( Cache::class, 'invalidate_single_static_html' ) );
		$this->assertTrue( method_exists( Cache::class, 'invalidate_woo_object' ) );
		$this->assertTrue( method_exists( Cache::class, 'maybe_serve_purge_fallback' ) );
		$this->assertTrue( method_exists( Cache::class, 'delete_cache_files' ) );
		$this->assertTrue( method_exists( Cache::class, 'delete_all_cache_files' ) );

		$proxy = new \ReflectionMethod( Cache::class, 'delete_cache_files' );
		$this->assertTrue( $proxy->isPrivate() );
		$owner = new \ReflectionMethod( Cache_Invalidator::class, 'delete_cache_files' );
		$this->assertTrue( $owner->isPublic() );

		$this->assertSame( 'class-cache-invalidator.php', Loader_Map::fallback_map()['Cache_Invalidator'] );
		$path = Loader_Map::path_for( 'Cache_Invalidator' );
		$this->assertNotNull( $path );
		$this->assertFileExists( (string) $path );
		$this->assertSame( $path, Loader_Map::path_for( 'PerformanceOptimise\\Inc\\Cache_Invalidator' ) );
	}
}
