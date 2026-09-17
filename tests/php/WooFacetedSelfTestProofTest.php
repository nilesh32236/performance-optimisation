<?php
/**
 * Tests for WooCommerce faceted-query guards and self-test proof (issue #1256).
 *
 * Locks in that layered-nav / faceted filter URLs are detected by
 * Util::is_woo_faceted_query(), skipped by preload scheduling, refused by
 * cache storage, and proven by the extended woo_cache_self_test()
 * (preload_checks, cart_checks, force_exclude).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the WooCommerce faceted-query proof.
 *
 * @package PerformanceOptimise\Tests
 */
class WooFacetedSelfTestProofTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		unset( $_SERVER['QUERY_STRING'] );
	}

	/**
	 * Tear down Brain Monkey and superglobal fixtures.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $_SERVER['QUERY_STRING'] );
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
		$prop->setValue( $cache, $options );

		$prop = new \ReflectionProperty( Cache::class, 'cache_root_dir' );
		$prop->setValue( $cache, WP_CONTENT_DIR . '/cache/wppo' );

		$prop = new \ReflectionProperty( Cache::class, 'domain' );
		$prop->setValue( $cache, 'example.com' );

		$prop = new \ReflectionProperty( Cache::class, 'request_uri' );
		$prop->setValue( $cache, $request_uri );

		$parsed = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path   = wp_normalize_path( trim( rawurldecode( (string) $parsed ), '/' ) );
		if ( false !== strpos( $path, '..' ) ) {
			$path = '';
		}
		$prop = new \ReflectionProperty( Cache::class, 'url_path' );
		$prop->setValue( $cache, $path );

		$prop = new \ReflectionProperty( Cache::class, 'cache_ob_level' );
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
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
	}

	/**
	 * Faceted layered-nav queries are detected (issue #1256).
	 */
	public function test_is_woo_faceted_query_detects_layered_nav_params(): void {
		foreach ( array( 'filter_color=blue', 'query_type_color=or', 'min_price=10', 'max_price=50', 'rating_filter=5', 'orderby=price', 'product_cat=hoodies', 'pa_color=blue', 'attribute_pa_size=m', 'gpf_category=shirts' ) as $qs ) {
			$this->assertTrue( Util::is_woo_faceted_query( $qs ), 'Expected faceted: ' . $qs );
		}
		$this->assertTrue( Util::is_woo_faceted_query( 'FILTER_COLOR=blue' ) );
		$this->assertFalse( Util::is_woo_faceted_query( '' ) );
		$this->assertFalse( Util::is_woo_faceted_query( 'utm_source=x&gclid=abc' ) );
		$this->assertFalse( Util::is_woo_faceted_query( 'p=123' ) );
	}

	/**
	 * Faceted URLs bypass the cache when safe mode is on.
	 */
	public function test_faceted_query_is_woo_excluded_when_safe_mode_on(): void {
		$this->stub_front_end_guests();

		foreach ( array( '/shop/?filter_color=blue', '/shop/?min_price=10&max_price=50', '/shop/?orderby=price' ) as $uri ) {
			$cache = $this->make_cache( array(), $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_woo_excluded' ), 'Expected excluded: ' . $uri );
			$this->assertTrue( $this->invoke_private( $cache, 'is_not_cacheable' ), 'Expected not cacheable: ' . $uri );
			$this->assertFalse( $this->invoke_private( $cache, 'maybe_store_cache' ), 'Expected no store: ' . $uri );
		}
	}

	/**
	 * Faceted URLs are skipped by preload scheduling (issue #1256).
	 */
	public function test_preload_skips_faceted_urls(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( array() );
		Util::clear_settings_cache();

		$cron = ( new \ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();

		$is_excluded = new \ReflectionMethod( Cron::class, 'is_woo_excluded_url' );

		$this->assertTrue( $is_excluded->invoke( $cron, 'http://example.com/shop/?filter_color=blue' ) );
		$this->assertTrue( $is_excluded->invoke( $cron, 'http://example.com/shop/?min_price=10&max_price=50' ) );
		$this->assertTrue( $is_excluded->invoke( $cron, 'http://example.com/shop/?orderby=price' ) );
		$this->assertFalse( $is_excluded->invoke( $cron, 'http://example.com/shop/' ) );
		$this->assertFalse( $is_excluded->invoke( $cron, 'http://example.com/?utm_source=x' ) );
	}

	/**
	 * Self-test proves preload skips, cart survival, and force_exclude=false on green.
	 */
	public function test_self_test_proves_preload_and_cart_survival_on_green(): void {
		$this->stub_front_end_guests();

		$result = Util::woo_cache_self_test();

		$this->assertTrue( $result['runnable'] );
		$this->assertTrue( $result['safe_mode'] );
		$this->assertArrayHasKey( 'preload_checks', $result );
		$this->assertArrayHasKey( 'cart_checks', $result );
		$this->assertArrayHasKey( 'force_exclude', $result );
		$this->assertNotEmpty( $result['preload_checks'] );
		$this->assertNotEmpty( $result['cart_checks'] );
		foreach ( $result['preload_checks'] as $check ) {
			$this->assertTrue( $check['skipped'] );
			$this->assertTrue( $check['pass'] );
		}
		foreach ( $result['cart_checks'] as $check ) {
			$this->assertTrue( $check['bypass'] );
			$this->assertTrue( $check['pass'] );
		}
		$this->assertFalse( $result['force_exclude'] );
		$this->assertTrue( $result['all_pass'] );
	}

	/**
	 * Self-test trips force_exclude when safe mode is off (fail-closed for commerce).
	 */
	public function test_self_test_trips_force_exclude_when_safe_mode_off(): void {
		$this->stub_front_end_guests();
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		Util::clear_settings_cache();

		$result = Util::woo_cache_self_test();

		$this->assertFalse( $result['safe_mode'] );
		$this->assertFalse( $result['all_pass'] );
		$this->assertTrue( $result['force_exclude'] );
		// Faceted preload skips hold unconditionally even with safe mode off.
		foreach ( $result['preload_checks'] as $check ) {
			if ( false !== strpos( (string) $check['path'], 'filter_' ) || false !== strpos( (string) $check['path'], 'min_price' ) || false !== strpos( (string) $check['path'], 'orderby' ) || false !== strpos( (string) $check['path'], 'rating_filter' ) ) {
				$this->assertTrue( $check['skipped'] );
				$this->assertTrue( $check['pass'] );
			}
		}
	}
}
