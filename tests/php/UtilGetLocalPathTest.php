<?php
/**
 * Tests for Util::get_local_path() ABSPATH bounds validation.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Util::get_local_path() ABSPATH bounds validation.
 *
 * @package PerformanceOptimise\Tests
 */
class UtilGetLocalPathTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 *
	 * @before
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Emulates wp_parse_url() in tests.
				$parts = parse_url( $url );
				if ( false === $parts ) {
					return false;
				}
				if ( -1 !== $component ) {
					return $parts[ $component ] ?? null;
				}
				return $parts;
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', $path );
				$path = preg_replace( '|(?<=.)/+|', '/', $path );
				if ( str_starts_with( $path, '//' ) ) {
					$path = '/' . ltrim( $path, '/' );
				}
				return $path;
			}
		);
		Functions\when( 'home_url' )->alias(
			static function () {
				return 'http://example.com';
			}
		);
	}

	/**
	 * Test that a legitimate URL resolving inside ABSPATH returns the path.
	 */
	public function test_legitimate_url_inside_abspath_returns_path(): void {
		$this->assertSame(
			'/tmp/wordpress/wp-content/themes/my-theme/style.css',
			Util::get_local_path( 'http://example.com/wp-content/themes/my-theme/style.css' )
		);
	}

	/**
	 * Test that an empty relative path resolves to ABSPATH itself.
	 */
	public function test_root_url_resolves_to_abspath(): void {
		$this->assertSame(
			'/tmp/wordpress/',
			Util::get_local_path( 'http://example.com/' )
		);
	}

	/**
	 * Test that a cousin-prefixed directory (e.g. wp-content2) is treated as
	 * inside ABSPATH, not rejected as a false positive of the bounds check.
	 */
	public function test_cousin_prefix_dir_is_not_false_positive(): void {
		// '/tmp/wordpress/wp-content2/...' genuinely lives inside ABSPATH.
		$this->assertSame(
			'/tmp/wordpress/wp-content2/outside/file.css',
			Util::get_local_path( 'http://example.com/wp-content2/outside/file.css' )
		);
	}

	/**
	 * Test that an out-of-bounds path resolves to '' after normalization.
	 */
	public function test_out_of_bounds_relative_path_returns_empty(): void {
		$this->assertSame(
			'',
			Util::get_local_path( 'http://example.com/../../etc/passwd' )
		);
	}

	/**
	 * Test that an unparseable URL returns ''.
	 */
	public function test_invalid_url_returns_empty(): void {
		Functions\when( 'wp_parse_url' )->justReturn( false );
		$this->assertSame( '', Util::get_local_path( '://not-a-url' ) );
	}

	/**
	 * Test that a directory directly inside ABSPATH passes the boundary check.
	 */
	public function test_direct_child_dir_is_accepted(): void {
		$this->assertSame(
			'/tmp/wordpress/wp-content',
			Util::get_local_path( 'http://example.com/wp-content' )
		);
	}
}
