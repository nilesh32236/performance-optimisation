<?php
/**
 * Tests for the Used_CSS Host-header canonical pin and Util host normalization.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the Used_CSS Host-header canonical pin and Util host normalization.
 *
 * @package PerformanceOptimise\Tests
 */
class UsedCssHostTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Superglobal backup so a forged host never leaks into later tests.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Back up superglobals touched by these tests.
	 *
	 * Note: this setUp shadows the trait's setUp, so it must replicate the
	 * Brain Monkey bootstrap (see AdvancedCacheHandlerTest).
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::clear_settings_cache();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup/restore.
		$this->server_backup['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup/restore.
		$this->server_backup['REQUEST_URI'] = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test-only superglobal backup/restore.
		$this->server_backup['GET']         = $_GET;
		$this->server_backup['COOKIE']      = $_COOKIE;
	}

	/**
	 * Restore superglobals after each test.
	 */
	protected function tearDown(): void {
		if ( null === $this->server_backup['HTTP_HOST'] ) {
			unset( $_SERVER['HTTP_HOST'] );
		} else {
			$_SERVER['HTTP_HOST'] = $this->server_backup['HTTP_HOST'];
		}
		if ( null === $this->server_backup['REQUEST_URI'] ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->server_backup['REQUEST_URI'];
		}
		$_GET    = $this->server_backup['GET'];
		$_COOKIE = $this->server_backup['COOKIE'];
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test that a forged Host header pins the used-CSS dir to the canonical
	 * home host and refuses writes.
	 */
	public function test_forged_host_pins_domain_and_refuses_save(): void {
		$_SERVER['HTTP_HOST']   = 'evil.com';
		$_SERVER['REQUEST_URI'] = '/test-page/';
		Functions\when( 'get_option' )->justReturn( array() );

		$used_css = new Used_CSS();

		$domain_prop = new \ReflectionProperty( Used_CSS::class, 'domain' );
		$domain_prop->setAccessible( true );
		$this->assertSame( 'example.com', $domain_prop->getValue( $used_css ) );
		$this->assertTrue( $used_css->is_host_mismatched() );
		$this->assertFalse( $used_css->save_used_css( '.a{color:red}', 'http://example.com/test-page/' ) );
	}

	/**
	 * Test that a forged host inside the explicit $url is refused even when
	 * the ambient Host header matches (e.g. filtered permalink).
	 */
	public function test_forged_url_host_refuses_save_with_matching_ambient_host(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/test-page/';
		Functions\when( 'get_option' )->justReturn( array() );

		$used_css = new Used_CSS();

		$this->assertFalse( $used_css->is_host_mismatched() );
		$this->assertFalse( $used_css->save_used_css( '.a{color:red}', 'http://evil.com/test-page/' ) );
	}

	/**
	 * Test that a matching Host header keeps the request pinned without mismatch.
	 */
	public function test_matching_host_has_no_mismatch(): void {
		$_SERVER['HTTP_HOST']   = 'example.com';
		$_SERVER['REQUEST_URI'] = '/test-page/';
		Functions\when( 'get_option' )->justReturn( array() );

		$used_css = new Used_CSS();

		$this->assertFalse( $used_css->is_host_mismatched() );

		$domain_prop = new \ReflectionProperty( Used_CSS::class, 'domain' );
		$domain_prop->setAccessible( true );
		$this->assertSame( 'example.com', $domain_prop->getValue( $used_css ) );
	}

	/**
	 * Test that normalize_cache_host strips ports and lowercases.
	 */
	public function test_normalize_cache_host_strips_port_and_lowercases(): void {
		$this->assertSame( 'example.com', Util::normalize_cache_host( 'Example.COM:8080' ) );
	}

	/**
	 * Test that normalize_cache_host rejects invalid input.
	 */
	public function test_normalize_cache_host_rejects_invalid(): void {
		$this->assertSame( '', Util::normalize_cache_host( '' ) );
		$this->assertSame( '', Util::normalize_cache_host( 'invalid..domain' ) );
		$this->assertSame( '', Util::normalize_cache_host( 'evil.com/path' ) );
		$this->assertSame( '', Util::normalize_cache_host( '[::1]evil' ) );
		$this->assertSame( '::1', Util::normalize_cache_host( '[::1]:8080' ) );
	}

	/**
	 * Test that normalize_cache_host converts an IDN host with a port.
	 */
	public function test_normalize_cache_host_idn_with_port(): void {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'intl idn_to_ascii() is unavailable.' );
		}
		$this->assertSame( 'xn--mnchen-3ya.de', Util::normalize_cache_host( 'münchen.de:8080' ) );
		$this->assertSame( 'xn--mnchen-3ya.de', Util::normalize_cache_host( 'münchen.de' ) );
	}
}
