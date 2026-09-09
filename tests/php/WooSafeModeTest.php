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
}
