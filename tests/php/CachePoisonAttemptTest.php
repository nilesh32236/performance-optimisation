<?php
/**
 * Regression test for the CVE-2026-74916 cache-poisoning pattern (issue #1254).
 *
 * Proves the three acceptance bullets end to end at the cache-key/storage
 * decision level:
 * 1. Tracking params never fragment the canonical slot (shared path-only key).
 * 2. A forged Host header creates no foreign-host entry (canonical pin + no store).
 * 3. A poison-attempt fetch followed by a clean-URL fetch leaves the clean
 *    body unpoisoned: the poisoned response is never stored over the clean
 *    file, so both resolve to the same canonical filesystem path.
 *
 * Fail-open is asserted throughout: every hostile input is served dynamic
 * (not cacheable / never stored), never fatal.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * CVE-2026-74916 poison-attempt regression tests.
 *
 * @package PerformanceOptimise\Tests
 */
class CachePoisonAttemptTest extends \PHPUnit\Framework\TestCase {
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
	 * Stub the read-path conditionals so servability assertions run.
	 */
	private function stub_read_path(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();
	}

	/**
	 * Invoke the private maybe_store_cache().
	 *
	 * @param Cache $cache Instance.
	 * @return bool
	 */
	private function can_store( Cache $cache ): bool {
		$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
		$store->setAccessible( true );
		return (bool) $store->invoke( $cache );
	}

	/**
	 * Invoke the private safe_path_for_url().
	 *
	 * @param Cache  $cache    Instance.
	 * @param string $url_path URL path or URL.
	 * @param string $filename Leaf filename.
	 * @return string
	 */
	private function safe_path( Cache $cache, string $url_path, string $filename ): string {
		$method = new ReflectionMethod( Cache::class, 'safe_path_for_url' );
		$method->setAccessible( true );
		return (string) $method->invoke( $cache, $url_path, $filename );
	}

	/**
	 * Tracking params reuse the canonical slot and never store over it.
	 *
	 * Runs in a separate process: DONOTCACHEPAGE is process-global and may
	 * be defined by earlier suites sharing the process.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_tracking_params_reuse_canonical_slot(): void {
		$_SERVER['HTTP_HOST']    = 'example.com';
		$_SERVER['REQUEST_URI']  = '/poison-page/?utm_source=google&gclid=abc123&fbclid=xyz';
		$_SERVER['QUERY_STRING'] = 'utm_source=google&gclid=abc123&fbclid=xyz';
		$_GET                    = array();
		$_COOKIE                 = array();
		$this->stub_read_path();

		$tracked = new Cache();
		$clean   = null;
		try {
			// Same path-only key as the clean URL: no fragmentation.
			$this->assertSame( 'example.com/poison-page', $tracked->cache_key() );
			// Never stored over the canonical file.
			$this->assertFalse( $this->can_store( $tracked ) );

			// Clean-URL fetch maps to the identical key and stays storable.
			$_SERVER['REQUEST_URI']  = '/poison-page/';
			$_SERVER['QUERY_STRING'] = '';
			$clean                   = new Cache();
			$this->assertSame( $clean->cache_key(), $tracked->cache_key() );
			$this->assertTrue( $this->can_store( $clean ) );

			// Filesystem level: both resolve to the same canonical file, so a
			// poisoned body could never land anywhere but the clean slot —
			// and the store refusal above proves it never lands at all.
			$tracked_path = $this->safe_path( $tracked, 'poison-page', 'index.html' );
			$clean_path   = $this->safe_path( $clean, 'poison-page', 'index.html' );
			$this->assertNotSame( '', $clean_path );
			$this->assertSame( $clean_path, $tracked_path );
			$this->assertStringStartsWith( '/tmp/wordpress/wp-content/cache/wppo/example.com/', $clean_path );
		} finally {
			unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'] );
		}
	}

	/**
	 * Forged Host creates no foreign-host entry and leaks no host into URLs.
	 */
	public function test_forged_host_creates_no_foreign_entry(): void {
		$backup = $this->backup_superglobals();
		try {
			$_SERVER['HTTP_HOST']    = 'evil.com';
			$_SERVER['REQUEST_URI']  = '/poison-page/';
			$_SERVER['QUERY_STRING'] = '';
			$_GET                    = array();
			$_COOKIE                 = array();
			Functions\when( 'get_option' )->justReturn( array() );
			\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

			$cache = new Cache();

			// Key stays pinned to the canonical home host.
			$this->assertSame( 'example.com/poison-page', $cache->cache_key() );
			$this->assertTrue( $cache->is_host_mismatched() );

			$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
			$not_cacheable->setAccessible( true );
			$this->assertTrue( $not_cacheable->invoke( $cache ) );
			$this->assertFalse( $this->can_store( $cache ) );

			// Cached URLs use the canonical host, never the forged one.
			$url = $cache->get_cache_file_url();
			$this->assertStringContainsString( '/cache/wppo/example.com/poison-page/index.html', $url );
			$this->assertStringNotContainsString( 'evil.com', $url );

			// Filesystem level: the choke-point resolves under the canonical
			// tree only — no foreign-host directory is addressable.
			$resolved = $this->safe_path( $cache, 'poison-page', 'index.html' );
			$this->assertStringStartsWith( '/tmp/wordpress/wp-content/cache/wppo/example.com/', $resolved );
			$this->assertStringNotContainsString( 'evil.com', $resolved );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}

	/**
	 * Encoded traversal attempts never escape the domain cache dir.
	 */
	public function test_traversal_attempts_stay_contained(): void {
		$backup = $this->backup_superglobals();
		try {
			$_SERVER['HTTP_HOST']    = 'example.com';
			$_SERVER['REQUEST_URI']  = '/poison-page/';
			$_SERVER['QUERY_STRING'] = '';
			$_GET                    = array();
			$_COOKIE                 = array();
			Functions\when( 'get_option' )->justReturn( array() );
			\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

			$cache = new Cache();
			$root  = '/tmp/wordpress/wp-content/cache/wppo';

			foreach ( array( '/%2e%2e/%2e%2e/etc/passwd', '/%252e%252e/evil', "/about/\0../../x", '..\\..\\windows' ) as $payload ) {
				$resolved = $this->safe_path( $cache, $payload, 'index.html' );
				if ( '' !== $resolved ) {
					// Double-encoding stays a benign literal: still contained.
					$this->assertStringStartsWith( $root . '/example.com/', $resolved, "Escape for: {$payload}" );
					$this->assertStringNotContainsString( '..', $resolved, "Dot-dot in path for: {$payload}" );
					$this->assertStringNotContainsString( "\0", $resolved, "NUL in path for: {$payload}" );
				}
			}

			// The canonical single-encoded traversal refuses outright.
			$this->assertSame( '', $this->safe_path( $cache, '/%2e%2e/%2e%2e/etc/passwd', 'index.html' ) );

			// Shared helper parity: single-decode traversal refuses, double
			// encoding stays a contained literal.
			$this->assertSame( '', Util::sanitize_cache_path( $root, 'example.com', '/%2e%2e/x', 'index.html' ) );
			$literal = Util::sanitize_cache_path( $root, 'example.com', '/%252e%252e/foo', 'index.html' );
			if ( '' !== $literal ) {
				$this->assertStringStartsWith( $root . '/example.com/', $literal );
			}
		} finally {
			$this->restore_superglobals( $backup );
		}
	}
}
