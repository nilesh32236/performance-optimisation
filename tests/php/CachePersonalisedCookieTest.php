<?php
/**
 * Regression tests for audit #1785 (critical + important cache findings).
 *
 * A visitor holding a `wp-postpass_*` cookie has unlocked a
 * password-protected post, and `comment_author_*` cookies personalise
 * `comment_form()` output. Caching either response would defeat post
 * password protection or leak commenter personal data to anonymous
 * visitors, so both the PHP write path (Cache::is_not_cacheable() /
 * Cache::maybe_store_cache()) and the generated advanced-cache.php
 * drop-in (covered in AdvancedCacheHandlerTest) must refuse them.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the password/commenter cookie cache refusal.
 *
 * @package PerformanceOptimise\Tests
 */
class CachePersonalisedCookieTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Back up superglobals touched by cache tests.
	 *
	 * @return array Backup.
	 */
	private function backup_superglobals(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup/restore.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup/restore.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test-only superglobal backup/restore.
		$qs = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : null;
		return array(
			'HTTP_HOST'    => $host,
			'REQUEST_URI'  => $uri,
			'QUERY_STRING' => $qs,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test-only superglobal backup/restore.
			'GET'          => $_GET,
			'COOKIE'       => $_COOKIE,
		);
	}

	/**
	 * Restore superglobals after a cache test.
	 *
	 * @param array $backup Backup from backup_superglobals().
	 */
	private function restore_superglobals( array $backup ): void {
		if ( null === $backup['HTTP_HOST'] ) {
			unset( $_SERVER['HTTP_HOST'] );
		} else {
			$_SERVER['HTTP_HOST'] = $backup['HTTP_HOST'];
		}
		if ( null === $backup['REQUEST_URI'] ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $backup['REQUEST_URI'];
		}
		if ( null === $backup['QUERY_STRING'] ) {
			unset( $_SERVER['QUERY_STRING'] );
		} else {
			$_SERVER['QUERY_STRING'] = $backup['QUERY_STRING'];
		}
		$_GET    = $backup['GET'];
		$_COOKIE = $backup['COOKIE'];
	}

	/**
	 * Set up a clean cacheable-looking request with the given cookies.
	 *
	 * @param array $cookies Cookies to present.
	 */
	private function prime_request( array $cookies ): void {
		$_SERVER['HTTP_HOST']    = 'example.com';
		$_SERVER['REQUEST_URI']  = '/test-page/';
		$_SERVER['QUERY_STRING'] = '';
		$_GET                    = array();
		$_COOKIE                 = $cookies;
		Functions\when( 'get_option' )->justReturn( array() );
		\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();
	}

	/**
	 * An unlocked password post (wp-postpass_* cookie) must never be served
	 * from cache nor stored: the stored unlocked page would be served to
	 * every anonymous visitor, defeating post password protection.
	 *
	 * Refusal outcomes hold in any process, so no process isolation is needed.
	 */
	public function test_postpass_cookie_is_not_cacheable_and_not_stored(): void {
		$backup = $this->backup_superglobals();
		try {
			$this->prime_request( array( 'wp-postpass_abc123' => 'hashed-value' ) );

			$cache = $this->make_injected_cache();

			$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
			$this->assertTrue( $not_cacheable->invoke( $cache ) );

			$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
			$this->assertFalse( $store->invoke( $cache ) );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}

	/**
	 * Commenter-personalised requests (comment_author_* cookies) must never
	 * be served from cache nor stored: comment_form() pre-fills name, email
	 * and URL from these cookies, and storing that page leaks personal data.
	 *
	 * @dataProvider comment_author_cookie_provider
	 * @param string $cookie_name Cookie name to present.
	 */
	#[DataProvider( 'comment_author_cookie_provider' )]
	public function test_comment_author_cookie_is_not_cacheable_and_not_stored( string $cookie_name ): void {
		$backup = $this->backup_superglobals();
		try {
			$this->prime_request( array( $cookie_name => 'Jane' ) );

			$cache = $this->make_injected_cache();

			$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
			$this->assertTrue( $not_cacheable->invoke( $cache ), 'Expected not cacheable: ' . $cookie_name );

			$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
			$this->assertFalse( $store->invoke( $cache ), 'Expected store refusal: ' . $cookie_name );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}

	/**
	 * All three comment_author_* cookie variants personalise output.
	 *
	 * @return array Test cases.
	 */
	public static function comment_author_cookie_provider(): array {
		return array(
			'name'  => array( 'comment_author_test' ),
			'email' => array( 'comment_author_email_test' ),
			'url'   => array( 'comment_author_url_test' ),
		);
	}

	/**
	 * A still-locked password-protected singular post must not be cached
	 * even when no postpass cookie is present.
	 *
	 * Refusal outcomes hold in any process, so no process isolation is needed.
	 */
	public function test_password_protected_singular_is_not_cacheable_and_not_stored(): void {
		$backup = $this->backup_superglobals();
		try {
			$this->prime_request( array() );
			Functions\when( 'is_singular' )->justReturn( true );
			Functions\when( 'post_password_required' )->justReturn( true );

			$cache = $this->make_injected_cache();

			$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
			$this->assertTrue( $not_cacheable->invoke( $cache ) );

			$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
			$this->assertFalse( $store->invoke( $cache ) );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}
}
