<?php
/**
 * Tests for Header_Emitter consolidation (issue #905).
 *
 * Pins zero-behaviour-change delegation: strip_crlf parity with the former
 * LiteSpeed_Integration::strip_crlf, CRLF-safe emit with headers_sent guard,
 * private/no-cache pair exact strings, purge/tag emission, and ESI routing
 * through the emitter.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Header_Emitter;
use PerformanceOptimise\Inc\LiteSpeed_ESI;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use Brain\Monkey\Functions;

/**
 * Tests for Header_Emitter.
 */
class HeaderEmitterTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Captured header() calls for the current test.
	 *
	 * @var string[]
	 */
	private array $captured_headers = array();

	/**
	 * Captured header() replace flags.
	 *
	 * @var bool[]
	 */
	private array $captured_replace = array();

	/**
	 * Set up header capture stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		\PerformanceOptimise\Inc\Util::reset_cached_home_urls();
		\PerformanceOptimise\Inc\Util::clear_settings_cache();
		\PerformanceOptimise\Inc\Util::clear_permalink_cache();
		$this->captured_headers = array();
		$this->captured_replace = array();
		$captured_headers       = &$this->captured_headers;
		$captured_replace       = &$this->captured_replace;
		Functions\when( 'header' )->alias(
			static function ( $header, $replace = true, $code = 0 ) use ( &$captured_headers, &$captured_replace ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match header().
				$captured_headers[] = $header;
				$captured_replace[] = (bool) $replace;
			}
		);
		Functions\when( 'headers_sent' )->justReturn( false );
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
	}

	/**
	 * Tear down statics.
	 */
	protected function tearDown(): void {
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		unset( $_SERVER['SERVER_SOFTWARE'] );
		\Brain\Monkey\tearDown();
		if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Verify strip_crlf removes CR, LF and NUL.
	 */
	public function test_strip_crlf_removes_control_chars(): void {
		$this->assertSame( 'abc', Header_Emitter::strip_crlf( "a\rb\nc" ) );
		$this->assertSame( 'abc', Header_Emitter::strip_crlf( "a\0b\rc" ) );
		$this->assertSame( 'X-LiteSpeed-Tag: WPPO', Header_Emitter::strip_crlf( "X-LiteSpeed-Tag: WP\r\nPO" ) );
	}

	/**
	 * Legacy private strip_crlf delegates to Header_Emitter (thin BC shim).
	 */
	public function test_litespeed_strip_crlf_delegates_to_emitter(): void {
		$ref = new \ReflectionMethod( LiteSpeed_Integration::class, 'strip_crlf' );
		$ref->setAccessible( true );
		$this->assertSame( Header_Emitter::strip_crlf( "a\rb\nc" ), $ref->invoke( null, "a\rb\nc" ) );
		$this->assertSame( 'tag', $ref->invoke( null, "ta\rg" ) );
	}

	/**
	 * Verify emit strips injection and forwards the replace flag.
	 */
	public function test_emit_strips_and_forwards_replace(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		$ok = Header_Emitter::emit( "X-LiteSpeed-Tag: WP\r\nPO", false );
		$this->assertTrue( $ok );
		$this->assertSame( array( 'X-LiteSpeed-Tag: WPPO' ), $this->captured_headers );
		$this->assertSame( array( false ), $this->captured_replace );
	}

	/**
	 * Verify emit defaults to replace=true (TTL/nocache parity).
	 */
	public function test_emit_defaults_to_replace(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		Header_Emitter::emit( 'X-LiteSpeed-Cache-Control: public,max-age=10' );
		$this->assertSame( array( 'X-LiteSpeed-Cache-Control: public,max-age=10' ), $this->captured_headers );
		$this->assertSame( array( true ), $this->captured_replace );
	}

	/**
	 * Verify emit is a no-op when headers were already sent.
	 */
	public function test_emit_noop_when_headers_sent(): void {
		Functions\when( 'headers_sent' )->justReturn( true );
		$ok = Header_Emitter::emit( 'X-LiteSpeed-Tag: WPPO', false );
		$this->assertFalse( $ok );
		$this->assertSame( array(), $this->captured_headers );
	}

	/**
	 * Verify emit_private_pair emits the exact ESI pair with replace=true (pre-extraction semantics).
	 */
	public function test_emit_private_pair_exact_strings(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		Header_Emitter::emit_private_pair();
		$this->assertSame(
			array(
				'Cache-Control: private,no-cache',
				'X-LiteSpeed-Cache-Control: private,no-vary',
			),
			$this->captured_headers
		);
		$this->assertSame( array( true, true ), $this->captured_replace );
	}

	/**
	 * Verify emit_nocache_pair emits the exact admin no-cache pair with replace=true.
	 */
	public function test_emit_nocache_pair_exact_strings(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		Header_Emitter::emit_nocache_pair();
		$this->assertSame(
			array(
				'Cache-Control: no-cache',
				'X-LiteSpeed-Cache-Control: no-cache',
			),
			$this->captured_headers
		);
		$this->assertSame( array( true, true ), $this->captured_replace );
	}

	/**
	 * Verify emit_purge_tag strips CRLF injection from the tag string.
	 */
	public function test_emit_purge_tag_strips_injection(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		Header_Emitter::emit_purge_tag( "WPPO\r\nEvil: 1" );
		$this->assertCount( 1, $this->captured_headers );
		$this->assertSame( 'X-LiteSpeed-Purge: tag=WPPOEvil: 1', $this->captured_headers[0] );
		$this->assertStringNotContainsString( "\r", $this->captured_headers[0] );
		$this->assertStringNotContainsString( "\n", $this->captured_headers[0] );
	}

	/**
	 * Verify emit_tag strips CRLF injection from a single tag.
	 */
	public function test_emit_tag_strips_injection(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		Header_Emitter::emit_tag( "WPPO\r\nX-Injected: 1" );
		$this->assertSame( array( 'X-LiteSpeed-Tag: WPPOX-Injected: 1' ), $this->captured_headers );
	}

	/**
	 * Verify emit_esi_tag prefixes ESI and strips injection.
	 */
	public function test_emit_esi_tag_prefixes_and_strips(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		Header_Emitter::emit_esi_tag( "cart\r\nX: 1" );
		$this->assertSame( array( 'X-LiteSpeed-Tag: ESI.cartX: 1' ), $this->captured_headers );
	}

	/**
	 * ESI cart send_headers still emits the private pair via the emitter.
	 */
	public function test_esi_cart_headers_route_through_emitter(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		LiteSpeed_Integration::reset_cache();
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		LiteSpeed_ESI::handle_send_headers();

		$this->assertContains( 'Cache-Control: private,no-cache', $this->captured_headers );
		$this->assertContains( 'X-LiteSpeed-Cache-Control: private,no-vary', $this->captured_headers );
		foreach ( $this->captured_headers as $header ) {
			$this->assertStringNotContainsString( 'X-LiteSpeed-Cache-Control: public', $header );
		}
	}

	/**
	 * ESI send_headers emits nothing once headers were sent (hardening).
	 */
	public function test_esi_send_headers_suppressed_when_sent(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		LiteSpeed_Integration::reset_cache();
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		LiteSpeed_ESI::handle_send_headers();

		$this->assertSame( array(), $this->captured_headers );
	}

	/**
	 * Verify remove_generic_cache_control calls header_remove for both headers.
	 */
	public function test_remove_generic_cache_control_removes_both(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		$removed = array();
		Functions\when( 'header_remove' )->alias(
			static function ( $name = null ) use ( &$removed ) {
				$removed[] = $name;
			}
		);
		Header_Emitter::remove_generic_cache_control();
		$this->assertContains( 'Cache-Control', $removed );
		$this->assertContains( 'Pragma', $removed );
	}

	/**
	 * Verify remove_generic_cache_control is a no-op when headers were sent.
	 */
	public function test_remove_generic_cache_control_noop_when_sent(): void {
		Functions\when( 'headers_sent' )->justReturn( true );
		$removed = array();
		Functions\when( 'header_remove' )->alias(
			static function ( $name = null ) use ( &$removed ) {
				$removed[] = $name;
			}
		);
		Header_Emitter::remove_generic_cache_control();
		$this->assertSame( array(), $removed );
	}
}
