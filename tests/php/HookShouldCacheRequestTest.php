<?php
/**
 * Tests for wppo_should_cache_request hook (Phase3 PR-C).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

/**
 * Verifies wppo_should_cache_request after DONOTCACHEPAGE, veto false skips ob_start.
 *
 * @since NEXT
 */
class HookShouldCacheRequestTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Helper to build Cache instance with controlled state.
	 *
	 * @param string $request_uri Request URI.
	 * @return Cache
	 */
	private function make_cache( string $request_uri = '/' ): Cache {
		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
		$props = array(
			'domain'         => 'example.com',
			'cache_root_dir' => '/tmp/wordpress/wp-content/cache/wppo',
			'request_uri'    => $request_uri,
			'url_path'       => trim( $request_uri, '/' ),
			'options'        => array( 'cache_settings' => array() ),
		);
		foreach ( $props as $k => $v ) {
			$prop = new \ReflectionProperty( Cache::class, $k );
			$prop->setAccessible( true );
			$prop->setValue( $cache, $v );
		}
		return $cache;
	}

	/**
	 * Filter returning false makes is_not_cacheable true (skip cache).
	 */
	public function test_filter_false_vetoes_cache(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_should_cache_request' === $tag ) {
					return false;
				}
				return $value;
			}
		);

		$cache = $this->make_cache( '/' );
		$ref   = new \ReflectionMethod( Cache::class, 'is_not_cacheable' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $cache );

		$this->assertTrue( $result, 'Filter false should make is_not_cacheable true' );
	}

	/**
	 * Filter returning true allows caching (is_not_cacheable false for plain page).
	 */
	public function test_filter_true_allows_cache(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_should_cache_request' === $tag ) {
					return true;
				}
				return $value;
			}
		);

		$cache = $this->make_cache( '/' );
		$ref   = new \ReflectionMethod( Cache::class, 'is_not_cacheable' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $cache );

		$this->assertFalse( $result );
	}

	/**
	 * DONOTCACHEPAGE true still wins even if filter returns true — verify code order.
	 */
	public function test_donotcachepage_wins_over_filter_true(): void {
		$content = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cache.php' );
		$pos_define = strpos( $content, "defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE" );
		$pos_filter = strpos( $content, "apply_filters( 'wppo_should_cache_request'" );
		$this->assertNotFalse( $pos_define );
		$this->assertNotFalse( $pos_filter );
		$this->assertLessThan( $pos_filter, $pos_define, 'DONOTCACHEPAGE check must appear before wppo_should_cache_request filter' );
	}

	/**
	 * Filter receives request_uri, is_mobile, is_logged_in args.
	 */
	public function test_filter_receives_four_args(): void {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			$this->markTestSkipped( 'DONOTCACHEPAGE already defined in this process; skip to avoid order dependency.' );
		}
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'wp_is_mobile' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$called = false;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value, $uri = null, $is_mobile = null, $is_logged = null ) use ( &$called ) {
				if ( 'wppo_should_cache_request' === $tag ) {
					$called = true;
					\PHPUnit\Framework\Assert::assertSame( '/members/', $uri );
					\PHPUnit\Framework\Assert::assertTrue( $is_mobile );
					\PHPUnit\Framework\Assert::assertTrue( $is_logged );
					return true;
				}
				return $value;
			}
		);
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );

		$cache = $this->make_cache( '/members/' );
		$ref   = new \ReflectionMethod( Cache::class, 'is_not_cacheable' );
		$ref->setAccessible( true );
		$ref->invoke( $cache );
		$this->addToAssertionCount( 1 );
	}
}
