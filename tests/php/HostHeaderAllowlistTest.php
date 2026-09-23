<?php
/**
 * Tests for the Host-header allowlist + query-aware cache key (issue #1199).
 *
 * Forged Host headers must never create cache entries under foreign hosts
 * (served uncached, no host leak), and tracking params must never poison the
 * clean-URL entry (shared path-only key, never stored over the clean file).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Advanced_Cache_Handler;
use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;

/**
 * Tests for the Host-header allowlist and query-aware cache key.
 *
 * @package PerformanceOptimise\Tests
 */
class HostHeaderAllowlistTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Back up touched superglobals.
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
	 * Restore touched superglobals.
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
	 * Stub the read-path conditionals so positive servability assertions run.
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
	 * Forged Host: key stays canonical, request served uncached, never stored.
	 */
	public function test_forged_host_key_stays_canonical_and_never_stores(): void {
		$backup = $this->backup_superglobals();
		try {
			$_SERVER['HTTP_HOST']    = 'evil.com';
			$_SERVER['REQUEST_URI']  = '/test-page/';
			$_SERVER['QUERY_STRING'] = '';
			$_GET                    = array();
			$_COOKIE                 = array();
			Functions\when( 'get_option' )->justReturn( array() );
			\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

			$cache = $this->make_injected_cache();

			// Key pinned to the canonical home host, never the forged host.
			$this->assertSame( 'example.com/test-page', $cache->cache_key() );
			$this->assertTrue( $cache->is_host_mismatched() );

			$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
			$this->assertTrue( $not_cacheable->invoke( $cache ) );

			$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
			$this->assertFalse( $store->invoke( $cache ) );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}

	/**
	 * Cached file URL under a forged Host still uses the canonical host.
	 */
	public function test_forged_host_cache_file_url_never_leaks_request_host(): void {
		$backup = $this->backup_superglobals();
		try {
			$_SERVER['HTTP_HOST']    = 'evil.com';
			$_SERVER['REQUEST_URI']  = '/test-page/';
			$_SERVER['QUERY_STRING'] = '';
			$_GET                    = array();
			$_COOKIE                 = array();
			Functions\when( 'get_option' )->justReturn( array() );
			\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

			$cache = $this->make_injected_cache();
			$url   = $cache->get_cache_file_url();

			$this->assertStringContainsString( '/cache/wppo/example.com/test-page/index.html', $url );
			$this->assertStringNotContainsString( 'evil.com', $url );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}

	/**
	 * UTM request shares the clean-URL key, stays servable, but never stores.
	 *
	 * Runs in a separate process: DONOTCACHEPAGE is process-global and may be
	 * defined by earlier suites sharing the process.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_utm_request_shares_clean_key_but_never_stores(): void {
		$_SERVER['HTTP_HOST']    = 'example.com';
		$_SERVER['REQUEST_URI']  = '/test-page/?utm_source=google&utm_medium=cpc';
		$_SERVER['QUERY_STRING'] = 'utm_source=google&utm_medium=cpc';
		$_GET                    = array();
		$_COOKIE                 = array();
		$this->stub_read_path();

		$tracked = $this->make_injected_cache();
		$this->assertSame( 'example.com/test-page', $tracked->cache_key() );

		$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
		$this->assertFalse( $not_cacheable->invoke( $tracked ) );

		$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
		$this->assertFalse( $store->invoke( $tracked ) );

		// Clean URL maps to the identical key and stays storable.
		$_SERVER['REQUEST_URI']  = '/test-page/';
		$_SERVER['QUERY_STRING'] = '';
		$clean                   = $this->make_injected_cache();
		$this->assertSame( $clean->cache_key(), $tracked->cache_key() );
		$this->assertTrue( $store->invoke( $clean ) );
	}

	/**
	 * Pre-boot allowlist mirror: strict canonical equality, fail-closed.
	 */
	public function test_is_host_allowed_mirrors_dropin_guard(): void {
		$this->assertTrue( Advanced_Cache_Handler::is_host_allowed( 'example.com', 'example.com' ) );
		$this->assertTrue( Advanced_Cache_Handler::is_host_allowed( 'EXAMPLE.COM:8080', 'example.com' ) );
		$this->assertTrue( Advanced_Cache_Handler::is_host_allowed( '[::1]:8080', '::1' ) );

		$this->assertFalse( Advanced_Cache_Handler::is_host_allowed( 'evil.com', 'example.com' ) );
		$this->assertFalse( Advanced_Cache_Handler::is_host_allowed( '', 'example.com' ) );
		$this->assertFalse( Advanced_Cache_Handler::is_host_allowed( 'example.com', '' ) );
		$this->assertFalse( Advanced_Cache_Handler::is_host_allowed( 'evil!/..', 'example.com' ) );
		$this->assertFalse( Advanced_Cache_Handler::is_host_allowed( 'example.com:8080:evil', 'example.com' ) );
		$this->assertFalse( Advanced_Cache_Handler::is_host_allowed( '[::1]evil', '::1' ) );
	}

	/**
	 * Drop-in host normalization matches the Util canonicalization rule.
	 */
	public function test_normalize_dropin_host_parity(): void {
		$this->assertSame( 'example.com', Advanced_Cache_Handler::normalize_dropin_host( 'Example.COM:8080' ) );
		$this->assertSame( '', Advanced_Cache_Handler::normalize_dropin_host( '' ) );
		$this->assertSame( '', Advanced_Cache_Handler::normalize_dropin_host( 'evil!/..' ) );
		$this->assertSame( '::1', Advanced_Cache_Handler::normalize_dropin_host( '[::1]:8080' ) );
	}
}
