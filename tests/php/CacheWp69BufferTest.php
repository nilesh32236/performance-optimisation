<?php
/**
 * Tests for the WP 6.9+ cache behaviours from issues #880 and #881.
 *
 * #880 — combined-CSS budget skip guards (WP version, CDN, wppo_inline_combined_css).
 * #881 — output-buffer nesting hardening against the core template-enhancement
 * buffer (wp_should_output_buffer_template_for_enhancement interplay) and the
 * once-per-request enhancement guard.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Tests for the WP 6.9+ combine-skip guards and buffer-nesting hardening.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheWp69BufferTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '0' );
		Functions\when( 'update_option' )->justReturn( true );
		unset( $GLOBALS['wp_version'] );
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $GLOBALS['wp_version'] );
		\Brain\Monkey\tearDown();
		if ( class_exists( Main::class ) ) {
			Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Build a Cache instance without running its constructor.
	 *
	 * @param array $options Options to seed.
	 * @return Cache
	 */
	private function make_cache( array $options = array() ): Cache {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		$prop = new \ReflectionProperty( Cache::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, $options );

		return $cache;
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
		$method->setAccessible( true );
		return $method->invokeArgs( $cache, $args );
	}

	/**
	 * Register temp CSS fixtures under the ABSPATH-bound cache dir and a
	 * WP_Styles-like global with matching srcs.
	 *
	 * @param string[] $handles Handle names.
	 * @param int[]    $sizes   File size per handle.
	 * @return string[] Created fixture paths.
	 */
	private function queue_styles( array $handles, array $sizes ): array {
		$dir = ABSPATH . 'wp-content/cache-test-fixtures';
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $dir, 0777, true );
		}

		global $wp_styles;
		$registered = array();
		$paths      = array();

		foreach ( $handles as $i => $handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$path     = $dir . '/' . $handle . '.css';
			$contents = str_repeat( 'a', $sizes[ $i ] ?? 100 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, $contents );
			$paths[]               = $path;
			$registered[ $handle ] = (object) array(
				'src'   => 'http://example.com/wp-content/cache-test-fixtures/' . $handle . '.css',
				'args'  => 'all',
				'extra' => array(),
			);
		}

		$wp_styles             = \Mockery::mock();
		$wp_styles->registered = $registered;
		$wp_styles->queue      = $handles;
		$wp_styles->shouldReceive( 'get_data' )->with( \Mockery::any(), 'path' )->andReturn( null );

		return $paths;
	}

	/**
	 * Remove the fixture directory.
	 *
	 * @return void
	 */
	private function cleanup_fixtures(): void {
		$dir = ABSPATH . 'wp-content/cache-test-fixtures';
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.css' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup of temp fixture dir.
			rmdir( $dir );
		}
	}

	/**
	 * Test that the combine skip never applies below WP 6.9 (issue #880):
	 * the 40KB budget is a 6.9+ default, so older cores keep combining.
	 */
	public function test_combine_skip_requires_wp_69(): void {
		$GLOBALS['wp_version'] = '6.8.2';
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$this->queue_styles( array( 'small' ), array( 100 ) );

		$cache = $this->make_cache();
		$this->assertFalse( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'small' ) ) ) );

		// A 6.9 RC also passes the 6.9-alpha gate.
		$GLOBALS['wp_version'] = '6.9-RC1';
		$this->assertTrue( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'small' ) ) ) );

		$this->cleanup_fixtures();
	}

	/**
	 * Test that a configured CDN URL keeps the combined file (issue #880):
	 * the combined file is served from the CDN and is never redundant.
	 */
	public function test_combine_skip_skipped_when_cdn_configured(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$this->queue_styles( array( 'small' ), array( 100 ) );

		$cache = $this->make_cache(
			array(
				'file_optimisation' => array( 'cdnURL' => 'https://cdn.example.com' ),
			)
		);
		$this->assertFalse( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'small' ) ) ) );

		$this->cleanup_fixtures();
	}

	/**
	 * Test that a falsy `wppo_inline_combined_css` filter keeps the combined
	 * file (issue #880): operators who disabled inlining still expect the
	 * combined file to exist.
	 */
	public function test_combine_skip_skipped_when_inline_disabled_by_filter(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		// Brain Monkey's apply_filters() returns the default when no filter is
		// added; stub a falsy filter value directly (the default-true case is
		// covered by the other skip tests).
		Functions\when( 'apply_filters' )->justReturn( false );
		$this->queue_styles( array( 'small' ), array( 100 ) );

		$cache = $this->make_cache();
		$this->assertFalse( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'small' ) ) ) );

		$this->cleanup_fixtures();
	}

	/**
	 * Test that classic themes always combine (skip is block-theme-only).
	 */
	public function test_combine_skip_not_applied_on_classic_theme(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		$this->queue_styles( array( 'small' ), array( 100 ) );

		$cache = $this->make_cache();
		$this->assertFalse( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'small' ) ) ) );

		$this->cleanup_fixtures();
	}

	/**
	 * Test that a total payload over the inline budget keeps combining.
	 */
	public function test_combine_skip_not_applied_when_over_budget(): void {
		$GLOBALS['wp_version'] = '6.9';
		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$this->queue_styles(
			array( 'big' ),
			array( 40001 )
		);

		$cache = $this->make_cache();
		$this->assertFalse( $this->invoke_private( $cache, 'should_skip_combine_for_inline_budget', array( array( 'big' ) ) ) );

		$this->cleanup_fixtures();
	}

	/**
	 * Test that the legacy cache buffer refuses to open while the core
	 * template-enhancement buffer is active (issue #881) — nesting balance.
	 */
	public function test_start_output_buffer_refuses_when_enhancement_buffer_active(): void {
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( true );

		$level_before = ob_get_level();
		( $this->make_cache() )->start_output_buffer();

		$this->assertSame( $level_before, ob_get_level() );
	}

	/**
	 * Test that the legacy cache buffer still opens below WP 6.9 where the
	 * enhancement-buffer API does not exist (default behavior unchanged).
	 */
	public function test_start_output_buffer_opens_without_enhancement_api(): void {
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( false );
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		$cache = $this->make_cache();

		// Make is_not_cacheable() pass: non-empty root dir + domain, benign URI.
		$prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, WP_CONTENT_DIR . '/cache/wppo' );

		$prop = new \ReflectionProperty( Cache::class, 'domain' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, 'example.com' );

		$prop = new \ReflectionProperty( Cache::class, 'request_uri' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, '/' );

		$prop = new \ReflectionProperty( Cache::class, 'url_path' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, '' );

		// Guests are cache-eligible with the default (empty) settings.
		$this->assertTrue( $this->invoke_private( $cache, 'is_cache_allowed_for_current_user' ) );
		$this->assertFalse( $this->invoke_private( $cache, 'is_not_cacheable' ) );

		$level_before = ob_get_level();
		$cache->start_output_buffer();

		$this->assertSame( $level_before + 1, ob_get_level() );

		// Clean the buffer opened by the test (and its tracked level).
		$prop = new \ReflectionProperty( Cache::class, 'cache_ob_level' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, null );
		while ( ob_get_level() > $level_before ) {
			ob_end_clean();
		}
	}

	/**
	 * Test that the buffer enhancement pipeline runs at most once per request
	 * (issue #881): the legacy fallback callback must not re-process HTML that
	 * the 6.9+ enhancement filter already processed.
	 */
	public function test_process_buffer_only_is_one_shot(): void {
		$cache = $this->make_cache();

		$prop = new \ReflectionProperty( Cache::class, 'buffer_enhanced' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, true );

		// When already enhanced this request, the input passes through untouched
		// (the image-optimisation pipeline is never even constructed).
		$this->assertSame( '<p>raw</p>', $this->invoke_private( $cache, 'process_buffer_only', array( '<p>raw</p>' ) ) );
	}

	/**
	 * Test that the legacy used-CSS buffer refuses to open while the core
	 * template-enhancement buffer is active (issue #881).
	 */
	public function test_start_used_css_buffer_refuses_when_enhancement_buffer_active(): void {
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( true );

		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$main->start_used_css_buffer();

		// Nothing opened: start_used_css_buffer would have opened a buffer when
		// the guard was missing (no other stubs are needed before the guard).
		$this->assertFalse( $this->used_css_buffer_flag( $main ) );
	}

	/**
	 * Test that the legacy LCP buffer refuses to open while the core
	 * template-enhancement buffer is active (issue #881).
	 */
	public function test_start_lcp_priority_buffer_refuses_when_enhancement_buffer_active(): void {
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( true );

		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$level_before = ob_get_level();
		$main->start_lcp_priority_buffer();

		$this->assertSame( $level_before, ob_get_level() );
	}

	/**
	 * Test the used-CSS one-shot: once the pipeline ran, both the 6.9+ filter
	 * and the legacy callback pass the buffer through untouched (issue #881).
	 */
	public function test_used_css_pipeline_is_one_shot(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$prop = new \ReflectionProperty( Main::class, 'used_css_buffer_enhanced' );
		$prop->setAccessible( true );
		$prop->setValue( $main, true );

		// should_optimise_for_logged_in() needs cache_settings; seed via reflection.
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue(
			$main,
			array(
				'cache_settings'    => array( 'enableLoggedInCache' => false ),
				'file_optimisation' => array(),
			)
		);

		$this->assertSame( '<p>untouched</p>', $main->process_used_css_only( '<p>untouched</p>', '<p>raw</p>' ) );
		$this->assertSame( '<p>untouched</p>', $main->process_used_css_capture( '<p>untouched</p>' ) );
	}

	/**
	 * Read the Main::$used_css_buffer_enhanced flag.
	 *
	 * @param Main $main Main instance.
	 * @return bool
	 */
	private function used_css_buffer_flag( Main $main ): bool {
		$prop = new \ReflectionProperty( Main::class, 'used_css_buffer_enhanced' );
		$prop->setAccessible( true );
		return (bool) $prop->getValue( $main );
	}
}
