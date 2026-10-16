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
	 * Original $_SERVER value, restored in tearDown().
	 *
	 * @var array|string|null
	 */
	private $original_accept;

	/**
	 * Snapshot the superglobal so the suite stays order-independent.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Test-only snapshot of a superglobal for restore; it is never used as input to the code under test.
		$this->original_accept = $_SERVER['HTTP_ACCEPT'] ?? null;
	}

	/**
	 * Restore the superglobal.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null === $this->original_accept ) {
			unset( $_SERVER['HTTP_ACCEPT'] );
		} else {
			$_SERVER['HTTP_ACCEPT'] = $this->original_accept;
		}
		parent::tearDown();
	}

	/**
	 * A string value is unslashed and sanitized through the WP helpers.
	 *
	 * @return void
	 */
	public function test_returns_the_sanitized_string_value(): void {
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
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		unset( $_SERVER['HTTP_ACCEPT'] );

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
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$_SERVER['HTTP_ACCEPT'] = 42;

		$this->assertSame( 'fallback', Util::server_string( 'HTTP_ACCEPT', 'fallback' ) );
	}
}
