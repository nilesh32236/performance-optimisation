<?php
/**
 * Tests for Util::cached_content_url().
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Util::cached_content_url().
 *
 * @package PerformanceOptimise\Tests
 */
class UtilCachedContentUrlTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'content_url' )->alias(
			static function ( $path ) {
				return 'http://example.com/wp-content' . $path;
			}
		);
	}

	/**
	 * Test that repeated calls for the same path resolve content_url once.
	 */
	public function test_repeated_calls_are_cached_without_content_url_filter(): void {
		$calls = 0;
		Functions\when( 'content_url' )->alias(
			static function ( $path ) use ( &$calls ) {
				++$calls;
				return 'http://example.com/wp-content' . $path;
			}
		);

		$first = Util::cached_content_url( '/themes/my-theme/' );
		$again = Util::cached_content_url( '/themes/my-theme/' );

		$this->assertSame( 'http://example.com/wp-content/themes/my-theme/', $first );
		$this->assertSame( $first, $again );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Test that distinct paths are cached independently.
	 */
	public function test_distinct_paths_are_cached_independently(): void {
		$calls = 0;
		Functions\when( 'content_url' )->alias(
			static function ( $path ) use ( &$calls ) {
				++$calls;
				return 'http://example.com/wp-content' . $path;
			}
		);

		Util::cached_content_url( '/themes/a/' );
		Util::cached_content_url( '/themes/b/' );
		Util::cached_content_url( '/themes/a/' );

		$this->assertSame( 2, $calls );
	}

	/**
	 * Test that a registered content_url filter disables caching.
	 */
	public function test_content_url_filter_disables_caching(): void {
		Functions\when( 'has_filter' )->justReturn( true );

		$calls = 0;
		Functions\when( 'content_url' )->alias(
			static function ( $path ) use ( &$calls ) {
				++$calls;
				return 'http://example.com/wp-content' . $path;
			}
		);

		Util::cached_content_url( '/themes/a/' );
		Util::cached_content_url( '/themes/a/' );

		$this->assertSame( 2, $calls );
	}

	/**
	 * Test that results are keyed per blog id under switch_to_blog().
	 */
	public function test_results_are_keyed_per_blog_id(): void {
		$blog_id = 1;
		Functions\when( 'get_current_blog_id' )->alias(
			static function () use ( &$blog_id ) {
				return $blog_id;
			}
		);

		$calls = 0;
		Functions\when( 'content_url' )->alias(
			static function ( $path ) use ( &$calls, &$blog_id ) {
				++$calls;
				return 'http://example.com/' . $blog_id . '/wp-content' . $path;
			}
		);

		$first = Util::cached_content_url( '/themes/blog-switch/' );

		$blog_id = 2;
		$second  = Util::cached_content_url( '/themes/blog-switch/' );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 2, $calls );

		$blog_id = 1;
		$this->assertSame( $first, Util::cached_content_url( '/themes/blog-switch/' ) );
		$this->assertSame( 2, $calls );
	}

	/**
	 * Test that canonical_scheme() falls back to http when home_url() is absent.
	 */
	public function test_canonical_scheme_falls_back_to_http(): void {
		$this->assertSame( 'http', Util::canonical_scheme() );
	}

	/**
	 * Test that canonical_scheme() reads the scheme from home_url().
	 */
	public function test_canonical_scheme_reads_home_url_scheme(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This stub *is* the wp_parse_url replacement.
				return parse_url( $url, $component );
			}
		);

		$this->assertSame( 'https', Util::canonical_scheme() );
	}

	/**
	 * Test that a site whose home_url() is http keeps http (no forced upgrade).
	 */
	public function test_canonical_scheme_keeps_http_site_on_http(): void {
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This stub *is* the wp_parse_url replacement.
				return parse_url( $url, $component );
			}
		);

		$this->assertSame( 'http', Util::canonical_scheme() );
	}

	/**
	 * Test that cached_content_url() pins the scheme to home_url()'s scheme.
	 *
	 * Regression: content_url() derives its scheme from is_ssl(), which is
	 * false under WP-CLI/cron. The resulting http:// asset URLs were cached
	 * into CSS on disk and then served to HTTPS visitors, where the browser
	 * blocked them as mixed content (fonts/images never loaded).
	 */
	public function test_content_url_scheme_is_pinned_to_home_scheme(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This stub *is* the wp_parse_url replacement.
				return parse_url( $url, $component );
			}
		);

		// content_url() reports http, as it does in a CLI/cron context.
		$url = Util::cached_content_url( '/themes/boltfolio/assets/fonts/' );

		$this->assertStringStartsWith( 'https://', $url );
		$this->assertStringNotContainsString( 'http://', $url );
	}
}
