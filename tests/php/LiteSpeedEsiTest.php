<?php
/**
 * Tests for LiteSpeed_ESI ESI punch-holing (#809).
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
 * @phpcs:disable WordPress.WP.EnqueuedResources
 * @phpcs:disable Squiz.Commenting.FunctionComment.Missing
 */

use PerformanceOptimise\Inc\LiteSpeed_ESI;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * ESI tests — OLS false / enterprise true, punch hole, nonce, AJAX, headers.
 */
class LiteSpeedEsiTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	protected function tearDown(): void {
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		unset( $_SERVER['SERVER_SOFTWARE'] );
		unset( $_COOKIE['woocommerce_items_in_cart'], $_COOKIE['woocommerce_cart_hash'] );
		parent::tearDown();
	}

	private function set_litespeed( bool $is_ls = true ): void {
		$_SERVER['SERVER_SOFTWARE'] = $is_ls ? 'LiteSpeed' : 'Apache';
		if ( class_exists( LiteSpeed_Integration::class ) ) {
			LiteSpeed_Integration::reset_cache();
		}
	}

	public function test_is_esi_available_false_on_ols(): void {
		$this->set_litespeed( true );
		// No filter true, default false should be returned for OLS-like (no Enterprise).
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_litespeed_esi_available' === $tag || 'wppo_esi_available' === $tag ) {
					return false;
				}
				if ( 'wppo_litespeed_is_litespeed' === $tag ) {
					return $value;
				}
				return $value;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		LiteSpeed_Integration::reset_cache();
		$this->assertFalse( LiteSpeed_ESI::is_esi_available() );
	}

	public function test_is_esi_available_true_on_enterprise(): void {
		$this->set_litespeed( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_litespeed_esi_available' === $tag ) {
					return true;
				}
				return $value;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		LiteSpeed_Integration::reset_cache();
		$this->assertTrue( LiteSpeed_ESI::is_esi_available() );
	}

	public function test_should_punch_hole_cart_true_on_ols(): void {
		$this->set_litespeed( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) {
				if ( 'wppo_esi_should_punch_hole' === $tag ) {
					return null;
				}
				if ( 'wppo_litespeed_esi_available' === $tag || 'wppo_esi_available' === $tag ) {
					return false;
				}
				if ( 'wppo_litespeed_is_litespeed' === $tag ) {
					return $value;
				}
				return $value;
			}
		);
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		LiteSpeed_Integration::reset_cache();
		$this->assertTrue( LiteSpeed_ESI::should_punch_hole( 'cart' ) );
	}

	public function test_should_punch_hole_false_on_guest_non_woo(): void {
		$this->set_litespeed( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) {
				if ( 'wppo_esi_should_punch_hole' === $tag ) {
					return null;
				}
				if ( 'wppo_litespeed_esi_available' === $tag || 'wppo_esi_available' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		LiteSpeed_Integration::reset_cache();
		$this->assertFalse( LiteSpeed_ESI::should_punch_hole( 'cart' ) );
	}

	public function test_inject_nonce_replacement_replaces_placeholder(): void {
		Functions\when( 'wp_create_nonce' )->alias(
			static function ( $action ) {
				return 'testnonce_' . $action;
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt123' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		$content = '<div data-wppo-nonce="__WPPO_ESI_NONCE__"></div>';
		$result  = LiteSpeed_ESI::inject_nonce_replacement( $content );
		$this->assertStringContainsString( 'testnonce_wppo_esi', $result );
		$this->assertStringNotContainsString( '__WPPO_ESI_NONCE__', $result );
	}

	public function test_ajax_handler_returns_json_and_headers(): void {
		$this->set_litespeed( true );
		$headers = array();
		Functions\when( 'header' )->alias(
			static function ( $str, $replace = true, $code = 0 ) use ( &$headers ) {
				$headers[] = $str;
			}
		);
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$captured = null;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ) use ( &$captured ) {
				$captured = $data;
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_GET['block'] = 'cart';
		LiteSpeed_ESI::handle_ajax_fragment();
		$this->assertIsArray( $captured );
		$this->assertArrayHasKey( 'html', $captured );
		$this->assertSame( '<span>cart(3)</span>', $captured['html'] );
		// Headers should contain private,no-cache.
		$found_private = false;
		$found_vary    = false;
		foreach ( $headers as $h ) {
			if ( false !== strpos( $h, 'Cache-Control: private,no-cache' ) ) {
				$found_private = true;
			}
			if ( false !== strpos( $h, 'X-LiteSpeed-Cache-Control: private,no-vary' ) ) {
				$found_vary = true;
			}
		}
		$this->assertTrue( $found_private, 'Cache-Control private,no-cache not found' );
		$this->assertTrue( $found_vary, 'X-LiteSpeed-Cache-Control private,no-vary not found' );
		unset( $_GET['block'] );
	}

	public function test_handle_send_headers_private_no_vary_on_cart(): void {
		$this->set_litespeed( true );
		$headers = array();
		Functions\when( 'header' )->alias(
			static function ( $str, $replace = true, $code = 0 ) use ( &$headers ) {
				$headers[] = $str;
			}
		);
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) {
				if ( 'wppo_litespeed_esi_available' === $tag || 'wppo_esi_available' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		LiteSpeed_Integration::reset_cache();
		LiteSpeed_ESI::handle_send_headers();
		$found = false;
		foreach ( $headers as $h ) {
			if ( false !== strpos( $h, 'X-LiteSpeed-Cache-Control: private,no-vary' ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Expected private,no-vary header on cart' );
		// Ensure not public.
		foreach ( $headers as $h ) {
			$this->assertStringNotContainsString( 'public', $h );
		}
	}

	public function test_render_esi_placeholder_ols_fallback(): void {
		$this->set_litespeed( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, ...$args ) {
				if ( 'wppo_litespeed_esi_available' === $tag || 'wppo_esi_available' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		LiteSpeed_Integration::reset_cache();
		$html = LiteSpeed_ESI::render_esi_placeholder( 'cart', array() );
		$this->assertStringContainsString( 'data-wppo-esi="cart"', $html );
		$this->assertStringContainsString( 'data-nonce', $html );
		$this->assertStringNotContainsString( 'esi:include', $html );
	}

	public function test_register_ajax_handlers(): void {
		$calls = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb, $prio = 10, $args = 1 ) use ( &$calls ) {
				$calls[] = $hook;
				return true;
			}
		);
		LiteSpeed_ESI::register_ajax_handlers();
		$this->assertContains( 'wp_ajax_wppo_esi_fragment', $calls );
		$this->assertContains( 'wp_ajax_nopriv_wppo_esi_fragment', $calls );
	}
}
