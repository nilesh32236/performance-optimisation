<?php
/**
 * Tests for Util::server_string() (audit #1820).
 *
 * The helper is the single owner of the $_SERVER read idiom. It must fail
 * closed to $fallback for an absent key AND for a non-string (array-shaped)
 * value — the array case is the PHP 8 TypeError / unsanitized-markup surface
 * the audit set out to close, and it is the half that no other test covered.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Util::server_string() fail-closed contract.
 *
 * @package PerformanceOptimise\Tests
 */
class UtilServerStringTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Normalise the superglobal before each case.
	 *
	 * Deliberately NOT named setUp(): a same-named method here would shadow
	 * WPPO_Test_Bootstrap::setUp(), so Brain Monkey's own setUp() would never
	 * run and patched functions would leak between tests (the hazard
	 * documented in RumCollectTokenSchemaTest).
	 *
	 * @return void
	 */
	private function clear_accept_header(): void {
		unset( $_SERVER['HTTP_ACCEPT'] );
	}

	/**
	 * A string value is unslashed and sanitized through the WP helpers.
	 *
	 * @return void
	 */
	public function test_returns_the_sanitized_string_value(): void {
		$this->clear_accept_header();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$_SERVER['HTTP_ACCEPT'] = 'text/html';

		$this->assertSame( 'text/html', Util::server_string( 'HTTP_ACCEPT' ) );
	}

	/**
	 * An absent key falls back instead of warning on an undefined index.
	 *
	 * @return void
	 */
	public function test_fails_closed_when_the_key_is_absent(): void {
		$this->clear_accept_header();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->assertSame( '', Util::server_string( 'HTTP_ACCEPT' ) );
		$this->assertSame(
			'fallback',
			Util::server_string( 'HTTP_ACCEPT', 'fallback' )
		);
	}

	/**
	 * An array-shaped header (?HTTP_ACCEPT[]=x) fails closed rather than
	 * reaching sanitize_text_field(), which TypeError's on PHP 8.
	 *
	 * @return void
	 */
	public function test_fails_closed_for_an_array_valued_header(): void {
		$this->clear_accept_header();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$_SERVER['HTTP_ACCEPT'] = array( 'image/webp', 'image/avif' );

		$this->assertSame( '', Util::server_string( 'HTTP_ACCEPT' ) );
		$this->assertSame(
			'fallback',
			Util::server_string( 'HTTP_ACCEPT', 'fallback' )
		);
	}

	/**
	 * A non-string scalar is also rejected (the guard is is_string(), not
	 * is_scalar()).
	 *
	 * @return void
	 */
	public function test_fails_closed_for_a_non_string_scalar(): void {
		$this->clear_accept_header();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$_SERVER['HTTP_ACCEPT'] = 42;

		$this->assertSame(
			'fallback',
			Util::server_string( 'HTTP_ACCEPT', 'fallback' )
		);
	}

	/**
	 * `has_server_string()` distinguishes an absent header from an empty one.
	 *
	 * `server_string()` returns '' for both, so the next-gen image caller must
	 * use the predicate to preserve the pre-refactor isset() semantics: an
	 * empty Accept header is present, so the request is not headerless.
	 *
	 * @return void
	 */
	public function test_has_server_string_distinguishes_absent_from_empty(): void {
		$this->clear_accept_header();
		$this->assertFalse( Util::has_server_string( 'HTTP_ACCEPT' ) );

		$_SERVER['HTTP_ACCEPT'] = '';
		$this->assertTrue( Util::has_server_string( 'HTTP_ACCEPT' ) );
		$this->assertSame( '', Util::server_string( 'HTTP_ACCEPT' ) );

		$_SERVER['HTTP_ACCEPT'] = array( 'image/webp' );
		$this->assertFalse( Util::has_server_string( 'HTTP_ACCEPT' ) );

		$_SERVER['HTTP_ACCEPT'] = 42;
		$this->assertFalse( Util::has_server_string( 'HTTP_ACCEPT' ) );
	}
}
