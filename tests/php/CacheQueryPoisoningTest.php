<?php
/**
 * Tests for the canonical-host + query-param cache poisoning guard (issue #1141).
 *
 * Covers Util::get_cache_query_allowlist(), Util::has_uncacheable_query(),
 * and their wiring into Cache::is_not_cacheable() / Cache::maybe_store_cache().
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the query-param poisoning guard.
 *
 * @package PerformanceOptimise\Tests
 */
class CacheQueryPoisoningTest extends \PHPUnit\Framework\TestCase {
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
	 * Default allowlist contains the known tracking params.
	 */
	public function test_allowlist_defaults_contain_tracking_params(): void {
		$allowlist = Util::get_cache_query_allowlist();
		$this->assertContains( 'utm_source', $allowlist );
		$this->assertContains( 'utm_medium', $allowlist );
		$this->assertContains( 'gclid', $allowlist );
		$this->assertContains( 'fbclid', $allowlist );
		$this->assertContains( 'msclkid', $allowlist );
		// Legacy uncacheable params are never allowlisted.
		$this->assertNotContains( 's', $allowlist );
		$this->assertNotContains( 'ver', $allowlist );
		$this->assertNotContains( 'v', $allowlist );
	}

	/**
	 * Empty query strings are cacheable.
	 */
	public function test_empty_query_is_cacheable(): void {
		$this->assertFalse( Util::has_uncacheable_query( '' ) );
		$this->assertFalse( Util::has_uncacheable_query( '   ' ) );
	}

	/**
	 * Tracking-only queries are cache-neutral for the read decision.
	 */
	public function test_tracking_only_query_is_cache_neutral(): void {
		$this->assertFalse( Util::has_uncacheable_query( 'utm_source=google&utm_medium=cpc' ) );
		$this->assertFalse( Util::has_uncacheable_query( 'UTM_SOURCE=google' ) );
		$this->assertFalse( Util::has_uncacheable_query( 'gclid=abc123' ) );
		$this->assertFalse( Util::has_uncacheable_query( 'fbclid=xyz' ) );
		$this->assertFalse( Util::has_uncacheable_query( 'utm_custom_future_param=1' ) );
	}

	/**
	 * Legacy and functional params force a dynamic response.
	 */
	public function test_functional_query_is_uncacheable(): void {
		$this->assertTrue( Util::has_uncacheable_query( 's=hello' ) );
		$this->assertTrue( Util::has_uncacheable_query( 'ver=1.2' ) );
		$this->assertTrue( Util::has_uncacheable_query( 'v=3' ) );
		$this->assertTrue( Util::has_uncacheable_query( 'add-to-cart=42' ) );
		$this->assertTrue( Util::has_uncacheable_query( 'foo=bar' ) );
		$this->assertTrue( Util::has_uncacheable_query( 'utm_source=x&foo=bar' ) );
	}

