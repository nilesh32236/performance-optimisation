<?php
/**
 * Tests for the WooCommerce AJAX (wc-ajax) cache guards (issue #907).
 *
 * Locks in that wc-ajax XHRs are never buffered or stored, and documents
 * the cart-cookie vs currency-cookie behavior: cart-content cookies bypass
 * the cache while currency-switcher cookies intentionally do not (no
 * per-currency segmentation — a future enhancement, not new vary logic).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Advanced_Cache_Handler;
use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * Tests for the wc-ajax cache guards.
 *
 * @package PerformanceOptimise\Tests
 */
class WcAjaxGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

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
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		unset(
			$_COOKIE['woocommerce_items_in_cart'],
			$_COOKIE['woocommerce_cart_hash'],
			$_COOKIE['wmc-current-currency'],
			$_COOKIE['aelia_cs_selected_currency'],
			$_GET['wc-ajax']
		);
		unset( $_SERVER['QUERY_STRING'] );
	}

	/**
	 * Tear down Brain Monkey and superglobal fixtures.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset(
			$_COOKIE['woocommerce_items_in_cart'],
			$_COOKIE['woocommerce_cart_hash'],
			$_COOKIE['wmc-current-currency'],
			$_COOKIE['aelia_cs_selected_currency'],
			$_GET['wc-ajax']
		);
		unset( $_SERVER['QUERY_STRING'] );
		unset( $GLOBALS['wp_filesystem'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Cache instance without running its constructor.
	 *
	 * @param array  $options     Options to seed.
	 * @param string $request_uri Request URI to seed.
	 * @return Cache
	 */
	private function make_cache( array $options = array(), string $request_uri = '/' ): Cache {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		$prop = new \ReflectionProperty( Cache::class, 'options' );
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
	 * Stub the front-end condition functions to benign guest-HTML defaults.
	 */
	private function stub_front_end_guests(): void {
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		// Pin the endpoint tag too: earlier suites declare it process-wide
		// via Brain Monkey, and a stale declaration without an expectation
		// throws MissingFunctionExpectations, which is_woo_excluded()
		// (correctly) fails open on — flipping cacheable fixtures.
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		// Pin the editor/admin tags too (issue #1097): the unconditional
		// editor-preview bypass in is_not_cacheable() fails open on the same
		// stale-stub mechanism, so cacheable fixtures must pin them false.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
	}

	/**
	 * Query-string wc-ajax endpoints are not cacheable.
	 */
	public function test_wc_ajax_query_string_is_not_cacheable(): void {
		$this->stub_front_end_guests();
		$_SERVER['QUERY_STRING'] = 'wc-ajax=get_refreshed_fragments';
		$_GET['wc-ajax']         = 'get_refreshed_fragments';

		$cache = $this->make_cache( array(), '/?wc-ajax=get_refreshed_fragments' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
		$this->assertFalse( $cache->is_page_cacheable() );
	}

	/**
	 * Pretty-permalink wc-ajax path endpoints are not cacheable.
	 */
	public function test_wc_ajax_path_form_is_not_cacheable(): void {
		$this->stub_front_end_guests();

		$cache = $this->make_cache( array(), '/wc-ajax/get_refreshed_fragments/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
		$this->assertFalse( $cache->is_page_cacheable() );
	}

	/**
	 * A bare $_GET wc-ajax param bypasses the cache even when the stored
	 * request URI carries no query string.
	 */
	public function test_wc_ajax_get_param_alone_is_not_cacheable(): void {
		$this->stub_front_end_guests();
		$_GET['wc-ajax'] = 'get_refreshed_fragments';

		$cache = $this->make_cache( array(), '/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * A wc-ajax query buried after other params is still not cacheable.
	 */
	public function test_wc_ajax_mixed_query_string_is_not_cacheable(): void {
		$this->stub_front_end_guests();
		$_SERVER['QUERY_STRING'] = 'foo=bar&wc-ajax=checkout';
		$_GET['wc-ajax']         = 'checkout';

		$cache = $this->make_cache( array(), '/?foo=bar&wc-ajax=checkout' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * A benign slug merely containing the string stays cacheable (the guard
	 * matches the wc-ajax path segment, not a raw substring).
	 */
	public function test_wc_ajax_substring_slug_remains_cacheable(): void {
		$this->stub_front_end_guests();

		$cache = $this->make_cache( array(), '/my-wc-ajax-guide/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_not_cacheable' ) );
		$this->assertTrue( $cache->is_page_cacheable() );
	}

	/**
	 * Encoded or upper-case wc-ajax path segments are still not cacheable
	 * (the guard decodes and matches case-insensitively).
	 */
	public function test_wc_ajax_encoded_and_uppercase_path_is_not_cacheable(): void {
		$this->stub_front_end_guests();

		foreach ( array( '/wc%2Dajax/get_refreshed_fragments/', '/WC-AJAX/get_refreshed_fragments/', '/%77c-ajax/x/' ) as $uri ) {
			$cache = $this->make_cache( array(), $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ), 'Expected not cacheable: ' . $uri );
		}
	}

	/**
	 * An upper-case wc-ajax query parameter is still not cacheable (the
	 * query-string match is case-insensitive, covering what $_GET misses).
	 */
	public function test_wc_ajax_uppercase_query_is_not_cacheable(): void {
		$this->stub_front_end_guests();
		$_SERVER['QUERY_STRING'] = 'WC-AJAX=get_refreshed_fragments';

		$cache = $this->make_cache( array(), '/?WC-AJAX=get_refreshed_fragments' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * Reflection-seeded instances agree with real constructor-built ones
	 * for encoded paths (locks the constructor sanitization parity).
	 */
	public function test_encoded_path_matches_constructor_built_instance(): void {
		$this->stub_front_end_guests();
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/wc%2Dajax/get_refreshed_fragments/';

		$via_constructor = new Cache();
		$seeded          = $this->make_cache( array(), '/wc%2Dajax/get_refreshed_fragments/' );

		$this->assertTrue( $this->invoke_private( $via_constructor, 'is_not_cacheable' ) );
		$this->assertSame(
			$this->invoke_private( $seeded, 'is_not_cacheable' ),
			$this->invoke_private( $via_constructor, 'is_not_cacheable' )
		);

		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Ordinary pages stay cacheable (regression guard).
	 */
	public function test_normal_page_remains_cacheable(): void {
		$this->stub_front_end_guests();

		$cache = $this->make_cache( array(), '/hello-world/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_not_cacheable' ) );
		$this->assertTrue( $cache->is_page_cacheable() );
	}

	/**
	 * Cart-content cookies bypass the cache (guard lock-in).
	 */
	public function test_cart_cookies_bypass_cache(): void {
		$this->stub_front_end_guests();

		$_COOKIE['woocommerce_items_in_cart'] = '1';
		$this->assertTrue( $this->invoke_private( $this->make_cache( array(), '/' ), 'is_not_cacheable' ) );
		unset( $_COOKIE['woocommerce_items_in_cart'] );

		$_COOKIE['woocommerce_cart_hash'] = 'abc123';
		$this->assertTrue( $this->invoke_private( $this->make_cache( array(), '/' ), 'is_not_cacheable' ) );
	}

	/**
	 * Currency-switcher cookies do NOT bypass the cache (documents current
	 * behavior).
	 *
	 * Only cart-content cookies imply dynamic cart fragments; currency
	 * plugins (WooCommerce Multilingual/multicurrency style cookies) get no
	 * per-currency segmentation today. This locks the known limitation —
	 * currency vary is a future M-sized item, not new vary logic here.
	 */
	public function test_currency_cookies_do_not_bypass_cache(): void {
		$this->stub_front_end_guests();

		$_COOKIE['wmc-current-currency'] = 'EUR';
		$this->assertFalse( $this->invoke_private( $this->make_cache( array(), '/' ), 'is_not_cacheable' ) );
		unset( $_COOKIE['wmc-current-currency'] );

		$_COOKIE['aelia_cs_selected_currency'] = 'USD';
		$this->assertFalse( $this->invoke_private( $this->make_cache( array(), '/' ), 'is_not_cacheable' ) );
	}

	/**
	 * The maybe_store_cache() method refuses wc-ajax storage even when the
	 * wppo_should_cache_request filter is forced to true (defense-in-depth).
	 */
	public function test_maybe_store_cache_refuses_wc_ajax(): void {
		$this->stub_front_end_guests();
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_should_cache_request' === $tag ) {
					return true;
				}
				return $value;
			}
		);
		$_SERVER['QUERY_STRING'] = 'wc-ajax=get_refreshed_fragments';

		$cache = $this->make_cache( array(), '/?wc-ajax=get_refreshed_fragments' );
		$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ) );
	}

	/**
	 * The maybe_store_cache() method refuses pretty-permalink wc-ajax paths
	 * even with an empty query string (storage-layer defense-in-depth).
	 */
	public function test_maybe_store_cache_refuses_wc_ajax_path_without_query(): void {
		$this->stub_front_end_guests();

		$cache = $this->make_cache( array(), '/wc-ajax/get_refreshed_fragments/' );
		$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ) );
	}

	/**
	 * The WP 6.9+ buffer filter path passes wc-ajax output through untouched.
	 */
	public function test_process_buffer_for_cache_returns_unfiltered_for_wc_ajax(): void {
		$this->stub_front_end_guests();
		$_SERVER['QUERY_STRING'] = 'wc-ajax=get_refreshed_fragments';
		$_GET['wc-ajax']         = 'get_refreshed_fragments';

		$cache = $this->make_cache( array(), '/?wc-ajax=get_refreshed_fragments' );
		$this->assertSame( '<p>ajax</p>', $cache->process_buffer_for_cache( '<p>ajax</p>', '<p>raw</p>' ) );
	}

	/**
	 * The stash_cache() method performs no filesystem write for wc-ajax requests.
	 */
	public function test_stash_cache_writes_nothing_for_wc_ajax(): void {
		$this->stub_front_end_guests();
		$_SERVER['QUERY_STRING'] = 'wc-ajax=get_refreshed_fragments';
		$_GET['wc-ajax']         = 'get_refreshed_fragments';

		$fs                       = new WPPO_WcAjax_FS_Guard();
		$GLOBALS['wp_filesystem'] = $fs;
		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$cache = $this->make_cache( array(), '/?wc-ajax=get_refreshed_fragments' );
		$cache->stash_cache( '<html>ajax</html>' );

		$this->assertFalse( $fs->write_attempted );
	}

	/**
	 * The generated advanced-cache.php drop-in bypasses wc-ajax requests.
	 */
	public function test_dropin_template_bypasses_wc_ajax(): void {
		$fs                       = new WPPO_WcAjax_FS_Capture();
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		$this->assertTrue( Advanced_Cache_Handler::create() );
		$this->assertStringContainsString( 'wc-ajax', $fs->put_contents );
		$this->assertStringContainsString( "\$_GET['wc-ajax']", $fs->put_contents );
		// Segment-anchored, delimiter-closed, case-insensitive path guard
		// (locks the template quoting — a broken delimiter would silently
		// disable the bypass in the generated file).
		$this->assertStringContainsString( '#(^|/)wc-ajax(/|$)#i', $fs->put_contents );
		// Raw QUERY_STRING fallback mirrors is_wc_ajax_request() so the
		// drop-in does not rely solely on the empty-query gate below it.
		$this->assertStringContainsString( '/(?:^|&)wc-ajax(?:=|&|$)/i', $fs->put_contents );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test-file filesystem doubles.

/**
 * Filesystem double that records any write attempt (stash_cache guard test).
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_WcAjax_FS_Guard {
	/**
	 * Whether any mutating filesystem call was attempted.
	 *
	 * @var bool
	 */
	public $write_attempted = false;

	/**
	 * Simulate file existence.
	 *
	 * @param string $path File path (unused).
	 * @return bool
	 */
	public function exists( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}

	/**
	 * Simulate directory check.
	 *
	 * @param string $path Path (unused).
	 * @return bool
	 */
	public function is_dir( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}

	/**
	 * Simulate file read.
	 *
	 * @param string $path File path (unused).
	 * @return string
	 */
	public function get_contents( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return '';
	}

	/**
	 * Record a write attempt.
	 *
	 * @param string $path     File path (unused).
	 * @param string $contents Contents (unused).
	 * @param int    $mode     Mode (unused).
	 * @return bool
	 */
	public function put_contents( $path, $contents, $mode = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->write_attempted = true;
		return false;
	}

	/**
	 * Record a move attempt.
	 *
	 * @param string $source      Source (unused).
	 * @param string $destination Destination (unused).
	 * @param bool   $overwrite   Overwrite (unused).
	 * @return bool
	 */
	public function move( $source, $destination, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->write_attempted = true;
		return false;
	}

	/**
	 * Record a delete attempt.
	 *
	 * @param string $path      Path (unused).
	 * @param bool   $recursive Recursive (unused).
	 * @param string $type      Type (unused).
	 * @return bool
	 */
	public function delete( $path, $recursive = false, $type = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->write_attempted = true;
		return false;
	}
}

