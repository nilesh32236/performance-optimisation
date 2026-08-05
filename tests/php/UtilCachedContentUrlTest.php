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
	 *
	 * @before
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

		$first = Util::cached_content_url( '/themes/a/' );

		$blog_id = 2;
		$second  = Util::cached_content_url( '/themes/a/' );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 2, $calls );

		$blog_id = 1;
		$this->assertSame( $first, Util::cached_content_url( '/themes/a/' ) );
		$this->assertSame( 2, $calls );
	}
}
