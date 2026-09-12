<?php
/**
 * Tests for WooCommerce safe-mode cache exclusions (issue #922).
 *
 * Covers Cache::is_woo_excluded() signals (session cookies, add-to-cart,
 * cart/checkout/my-account URIs, conditional tags), the wooSafeMode=false
 * bypass, the wppo_woo_cacheable re-allow path, and maybe_store_cache()
 * storage parity.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * Tests for WooCommerce safe-mode exclusions.
 *
 * @package PerformanceOptimise\Tests
 */
class WooSafeModeTest extends \PHPUnit\Framework\TestCase {
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
		unset( $_GET['add-to-cart'], $_GET['wc-ajax'] );
		unset( $_SERVER['QUERY_STRING'] );
	}

	/**
	 * Tear down Brain Monkey and superglobal fixtures.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $_GET['add-to-cart'], $_GET['wc-ajax'] );
		unset( $_SERVER['QUERY_STRING'] );
		foreach ( array_keys( $_COOKIE ) as $key ) {
			if ( 0 === strpos( (string) $key, 'wp_woocommerce_session_' ) || in_array( (string) $key, array( 'woocommerce_items_in_cart', 'woocommerce_cart_hash' ), true ) ) {
				unset( $_COOKIE[ $key ] );
			}
		}
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
		// editor-preview bypass in is_not_cacheable()/maybe_store_cache()
		// fails open on the same stale-stub mechanism, so cacheable fixtures
		// must pin them false.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
	}

	/**
	 * Woo session cookies bypass the cache.
	 */
	public function test_session_cookie_is_not_cacheable(): void {
		$this->stub_front_end_guests();
		$_COOKIE['wp_woocommerce_session_abc123'] = 'sessid||exp||hash';

		$cache = $this->make_cache( array(), '/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * Empty session cookie values do not bypass the cache.
	 */
	public function test_empty_session_cookie_remains_cacheable(): void {
		$this->stub_front_end_guests();
		$_COOKIE['wp_woocommerce_session_abc123'] = '';

		$cache = $this->make_cache( array(), '/hello-world/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_woo_excluded' ) );
	}

	/**
	 * A bare $_GET add-to-cart param bypasses the cache.
	 */
	public function test_add_to_cart_get_param_is_not_cacheable(): void {
		$this->stub_front_end_guests();
		$_GET['add-to-cart'] = '123';

		$cache = $this->make_cache( array(), '/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * An add-to-cart query buried after other params still bypasses the cache.
	 */
	public function test_add_to_cart_query_string_is_not_cacheable(): void {
		$this->stub_front_end_guests();
		$_SERVER['QUERY_STRING'] = 'foo=bar&add-to-cart=123';

		$cache = $this->make_cache( array(), '/?foo=bar&add-to-cart=123' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * Default cart/checkout/my-account slugs bypass the cache.
	 */
	public function test_default_woo_slugs_are_not_cacheable(): void {
		$this->stub_front_end_guests();

		foreach ( array( '/cart/', '/checkout/', '/my-account/', '/cart/page/2/' ) as $uri ) {
			$cache = $this->make_cache( array(), $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ), 'Expected excluded: ' . $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ), 'Expected not cacheable: ' . $uri );
		}
	}

	/**
	 * Woo conditional tags bypass the cache even for custom slugs.
	 */
	public function test_conditional_tags_exclude_custom_slugs(): void {
		$this->stub_front_end_guests();
		Functions\when( 'is_cart' )->justReturn( true );

		$cache = $this->make_cache( array(), '/basket/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * WooSafeMode=false disables the safe-mode bypass.
	 */
	public function test_woo_safe_mode_false_bypass(): void {
		$this->stub_front_end_guests();
		$_COOKIE['wp_woocommerce_session_abc123'] = 'sessid||exp||hash';

		$options = array( 'cache_settings' => array( 'wooSafeMode' => false ) );
		$cache   = $this->make_cache( $options, '/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_woo_excluded' ) );

		$cache = $this->make_cache( $options, '/cart/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_woo_excluded' ) );
	}

	/**
	 * Absent wooSafeMode key keeps safe mode enabled (BC default).
	 */
	public function test_absent_woo_safe_mode_key_stays_enabled(): void {
		$this->stub_front_end_guests();
		$_COOKIE['woocommerce_cart_hash'] = 'abc123';

		$cache = $this->make_cache( array(), '/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );
	}

	/**
	 * The wppo_woo_cacheable filter can re-allow a Woo-excluded URL.
	 */
	public function test_woo_cacheable_filter_reallows_caching(): void {
		$this->stub_front_end_guests();
		$_COOKIE['woocommerce_cart_hash'] = 'abc123';
		Functions\when( 'has_filter' )->alias(
			static function ( $tag ) {
				return 'wppo_woo_cacheable' === $tag ? true : false;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_woo_cacheable' === $tag ) {
					return true;
				}
				if ( 'wppo_should_cache_request' === $tag ) {
					return true;
				}
				return $value;
			}
		);

		$cache = $this->make_cache( array(), '/cart/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_woo_excluded' ) );
	}

	/**
	 * Storage refuses Woo-excluded requests (storage parity).
	 */
	public function test_maybe_store_cache_refuses_session_cookie(): void {
		$this->stub_front_end_guests();
		$_COOKIE['wp_woocommerce_session_abc123'] = 'sessid||exp||hash';

		$cache = $this->make_cache( array(), '/' );
		$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ) );
	}

	/**
	 * Storage refuses add-to-cart requests (storage parity).
	 */
	public function test_maybe_store_cache_refuses_add_to_cart(): void {
		$this->stub_front_end_guests();
		$_GET['add-to-cart'] = '123';

		$cache = $this->make_cache( array(), '/' );
		$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ) );
	}

	/**
	 * Storage honors wooSafeMode=false at store time.
	 */
	public function test_maybe_store_cache_honors_safe_mode_off(): void {
		$this->stub_front_end_guests();
		$_COOKIE['wp_woocommerce_session_abc123'] = 'sessid||exp||hash';

		$options = array( 'cache_settings' => array( 'wooSafeMode' => false ) );
		$cache   = $this->make_cache( $options, '/' );
		$this->assertTrue( $this->invoke_private( $cache, 'maybe_store_cache' ) );
	}

	/**
	 * Stub Woo page resolution for a custom nested slug (shop/basket).
	 */
	private function stub_custom_woo_slug(): void {
		Functions\when( 'wc_get_page_id' )->alias(
			static function ( $key ) {
				$pages = array(
					'cart'      => 10,
					'checkout'  => 0,
					'myaccount' => 0,
					'shop'      => 0,
				);
				return isset( $pages[ $key ] ) ? $pages[ $key ] : 0;
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) {
				return 10 === (int) $post_id ? 'http://example.com/shop/basket/' : 'http://example.com/?p=' . (int) $post_id;
			}
		);
		Functions\when( 'get_post_field' )->alias(
			static function ( $field, $post_id ) {
				return 10 === (int) $post_id ? 'basket' : '';
			}
		);
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
	}

	/**
	 * Custom nested Woo slugs from Util::get_woo_excluded_paths() are excluded
	 * (issue #962): /shop/basket/ matches, /basketball/ does not (segment).
	 */
	public function test_custom_nested_woo_slug_is_excluded(): void {
		$this->stub_front_end_guests();
		$this->stub_custom_woo_slug();

		$cache = $this->make_cache( array(), '/shop/basket/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );

		$cache = $this->make_cache( array(), '/shop/basket/page/2/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );

		$cache = $this->make_cache( array(), '/basketball/' );
		$this->assertFalse( $this->invoke_private( $cache, 'is_woo_excluded' ) );
	}

	/**
	 * Store API routes are never cacheable (issue #962), at serve and store time.
	 */
	public function test_store_api_routes_are_never_cacheable(): void {
		$this->stub_front_end_guests();
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );

		foreach ( array( '/wp-json/wc/store/v1/cart', '/wc/store/v1/checkout', '/wp-json/wcstore/v1/cart' ) as $uri ) {
			$cache = $this->make_cache( array(), $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ), 'Expected excluded: ' . $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ), 'Expected not cacheable: ' . $uri );
			$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ), 'Expected no store: ' . $uri );
		}
	}

	/**
	 * Store API stays uncacheable even when safe mode is off (issue #962).
	 */
	public function test_store_api_excluded_when_safe_mode_off(): void {
		$this->stub_front_end_guests();
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );

		$options = array( 'cache_settings' => array( 'wooSafeMode' => false ) );
		$cache   = $this->make_cache( $options, '/wp-json/wc/store/v1/cart' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );
		$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ) );
	}

	/**
	 * Woo endpoint URLs (order-pay, view-order, …) are excluded (issue #962).
	 */
	public function test_wc_endpoint_url_is_excluded(): void {
		$this->stub_front_end_guests();
		Functions\when( 'is_wc_endpoint_url' )->justReturn( true );

		$cache = $this->make_cache( array(), '/checkout/order-pay/123/' );
		$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ) );
		$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ) );
	}

	/**
	 * Util::is_woo_safe_mode_enabled(): absent key = enabled, false = disabled.
	 */
	public function test_woo_safe_mode_enabled_helper(): void {
		$this->assertTrue( \PerformanceOptimise\Inc\Util::is_woo_safe_mode_enabled( array() ) );
		$this->assertTrue( \PerformanceOptimise\Inc\Util::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => true ) ) ) );
		$this->assertFalse( \PerformanceOptimise\Inc\Util::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) ) );
		$this->assertTrue( \PerformanceOptimise\Inc\Util::is_woo_store_api_path( '/wp-json/wc/store/v1/cart' ) );
		$this->assertFalse( \PerformanceOptimise\Inc\Util::is_woo_store_api_path( '/shop/' ) );
	}

	/**
	 * Surgical Woo purge (issue #962): product invalidation collects only the
	 * product permalink (+ archives), never the home page, and the filter
	 * receives the kind.
	 */
	public function test_invalidate_woo_object_collects_surgical_urls(): void {
		$this->stub_front_end_guests();
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'get_permalink' )->alias(
			static function ( $post_id ) {
				return 99 === (int) $post_id ? 'http://example.com/product/hoodie/' : 'http://example.com/?p=' . (int) $post_id;
			}
		);
		Functions\when( 'wp_make_link_relative' )->alias(
			static function ( $url ) {
				$path = wp_parse_url( (string) $url, PHP_URL_PATH );
				return is_string( $path ) ? $path : '';
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( true );

		$GLOBALS['wppo_test_woo_urls'] = null;
		$GLOBALS['wppo_test_woo_kind'] = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) {
				if ( 'wppo_woo_invalidation_urls' === $tag ) {
					$GLOBALS['wppo_test_woo_urls'] = $value;
					$GLOBALS['wppo_test_woo_kind'] = $args[1] ?? null;
				}
				if ( 'wppo_should_cache_request' === $tag ) {
					return true;
				}
				return $value;
			}
		);

		$cache = $this->make_cache( array(), '/' );
		$cache->invalidate_woo_object( 99, 'product' );

		$this->assertSame( 'product', $GLOBALS['wppo_test_woo_kind'] );
		$this->assertIsArray( $GLOBALS['wppo_test_woo_urls'] );
		$this->assertContains( '/product/hoodie/', $GLOBALS['wppo_test_woo_urls'] );
		$this->assertNotContains( '/', $GLOBALS['wppo_test_woo_urls'] );

		unset( $GLOBALS['wppo_test_woo_urls'], $GLOBALS['wppo_test_woo_kind'] );
	}

	/**
	 * Stub the home-URL helpers consumed by Util::woo_cache_self_test().
	 */
	private function stub_self_test_urls(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
	}

	/**
	 * Self-test passes for canonical dynamic paths when safe mode is on (issue #1020).
	 */
	public function test_woo_cache_self_test_passes_for_dynamic_paths(): void {
		$this->stub_front_end_guests();
		$this->stub_self_test_urls();

		$result = \PerformanceOptimise\Inc\Util::woo_cache_self_test();

		$this->assertTrue( $result['runnable'] );
		$this->assertTrue( $result['woo_active'] );
		$this->assertTrue( $result['safe_mode'] );
		$this->assertTrue( $result['donotcachepage_honored'] );
		$this->assertSame( array( 'cart', 'checkout', 'my-account' ), $result['excluded_paths'] );
		$this->assertTrue( $result['all_pass'] );
		$this->assertCount( 4, $result['checks'] );

		foreach ( $result['checks'] as $check ) {
			$this->assertTrue( $check['is_dynamic'] );
			$this->assertFalse( $check['cacheable'] );
			$this->assertTrue( $check['donotcachepage_honored'] );
			$this->assertTrue( $check['pass'] );
			$this->assertArrayNotHasKey( 'error', $check );
		}
	}

	/**
	 * Self-test fails visibly (pass=false, cacheable=true for page probes) when safe mode is off (issue #1020).
	 *
	 * Page probes become cacheable (pass=false) while the Store API probe
	 * stays uncacheable even with the toggle off.
	 */
	public function test_woo_cache_self_test_safe_mode_off_marks_pages_cacheable(): void {
		$this->stub_front_end_guests();
		$this->stub_self_test_urls();
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$result = \PerformanceOptimise\Inc\Util::woo_cache_self_test();

		$this->assertTrue( $result['runnable'] );
		$this->assertFalse( $result['safe_mode'] );
		$this->assertFalse( $result['all_pass'] );

		$by_path = array();
		foreach ( $result['checks'] as $check ) {
			$by_path[ $check['path'] ] = $check;
		}

		$this->assertTrue( $by_path['/cart/']['cacheable'] );
		$this->assertFalse( $by_path['/cart/']['pass'] );
		$this->assertFalse( $by_path['/wp-json/wc/store/v1/cart/']['cacheable'] );
		$this->assertTrue( $by_path['/wp-json/wc/store/v1/cart/']['pass'] );
	}

	/**
	 * Self-test reports woo_active=false with runnable shape when Woo is absent (issue #1020).
	 *
	 * Woo conditional symbols eval-persist process-wide once any Brain Monkey
	 * stub declares them (see bootstrap), so absence is simulated via the
	 * redefinable-internals `function_exists` stub — the same pattern as
	 * SystemInfoTest::test_get_woocommerce_presets_null_when_inactive.
	 * The default-path probes still run (safe mode defaults on) — the shape
	 * the Dashboard read-only notice branch consumes.
	 */
	public function test_woo_cache_self_test_woo_inactive_shape(): void {
		$this->stub_front_end_guests();
		$this->stub_self_test_urls();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\stubs( array( 'function_exists' ) );
		Functions\when( 'function_exists' )->justReturn( false );
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$result = \PerformanceOptimise\Inc\Util::woo_cache_self_test();

		$this->assertFalse( $result['woo_active'] );
		$this->assertTrue( $result['runnable'] );
		$this->assertTrue( $result['safe_mode'] );
		$this->assertSame( array( 'cart', 'checkout', 'my-account' ), $result['excluded_paths'] );
		$this->assertTrue( $result['all_pass'] );
		$this->assertNotEmpty( $result['checks'] );
	}

	/**
	 * Self-test includes resolved custom Woo slugs as probes (issue #1020).
	 */
	public function test_woo_cache_self_test_includes_custom_slug(): void {
		$this->stub_front_end_guests();
		$this->stub_self_test_urls();
		$this->stub_custom_woo_slug();

		$result = \PerformanceOptimise\Inc\Util::woo_cache_self_test();

		$this->assertContains( 'shop/basket', $result['excluded_paths'] );

		$by_path = array();
		foreach ( $result['checks'] as $check ) {
			$by_path[ $check['path'] ] = $check;
		}

		$this->assertArrayHasKey( '/shop/basket/', $by_path );
		$this->assertTrue( $by_path['/shop/basket/']['pass'] );
		$this->assertTrue( $result['all_pass'] );
	}
}