/**
 * Filesystem double that captures drop-in writes.
 *
 * Emulates just enough of the atomic tmp-plus-rename write path for
 * Advanced_Cache_Handler::create(): tmp writes are stored per-path so the
 * post-write marker verification reads back what was written, and move()
 * promotes the tmp file to its destination.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_WcAjax_FS_Capture {
	/**
	 * Contents passed to the last put_contents() call.
	 *
	 * @var string
	 */
	public $put_contents = '';

	/**
	 * Contents keyed by put_contents() path.
	 *
	 * @var array
	 */
	public $put_paths = array();

	/**
	 * Simulate file existence (no drop-in present until moved).
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public function exists( $path ) {
		if ( false !== strpos( (string) $path, '.tmp.' ) ) {
			return isset( $this->put_paths[ $path ] );
		}
		if ( '.wppo-backup' === substr( (string) $path, -13 ) ) {
			return isset( $this->put_paths[ $path ] );
		}
		foreach ( $this->put_paths as $written_path => $ignored ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Key iteration only.
			if ( false === strpos( (string) $written_path, '.tmp.' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Simulate file read (returns what was written per path).
	 *
	 * @param string $path File path.
	 * @return string
	 */
	public function get_contents( $path ) {
		if ( isset( $this->put_paths[ $path ] ) ) {
			return $this->put_paths[ $path ];
		}
		foreach ( $this->put_paths as $written_path => $contents ) {
			if ( false === strpos( (string) $written_path, '.tmp.' ) && false === strpos( (string) $path, '.tmp.' ) ) {
				return $contents;
			}
		}
		return '';
	}

	/**
	 * Capture a write call.
	 *
	 * @param string $path     File path.
	 * @param string $contents Contents to write.
	 * @param int    $mode     Mode (unused).
	 * @return bool
	 */
	public function put_contents( $path, $contents, $mode = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->put_contents       = $contents;
		$this->put_paths[ $path ] = $contents;
		return true;
	}

	/**
	 * Promote a tmp file to its destination.
	 *
	 * @param string $source      Source.
	 * @param string $destination Destination.
	 * @param bool   $overwrite   Overwrite (unused).
	 * @return bool
	 */
	public function move( $source, $destination, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( isset( $this->put_paths[ $source ] ) ) {
			$this->put_paths[ $destination ] = $this->put_paths[ $source ];
			unset( $this->put_paths[ $source ] );
			return true;
		}
		return false;
	}

	/**
	 * Simulate delete.
	 *
	 * @param string $path      Path.
	 * @param bool   $recursive Recursive (unused).
	 * @param string $type      Type (unused).
	 * @return bool
	 */
	public function delete( $path, $recursive = false, $type = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $this->put_paths[ $path ] );
		return true;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
