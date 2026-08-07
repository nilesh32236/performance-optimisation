<?php
/**
 * Tests for WP core inline-CSS (`path` data) integration.
 *
 * Covers the enqueue-time minified-CSS rewrite (`minify_queued_styles`), the
 * `style_loader_tag` guard for already-processed handles, and the combined-CSS
 * `path` registration / double-inlining guards. The `path`-data inline
 * mechanism exists since WP 5.8 (`wp_maybe_inline_styles()` /
 * `styles_inline_size_limit`); only the default budget changed in 6.9.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Tests for core inline-CSS (`path` data) integration.
 *
 * @package PerformanceOptimise\Tests
 */
class InlineCssTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Temp CSS file used for file-size based tests.
	 *
	 * @var string
	 */
	private string $small_css_file;

	/**
	 * Temp CSS file larger than the inline limit.
	 *
	 * @var string
	 */
	private string $large_css_file;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->small_css_file = tempnam( sys_get_temp_dir(), 'wppo-small-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->small_css_file, 'body { color: red; }' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->large_css_file = tempnam( sys_get_temp_dir(), 'wppo-large-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->large_css_file, str_repeat( 'a', 50000 ) );

		// Register common stubs first so per-test when() calls just reconfigure.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', (string) $path );
				$path = preg_replace( '|(?<=.)/+|', '/', $path );
				if ( str_starts_with( $path, '//' ) ) {
					$path = '/' . ltrim( $path, '/' );
				}
				return $path;
			}
		);
		Functions\when( 'WP_Filesystem' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'content_url' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Clean up after each test.
	 */
	protected function tearDown(): void {
		if ( file_exists( $this->small_css_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $this->small_css_file );
		}
		if ( file_exists( $this->large_css_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $this->large_css_file );
		}
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param object $instance The object instance.
	 * @param string $name     The method name.
	 * @param array  $args     Arguments to pass.
	 * @return mixed The method return value.
	 */
	private function invoke_private( $instance, $name, array $args = array() ) {
		$method = new \ReflectionMethod( $instance, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $instance, $args );
	}

	/**
	 * Build a Main instance without running its constructor.
	 *
	 * @return Main Main instance with default private state.
	 */
	private function make_main(): Main {
		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$prop = new \ReflectionProperty( Main::class, 'exclude_css' );
		$prop->setAccessible( true );
		$prop->setValue( $main, array( 'wppo-combine-css' ) );

		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue(
			$main,
			array(
				'file_optimisation' => array(
					'combineCSS' => false,
				),
			)
		);

		return $main;
	}

	/**
	 * Build a Cache instance without running its constructor.
	 *
	 * The tested helpers only read the global $wp_styles registry and filesystem
	 * state, so the constructor (which performs domain validation) is skipped.
	 *
	 * @return Cache Cache instance.
	 */
	private function make_cache(): Cache {
		return ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Build a minimal WP_Styles-like mock.
	 *
	 * @param array $registered Map of handle => object with ->src.
	 * @param array $queue      Queued handles.
	 * @param mixed $path_value Value returned by get_data() for 'path'.
	 * @return object WP_Styles-like mock.
	 */
	private function make_wp_styles( array $registered, array $queue, $path_value ) {
		$wp_styles             = \Mockery::mock();
		$wp_styles->registered = $registered;
		$wp_styles->queue      = $queue;
		$wp_styles->shouldReceive( 'get_data' )->with( \Mockery::any(), 'path' )->andReturn( $path_value );
		return $wp_styles;
	}

	/**
	 * Test that minify_queued_styles is a no-op when core inlining is unavailable.
	 */
	public function test_minify_queued_styles_noop_when_core_inline_missing(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnFalse();

		$main = $this->make_main();

		$this->assertNull( $main->minify_queued_styles() );
	}

	/**
	 * Test that minify_queued_styles is a no-op when combineCSS is enabled.
	 */
	public function test_minify_queued_styles_noop_when_combine_css_enabled(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		$main = $this->make_main();
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue(
			$main,
			array(
				'file_optimisation' => array(
					'combineCSS' => true,
				),
			)
		);

		$this->assertNull( $main->minify_queued_styles() );
	}

	/**
	 * Test that a non-'all' media style is skipped by minify_queued_styles.
	 */
	public function test_minify_queued_styles_skips_non_all_media(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();
		Functions\expect( 'apply_filters' )->with( 'wppo_exclude_minification', \Mockery::type( 'bool' ), \Mockery::any(), \Mockery::any(), \Mockery::any() )->never();

		global $wp_styles;
		$wp_styles = $this->make_wp_styles(
			array(
				'print-css' => (object) array(
					'src'   => 'http://example.com/wp-content/themes/t/style.css',
					'args'  => 'print',
					'extra' => array(),
				),
			),
			array( 'print-css' ),
			false
		);

		$main = $this->make_main();

		$this->assertNull( $main->minify_queued_styles() );
	}

	/**
	 * Test that minify_css leaves a handle carrying the plugin's own path data
	 * untouched (no double re-emit).
	 */
	public function test_minify_css_returns_tag_when_handle_has_plugin_path_data(): void {
		global $wp_styles;
		$wp_styles = $this->make_wp_styles( array(), array(), '/tmp/wordpress/wp-content/cache/wppo/min/css/abc123.css' );

		$main   = $this->make_main();
		$rel    = 'stylesheet';
		$tag    = sprintf( '<link rel="%1$s" id="foo-css" href="http://example.com/foo.css" />', $rel );
		$result = $main->minify_css( $tag, 'foo', 'http://example.com/foo.css' );

		$this->assertSame( $tag, $result );
	}

	/**
	 * Test that minify_css still processes handles without path data.
	 *
	 * The path-data guard must not block the legacy rewrite path, so a handle
	 * without path data falls through to the normal eligibility checks.
	 */
	public function test_minify_css_processes_handle_without_path_data(): void {
		global $wp_styles;
		$wp_styles = $this->make_wp_styles( array(), array(), false );

		// Remote/external URL: Util::get_local_path() returns '' and the tag is
		// returned unchanged via the empty-local-path early return.
		Functions\when( 'wp_parse_url' )->justReturn( false );

		$main   = $this->make_main();
		$rel    = 'stylesheet';
		$tag    = sprintf( '<link rel="%1$s" id="foo-css" href="https://cdn.example.com/foo.css" />', $rel );
		$result = $main->minify_css( $tag, 'foo', 'https://cdn.example.com/foo.css' );

		$this->assertSame( $tag, $result );
	}

	/**
	 * Test that path data registered by core or a third party (outside the
	 * plugin's min cache directory) is not exempted from legacy minification.
	 */
	public function test_minify_css_minifies_third_party_path_data_handle(): void {
		global $wp_styles;
		$wp_styles = $this->make_wp_styles( array(), array(), '/var/www/themes/foo/style.css' );

		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				$url = (string) $url;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Alias emulating wp_parse_url() in tests.
				$path = ( 'http://example.com' === $url ) ? '/' : ( parse_url( $url, PHP_URL_PATH ) ? parse_url( $url, PHP_URL_PATH ) : '/' );
				if ( -1 === $component ) {
					return array( 'path' => $path );
				}
				return PHP_URL_PATH === $component ? $path : null;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com/wp-content' . (string) $path;
			}
		);

		// A real, non-minified local stylesheet so Util::get_local_path() and
		// is_css_minified() can operate on it.
		$source_dir  = '/tmp/wordpress/wp-content/themes/t';
		$source_file = $source_dir . '/style.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory.
		mkdir( $source_dir, 0777, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $source_file, "body {\n\tcolor: red;\n}\n\nh1 {\n\tcolor: blue;\n}\n" );

		$cached_file = '/tmp/wordpress/wp-content/cache/wppo/min/css/' . md5( 'third-party' ) . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $cached_file, 'body{color:red}' );
		$cached_url = 'http://example.com/wp-content/cache/wppo/min/css/' . basename( $cached_file );

		$minifier = \Mockery::mock( 'overload:PerformanceOptimise\Inc\Minify\CSS' );
		$minifier->shouldReceive( 'minify' )->once()->andReturn( $cached_url );

		$main = $this->make_main();
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Static fixture HTML for minify_css() tests.
		$tag    = '<link rel="stylesheet" id="foo-css" href="http://example.com/wp-content/themes/t/style.css" />';
		$result = $main->minify_css( $tag, 'foo', 'http://example.com/wp-content/themes/t/style.css' );

		$this->assertStringContainsString( basename( $cached_file ), $result );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $source_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $cached_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup of temp test fixture dir.
		@rmdir( $source_dir );
	}

	/**
	 * Test that register_combine_css_path registers path data on the combined handle.
	 */
	public function test_register_combine_css_path_adds_path_data(): void {
		Functions\expect( 'wp_style_add_data' )->once()->with( 'wppo-combine-css', 'path', $this->small_css_file );
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		$cache = $this->make_cache();
		$this->invoke_private( $cache, 'register_combine_css_path', array( $this->small_css_file ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that register_combine_css_path is skipped when the file is missing.
	 */
	public function test_register_combine_css_path_skips_missing_file(): void {
		Functions\expect( 'wp_style_add_data' )->never();
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		$cache = $this->make_cache();
		$this->invoke_private( $cache, 'register_combine_css_path', array( '/tmp/does-not-exist.css' ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that core_will_inline is true for small path-data styles.
	 */
	public function test_core_will_inline_true_for_small_path_data_style(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		global $wp_styles;
		$wp_styles = $this->make_wp_styles(
			array(
				'foo' => (object) array(
					'src'   => 'http://example.com/foo.css',
					'args'  => 'all',
					'extra' => array(),
				),
			),
			array( 'foo' ),
			$this->small_css_file
		);

		$cache = $this->make_cache();
		$this->assertTrue( $this->invoke_private( $cache, 'core_will_inline', array( 'foo' ) ) );
	}

	/**
	 * Test that core_will_inline is false for path-data styles over the limit.
	 */
	public function test_core_will_inline_false_for_large_path_data_style(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		global $wp_styles;
		$wp_styles = $this->make_wp_styles(
			array(
				'foo' => (object) array(
					'src'   => 'http://example.com/foo.css',
					'args'  => 'all',
					'extra' => array(),
				),
			),
			array( 'foo' ),
			$this->large_css_file
		);

		$cache = $this->make_cache();
		$this->assertFalse( $this->invoke_private( $cache, 'core_will_inline', array( 'foo' ) ) );
	}

	/**
	 * Test that core_will_inline is false for styles without path data.
	 */
	public function test_core_will_inline_false_without_path_data(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		global $wp_styles;
		$wp_styles = $this->make_wp_styles(
			array(
				'foo' => (object) array(
					'src'   => 'http://example.com/foo.css',
					'args'  => 'all',
					'extra' => array(),
				),
			),
			array( 'foo' ),
			false
		);

		$cache = $this->make_cache();
		$this->assertFalse( $this->invoke_private( $cache, 'core_will_inline', array( 'foo' ) ) );
	}

	/**
	 * Test that will_combine_css_inline is true for a small combined file.
	 */
	public function test_will_combine_css_inline_true_for_small_file(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		global $wp_styles;
		$wp_styles = $this->make_wp_styles(
			array(
				'wppo-combine-css' => (object) array(
					'src'   => 'http://example.com/wp-content/cache/wppo/example.com/index.css',
					'args'  => 'all',
					'extra' => array(),
				),
			),
			array( 'wppo-combine-css' ),
			$this->small_css_file
		);

		$cache = $this->make_cache();
		$this->assertTrue( $this->invoke_private( $cache, 'will_combine_css_inline', array( $this->small_css_file ) ) );
	}

	/**
	 * Test that will_combine_css_inline is false for a large combined file.
	 */
	public function test_will_combine_css_inline_false_for_large_file(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		global $wp_styles;
		$wp_styles = $this->make_wp_styles(
			array(
				'wppo-combine-css' => (object) array(
					'src'   => 'http://example.com/wp-content/cache/wppo/example.com/index.css',
					'args'  => 'all',
					'extra' => array(),
				),
			),
			array( 'wppo-combine-css' ),
			$this->large_css_file
		);

		$cache = $this->make_cache();
		$this->assertFalse( $this->invoke_private( $cache, 'will_combine_css_inline', array( $this->large_css_file ) ) );
	}

	/**
	 * Test that get_styles_inline_limit returns a version-aware default.
	 */
	public function test_get_styles_inline_limit_is_version_aware(): void {
		$cache = $this->make_cache();

		$GLOBALS['wp_version'] = '6.8';
		$this->assertSame( 20000, $this->invoke_private( $cache, 'get_styles_inline_limit' ) );

		$GLOBALS['wp_version'] = '6.9';
		$this->assertSame( 40000, $this->invoke_private( $cache, 'get_styles_inline_limit' ) );

		// Without a known $wp_version the newest default is used.
		unset( $GLOBALS['wp_version'] );
		$this->assertSame( 40000, $this->invoke_private( $cache, 'get_styles_inline_limit' ) );
	}

	/**
	 * Test that core_will_inline honours core's cumulative, smallest-first budget.
	 */
	public function test_core_will_inline_respects_cumulative_budget(): void {
		Functions\when( 'function_exists' )->justReturn( true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$medium_a = tempnam( sys_get_temp_dir(), 'wppo-meda-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$medium_b = tempnam( sys_get_temp_dir(), 'wppo-medb-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $medium_a, str_repeat( 'a', 25000 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $medium_b, str_repeat( 'b', 25000 ) );

		$paths = array(
			'a' => $medium_a,
			'b' => $medium_b,
		);

		global $wp_styles;
		$wp_styles             = \Mockery::mock();
		$wp_styles->registered = array(
			'a' => (object) array( 'src' => 'http://example.com/a.css' ),
			'b' => (object) array( 'src' => 'http://example.com/b.css' ),
		);
		$wp_styles->queue      = array( 'a', 'b' );
		$wp_styles->shouldReceive( 'get_data' )->with( \Mockery::any(), 'path' )->andReturnUsing(
			static function ( $handle ) use ( $paths ) {
				return $paths[ $handle ] ?? null;
			}
		);

		$cache = $this->make_cache();

		// A alone fits the 40KB budget...
		$this->assertTrue( $this->invoke_private( $cache, 'core_will_inline', array( 'a' ) ) );
		// ...but A + B exceeds it, so B is not inlined despite fitting individually.
		$this->assertFalse( $this->invoke_private( $cache, 'core_will_inline', array( 'b' ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $medium_a );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $medium_b );
	}

	/**
	 * Test that minify_queued_styles rewrites a style to its minified file and
	 * registers core `path` data for inlining.
	 */
	public function test_minify_queued_styles_rewrites_style_and_registers_path(): void {
		Functions\when( 'wp_maybe_inline_styles' )->justReturn( '' );

		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				$url = (string) $url;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Alias emulating wp_parse_url() in tests.
				$path = ( 'http://example.com' === $url ) ? '/' : ( parse_url( $url, PHP_URL_PATH ) ? parse_url( $url, PHP_URL_PATH ) : '/' );
				if ( -1 === $component ) {
					return array( 'path' => $path );
				}
				return PHP_URL_PATH === $component ? $path : null;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		// A real, non-minified local stylesheet that passes all eligibility checks.
		$source_dir  = '/tmp/wordpress/wp-content/themes/t';
		$source_file = $source_dir . '/style.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory.
		mkdir( $source_dir, 0777, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $source_file, "body {\n\tcolor: red;\n}\n\nh1 {\n\tcolor: blue;\n}\n" );

		$cached_file = '/tmp/wordpress/wp-content/cache/wppo/min/css/' . md5( 'happy-path' ) . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $cached_file, 'body{color:red}' );
		$cached_url = 'http://example.com/wp-content/cache/wppo/min/css/' . basename( $cached_file );

		$minifier = \Mockery::mock( 'overload:PerformanceOptimise\Inc\Minify\CSS' );
		$minifier->shouldReceive( 'minify' )->once()->andReturn( $cached_url );
		$minifier->shouldReceive( 'get_cache_file_path' )->once()->andReturn( $cached_file );

		Functions\expect( 'wp_style_add_data' )->once()->with( 'foo', 'path', $cached_file )->andReturn( true );

		global $wp_styles;
		$wp_styles = $this->make_wp_styles(
			array(
				'foo' => (object) array(
					'src'   => 'http://example.com/wp-content/themes/t/style.css',
					'args'  => 'all',
					'extra' => array(),
				),
			),
			array( 'foo' ),
			false
		);

		$main = $this->make_main();
		$main->minify_queued_styles();

		$this->assertSame( $cached_url, $wp_styles->registered['foo']->src );
		$this->assertSame( (int) filemtime( $cached_file ), $wp_styles->registered['foo']->ver );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $source_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $cached_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup of temp test fixture dir.
		@rmdir( $source_dir );
	}
}
