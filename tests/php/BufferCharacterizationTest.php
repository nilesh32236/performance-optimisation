<?php
/**
 * Buffer orchestration characterization tests (issue #905).
 *
 * Pins CURRENT behaviour of the triple-path dual-era buffer orchestration
 * (Main::setup_hooks cache/used-css/LCP registration + Cache::start_output_buffer
 * self-gate): HTML vs non-HTML, JSON/AJAX/REST bypass, WP 6.9 dual path vs
 * legacy fallback, LiteSpeed interplay, cache-write ordering, DONOTCACHEPAGE.
 *
 * These are characterization tests — they document what the code does today,
 * not what it should do. No behaviour change in this step.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Characterization coverage for the buffer orchestration.
 */
class BufferCharacterizationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * OB level at setUp (for leak detection).
	 *
	 * @var int
	 */
	private int $ob_baseline = 0;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_cached_home_urls();
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		\PerformanceOptimise\Inc\Util::clear_permalink_cache();
		\PerformanceOptimise\Inc\Util::reset_html_processor_memo();
		\PerformanceOptimise\Inc\CDN::reset_cache();
		if ( class_exists( LiteSpeed_Integration::class ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		$this->set_buffer_enhanced( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		$_SERVER['SERVER_SOFTWARE'] = 'Apache';
		$_SERVER['HTTP_HOST']       = 'example.com';
		$_SERVER['REQUEST_URI']     = '/';
		unset( $_SERVER['QUERY_STRING'] );
		unset( $GLOBALS['wp_version'] );
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		$this->ob_baseline = ob_get_level();
	}

	/**
	 * Tear down Brain Monkey and statics.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		while ( ob_get_level() > $this->ob_baseline ) {
			ob_end_clean();
		}
		$this->set_buffer_enhanced( false );
		unset( $GLOBALS['wp_version'] );
		unset( $_SERVER['QUERY_STRING'], $_SERVER['SERVER_SOFTWARE'] );
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		if ( class_exists( LiteSpeed_Integration::class ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		if ( class_exists( Main::class ) ) {
			Main::reset_instance();
		}
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Cache instance without running its constructor.
	 *
	 * @param array  $options     Options to seed.
	 * @param string $request_uri Request URI.
	 * @return Cache
	 */
	private function make_cache( array $options = array(), string $request_uri = '/' ): Cache {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$prop  = new \ReflectionProperty( Cache::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, $options );
		$prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, WP_CONTENT_DIR . '/cache/wppo' );
		$prop = new \ReflectionProperty( Cache::class, 'domain' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, 'example.com' );
		$prop = new \ReflectionProperty( Cache::class, 'request_uri' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, $request_uri );
		$parsed = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path   = wp_normalize_path( trim( rawurldecode( (string) $parsed ), '/' ) );
		if ( false !== strpos( $path, '..' ) ) {
			$path = '';
		}
		$prop = new \ReflectionProperty( Cache::class, 'url_path' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, $path );
		$prop = new \ReflectionProperty( Cache::class, 'cache_ob_level' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, null );
		return $cache;
	}

	/**
	 * Invoke a private Cache/Main method.
	 *
	 * @param object $obj  Instance.
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke_private( $obj, string $name, array $args = array() ) {
		$method = new \ReflectionMethod( $obj, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $obj, $args );
	}

	/**
	 * Set the Cache::$buffer_enhanced one-shot flag.
	 *
	 * @param bool $value Value.
	 */
	private function set_buffer_enhanced( bool $value ): void {
		$prop = new \ReflectionProperty( Cache::class, 'buffer_enhanced' );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	/**
	 * Stub the front-end condition functions to benign HTML defaults.
	 */
	private function stub_front_end_html(): void {
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		// Pin the preview/AJAX/JSON tags too (issue #1097): the unconditional
		// editor-preview bypass fails open on stale process-wide Brain Monkey
		// declarations, so cacheable fixtures must pin them false.
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
	}

	/**
	 * Build a Main instance without running its constructor.
	 *
	 * @param array $options Options to seed.
	 * @return Main
	 */
	private function make_main( array $options = array() ): Main {
		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $main, $options );
		$prop = new \ReflectionProperty( Main::class, 'image_optimisation' );
		$prop->setAccessible( true );
		$prop->setValue( $main, new Image_Optimisation( $options ) );
		$prop = new \ReflectionProperty( Main::class, 'google_fonts' );
		$prop->setAccessible( true );
		$prop->setValue( $main, new Google_Fonts( $options ) );
		$prop = new \ReflectionProperty( Main::class, 'used_css_buffer_enhanced' );
		$prop->setAccessible( true );
		$prop->setValue( $main, false );
		return $main;
	}

	/**
	 * Plain HTML front-end request is cacheable.
	 */
	public function test_html_request_is_page_cacheable(): void {
		$this->stub_front_end_html();
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( false );
		$cache = $this->make_cache( array(), '/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_not_cacheable' ) );
		$this->assertTrue( $cache->is_page_cacheable() );
	}

	/**
	 * Non-HTML asset extensions bypass the cache.
	 */
	public function test_asset_extensions_are_not_cacheable(): void {
		$this->stub_front_end_html();
		foreach ( array( '/style.css', '/app.js', '/image.png', '/feed.xml' ) as $uri ) {
			$cache = $this->make_cache( array(), $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ), 'Expected not cacheable: ' . $uri );
			$this->assertFalse( $cache->is_page_cacheable(), 'Expected is_page_cacheable false: ' . $uri );
		}
	}

	/**
	 * Feeds and XML sitemaps bypass the cache.
	 */
	public function test_feed_and_sitemap_are_not_cacheable(): void {
		$this->stub_front_end_html();
		Functions\when( 'is_feed' )->justReturn( true );
		$cache = $this->make_cache( array(), '/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );

		Functions\when( 'is_feed' )->justReturn( false );
		$cache = $this->make_cache( array(), '/wp-sitemap.xml' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * 404 responses bypass the cache.
	 */
	public function test_404_is_not_cacheable(): void {
		$this->stub_front_end_html();
		Functions\when( 'is_404' )->justReturn( true );
		$cache = $this->make_cache( array(), '/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * WooCommerce cart/checkout/account and cart cookies bypass the cache.
	 */
	public function test_woo_contexts_are_not_cacheable(): void {
		$this->stub_front_end_html();
		Functions\when( 'is_cart' )->justReturn( true );
		$this->assertTrue( $this->invoke_private( $this->make_cache( array(), '/' ), 'is_not_cacheable' ) );

		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( true );
		$this->assertTrue( $this->invoke_private( $this->make_cache( array(), '/' ), 'is_not_cacheable' ) );

		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( true );
		$this->assertTrue( $this->invoke_private( $this->make_cache( array(), '/' ), 'is_not_cacheable' ) );

		Functions\when( 'is_account_page' )->justReturn( false );
		$_COOKIE['woocommerce_items_in_cart'] = '1';
		$this->assertTrue( $this->invoke_private( $this->make_cache( array(), '/' ), 'is_not_cacheable' ) );
		unset( $_COOKIE['woocommerce_items_in_cart'] );
	}

	/**
	 * Filter wppo_should_cache_request=false bypasses the cache even for HTML.
	 */
	public function test_should_cache_filter_false_bypasses(): void {
		$this->stub_front_end_html();
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_should_cache_request' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		$cache = $this->make_cache( array(), '/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
		$this->assertFalse( $cache->is_page_cacheable() );
	}

	/**
	 * Query strings s/ver/v block file-cache storage (maybe_store_cache).
	 */
	public function test_query_string_blocks_cache_storage(): void {
		$this->stub_front_end_html();
		foreach ( array( 's=test', 'ver=1.0', 'v=2' ) as $qs ) {
			$_SERVER['QUERY_STRING'] = $qs;
			$cache                   = $this->make_cache( array(), '/?' . $qs );
			$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ), 'Expected no store for QS: ' . $qs );
		}
		unset( $_SERVER['QUERY_STRING'] );
	}

	/**
	 * LCP buffer bails on AJAX (JSON bypass by construction).
	 */
	public function test_lcp_buffer_bails_on_ajax(): void {
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_robots' )->justReturn( false );
		Functions\when( 'is_trackback' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$main         = $this->make_main( array( 'cache_settings' => array() ) );
		$level_before = ob_get_level();
		$main->start_lcp_priority_buffer();
		$this->assertSame( $level_before, ob_get_level() );
	}

	/**
	 * LCP buffer bails on feeds (non-HTML bypass).
	 */
	public function test_lcp_buffer_bails_on_feed(): void {
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( true );
		Functions\when( 'is_robots' )->justReturn( false );
		Functions\when( 'is_trackback' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
		$main         = $this->make_main( array( 'cache_settings' => array() ) );
		$level_before = ob_get_level();
		$main->start_lcp_priority_buffer();
		$this->assertSame( $level_before, ob_get_level() );
	}

	/**
	 * Server-Timing capture bails on admin/AJAX (never opts into the buffer there).
	 */
	public function test_capture_template_start_bails_on_admin_ajax(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_server_timing_enabled' === $tag ) {
					return true;
				}
				return $value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$main = $this->make_main( array( 'performance_audit' => array( 'server_timing_enabled' => true ) ) );
		$main->capture_template_start();
		$prop = new \ReflectionProperty( Main::class, 'server_timing_template_start' );
		$prop->setAccessible( true );
		$this->assertSame( 0.0, $prop->getValue( $main ) );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$main->capture_template_start();
		$this->assertSame( 0.0, $prop->getValue( $main ) );
	}

	/**
	 * Server-Timing emission bails on admin/AJAX even when enabled.
	 */
	public function test_emit_server_timing_bails_on_admin_ajax(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_server_timing_enabled' === $tag ) {
					return true;
				}
				return $value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'headers_sent' )->justReturn( false );
		$headers = array();
		Functions\when( 'header' )->alias(
			static function ( $header, $replace = true ) use ( &$headers ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match header().
				$headers[] = $header;
			}
		);
		$main = $this->make_main( array( 'performance_audit' => array( 'server_timing_enabled' => true ) ) );
		$main->emit_server_timing_header( '<html></html>' );
		$this->assertSame( array(), $headers );
	}

	/**
	 * Legacy cache buffer refuses to open while the core buffer is active.
	 */
	public function test_start_output_buffer_refuses_when_enhancement_active(): void {
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( true );
		$level_before = ob_get_level();
		( $this->make_cache() )->start_output_buffer();
		$this->assertSame( $level_before, ob_get_level() );
	}

	/**
	 * Legacy cache buffer opens when the core buffer is inactive (opt-out path).
	 */
	public function test_start_output_buffer_opens_when_enhancement_inactive(): void {
		$this->stub_front_end_html();
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		$cache        = $this->make_cache( array(), '/' );
		$level_before = ob_get_level();
		$cache->start_output_buffer();
		$this->assertSame( $level_before + 1, ob_get_level() );
		$prop = new \ReflectionProperty( Cache::class, 'cache_ob_level' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, null );
		while ( ob_get_level() > $level_before ) {
			ob_end_clean();
		}
	}

	/**
	 * Legacy cache buffer opens at most once per request (exactly-once start).
	 */
	public function test_start_output_buffer_is_exactly_once(): void {
		$this->stub_front_end_html();
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( false );
		$cache        = $this->make_cache( array(), '/' );
		$level_before = ob_get_level();
		$cache->start_output_buffer();
		$cache->start_output_buffer();
		$this->assertSame( $level_before + 1, ob_get_level() );
		$prop = new \ReflectionProperty( Cache::class, 'cache_ob_level' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, null );
		while ( ob_get_level() > $level_before ) {
			ob_end_clean();
		}
	}

	/**
	 * Enhancement pipeline runs at most once per request.
	 */
	public function test_process_buffer_only_is_one_shot(): void {
		$cache = $this->make_cache();
		$this->set_buffer_enhanced( true );
		$this->assertSame( '<p>raw</p>', $this->invoke_private( $cache, 'process_buffer_only', array( '<p>raw</p>' ) ) );
		$this->set_buffer_enhanced( false );
	}

	/**
	 * Cache-write ordering: filter path processes, finalized action persists.
	 */
	public function test_process_then_stash_ordering(): void {
		$this->stub_front_end_html();
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( false );
		// Filesystem unavailable in unit tests (WP_Filesystem stubbed false), so
		// stash_cache must no-op without throwing even for a cacheable page.
		$cache     = $this->make_cache( array(), '/' );
		$processed = $cache->process_buffer_for_cache( '<html><body>hi</body></html>', '<html><body>hi</body></html>' );
		$this->assertIsString( $processed );
		$this->assertStringContainsString( 'hi', $processed );
		// Second filter call in the same request is a no-op (one-shot guard).
		$this->set_buffer_enhanced( true );
		$this->assertSame( '<p>x</p>', $cache->process_buffer_for_cache( '<p>x</p>', '<p>raw</p>' ) );
		$this->set_buffer_enhanced( false );
		// Stash never throws without a filesystem.
		$cache->stash_cache( '<html><body>hi</body></html>' );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Capture setup_hooks buffer registrations for assertions.
	 *
	 * @param array  $options            Plugin options.
	 * @param string $wp_version         WP version string.
	 * @param bool   $enhancement_active Mocked core-buffer state.
	 * @return array{actions:array,filters:array}
	 */
	private function capture_setup_hooks( array $options, string $wp_version, bool $enhancement_active ): array {
		$GLOBALS['wp_version'] = $wp_version;
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( $enhancement_active );
		$actions = array();
		$filters = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback = null, $priority = 10, $args = 1 ) use ( &$actions ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match add_action().
				$actions[] = array( $hook, $callback, $priority );
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback = null, $priority = 10, $args = 1 ) use ( &$filters ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match add_filter().
				$filters[] = array( $hook, $callback, $priority );
				return true;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( $options ) {
				if ( 'wppo_settings' === $name ) {
					return $options;
				}
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/';
		$main                   = $this->make_main( $options );
		$this->invoke_private( $main, 'setup_hooks' );
		return array(
			'actions' => $actions,
			'filters' => $filters,
		);
	}

	/**
	 * Find a captured hook registration.
	 *
	 * @param array  $hooks Captured hooks.
	 * @param string $hook  Hook name.
	 * @param string $method Method name fragment.
	 * @return array|null
	 */
	private function find_hook( array $hooks, string $hook, string $method ): ?array {
		foreach ( $hooks as $entry ) {
			if ( $entry[0] !== $hook ) {
				continue;
			}
			$callback = $entry[1];
			if ( is_array( $callback ) && isset( $callback[1] ) && false !== strpos( (string) $callback[1], $method ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * WP 6.9+ with cache enabled registers BOTH the enhancement filter and the legacy fallback.
	 */
	public function test_setup_hooks_registers_dual_path_on_wp69(): void {
		$captured = $this->capture_setup_hooks(
			array(
				'cache_settings'    => array( 'enableCache' => true ),
				'file_optimisation' => array(),
			),
			'6.9',
			false
		);
		$this->assertNotNull( $this->find_hook( $captured['filters'], 'wp_template_enhancement_output_buffer', 'process_buffer_for_cache' ) );
		$this->assertNotNull( $this->find_hook( $captured['actions'], 'template_redirect', 'start_output_buffer' ) );
	}

	/**
	 * Pre-6.9 registers ONLY the legacy fallback (no enhancement filter).
	 */
	public function test_setup_hooks_registers_legacy_only_pre69(): void {
		$captured = $this->capture_setup_hooks(
			array(
				'cache_settings'    => array( 'enableCache' => true ),
				'file_optimisation' => array(),
			),
			'6.8.2',
			false
		);
		$this->assertNull( $this->find_hook( $captured['filters'], 'wp_template_enhancement_output_buffer', 'process_buffer_for_cache' ) );
		$this->assertNotNull( $this->find_hook( $captured['actions'], 'template_redirect', 'start_output_buffer' ) );
	}

	/**
	 * Standalone used-CSS registers its dual path only when page cache is off.
	 */
	public function test_setup_hooks_registers_used_css_standalone(): void {
		$captured = $this->capture_setup_hooks(
			array(
				'cache_settings'    => array( 'enableCache' => false ),
				'file_optimisation' => array( 'removeUnusedCSS' => true ),
			),
			'6.9',
			false
		);
		$this->assertNotNull( $this->find_hook( $captured['filters'], 'wp_template_enhancement_output_buffer', 'process_used_css_only' ) );
		$this->assertNotNull( $this->find_hook( $captured['actions'], 'template_redirect', 'start_used_css_buffer' ) );

		// With page cache on, the standalone used-CSS path stays unregistered.
		$captured = $this->capture_setup_hooks(
			array(
				'cache_settings'    => array( 'enableCache' => true ),
				'file_optimisation' => array( 'removeUnusedCSS' => true ),
			),
			'6.9',
			false
		);
		$this->assertNull( $this->find_hook( $captured['filters'], 'wp_template_enhancement_output_buffer', 'process_used_css_only' ) );
	}

	/**
	 * LCP path registers the enhancement filter (30) plus legacy fallback (20).
	 */
	public function test_setup_hooks_registers_lcp_dual_path(): void {
		$captured = $this->capture_setup_hooks(
			array(
				'cache_settings'     => array(),
				'file_optimisation'  => array(),
				'image_optimisation' => array( 'prioritizeLCPImages' => true ),
			),
			'6.9',
			false
		);
		$filter   = $this->find_hook( $captured['filters'], 'wp_template_enhancement_output_buffer', 'prioritize_lcp_in_buffer' );
		$action   = $this->find_hook( $captured['actions'], 'template_redirect', 'start_lcp_priority_buffer' );
		$this->assertNotNull( $filter );
		$this->assertNotNull( $action );
		$this->assertSame( 30, $filter[2] );
		$this->assertSame( 20, $action[2] );
	}

	/**
	 * Pre-6.9 registers ONLY the legacy LCP fallback (no enhancement filter).
	 *
	 * Issue #937: the LCP enhancement filter registration is gated on the
	 * 6.9+ version floor like the cache and used-CSS paths; older cores keep
	 * the template_redirect fallback unchanged.
	 */
	public function test_setup_hooks_registers_lcp_legacy_only_pre69(): void {
		$captured = $this->capture_setup_hooks(
			array(
				'cache_settings'     => array(),
				'file_optimisation'  => array(),
				'image_optimisation' => array( 'prioritizeLCPImages' => true ),
			),
			'6.8.2',
			false
		);
		$this->assertNull( $this->find_hook( $captured['filters'], 'wp_template_enhancement_output_buffer', 'prioritize_lcp_in_buffer' ) );
		$this->assertNotNull( $this->find_hook( $captured['actions'], 'template_redirect', 'start_lcp_priority_buffer' ) );
	}

	/**
	 * Cache priority ordering: cache filter (10) runs before used-CSS (20) before LCP (30).
	 */
	public function test_buffer_filter_priority_ordering(): void {
		$captured = $this->capture_setup_hooks(
			array(
				'cache_settings'     => array( 'enableCache' => true ),
				'file_optimisation'  => array(),
				'image_optimisation' => array( 'prioritizeLCPImages' => true ),
			),
			'6.9',
			false
		);
		$cache    = $this->find_hook( $captured['filters'], 'wp_template_enhancement_output_buffer', 'process_buffer_for_cache' );
		$lcp      = $this->find_hook( $captured['filters'], 'wp_template_enhancement_output_buffer', 'prioritize_lcp_in_buffer' );
		$this->assertNotNull( $cache );
		$this->assertNotNull( $lcp );
		$this->assertSame( 10, $cache[2] );
		$this->assertSame( 30, $lcp[2] );
		$this->assertLessThan( $lcp[2], $cache[2] );
	}

	/**
	 * Renamed predicate exists; old colliding name is gone from Cache.
	 */
	public function test_is_page_cacheable_renamed(): void {
		$this->assertTrue( method_exists( Cache::class, 'is_page_cacheable' ) );
		$this->assertFalse( method_exists( Cache::class, 'is_request_cacheable' ) );
		$this->assertTrue( method_exists( LiteSpeed_Integration::class, 'is_request_cacheable' ) );
	}

	/**
	 * LiteSpeed cacheability is stricter than page cacheability (QS gate).
	 */
	public function test_litespeed_cacheable_adds_query_string_gate(): void {
		$this->stub_front_end_html();
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$_SERVER['QUERY_STRING']    = 's=test';
		LiteSpeed_Integration::reset_cache();
		// Page-level predicate ignores search QS (storage gate handles it).
		$page_cacheable = ( $this->make_cache( array(), '/?s=test' ) )->is_page_cacheable();
		$this->assertTrue( $page_cacheable );
		// LiteSpeed layer adds the s|ver|v gate on top.
		$this->assertFalse( LiteSpeed_Integration::is_request_cacheable() );
		unset( $_SERVER['QUERY_STRING'] );
		LiteSpeed_Integration::reset_cache();
	}

	/**
	 * LiteSpeed file-cache bypass: LS-owned cache skips file storage.
	 */
	public function test_maybe_store_cache_bypass_when_ls_owns(): void {
		$this->stub_front_end_html();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array(
						'cache_settings'        => array( 'cacheLife' => 24 ),
						'litespeed_integration' => array( 'mode' => 'litespeed' ),
					);
				}
				return $fallback;
			}
		);
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		LiteSpeed_Integration::reset_cache();
		$cache = $this->make_cache( array(), '/' );
		// LS owns the cache here, so file storage is bypassed even for HTML.
		$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ) );
		$_SERVER['SERVER_SOFTWARE'] = 'Apache';
		LiteSpeed_Integration::reset_cache();
	}

	/**
	 * DONOTCACHEPAGE opts out of page cacheability.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_donotcachepage_disables_page_cache(): void {
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
		define( 'DONOTCACHEPAGE', true );
		\Brain\Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'WP_Filesystem' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com/wp-content' . (string) $path;
			}
		);
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$prop  = new \ReflectionProperty( Cache::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $cache, array() );
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
		$method = new \ReflectionMethod( $cache, 'is_not_cacheable' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( $cache ) );
		$this->assertFalse( $cache->is_page_cacheable() );
		\Brain\Monkey\tearDown();
	}

	/**
	 * DONOTCACHEPAGE blocks file-cache storage.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_donotcachepage_blocks_cache_storage(): void {
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
		define( 'DONOTCACHEPAGE', true );
		\Brain\Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'WP_Filesystem' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'content_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com/wp-content' . (string) $path;
			}
		);
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'options'        => array(),
			'cache_root_dir' => WP_CONTENT_DIR . '/cache/wppo',
			'domain'         => 'example.com',
			'request_uri'    => '/',
			'url_path'       => '',
		) as $name => $value ) {
			$prop = new \ReflectionProperty( Cache::class, $name );
			$prop->setAccessible( true );
			$prop->setValue( $cache, $value );
		}
		$method = new \ReflectionMethod( $cache, 'maybe_store_cache' );
		$method->setAccessible( true );
		$this->assertFalse( $method->invoke( $cache ) );
		\Brain\Monkey\tearDown();
	}
}
