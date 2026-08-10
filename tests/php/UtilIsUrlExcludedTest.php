<?php
/**
 * Tests for Util::is_url_excluded().
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Util::is_url_excluded().
 *
 * @package PerformanceOptimise\Tests
 */
class UtilIsUrlExcludedTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
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
	}

	/**
	 * Test that an exact-match rule without a trailing slash excludes a URL
	 * that carries a trailing slash (pretty permalink form).
	 */
	public function test_exact_rule_matches_trailing_slash_url(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'http://example.com/cart/',
				array( 'http://example.com/cart' )
			)
		);
	}

	/**
	 * Test that an exact-match rule with a trailing slash excludes a URL that
	 * carries a trailing slash.
	 */
	public function test_exact_rule_with_trailing_slash_matches(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'http://example.com/cart/',
				array( 'http://example.com/cart/' )
			)
		);
	}

	/**
	 * Test that a root-relative rule is resolved against home_url() and matches.
	 */
	public function test_root_relative_rule_is_resolved_and_matches(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'http://example.com/cart/',
				array( '/cart' )
			)
		);
	}

	/**
	 * Test that a "(.*)" prefix rule matches a deeper path.
	 */
	public function test_wildcard_prefix_rule_matches_subpath(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'http://example.com/cart/checkout/',
				array( 'http://example.com/cart/(.*)' )
			)
		);
	}

	/**
	 * Test that a "(.*)" prefix rule with a trailing slash also matches the
	 * base path itself.
	 */
	public function test_wildcard_prefix_rule_matches_base_path(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'http://example.com/cart/',
				array( 'http://example.com/cart/(.*)' )
			)
		);
	}

	/**
	 * Test that a non-matching URL is not excluded.
	 */
	public function test_non_matching_url_is_not_excluded(): void {
		$this->assertFalse(
			Util::is_url_excluded(
				'http://example.com/shop/',
				array( 'http://example.com/cart' )
			)
		);
	}

	/**
	 * Test that a similar-but-not-equal path is not excluded.
	 */
	public function test_partial_path_is_not_excluded(): void {
		$this->assertFalse(
			Util::is_url_excluded(
				'http://example.com/cartoon/',
				array( 'http://example.com/cart' )
			)
		);
	}

	/**
	 * Test that an empty exclusion list never excludes.
	 */
	public function test_empty_exclusion_list_never_excludes(): void {
		$this->assertFalse(
			Util::is_url_excluded( 'http://example.com/cart/', array() )
		);
	}

	/**
	 * Test that an http rule matches an https URL (scheme-normalized matching).
	 */
	public function test_http_rule_matches_https_url(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'https://example.com/cart/',
				array( 'http://example.com/cart' )
			)
		);
	}

	/**
	 * Test that an https rule matches an http URL (scheme-normalized matching).
	 */
	public function test_https_rule_matches_http_url(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'http://example.com/cart/',
				array( 'https://example.com/cart' )
			)
		);
	}

	/**
	 * Test that an empty string rule is skipped instead of matching the homepage.
	 */
	public function test_empty_rule_is_skipped(): void {
		$this->assertFalse(
			Util::is_url_excluded(
				'http://example.com/',
				array( '' )
			)
		);
	}

	/**
	 * Test that a whitespace-only rule is skipped instead of matching the homepage.
	 */
	public function test_whitespace_rule_is_skipped(): void {
		$this->assertFalse(
			Util::is_url_excluded(
				'http://example.com/',
				array( '   ' )
			)
		);
	}

	/**
	 * Test that a root-relative wildcard rule matches a descendant path.
	 */
	public function test_root_relative_wildcard_rule_matches_subpath(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'http://example.com/my-account/orders/',
				array( 'my-account/(.*)' )
			)
		);
	}

	/**
	 * Test that a root-relative wildcard rule matches the base path itself.
	 */
	public function test_root_relative_wildcard_rule_matches_base_path(): void {
		$this->assertTrue(
			Util::is_url_excluded(
				'http://example.com/my-account/',
				array( 'my-account/(.*)' )
			)
		);
	}

	/**
	 * Test that a root-relative wildcard rule does not match a similar sibling path.
	 */
	public function test_root_relative_wildcard_rule_does_not_match_sibling(): void {
		$this->assertFalse(
			Util::is_url_excluded(
				'http://example.com/my-accounting/',
				array( 'my-account/(.*)' )
			)
		);
	}
}
