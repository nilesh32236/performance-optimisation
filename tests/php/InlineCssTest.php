<?php
/**
 * Tests for WP 6.3+ core inline-CSS (`path` data) integration.
 *
 * Covers the enqueue-time minified-CSS rewrite (`minify_queued_styles`), the
 * `style_loader_tag` guard for already-processed handles, and the combined-CSS
 * `path` registration / double-inlining guards.
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->small_css_file = tempnam( sys_get_temp_dir(), 'wppo-small-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->small_css_file, 'body { color: red; }' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->large_css_file = tempnam( sys_get_temp_dir(), 'wppo-large-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->large_css_file, str_repeat( 'a', 50000 ) );

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
	 * Test that minify_css leaves a path-data handle untouched (no double re-emit).
	 */
	public function test_minify_css_returns_tag_when_handle_has_path_data(): void {
		global $wp_styles;
		$wp_styles = $this->make_wp_styles( array(), array(), $this->small_css_file );

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
	 * Test that register_combine_css_path registers path data on the combined handle.
	 */
	public function test_register_combine_css_path_adds_path_data(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();
		Functions\expect( 'wp_style_add_data' )->once()->with( 'wppo-combine-css', 'path', $this->small_css_file );

		$cache = $this->make_cache();
		$this->invoke_private( $cache, 'register_combine_css_path', array( $this->small_css_file ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that register_combine_css_path is skipped when the file is missing.
	 */
	public function test_register_combine_css_path_skips_missing_file(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();
		Functions\expect( 'wp_style_add_data' )->never();

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

		$cache = $this->make_cache();
		$this->assertTrue( $this->invoke_private( $cache, 'will_combine_css_inline', array( $this->small_css_file ) ) );
	}

	/**
	 * Test that will_combine_css_inline is false for a large combined file.
	 */
	public function test_will_combine_css_inline_false_for_large_file(): void {
		Functions\expect( 'function_exists' )->with( 'wp_maybe_inline_styles' )->once()->andReturnTrue();

		$cache = $this->make_cache();
		$this->assertFalse( $this->invoke_private( $cache, 'will_combine_css_inline', array( $this->large_css_file ) ) );
	}
}
