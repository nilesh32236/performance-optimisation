<?php
/**
 * Test case for the Util class.
 *
 * @package PerformanceOptimise\Tests\PHP
 */

namespace PerformanceOptimise\Tests\PHP;

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Util;

/**
 * Class UtilCachedHomeUrlTest
 */
class UtilCachedHomeUrlTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Setup.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
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
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			function ( $url ) {
				return rtrim( $url, '/' );
			}
		);

		// Reset static cache before test.
		$reflection = new \ReflectionClass( Util::class );
		$method     = $reflection->getMethod( 'cached_home_url' );
		$method->setAccessible( true );
		// Can't directly clear static scope, so we just assume it's fresh or rely on blog_id isolation.

		$this->assertSame( 'http://example.com', Util::cached_home_url() );
		$this->assertSame( 'http://example.com/test-path', Util::cached_home_url( '/test-path' ) );
		$this->assertSame( 'http://example.com/test-path', Util::cached_home_url( 'test-path' ) );
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
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			function ( $url ) {
				return rtrim( $url, '/' );
			}
		);

		$this->assertSame( 'http://filtered-example.com', Util::cached_home_url() );
		$this->assertSame( 'http://filtered-example.com/filtered-path', Util::cached_home_url( '/filtered-path' ) );
	}
}