	/**
	 * Legacy params stay uncacheable even when a filter allowlists them.
	 */
	public function test_legacy_params_win_over_allowlist_filter(): void {
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_cache_query_allowlist' === $hook ) {
					return array( 's', 'utm_source' );
				}
				return $value;
			}
		);

		$this->assertTrue( Util::has_uncacheable_query( 's=hello' ) );
		$this->assertFalse( Util::has_uncacheable_query( 'utm_source=x' ) );
	}

	/**
	 * Sites can extend the allowlist with custom marketing params.
	 */
	public function test_filter_can_add_custom_tracking_param(): void {
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_cache_query_allowlist' === $hook ) {
					$value[] = 'ref';
					return $value;
				}
				return $value;
			}
		);

		$this->assertFalse( Util::has_uncacheable_query( 'ref=partner' ) );
		$this->assertTrue( Util::has_uncacheable_query( 'unknown_param=1' ) );
	}

	/**
	 * Null query string falls back to $_SERVER['QUERY_STRING'].
	 */
	public function test_null_reads_server_query_string(): void {
		$backup = $this->backup_superglobals();
		try {
			$_SERVER['QUERY_STRING'] = 'utm_source=x';
			$this->assertFalse( Util::has_uncacheable_query() );
			$_SERVER['QUERY_STRING'] = 'foo=bar';
			$this->assertTrue( Util::has_uncacheable_query() );
			unset( $_SERVER['QUERY_STRING'] );
			$this->assertFalse( Util::has_uncacheable_query() );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}

	/**
	 * Tracked requests are never stored over the clean-URL entry.
	 *
	 * Refusal outcomes hold in any process (an early DONOTCACHEPAGE refuse
	 * still satisfies the assertion), so no process isolation is needed.
	 */
	public function test_tracked_request_is_never_stored(): void {
		$backup = $this->backup_superglobals();
		try {
			$_SERVER['HTTP_HOST']    = 'example.com';
			$_SERVER['REQUEST_URI']  = '/test-page/';
			$_SERVER['QUERY_STRING'] = 'utm_source=google&utm_medium=cpc';
			$_GET                    = array();
			$_COOKIE                 = array();
			Functions\when( 'get_option' )->justReturn( array() );
			\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

			$cache = new Cache();
			$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
			$store->setAccessible( true );
			$this->assertFalse( $store->invoke( $cache ) );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}

	/**
	 * Functional queries are neither served from cache nor stored.
	 */
	public function test_functional_query_is_not_cacheable_and_not_stored(): void {
		$backup = $this->backup_superglobals();
		try {
			$_SERVER['HTTP_HOST']    = 'example.com';
			$_SERVER['REQUEST_URI']  = '/test-page/?foo=bar';
			$_SERVER['QUERY_STRING'] = 'foo=bar';
			$_GET                    = array();
			$_COOKIE                 = array();
			Functions\when( 'get_option' )->justReturn( array() );
			\PerformanceOptimise\Inc\LiteSpeed_Integration::reset_cache();

			$cache = new Cache();

			$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
			$not_cacheable->setAccessible( true );
			$this->assertTrue( $not_cacheable->invoke( $cache ) );

			$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
			$store->setAccessible( true );
			$this->assertFalse( $store->invoke( $cache ) );
		} finally {
			$this->restore_superglobals( $backup );
		}
	}

	/**
	 * Clean-URL control stays cacheable and storable with a matching host.
	 *
	 * Runs in a separate process: DONOTCACHEPAGE is process-global and may
	 * be defined by earlier suites sharing the process, which would force
	 * a refusal and weaken this positive control.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_clean_url_matching_host_stays_storable(): void {
		$_SERVER['HTTP_HOST']    = 'example.com';
		$_SERVER['REQUEST_URI']  = '/test-page/';
		$_SERVER['QUERY_STRING'] = '';
		$_GET                    = array();
		$_COOKIE                 = array();
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

		$cache = new Cache();

		$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
		$not_cacheable->setAccessible( true );
		$this->assertFalse( $not_cacheable->invoke( $cache ) );

		$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
		$store->setAccessible( true );
		$this->assertTrue( $store->invoke( $cache ) );
	}

	/**
	 * Tracking-only query stays servable from the clean entry (read path)
	 * while the write path refuses — sequential clean→tracked fetches keep
	 * the canonical file stable.
	 *
	 * Runs in a separate process for the same DONOTCACHEPAGE reason as the
	 * clean-URL control above.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_tracking_only_query_servable_but_not_stored(): void {
		$_SERVER['HTTP_HOST']    = 'example.com';
		$_SERVER['REQUEST_URI']  = '/test-page/?utm_source=google';
		$_SERVER['QUERY_STRING'] = 'utm_source=google';
		$_GET                    = array();
		$_COOKIE                 = array();
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

		$cache = new Cache();

		$not_cacheable = new ReflectionMethod( Cache::class, 'is_not_cacheable' );
		$not_cacheable->setAccessible( true );
		$this->assertFalse( $not_cacheable->invoke( $cache ) );

		$store = new ReflectionMethod( Cache::class, 'maybe_store_cache' );
		$store->setAccessible( true );
		$this->assertFalse( $store->invoke( $cache ) );
	}
}
