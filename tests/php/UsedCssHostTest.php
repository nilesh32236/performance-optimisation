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
