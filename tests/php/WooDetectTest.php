<?php
/**
 * Regression tests for the REF-013 Woo_Detect boundary extraction (issue #1522).
 *
 * Pins byte-identical behavior for the WooCommerce detection cluster moved
 * from `Util` to `PerformanceOptimise\Inc\Woo_Detect`: path matrices
 * (cart/checkout/account, custom slugs, Store API pretty + rest_route +
 * encoded forms, wc-ajax, add-to-cart, faceted queries), safe-mode
 * on/off/absent/malformed handling, no-Woo fail-closed behavior, the
 * verifiable self-test vectors, plus facade-proxy equivalence
 * (`Util::x === Woo_Detect::x`) for every moved method.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\Woo_Detect;

/**
 * Woo_Detect boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WooDetectTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up Brain Monkey and common stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		unset( $_SERVER['QUERY_STRING'], $_GET['rest_route'] );
	}

	/**
	 * Tear down Brain Monkey and superglobal fixtures.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		unset( $_SERVER['QUERY_STRING'], $_GET['rest_route'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub Woo page resolution for a custom nested slug (shop/basket).
	 *
	 * @return void
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
	}

	/**
	 * Safe mode matrix: absent key fails safe (on), explicit false disables,
	 * malformed values normalize to enabled.
	 */
	public function test_safe_mode_matrix(): void {
		$this->assertTrue( Woo_Detect::is_woo_safe_mode_enabled( array() ) );
		$this->assertTrue( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array() ) ) );
		$this->assertTrue( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => true ) ) ) );
		$this->assertTrue( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => 1 ) ) ) );
		$this->assertFalse( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) ) );
		$this->assertFalse( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => 0 ) ) ) );
		// Malformed (non-scalar) values normalize to enabled (fail-safe).
		$this->assertTrue( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => array( 'x' ) ) ) ) );
		// Default settings source: empty option store means absent key → on.
		$this->assertTrue( Woo_Detect::is_woo_safe_mode_enabled() );
	}

	/**
	 * Store API path matrix: pretty, shortcode, wp-json, rest_route and
	 * encoded forms match; unrelated API routes do not.
	 */
	public function test_store_api_path_matrix(): void {
		$this->assertTrue( Woo_Detect::is_woo_store_api_path( '/wp-json/wc/store/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_store_api_path( 'wp-json/wcstore/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_store_api_path( '/wc/store/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_store_api_path( '/WC/STORE/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_store_api_path( 'rest_route=/wc/store/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_store_api_path( '%2Fwc%2Fstore%2Fv1%2Fcart' ) );
		$this->assertFalse( Woo_Detect::is_woo_store_api_path( '' ) );
		$this->assertFalse( Woo_Detect::is_woo_store_api_path( '/' ) );
		$this->assertFalse( Woo_Detect::is_woo_store_api_path( '/shop/' ) );
		$this->assertFalse( Woo_Detect::is_woo_store_api_path( '/wp-json/wp/v2/posts' ) );
	}

	/**
	 * Store API request matrix: path, rest_route value and raw query string
	 * all resolve; a plain request does not.
	 */
	public function test_store_api_request_matrix(): void {
		$this->assertTrue( Woo_Detect::is_woo_store_api_request( '/wp-json/wc/store/v1/cart', '', '' ) );
		$this->assertTrue( Woo_Detect::is_woo_store_api_request( '/', '', '/wc/store/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_store_api_request( '/', 'rest_route=/wc/store/v1/cart', '' ) );
		$this->assertTrue( Woo_Detect::is_woo_store_api_request( '/', 'rest_route=%2Fwc%2Fstore%2Fv1%2Fcart', '' ) );
		$this->assertFalse( Woo_Detect::is_woo_store_api_request( '/', '', '' ) );
		$this->assertFalse( Woo_Detect::is_woo_store_api_request( '/shop/', 'foo=bar', '' ) );
	}

	/**
	 * Dynamic-path matrix on stock slugs without Woo: fail-safe segment
	 * matching incl. subdirectory prefixes; non-Woo paths stay cacheable.
	 */
	public function test_dynamic_path_defaults_no_woo(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$this->assertTrue( Woo_Detect::is_woo_dynamic_path( '/cart/' ) );
		$this->assertTrue( Woo_Detect::is_woo_dynamic_path( '/checkout/' ) );
		$this->assertTrue( Woo_Detect::is_woo_dynamic_path( '/my-account/' ) );
		$this->assertTrue( Woo_Detect::is_woo_dynamic_path( '/subsite/cart/' ) );
		$this->assertTrue( Woo_Detect::is_woo_dynamic_path( '/wp-json/wc/store/v1/cart' ) );
		$this->assertFalse( Woo_Detect::is_woo_dynamic_path( '/' ) );
		$this->assertFalse( Woo_Detect::is_woo_dynamic_path( '/shop/' ) );
		$this->assertFalse( Woo_Detect::is_woo_dynamic_path( '/basketball/' ) );
	}

	/**
	 * Excluded paths without Woo: stock defaults only, never fatal.
	 */
	public function test_excluded_paths_defaults_no_woo(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$this->assertSame( array( 'cart', 'checkout', 'my-account' ), Woo_Detect::get_woo_excluded_paths() );
	}

	/**
	 * Custom nested Woo slugs resolve and match as full segments.
	 */
	public function test_custom_nested_slug(): void {
		$this->stub_custom_woo_slug();
		$paths = Woo_Detect::get_woo_excluded_paths();
		$this->assertContains( 'cart', $paths );
		$this->assertContains( 'shop/basket', $paths );
		$this->assertTrue( Woo_Detect::is_woo_dynamic_path( '/shop/basket/' ) );
		$this->assertTrue( Woo_Detect::is_woo_dynamic_path( '/shop/basket/page/2/' ) );
		$this->assertFalse( Woo_Detect::is_woo_dynamic_path( '/basketball/' ) );
	}

	/**
	 * Woo active probe: any WooCommerce symbol counts as active.
	 */
	public function test_woo_active_probe(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$this->assertTrue( Woo_Detect::is_woo_active() );
	}

	/**
	 * Faceted-query matrix: layered-nav params match case-insensitively;
	 * plain queries do not.
	 */
	public function test_faceted_query_matrix(): void {
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'filter_color=blue' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'FILTER_COLOR=blue' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'query_type_color=or' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'min_price=10' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'max_price=50' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'rating_filter=5' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'orderby=price' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'product_cat=shirts' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'pa_color=red' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'attribute_pa_size=m' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'gpf_category=1' ) );
		$this->assertTrue( Woo_Detect::is_woo_faceted_query( 'foo=bar&filter_size=large' ) );
		$this->assertFalse( Woo_Detect::is_woo_faceted_query( '' ) );
		$this->assertFalse( Woo_Detect::is_woo_faceted_query( 'foo=bar' ) );
		$this->assertFalse( Woo_Detect::is_woo_faceted_query( 'utm_source=x' ) );
	}

	/**
	 * Wc-ajax matrix: path segment and query param, case-insensitive, with
	 * exact-segment discipline (no `/my-wc-ajax-guide/` false positive).
	 */
	public function test_ajax_matrix(): void {
		$this->assertTrue( Woo_Detect::is_woo_ajax_request( '/wc-ajax/get_refreshed_fragments/', '' ) );
		$this->assertTrue( Woo_Detect::is_woo_ajax_request( '/', 'wc-ajax=get_refreshed_fragments' ) );
		$this->assertTrue( Woo_Detect::is_woo_ajax_request( '/', 'WC-AJAX=get_refreshed_fragments' ) );
		$this->assertTrue( Woo_Detect::is_woo_ajax_request( '/WC-AJAX/x/', '' ) );
		$this->assertFalse( Woo_Detect::is_woo_ajax_request( '/my-wc-ajax-guide/', '' ) );
		$this->assertFalse( Woo_Detect::is_woo_ajax_request( '/shop/', '' ) );
		$this->assertFalse( Woo_Detect::is_woo_ajax_request( '/', '' ) );
	}

	/**
	 * Add-to-cart matrix: query param, case-insensitive.
	 */
	public function test_add_to_cart_matrix(): void {
		$this->assertTrue( Woo_Detect::is_woo_add_to_cart_request( 'add-to-cart=123' ) );
		$this->assertTrue( Woo_Detect::is_woo_add_to_cart_request( 'foo=bar&ADD-TO-CART=5' ) );
		$this->assertFalse( Woo_Detect::is_woo_add_to_cart_request( '' ) );
		$this->assertFalse( Woo_Detect::is_woo_add_to_cart_request( 'foo=bar' ) );
		$this->assertFalse( Woo_Detect::is_woo_add_to_cart_request( 'add-to-cart-guide=yes' ) );
	}

	/**
	 * Canonical URL predicate: Store API / wc-ajax / faceted are excluded
	 * unconditionally; cart paths are safe-mode gated.
	 */
	public function test_excluded_url_matrix(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( array() );
		Util::clear_settings_cache();

		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/cart/' ) );
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/wp-json/wc/store/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/?rest_route=/wc/store/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/?wc-ajax=get_refreshed_fragments' ) );
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/shop/?filter_color=blue' ) );
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/?add-to-cart=123' ) );
		$this->assertFalse( Woo_Detect::is_woo_excluded_url( 'https://example.com/shop/' ) );

		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		Util::clear_settings_cache();

		$this->assertFalse( Woo_Detect::is_woo_excluded_url( 'https://example.com/cart/' ) );
		// Store API and wc-ajax stay excluded with safe mode off; add-to-cart
		// still skips via the unconditional generic query guard.
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/wp-json/wc/store/v1/cart' ) );
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/?wc-ajax=get_refreshed_fragments' ) );
		$this->assertTrue( Woo_Detect::is_woo_excluded_url( 'https://example.com/?add-to-cart=123' ) );
	}

	/**
	 * Self-test with safe mode on: every probe passes, no force-exclude.
	 */
	public function test_self_test_all_pass_safe_mode_on(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( array() );
		Util::clear_settings_cache();

		$result = Woo_Detect::woo_cache_self_test();

		$this->assertTrue( $result['runnable'] );
		$this->assertTrue( $result['safe_mode'] );
		$this->assertTrue( $result['all_pass'] );
		$this->assertFalse( $result['force_exclude'] );
		$this->assertSame( array( 'cart', 'checkout', 'my-account' ), $result['excluded_paths'] );
		$this->assertNotEmpty( $result['checks'] );
		$this->assertCount( 3, $result['fragment_checks'] );
		$this->assertNotEmpty( $result['editor_checks'] );
		$this->assertNotEmpty( $result['preload_checks'] );
		$this->assertNotEmpty( $result['cart_checks'] );
		foreach ( array( 'checks', 'fragment_checks', 'editor_checks', 'preload_checks', 'cart_checks' ) as $group ) {
			foreach ( $result[ $group ] as $check ) {
				$label = isset( $check['url'] ) ? (string) $check['url'] : ( isset( $check['path'] ) ? (string) $check['path'] : $group );
				$this->assertTrue( $check['pass'], 'Self-test probe failed in ' . $group . ': ' . $label );
			}
		}
	}

	/**
	 * Self-test with safe mode off: commerce probes fail closed
	 * (force_exclude trips, all_pass drops).
	 */
	public function test_self_test_safe_mode_off_trips_force_exclude(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		Util::clear_settings_cache();

		$result = Woo_Detect::woo_cache_self_test();

		$this->assertTrue( $result['runnable'] );
		$this->assertFalse( $result['safe_mode'] );
		$this->assertFalse( $result['all_pass'] );
		$this->assertTrue( $result['force_exclude'] );
	}

	/**
	 * Facade-proxy equivalence: every Util:: proxy returns exactly what the
	 * owning Woo_Detect:: method returns.
	 */
	public function test_proxy_equivalence(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( array() );
		Util::clear_settings_cache();

		$this->assertSame( Woo_Detect::is_woo_safe_mode_enabled(), Util::is_woo_safe_mode_enabled() );
		$this->assertSame( Woo_Detect::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) ), Util::is_woo_safe_mode_enabled( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) ) );
		$this->assertSame( Woo_Detect::is_woo_store_api_path( '/wc/store/v1/cart' ), Util::is_woo_store_api_path( '/wc/store/v1/cart' ) );
		$this->assertSame( Woo_Detect::is_woo_store_api_request( '/', 'rest_route=/wc/store/v1/cart', '/wc/store/v1/cart' ), Util::is_woo_store_api_request( '/', 'rest_route=/wc/store/v1/cart', '/wc/store/v1/cart' ) );
		$this->assertSame( Woo_Detect::is_woo_dynamic_path( '/cart/' ), Util::is_woo_dynamic_path( '/cart/' ) );
		$this->assertSame( Woo_Detect::get_woo_excluded_paths(), Util::get_woo_excluded_paths() );
		$this->assertSame( Woo_Detect::is_woo_active(), Util::is_woo_active() );
		$this->assertSame( Woo_Detect::is_woo_faceted_query( 'filter_color=blue' ), Util::is_woo_faceted_query( 'filter_color=blue' ) );
		$this->assertSame( Woo_Detect::is_woo_ajax_request( '/', 'wc-ajax=x' ), Util::is_woo_ajax_request( '/', 'wc-ajax=x' ) );
		$this->assertSame( Woo_Detect::is_woo_add_to_cart_request( 'add-to-cart=1' ), Util::is_woo_add_to_cart_request( 'add-to-cart=1' ) );
		$this->assertSame( Woo_Detect::is_woo_excluded_url( 'https://example.com/cart/' ), Util::is_woo_excluded_url( 'https://example.com/cart/' ) );
		$this->assertEquals( Woo_Detect::woo_cache_self_test(), Util::woo_cache_self_test() );
	}
}
