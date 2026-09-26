<?php
/**
 * Tests for the WooCommerce dynamic-exclusion audit (issue #1460).
 *
 * Locks in the consolidated exclusion matrix: explicit wc-ajax /
 * add-to-cart helpers, safe-mode gating, Store API unconditionality, and
 * the ESI fragment guidance (mini-cart punch-through vs bypass list).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\LiteSpeed_ESI;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the Woo dynamic-exclusion audit.
 *
 * @package PerformanceOptimise\Tests
 */
class WooDynamicExclusionAuditTest extends \PHPUnit\Framework\TestCase {
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
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Explicit wc-ajax helper: path segment, query param, case-insensitive.
	 */
	public function test_woo_ajax_request_matrix(): void {
		$this->assertTrue( Util::is_woo_ajax_request( '/wc-ajax/get_refreshed_fragments/', '' ) );
		$this->assertTrue( Util::is_woo_ajax_request( '/', 'wc-ajax=get_refreshed_fragments' ) );
		$this->assertTrue( Util::is_woo_ajax_request( '/', 'WC-AJAX=get_refreshed_fragments' ) );
		$this->assertTrue( Util::is_woo_ajax_request( '/WC-AJAX/x/', '' ) );
		$this->assertFalse( Util::is_woo_ajax_request( '/my-wc-ajax-guide/', '' ) );
		$this->assertFalse( Util::is_woo_ajax_request( '/shop/', '' ) );
		$this->assertFalse( Util::is_woo_ajax_request( '/', '' ) );
	}

	/**
	 * Explicit add-to-cart helper: query param, case-insensitive.
	 */
	public function test_woo_add_to_cart_request_matrix(): void {
		$this->assertTrue( Util::is_woo_add_to_cart_request( 'add-to-cart=123' ) );
		$this->assertTrue( Util::is_woo_add_to_cart_request( 'foo=bar&ADD-TO-CART=5' ) );
		$this->assertFalse( Util::is_woo_add_to_cart_request( '' ) );
		$this->assertFalse( Util::is_woo_add_to_cart_request( 'foo=bar' ) );
		$this->assertFalse( Util::is_woo_add_to_cart_request( 'add-to-cart-guide=yes' ) );
	}

	/**
	 * Canonical URL predicate: wc-ajax excluded even with safe mode off.
	 */
	public function test_excluded_url_wc_ajax_unconditional_safe_mode_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		Util::clear_settings_cache();
		$this->assertTrue( Util::is_woo_excluded_url( 'https://example.com/?wc-ajax=get_refreshed_fragments' ) );
		$this->assertTrue( Util::is_woo_excluded_url( 'https://example.com/wc-ajax/get_refreshed_fragments/' ) );
	}

	/**
	 * Canonical URL predicate: cart path gated on safe mode.
	 */
	public function test_excluded_url_cart_path_gated_on_safe_mode(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Util::clear_settings_cache();
		$this->assertTrue( Util::is_woo_excluded_url( 'https://example.com/cart/' ) );

		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		Util::clear_settings_cache();
		$this->assertFalse( Util::is_woo_excluded_url( 'https://example.com/cart/' ) );
	}

	/**
	 * Canonical URL predicate: Store API never preloaded, safe mode off.
	 */
	public function test_excluded_url_store_api_unconditional(): void {
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'wooSafeMode' => false ) ) );
		Util::clear_settings_cache();
		$this->assertTrue( Util::is_woo_excluded_url( 'https://example.com/wp-json/wc/store/v1/cart' ) );
		$this->assertTrue( Util::is_woo_excluded_url( 'https://example.com/?rest_route=/wc/store/v1/cart' ) );
	}

	/**
	 * ESI guidance shape: bypass routes plus fragment blocks.
	 */
	public function test_esi_woo_fragment_guidance_shape(): void {
		$guidance = LiteSpeed_ESI::get_woo_fragment_guidance();
		$this->assertArrayHasKey( 'bypass_routes', $guidance );
		$this->assertArrayHasKey( 'fragment_blocks', $guidance );
		$this->assertArrayHasKey( 'cookie_vary', $guidance );
		$this->assertContains( 'wc-ajax', $guidance['bypass_routes'] );
		$this->assertContains( 'woocommerce_cart_hash', $guidance['cookie_vary'] );
	}

	/**
	 * Non-Woo cart fragment falls back to a harmless placeholder.
	 */
	public function test_esi_cart_fragment_non_woo_fallback(): void {
		$fragment = LiteSpeed_ESI::render_woo_cart_fragment();
		$this->assertStringContainsString( 'wppo-mini-cart', $fragment );
		$this->assertStringContainsString( 'cart(0)', $fragment );
	}
}
