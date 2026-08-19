<?php
/**
 * Test case for the Util class.
 *
 * @package PerformanceOptimise\Tests\PHP
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Util;

/**
 * Class UtilCachedHomeUrlTest
 */
class UtilCachedHomeUrlTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Setup.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Util::reset_cached_home_urls();
	}

	/**
	 * Teardown.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test cached home URL with no filter.
	 */
	public function test_cached_home_url_no_filter() {
		Functions\when( 'has_filter' )->justReturn( false );

		$home_url_count = 0;
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) use ( &$home_url_count ) {
				$home_url_count++;
				return 'http://example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 9001 );
		Functions\when( 'untrailingslashit' )->alias(
			function ( $url ) {
				return rtrim( $url, '/' );
			}
		);

		$this->assertSame( 'http://example.com', Util::cached_home_url() );
		$this->assertSame( 'http://example.com/test-path', Util::cached_home_url( '/test-path' ) );
		$this->assertSame( 'http://example.com/test-path', Util::cached_home_url( 'test-path' ) );
		$this->assertSame( 'http://example.com/', Util::cached_home_url( '/' ) );
		$this->assertSame( 'http://example.com/trailing/', Util::cached_home_url( '/trailing/' ) );

		$this->assertSame( 1, $home_url_count, 'home_url should be called exactly once per blog due to caching.' );
	}

	/**
	 * Test cached home URL with filter.
	 */
	public function test_cached_home_url_with_filter() {
		Functions\when( 'has_filter' )->justReturn( true );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				// Mocking filtered behavior.
				return 'http://filtered-example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 9001 );
		Functions\when( 'untrailingslashit' )->alias(
			function ( $url ) {
				return rtrim( $url, '/' );
			}
		);

		$this->assertSame( 'http://filtered-example.com', Util::cached_home_url() );
		$this->assertSame( 'http://filtered-example.com/filtered-path', Util::cached_home_url( '/filtered-path' ) );
		$this->assertSame( 'http://filtered-example.com/', Util::cached_home_url( '/' ) );
		$this->assertSame( 'http://filtered-example.com/trailing/', Util::cached_home_url( '/trailing/' ) );
	}
}
