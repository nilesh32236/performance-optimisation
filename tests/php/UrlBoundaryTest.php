<?php
/**
 * Direct contract tests for the REF-004 Url boundary (issue #1504).
 *
 * Mirrors UtilCachedHomeUrlTest / UtilIsUrlExcludedTest through
 * `PerformanceOptimise\Inc\Url::` directly so Url-only regressions
 * (memoization, exclusion matching, redirect resolver, port policy)
 * are pinned at the owning boundary instead of arriving indirectly
 * via the Util facade proxies.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Url;

/**
 * Url boundary tests.
 *
 * @package PerformanceOptimise\Tests
 */
class UrlBoundaryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Url::reset_cached_home_urls();
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				$path = '/' . ltrim( (string) $path, '/' );
				return 'http://example.com' . $path;
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test double for wp_parse_url().
				$result = parse_url( (string) $url, $component );
				if ( -1 === $component ) {
					return false === $result ? false : $result;
				}
				return null === $result ? null : $result;
			}
		);
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( $url ) {
				if ( ! is_string( $url ) || '' === $url || false === strpos( $url, '://' ) ) {
					return false;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test double for core URL validation.
				$parsed = parse_url( $url );
				if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
					return false;
				}
				if ( ! in_array( $parsed['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
					return false;
				}
				return $url;
			}
		);
	}

	/**
	 * Url::cached_home_url() resolves once per blog and appends paths.
	 */
	public function test_cached_home_url_memoizes_per_blog(): void {
		$calls = 0;
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) use ( &$calls ) {
				++$calls;
				return 'http://example.com' . ( $path ? '/' . ltrim( (string) $path, '/' ) : '' );
			}
		);

		$this->assertSame( 'http://example.com', Url::cached_home_url() );
		$this->assertSame( 'http://example.com/test-path', Url::cached_home_url( '/test-path' ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * A registered home_url filter disables the memo.
	 */
	public function test_cached_home_url_filter_disables_memo(): void {
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://filtered-example.com' . ( $path ? '/' . ltrim( (string) $path, '/' ) : '' );
			}
		);

		$this->assertSame( 'http://filtered-example.com', Url::cached_home_url() );
		$this->assertSame( 'http://filtered-example.com/filtered-path', Url::cached_home_url( '/filtered-path' ) );
	}

	/**
	 * Reset clears the memo so the next call re-resolves home_url().
	 */
	public function test_reset_cached_home_urls_clears_memo(): void {
		$calls = 0;
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) use ( &$calls ) {
				++$calls;
				return 'http://example.com' . ( $path ? '/' . ltrim( (string) $path, '/' ) : '' );
			}
		);

		Url::cached_home_url();
		Url::reset_cached_home_urls();
		Url::cached_home_url();

		$this->assertSame( 2, $calls );
	}

	/**
	 * Exact exclusion rules match regardless of trailing slash.
	 */
	public function test_exact_rule_matches_trailing_slash_url(): void {
		$this->assertTrue(
			Url::is_url_excluded(
				'http://example.com/cart/',
				array( 'http://example.com/cart' )
			)
		);
	}

	/**
	 * Root-relative rules resolve against the cached home URL.
	 */
	public function test_root_relative_rule_is_resolved_and_matches(): void {
		$this->assertTrue(
			Url::is_url_excluded(
				'http://example.com/cart/',
				array( '/cart' )
			)
		);
	}

	/**
	 * "(.*)" prefix rules match deeper paths and the base path itself.
	 */
	public function test_wildcard_prefix_rule_matches_subpath_and_base(): void {
		$this->assertTrue(
			Url::is_url_excluded(
				'http://example.com/cart/checkout/',
				array( 'http://example.com/cart/(.*)' )
			)
		);
		$this->assertTrue(
			Url::is_url_excluded(
				'http://example.com/cart/',
				array( 'http://example.com/cart/(.*)' )
			)
		);
	}

	/**
	 * Non-matching URLs and empty rule lists never exclude.
	 */
	public function test_non_matching_url_is_not_excluded(): void {
		$this->assertFalse(
			Url::is_url_excluded(
				'http://example.com/shop/',
				array( 'http://example.com/cart' )
			)
		);
		$this->assertFalse(
			Url::is_url_excluded( 'http://example.com/cart/', array() )
		);
	}

	/**
	 * Exclusion matching is scheme-normalized (http/https interchangeable).
	 */
	public function test_scheme_normalized_matching(): void {
		$this->assertTrue(
			Url::is_url_excluded(
				'https://example.com/cart/',
				array( 'http://example.com/cart' )
			)
		);
		$this->assertTrue(
			Url::is_url_excluded(
				'http://example.com/cart/',
				array( 'https://example.com/cart' )
			)
		);
	}

	/**
	 * Same-site gate accepts the home host case-insensitively.
	 */
	public function test_is_same_site_url_accepts_home_host(): void {
		$this->assertTrue( Url::is_same_site_url( 'http://example.com/page/' ) );
		$this->assertTrue( Url::is_same_site_url( 'http://EXAMPLE.com/page/' ) );
		$this->assertFalse( Url::is_same_site_url( 'http://evil.com/page/' ) );
	}

	/**
	 * Same-site gate pins nonstandard ports to the home effective port.
	 */
	public function test_is_same_site_url_rejects_foreign_port(): void {
		$this->assertFalse( Url::is_same_site_url( 'http://example.com:8080/page/' ) );
		$this->assertFalse( Url::is_same_site_url( 'http://example.com:9000/page/' ) );
	}

	/**
	 * Redirect resolver keeps same-host relative hops and rejects off-host hops.
	 */
	public function test_resolve_same_host_redirect(): void {
		$resolved = Url::resolve_same_host_redirect(
			'/other-page/',
			'http://example.com/subdir/current/'
		);
		$this->assertSame( 'http://example.com/other-page/', $resolved );

		$this->assertFalse(
			Url::resolve_same_host_redirect(
				'http://evil.com/other/',
				'http://example.com/subdir/current/'
			)
		);
		$this->assertFalse( Url::resolve_same_host_redirect( '', 'http://example.com/a/' ) );
	}

	/**
	 * Redirect resolver normalizes dot segments lexically.
	 */
	public function test_resolve_same_host_redirect_normalizes_dot_segments(): void {
		$resolved = Url::resolve_same_host_redirect(
			'../other',
			'http://example.com/subdir/current/'
		);
		$this->assertSame( 'http://example.com/other', $resolved );
	}
}
